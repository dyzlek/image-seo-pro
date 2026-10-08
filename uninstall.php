<?php
/**
 * Suppression du plugin : retire les copies WebP / AVIF, les métadonnées et les réglages.
 * Les images d'origine et les textes alternatifs restent intacts.
 *
 * @package ImageSeoPro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-isp-settings.php';
require_once __DIR__ . '/includes/class-isp-converter.php';
require_once __DIR__ . '/includes/class-isp-optimizer.php';

global $wpdb;

$isp_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", ISP_Optimizer::META ) ); // phpcs:ignore WordPress.DB
foreach ( $isp_ids as $isp_id ) {
	ISP_Optimizer::restore( (int) $isp_id );
}

delete_option( ISP_Settings::OPTION );
delete_post_meta_by_key( '_isp_lqip_base64' ); // Ancienne version 1.x.
