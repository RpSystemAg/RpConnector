<?php
/**
 * PHPStan bootstrap.
 *
 * WordPress defines these before any plugin file is reached, so the plugin
 * never declares them and PHPStan -- which sees only the plugin directory --
 * would report every use as an undefined constant. Declaring them here is not
 * a workaround; it is telling the analyser the truth about the environment the
 * code runs in.
 *
 * The values are irrelevant to the analysis. Only the types matter.
 *
 * This file is deliberately not part of any test suite. It exists so the static
 * analysis has the same shape in CI and on a developer machine.
 */

// Filesystem and URL layout provided by WordPress core.
defined( 'ABSPATH' ) || define( 'ABSPATH', '/wordpress/' );
defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', '/wordpress/wp-content' );
defined( 'WP_CONTENT_URL' ) || define( 'WP_CONTENT_URL', 'https://example.test/wp-content' );
defined( 'WP_PLUGIN_DIR' ) || define( 'WP_PLUGIN_DIR', '/wordpress/wp-content/plugins' );
defined( 'WPINC' ) || define( 'WPINC', 'wp-includes' );

// Runtime switches the plugin branches on.
defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', false );
defined( 'WP_DEBUG_LOG' ) || define( 'WP_DEBUG_LOG', false );
defined( 'DOING_CRON' ) || define( 'DOING_CRON', false );
defined( 'DOING_AJAX' ) || define( 'DOING_AJAX', false );
defined( 'WP_CLI' ) || define( 'WP_CLI', false );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );

// Defined by the plugin bootstrap file itself, which PHPStan analyses but does
// not execute.
defined( 'PRSTUDIO_UC_VERSION' ) || define( 'PRSTUDIO_UC_VERSION', '0.0.0-static-analysis' );
defined( 'PRSTUDIO_UC_PLUGIN_FILE' ) || define( 'PRSTUDIO_UC_PLUGIN_FILE', __FILE__ );
defined( 'PRSTUDIO_UC_PLUGIN_DIR' ) || define( 'PRSTUDIO_UC_PLUGIN_DIR', __DIR__ . '/' );
defined( 'PRSTUDIO_UC_PLUGIN_URL' ) || define( 'PRSTUDIO_UC_PLUGIN_URL', 'https://example.test/' );
defined( 'PRSTUDIO_UC_TESTING' ) || define( 'PRSTUDIO_UC_TESTING', false );
