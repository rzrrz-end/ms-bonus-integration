<?php
/**
 * WooCommerce My Account bonuses endpoint.
 *
 * @package MS_Bonus_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MS_Bonus_Account_Page
 */
class MS_Bonus_Account_Page {

	const ENDPOINT             = 'bonuses';
	const WOOMS_AGENT_META_KEY = 'agent_uuid';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register rewrite endpoint.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Add menu item to My Account navigation.
	 *
	 * @param array<string, string> $items Menu items.
	 * @return array<string, string>
	 */
	public static function add_menu_item( $items ) {
		$new_items = array();

		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;

			if ( 'orders' === $key ) {
				$new_items[ self::ENDPOINT ] = __( 'Мои бонусы', 'ms-bonus-integration' );
			}
		}

		if ( ! isset( $new_items[ self::ENDPOINT ] ) ) {
			$new_items[ self::ENDPOINT ] = __( 'Мои бонусы', 'ms-bonus-integration' );
		}

		return $new_items;
	}

	/**
	 * Enqueue frontend styles on bonuses page.
	 */
	public static function enqueue_assets() {
		if ( ! is_account_page() || ! is_wc_endpoint_url( self::ENDPOINT ) ) {
			return;
		}

		wp_enqueue_style(
			'ms-bonus-account',
			MS_BONUS_PLUGIN_URL . 'assets/css/account-bonuses.css',
			array(),
			MS_BONUS_VERSION
		);
	}

	/**
	 * Render bonuses endpoint content.
	 */
	public static function render_endpoint() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			echo '<p>' . esc_html__( 'Войдите в аккаунт, чтобы увидеть бонусный баланс.', 'ms-bonus-integration' ) . '</p>';
			return;
		}

		$counterparty_id = self::get_user_counterparty_id( $user_id );

		if ( empty( $counterparty_id ) ) {
			echo '<div class="ms-bonus-account ms-bonus-account--empty">';
			echo '<p class="ms-bonus-account__message">';
			echo esc_html__( 'Бонусный баланс появится после первого заказа', 'ms-bonus-integration' );
			echo '</p></div>';
			return;
		}

		$balance = self::get_cached_user_balance( $user_id, $counterparty_id );

		if ( is_wp_error( $balance ) ) {
			MS_Bonus_Helpers::log_error(
				sprintf(
					'Balance request failed for user %d, counterparty %s: [%s] %s',
					$user_id,
					$counterparty_id,
					$balance->get_error_code(),
					$balance->get_error_message()
				)
			);

			echo '<div class="ms-bonus-account ms-bonus-account--error">';
			echo '<p class="ms-bonus-account__message">';
			echo esc_html__( 'Не удалось получить баланс бонусов, попробуйте позже', 'ms-bonus-integration' );
			echo '</p></div>';
			return;
		}

		$rate_hint = self::get_point_rate_hint();

		echo '<div class="ms-bonus-account">';
		echo '<p class="ms-bonus-account__balance">';
		printf(
			/* translators: %d: bonus points balance */
			esc_html__( 'Ваш бонусный баланс: %d баллов', 'ms-bonus-integration' ),
			(int) $balance
		);
		echo '</p>';

		echo '<p class="ms-bonus-account__rules">';
		echo esc_html__( 'Правила: 1000 приветственных баллов через 10 дней после регистрации; 3% с каждого оплаченного заказа; списание до 20% суммы заказа; баллы не сгорают; бонусы нельзя совмещать с промокодом.', 'ms-bonus-integration' );
		echo '</p>';

		if ( '' !== $rate_hint ) {
			echo '<p class="ms-bonus-account__hint">' . esc_html( $rate_hint ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Get MoySklad counterparty UUID linked to the user via WooMS order meta.
	 *
	 * WooMS stores counterparty ID in order meta key "agent_uuid"
	 * (see WooMS\Orders::save_uuid_agent_to_order).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Counterparty UUID or empty string.
	 */
	public static function get_user_counterparty_id( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return '';
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 20,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'return'      => 'objects',
			)
		);

		foreach ( $orders as $order ) {
			$agent_uuid = (string) $order->get_meta( self::WOOMS_AGENT_META_KEY, true );

			if ( '' !== $agent_uuid ) {
				return sanitize_text_field( $agent_uuid );
			}
		}

		return '';
	}

	/**
	 * Get user bonus balance (delegates to MS_Bonus_API cache layers).
	 *
	 * @param int    $user_id         WordPress user ID.
	 * @param string $counterparty_id MoySklad counterparty UUID.
	 * @param int    $timeout         API request timeout in seconds.
	 * @return int|WP_Error
	 */
	public static function get_cached_user_balance( $user_id, $counterparty_id, $timeout = MS_Bonus_API::DEFAULT_TIMEOUT ) {
		unset( $user_id );

		return MS_Bonus_API::get_counterparty_balance( $counterparty_id, $timeout );
	}

	/**
	 * Build dynamic hint about point-to-ruble rate from bonus program settings.
	 *
	 * @return string
	 */
	private static function get_point_rate_hint() {
		$program = MS_Bonus_API::get_bonus_program();

		if ( is_wp_error( $program ) ) {
			MS_Bonus_Helpers::log_error(
				sprintf(
					'Bonus program request failed: [%s] %s',
					$program->get_error_code(),
					$program->get_error_message()
				)
			);
			return '';
		}

		if ( ! isset( $program['spendRatePointsToRouble'] ) || '' === $program['spendRatePointsToRouble'] ) {
			return '';
		}

		$rate = (float) $program['spendRatePointsToRouble'];

		if ( $rate <= 0 ) {
			return '';
		}

		$formatted_rate = wc_format_decimal( $rate, wc_get_price_decimals(), true );

		return sprintf(
			/* translators: %s: ruble value of one bonus point */
			__( '1 балл = %s руб.', 'ms-bonus-integration' ),
			$formatted_rate
		);
	}
}
