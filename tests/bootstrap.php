<?php
/**
 * PHPUnit bootstrap for WordPress integration tests.
 *
 * Requires wp-tests-config.php in the project root (copy from wp-tests-config.php.example).
 */

define( 'WP_TESTS_CONFIG_FILE_PATH', dirname( __DIR__ ) . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php';

// Load plugin class files (without instantiating — tests do that themselves).
require_once dirname( __DIR__ ) . '/src/class-instagramimporteradminpage.php';
require_once dirname( __DIR__ ) . '/src/class-instagramarchiveimporter.php';
