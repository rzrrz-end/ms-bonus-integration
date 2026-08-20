<?php
/**
 * Plugin Name: MS Bonus Integration
 * Description: Интеграция бонусной программы МойСклад с WooCommerce. Использует учётные данные WooMS.
 * Version: 1.3.0
 * Author: MS Bonus Integration
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ms-bonus-integration
 * WC requires at least: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'MS_BONUS_VERSION', '1.3.0' );
define( 'MS_BONUS_PLUGIN_FILE', __FILE__ );
define( 'MS_BONUS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MS_BONUS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		require_once MS_BONUS_PLUGIN_DIR . 'includes/class-ms-bonus-account-page.php';
		MS_Bonus_Account_Page::add_endpoint();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		flush_rewrite_rules();
	}
);

/**
 * Default plugin settings.
 *
 * @return array<string, string>
 */
function ms_bonus_default_settings() {
	return array(
		'bonus_program_id' => '',
		'order_status'     => 'processing',
	);
}

/**
 * Get merged plugin settings.
 *
 * @return array<string, string>
 */
function ms_bonus_get_settings() {
	$stored = get_option( 'ms_bonus_settings', array() );

	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	return wp_parse_args( $stored, ms_bonus_default_settings() );
}

/**
 * Bootstrap plugin after WooCommerce is loaded.
 */
function ms_bonus_integration_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'MS Bonus Integration требует активный WooCommerce.', 'ms-bonus-integration' );
				echo '</p></div>';
			}
		);
		return;
	}

	require_once MS_BONUS_PLUGIN_DIR . 'includes/class-ms-bonus-api.php';
	require_once MS_BONUS_PLUGIN_DIR . 'includes/class-ms-bonus-helpers.php';
	require_once MS_BONUS_PLUGIN_DIR . 'includes/class-ms-bonus-settings.php';
	require_once MS_BONUS_PLUGIN_DIR . 'includes/class-ms-bonus-account-page.php';
	require_once MS_BONUS_PLUGIN_DIR . 'includes/class-ms-bonus-wooms-bridge.php';
	require_once MS_BONUS_PLUGIN_DIR . 'includes/class-ms-bonus-checkout.php';
	require_once MS_BONUS_PLUGIN_DIR . 'includes/class-ms-bonus-order-sync.php';
	require_once MS_BONUS_PLUGIN_DIR . 'includes/class-ms-bonus-earning.php';
	require_once MS_BONUS_PLUGIN_DIR . 'includes/class-ms-bonus-welcome.php';

	MS_Bonus_Settings::init();
	MS_Bonus_Account_Page::init();
	MS_Bonus_Wooms_Bridge::init();
	MS_Bonus_Checkout::init();
	MS_Bonus_Order_Sync::init();
	MS_Bonus_Earning::init();
	MS_Bonus_Welcome::init();
}
add_action( 'plugins_loaded', 'ms_bonus_integration_init' );
