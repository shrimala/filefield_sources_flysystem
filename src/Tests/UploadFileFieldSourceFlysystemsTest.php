<?php
namespace Drupal\filefield_sources_flysystem\Tests;

/**
 * @file
 * Tests for the fileField_source_flysystem module.
 */
use Drupal\file\Tests\FileFieldTestBase;
use Drupal\simpletest\WebTestBase;

/**
 * Tests the fileField_source_flysystem module.
 *
 * @group fileField_source_flysystem
 */
class UploadFileFieldSourceFlysystemsTest extends FileFieldTestBase {
  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('filefield_sources_flysystem');

  protected $typeName;
  protected $fieldName;
  protected $node;

  /**
   * Metadata about our test case.
   */
  public static function getInfo() {
    return array(
      // The human readable name of the test case.
      'name' => 'Upload File Field Source Flysystem Test',
      // A short description of the tests this case performs.
      'description' => 'Tests for the FileField_Source_Flysystem module.',
    );
  }

  /**
   * Perform any setup tasks for our test case.
   */
  public function setUp() {
    WebTestBase::setUp();
    // parent::setUp(array('FileField_Source_Flysystem'));.
  }

  /**
   * Automated test for module.
   */
  public function testNewContent() {
    $this->adminUser = $this->drupalCreateUser(array(
      'access content',
      'access administration pages',
      'administer site configuration',
      'administer users',
      'administer permissions',
      'administer content types',
      'administer node fields',
      'administer node display',
      'administer node form display',
      'administer nodes',
      'administer modules',
      'bypass node access',
    ));
    $this->drupalLogin($this->adminUser);

    // Check module is activate or not.
    $this->drupalGet('/admin/modules');

    // Create content type.
    $this->typeName = 'article';
    $this->drupalCreateContentType(array('type' => $this->typeName, 'name' => 'File Upload'));

    // Add file field.
    $this->fieldName = strtolower('File_Upload');
    $this->createFileField($this->fieldName, 'node', $this->typeName);

    // Go to field manage to check  Upload destination.
    $this->drupalGet('admin/structure/types/manage/' . $this->typeName . '/fields/node.' . $this->typeName . '.' . $this->fieldName . '/storage');

    // Add Node.
    $this->drupalGet('/node/add/' . $this->typeName);

    // Verify the field exists on the page or node.
    $this->assertField('files[' . $this->fieldName . '_0]', 'Found Field in node');

    // Print out the current url.
    $this->verbose(print_r($this->getUrl(), TRUE));
  }

}
