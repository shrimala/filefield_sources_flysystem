-----------------
FileField Sources Flysystem
-----------------

Description
-----------
FileField Sources Flysystem module enhances the functionalities of Filefield 
Sources module for Drupal 8.It allows you to connect with different filesystems 
like dropbox, S3 etc uisng flysystem and you can select files from different 
directories of that filesystem and upload it in you drupal storage. you can also
access files directly from remote storage by using "serve from your current 
location".


Installation
------------
1) Place this module directory in your modules folder (this will usually be
   "modules/").

2) Enable the module within your Drupal site.

3) Add or configure an existing file or image field. To configure a typical node
   field, visit Manage -> Structure -> Content types and click
   "Manage form display" on a type you'd like to modify. Add a new file field or
   edit an existing one.

   While editing the file or image field, you'll have new options available
   under a "File sources" details. You can enable "File attach by Flysystem" and 
   configure "flysystem setting".

4) Create a piece of content that uses your file and try it out.

