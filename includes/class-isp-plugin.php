<?php
/**
 * Assemblage du plugin.
 *
 * @package ImageSeoPro
 */

defined( 'ABSPATH' ) || exit;

final class ISP_Plugin {

	/**
	 * @var ISP_Plugin|null
	 */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		load_plugin_textdomain( 'image-seo-pro', false, dirname( plugin_basename( ISP_FILE ) ) . '/languages' );

		ISP_Settings::migrate_legacy();

		new ISP_Optimizer();
		new ISP_Delivery();

		if ( is_admin() ) {
			new ISP_Admin();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ISP_PATH . 'includes/class-isp-cli.php';
			WP_CLI::add_command( 'isp', 'ISP_CLI' );
		}
	}

	public static function activate() {
		ISP_Settings::migrate_legacy();
	}
}
