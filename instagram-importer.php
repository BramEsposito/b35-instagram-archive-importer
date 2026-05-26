<?php

/*
Plugin Name: Instagram Archive importer
Plugin URI: https://bramesposito.com/projects/wordpress/instagram-archive-importer
Description: Import all your old instagram posts from your archive
Author: Bram Esposito
Author URI: https://bramesposito.com
Version: 1.00
Text Domain: instagram-archive-importer
License: MIT License
*/

require_once plugin_dir_path(__FILE__).'src/InstagramArchiveImporter.php';
require_once plugin_dir_path(__FILE__).'src/LocationMetaBox.php';

new InstagramArchiveImporter(plugin_dir_path(__FILE__));
new LocationMetaBox(plugin_dir_url(__FILE__));
