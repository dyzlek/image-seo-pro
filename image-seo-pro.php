<?php
/**
 * Plugin Name:       Image SEO Pro
 * Plugin URI:        https://github.com/dyzlek/image-seo-pro
 * Description:       Lighter images (WebP / AVIF, originals never modified) and complete alt texts, with AI suggestions.
 * Version:           2.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Dylan Belledent
 * Author URI:        https://github.com/dyzlek
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       image-seo-pro
 * Domain Path:       /languages
 *
 * @package ImageSeoPro
 */

defined( 'ABSPATH' ) || exit;

define( 'ISP_VERSION', '2.0.0' );
define( 'ISP_FILE', __FILE__ );
define( 'ISP_PATH', plugin_dir_path( __FILE__ ) );
define( 'ISP_URL', plugin_dir_url( __FILE__ ) );

require_once ISP_PATH . 'includes/class-isp-settings.php';
require_once ISP_PATH . 'includes/class-isp-converter.php';
require_once ISP_PATH . 'includes/class-isp-optimizer.php';
require_once ISP_PATH . 'includes/class-isp-delivery.php';
require_once ISP_PATH . 'includes/class-isp-alt-text.php';
require_once ISP_PATH . 'includes/class-isp-ai-client.php';
require_once ISP_PATH . 'includes/class-isp-admin.php';
require_once ISP_PATH . 'includes/class-isp-plugin.php';

register_activation_hook( __FILE__, array( 'ISP_Plugin', 'activate' ) );

add_action( 'plugins_loaded', array( 'ISP_Plugin', 'instance' ) );
