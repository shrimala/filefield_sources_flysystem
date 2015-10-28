<?php

/**
 * @file
 * Contains \Drupal\filefield_sources_flysystem\Plugin\FilefieldSource\Flysystem.
 */

namespace Drupal\filefield_sources_flysystem\Plugin\FilefieldSource;

use Drupal\Core\Form\FormStateInterface;
use Drupal\filefield_sources\FilefieldSourceInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Site\Settings;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Dropbox\Client;
use Drupal\flysystem\FlysystemFactory;
use Drupal\flysystem\FlysystemBridge;
use \GuzzleHttp\ClientInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
 * A FileField source plugin to allow use of files within a server directory.
 *
 * @FilefieldSource(
 *   id = "flysystem",
 *   name = @Translation("File attach by Flysystem"),
 *   label = @Translation("File attach Using Flysystem"),
 *   description = @Translation("Select a file from a directory on the Dropbox."),
 *   weight = 7
 * )
 */
class Flysystem extends FlysystemFactory implements FilefieldSourceInterface, ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, ClientInterface $http_client) {
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $container->get('http_client'));
  }

  /**
   * {@inheritdoc}
   */
  public static function value(array &$element, &$input, FormStateInterface $form_state) {
    if (!empty($input['filefield_flysystem']['filename'])) {
      $instance = entity_load('field_config', $element['#entity_type'] . '.' . $element['#bundle'] . '.' . $element['#field_name']);
      $filepath = $input['filefield_flysystem']['filename'];

      $directory = $element['#upload_location'];
      $mode = Settings::get('file_chmod_directory', FILE_CHMOD_DIRECTORY);
      if (!drupal_chmod($directory, $mode) && !file_prepare_directory($directory, FILE_CREATE_DIRECTORY)) {
        \Drupal::logger('filefield_sources')->log(E_NOTICE, 'File %file could not be copied, because the destination directory %destination is not configured correctly.', array(
          '%file' => $filepath,
          '%destination' => drupal_realpath($directory),
        ));
        drupal_set_message(t('The specified file %file could not be copied, because the destination directory is not properly configured. This may be caused by a problem with file or directory permissions. More information is available in the system log.', array('%file' => $filepath)), 'error');
        return;
      }
      // Clean up the file name extensions and transliterate.
      $original_filepath = $filepath;
      $new_filepath = filefield_sources_clean_filename($filepath, $instance->getSetting['file_extensions']);
      rename($filepath, $new_filepath);
      $filepath = $new_filepath;
      // Run all the normal validations, minus file size restrictions.
      $validators = $element['#upload_validators'];
      if (isset($validators['file_validate_size'])) {
        unset($validators['file_validate_size']);
      }
      // Serve files from source folder directly.
      if ($element['#filefield_sources_settings']['flysystem']['attach_mode'] == FILEFIELD_SOURCE_FLYSYSTEM_ATTACH_MODE_SERVEFROMFOLDER) {
        $directory = $filepath;
        if ($file = filefield_sources_save_file_servefromattach($filepath, $validators, $directory)) {
          if (!in_array($file->id(), $input['fids'])) {
            $input['fids'][] = $file->id();
          }
        }
      }
      else {
        if ($file = filefield_sources_save_file($filepath, $validators, $directory)) {
          if (!in_array($file->id(), $input['fids'])) {
            $input['fids'][] = $file->id();
          }

          // Delete the original file if "moving" the file instead of copying.
          if ($element['#filefield_sources_settings']['flysystem']['attach_mode'] !== FILEFIELD_SOURCE_FLYSYSTEM_ATTACH_MODE_COPY) {
            @unlink($filepath);
          }
        }
      }

      // Restore the original file name if the file still exists.
      if (file_exists($filepath) && $filepath != $original_filepath) {
        rename($filepath, $original_filepath);
      }

      $input['filefield_flysystem']['filename'] = '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function process(array &$element, FormStateInterface $form_state, array &$complete_form) {

    $settings = $element['#filefield_sources_settings']['flysystem'];
    $field_name = $element['#field_name'];
    $instance = entity_load('field_config', $element['#entity_type'] . '.' . $element['#bundle'] . '.' . $field_name);

    $element['filefield_flysystem'] = array(
      '#weight' => 100.5,
      '#theme' => 'filefield_sources_element',
      '#source_id' => 'flysystem',
      // Required for proper theming.
      '#filefield_source' => TRUE,
    );

    $path = static::getDirectory($settings);
    $options = static::getAttachOptions($settings);
    // If we have built this element before, append the list of options that we
    // had previously. This allows files to be deleted after copying them and
    // still be considered a valid option during the validation and submit.
    $triggering_element = $form_state->getTriggeringElement();
    $property = array(
      'filefield_sources',
      $field_name,
      'attach_options',
    );
    if (!isset($triggering_element) && $form_state->has($property)) {
      $attach_options = $form_state->get($property);
      $options = $options + $attach_options;
    }
    else {
      $form_state->set(array('filefield_sources', $field_name, 'attach_options'), $options);
    }

    $description = t('This method may be used to attach files that exceed the file size limit. Files may be attached from the %directory directory on the server, usually uploaded through FTP.', array('%directory' => realpath($path)));

    // Error messages.
    if ($options === FALSE || empty($settings['path'])) {
      $attach_message = t('A file attach directory could not be located.');
      $attach_description = t('Please check your settings for the %field field.', array('%field' => $instance->getLabel()));
    }
    elseif (!count($options)) {
      $attach_message = t('There currently are no files to attach.');
      $attach_description = $description;
    }

    if (isset($attach_message)) {
      $element['filefield_flysystem']['attach_message'] = array(
        '#markup' => $attach_message,
      );
      $element['filefield_flysystem']['#description'] = $attach_description;
    }
    else {
      $validators = $element['#upload_validators'];
      if (isset($validators['file_validate_size'])) {
        unset($validators['file_validate_size']);
      }
      $description .= '<br />' . filefield_sources_element_validation_help($validators);
      $element['filefield_flysystem']['filename'] = array(
        '#type' => 'select',
        '#options' => $options,
      );
      $element['filefield_flysystem']['#description'] = $description;
    }

    $ajax_settings = [
      'callback' => [get_called_class(), 'uploadAjaxCallbackflysystem'],
      'options' => [
        'query' => [
          'element_parents' => implode('/', $element['#array_parents']),
        ],
      ],
      'wrapper' => $element['upload_button']['#ajax']['wrapper'],
      'effect' => 'fade',
      'progress' => [
        'type' => $element['#progress_indicator'],
        'message' => $element['#progress_message'],
      ],
    ];
    $element['filefield_flysystem']['attach'] = [
      '#name' => implode('_', $element['#parents']) . '_attach',
      '#type' => 'submit',
      '#value' => t('Attach'),
      '#validate' => [],
      '#submit' => ['file_managed_file_submit'],
      '#limit_validation_errors' => [$element['#parents']],
      '#ajax' => $ajax_settings,
    ];

    return $element;
  }

  /**
   * Theme the output of the attach element.
   */
  public static function element($variables) {
    $element = $variables['element'];
    $options = form_select_options($element['filename']);
    $option_output = '';
    foreach ($options as $key => $value) {
      $option_output .= '<option value=' . $value["value"] . '>' . $value["label"] . '</option>';
    }
    if (isset($element['attach_message'])) {
      $output = $element['attach_message']['#markup'];
    }
    else {
      $size = !empty($element['filename']['#size']) ? ' size="' . $element['filename']['#size'] . '"' : '';
      $element['filename']['#attributes']['class'][] = 'form-select';
      $multiple = !empty($element['#multiple']);
      $output = '<select name="' . $element['filename']['#name'] . '' . ($multiple ? '[]' : '') . '"' . ($multiple ? ' multiple="multiple" ' : '') . new Attribute($element['filename']['#attributes']) . ' id="' . $element['filename']['#id'] . '" ' . $size . '>' . $option_output . '</select>';
    }

    $output .= drupal_render($element['attach']);
    $element['#children'] = $output;
    $element['#theme_wrappers'] = array('form_element');
    return '<div class="filefield-source filefield-source-flysystem clear-block">' . drupal_render($element) . '</div>';
  }

  /**
   * Get directory from settings.
   *
   * @param array $settings
   *   Attach source's settings.
   * @param object $account
   *   User to replace token.
   *
   * @return string
   *   Path that contains files to attach.
   */
  protected static function getDirectory(array $settings, $account = NULL) {
    $account = isset($account) ? $account : \Drupal::currentUser();
    $path = $settings['path'];
    $scheme = $settings['select_scheme'];
    // Replace user level tokens.
    // Node level tokens require a lot of complexity like temporary storage
    // locations when values don't exist. See the filefield_paths module.
    if (\Drupal::moduleHandler()->moduleExists('token')) {
      $token = \Drupal::token();
      $path = $token->replace($path, array('user' => $account));
    }

    return $scheme . '://' . $path;
  }

  /**
   * Get attach options.
   *
   * @param array $settings
   *   Attach source's settings.
   *
   * @return array
   *   List of options.
   */
  protected static function getAttachOptions(array $settings) {
    $flysystemfactory = \Drupal::service('flysystem_factory');
    $path = $settings['path'];
    $flysystem = $flysystemfactory->getFilesystem($settings['select_scheme']);
    $files = $flysystem->listContents($path, TRUE);

    $options = array();

    if (count($files)) {
      $options = array('' => t('-- Select file --'));
      foreach ($files as $key => $fileinfo) {
        $file_name = $fileinfo['basename'];
        $file_url = $settings['select_scheme'] . '://' . $fileinfo['path'];
        $options[$file_url] = $file_name;
      }
    }

    natcasesort($options);
    return $options;
  }

  /**
   * Implements hook_filefield_source_settings().
   */
  public static function settings(WidgetInterface $plugin) {
    $scheme_settings = Settings::get('flysystem', []);
    $flysystem_array = [];
    foreach ($scheme_settings as $key => $value) {
      $flysystem_array[$key] = ucfirst($value['driver']);
    }
    $settings = $plugin->getThirdPartySetting('filefield_sources', 'filefield_sources', array(
      'flysystem' => array(
        'path' => FILEFIELD_SOURCE_FLYSYSTEM_ATTACH_DEFAULT_PATH,
        'attach_mode' => FILEFIELD_SOURCE_FLYSYSTEM_ATTACH_MODE_MOVE,
        'select_scheme' => FILEFIELD_SOURCE_FLYSYSTEM_SCHEME,
      ),
    ));

    $return['flysystem'] = array(
      '#title' => t('Flysystem settings'),
      '#type' => 'details',
      '#description' => t('Select files from a filesystem by Flysystem.'),
      '#weight' => 3,
    );

    $return['flysystem']['select_scheme'] = array(
      '#type' => 'radios',
      '#title' => t('Flysystem filesystems'),
      '#options' => $flysystem_array,
      '#default_value' => isset($settings['flysystem']['select_scheme']) ? $settings['flysystem']['select_scheme'] : '',
    );
    $return['flysystem']['path'] = array(
      '#type' => 'textfield',
      '#title' => t('Filesystem path'),
      '#default_value' => $settings['flysystem']['path'],
      '#size' => 60,
      '#maxlength' => 128,
      '#description' => t('The directory within the Flysystem filesystem that will contain attachable files.'),
    );
    if (\Drupal::moduleHandler()->moduleExists('token')) {
      $return['flysystem']['tokens'] = array(
        '#theme' => 'token_tree',
        '#token_types' => array('user'),
      );
    }
    $return['flysystem']['attach_mode'] = array(
      '#type' => 'radios',
      '#title' => t('File Options'),
      '#options' => array(
        FILEFIELD_SOURCE_FLYSYSTEM_ATTACH_MODE_MOVE => t('Move the file to the storage destination'),
        FILEFIELD_SOURCE_FLYSYSTEM_ATTACH_MODE_COPY => t('Copy the file to the storage destination'),
        FILEFIELD_SOURCE_FLYSYSTEM_ATTACH_MODE_SERVEFROMFOLDER => t('Serve the file from its current location (only possible when this Flysystem filesystem is also the file storage destination for this file field).'),
      ),
      '#default_value' => isset($settings['flysystem']['attach_mode']) ? $settings['flysystem']['attach_mode'] : 'move',
    );
    return $return;
  }

  /**
   * Ajax callback for managed_file upload forms.
   *
   * This ajax callback takes care of the following things:
   *   - Ensures that broken requests due to too big files are caught.
   *   - Adds a class to the response to be able to highlight in the UI, that a
   *     new file got uploaded.
   *
   * @param array $form
   *   The build form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The ajax response of the ajax upload.
   */
  public static function uploadAjaxCallbackflysystem(array &$form, FormStateInterface &$form_state, Request $request) {
    /** @var \Drupal\Core\Render\RendererInterface $renderer */
    $renderer = \Drupal::service('renderer');

    $form_parents = explode('/', $request->query->get('element_parents'));

    // Retrieve the element to be rendered.
    $form = NestedArray::getValue($form, $form_parents);

    // Add the special AJAX class if a new file was added.
    $current_file_count = $form_state->get('file_upload_delta_initial');
    if (isset($form['#file_upload_delta']) && $current_file_count < $form['#file_upload_delta']) {
      $form[$current_file_count]['#attributes']['class'][] = 'ajax-new-content';
    }
    // Otherwise just add the new content class on a placeholder.
    else {
      $form['#suffix'] .= '<span class="ajax-new-content"></span>';
    }

    $status_messages = ['#type' => 'status_messages'];
    $form['#prefix'] .= $renderer->renderRoot($status_messages);
    $output = $renderer->renderRoot($form);

    $response = new AjaxResponse();
    $response->setAttachments($form['#attached']);

    return $response->addCommand(new ReplaceCommand(NULL, $output));
  }

}
