<?php
/*
Plugin Name: Really Simple CSV Importer CLI
Plugin URI: 
Description: Import posts, categories, tags, custom fields from simple csv file.
Author: digitalcube 
Author URI: https://en.digitalcube.jp/
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
Version: 1.3
*/

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

require_once __DIR__.'/CSVImport_Command.php';

WP_CLI::add_command( 'import', 'CSVImport_Command' );
