<?php
/**
 * Plugin Name:       WP Sync
 * Description:       ローカルと本番の WordPress の間で、データベース・メディア・テーマ・プラグインを pull / push で同期します（WP Migrate Pro の代わり）。
 * Version:           0.4.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            yama
 * Update URI:        https://github.com/yama203/wp-sync
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPS_VERSION', '0.4.1' );
// 通信の形を変えたら上げる。両側で一致しないと同期しない。
define( 'WPS_API_VERSION', 4 );
define( 'WPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPS_URL', plugin_dir_url( __FILE__ ) );
define( 'WPS_BASENAME', plugin_basename( __FILE__ ) );

require_once WPS_DIR . 'includes/class-wps-settings.php';
require_once WPS_DIR . 'includes/class-wps-transport.php';
require_once WPS_DIR . 'includes/class-wps-replacer.php';
require_once WPS_DIR . 'includes/class-wps-db.php';
require_once WPS_DIR . 'includes/class-wps-files.php';
require_once WPS_DIR . 'includes/class-wps-server.php';
require_once WPS_DIR . 'includes/class-wps-job.php';
require_once WPS_DIR . 'includes/class-wps-updater.php';

WPS_Server::init();
WPS_Updater::init();

if ( is_admin() ) {
	require_once WPS_DIR . 'includes/class-wps-admin.php';
	WPS_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WPS_DIR . 'includes/class-wps-cli.php';
	WP_CLI::add_command( 'sync', 'WPS_CLI' );
}

register_activation_hook( __FILE__, array( 'WPS_Settings', 'secret' ) );
