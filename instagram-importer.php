<?php // phpcs:ignore Squiz.Commenting.FileComment.Missing -- WordPress plugin header serves as file documentation
/**
 * Instagram Archive Importer plugin.
 *
 * Plugin Name: Instagram Archive importer
 * Plugin URI: https://bramesposito.com/projects/wordpress/instagram-archive-importer
 * Description: Import all your old instagram posts from your archive
 * Author: Bram Esposito
 * Author URI: https://bramesposito.com
 * Version: 1.00
 * Text Domain: b35-instagram-archive-importer
 * License: MIT License
 *
 * @package Instagram_Archive_Importer
 */

require_once plugin_dir_path( __FILE__ ) . 'src/class-instagramimporteradminpage.php';
require_once plugin_dir_path( __FILE__ ) . 'src/class-instagramarchiveimporter.php';
require_once plugin_dir_path( __FILE__ ) . 'src/class-locationmetabox.php';

new InstagramImporterAdminPage( plugin_dir_path( __FILE__ ), plugin_dir_url( __FILE__ ) );
new InstagramArchiveImporter( plugin_dir_path( __FILE__ ) );
new LocationMetaBox( plugin_dir_url( __FILE__ ) );
