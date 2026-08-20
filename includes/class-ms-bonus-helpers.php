<?php
/**
 * Shared helpers for MS Bonus Integration.
 *
 * @package MS_Bonus_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MS_Bonus_Helpers
 */
class MS_Bonus_Helpers {

	const META_REQUESTED      = '_ms_bonus_requested';
	const META_SPENT          = '_ms_bonus_spent';
	const META_ERROR          = '_ms_bonus_error';
	const META_PROCESSED      = '_ms_bonus_processed';
	const META_EARNED         = '_ms_bonus_earned';
	const META_EARN_ATTEMPTED = '_ms_bonus_earn_attempted';
	const META_EARN_ERROR     = '_ms_bonus_earn_error';
	const META_EARN_RETRIES   = '_ms_bonus_earn_retries';

	const META_WELCOME_CREDITED_AT = '_ms_welcome_bonus_credited_at';
	const META_WELCOME_RETRIES     = '_ms_welcome_bonus_retries';

	const FEE_CART_NAME        = 'ms_bonus_discount';
	const CRON_EARN_HOOK       = 'ms_bonus_retry_earning';
	const CRON_WELCOME_HOOK    = 'ms_bonus_welcome_bonus';
	const EARN_MAX_RETRIES     = 6;
	const WELCOME_MAX_RETRIES  = 30;
	const WELCOME_BONUS_POINTS = 1000;
	const WELCOME_DELAY_DAYS   = 10;
	const CHECKOUT_API_TIMEOUT = 5;

	/** Order earning rate: 3% of order amount. */
	const EARN_RATE = 0.03;

	/** Unified checkout spend limit: 20% of order total. */
	const SPEND_MAX_PERCENT = 20.0;

	/**
	 * In-request cache for checkout spend limits keyed by user ID and timeout.
	 *
	 * @var array<string, array|null>
	 */
	private static $spend_limits_cache = array();

	/**
	 * Write message to PHP error log.
	 *
	 * @param string $message Log message.
	 */
	public static function log_error( $message ) {
		error_log( '[MS Bonus Integration] ' . $message );
	}

	/**
	 * Whether the cart has any WooCommerce coupon applied.
	 *
	 * @return bool
	 */
	public static function cart_has_coupon() {
		if ( ! WC()->cart ) {
			return false;
		}

		$coupons = WC()->cart->get_applied_coupons();

		return ! empty( $coupons );
	}

	/**
	 * Extract counterparty UUID from MoySklad customerorder response.
	 *
	 * @param array<string, mixed> $result API response.
	 * @return string
	 */
	public static function get_agent_id_from_result( $result ) {
		if ( empty( $result ) || ! is_array( $result ) ) {
			return '';
		}

		if ( ! empty( $result['agent']['id'] ) ) {
			return sanitize_text_field( (string) $result['agent']['id'] );
		}

		if ( ! empty( $result['agent']['meta']['href'] ) ) {
			return self::extract_uuid_from_href( (string) $result['agent']['meta']['href'] );
		}

		return '';
	}

	/**
	 * Resolve counterparty ID for an order.
	 *
	 * @param WC_Order                  $order  WooCommerce order.
	 * @param array<string, mixed>|null $result Optional MoySklad response.
	 * @return string
	 */
	public static function get_order_counterparty_id( $order, $result = null ) {
		if ( is_array( $result ) ) {
			$from_result = self::get_agent_id_from_result( $result );
			if ( '' !== $from_result ) {
				return $from_result;
			}
		}

		$from_order = (string) $order->get_meta( MS_Bonus_Account_Page::WOOMS_AGENT_META_KEY, true );
		if ( '' !== $from_order ) {
			return sanitize_text_field( $from_order );
		}

		$user_id = $order->get_user_id();
		if ( $user_id ) {
			return MS_Bonus_Account_Page::get_user_counterparty_id( $user_id );
		}

		return '';
	}

	/**
	 * Detect first MoySklad sync: wooms_id exists on order object but not yet persisted.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	public static function is_first_moysklad_sync( $order ) {
		foreach ( $order->get_meta_data() as $meta ) {
			if ( 'wooms_id' === $meta->key && $meta->id ) {
				return false;
			}
		}

		return (bool) $order->get_meta( 'wooms_id', true );
	}

	/**
	 * Cart total eligible for bonus payment (before bonus fee).
	 *
	 * @return float
	 */
	public static function get_cart_total_before_bonus() {
		if ( ! WC()->cart ) {
			return 0.0;
		}

		$cart  = WC()->cart;
		$total = (float) $cart->get_subtotal()
			+ (float) $cart->get_shipping_total()
			+ (float) $cart->get_cart_contents_tax()
			+ (float) $cart->get_shipping_tax();

		foreach ( $cart->get_fees() as $fee ) {
			if ( self::FEE_CART_NAME === $fee->name ) {
				continue;
			}
			$total += (float) $fee->total;
		}

		return max( 0.0, $total );
	}

	/**
	 * Calculate maximum spendable bonus points for user.
	 *
	 * Unified limit: min( balance, floor( order_total * 20% / spend_rate ) ).
	 * Unavailable when a coupon is applied.
	 *
	 * @param int $user_id WordPress user ID.
	 * @param int $timeout API request timeout in seconds.
	 * @return array{balance: int, max_points: int, spend_rate: float, max_percent: float}|null
	 */
	public static function get_spend_limits_for_user( $user_id, $timeout = MS_Bonus_API::DEFAULT_TIMEOUT ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return null;
		}

		if ( self::cart_has_coupon() ) {
			return null;
		}

		$cache_key = $user_id . '|' . (int) $timeout;

		if ( array_key_exists( $cache_key, self::$spend_limits_cache ) ) {
			return self::$spend_limits_cache[ $cache_key ];
		}

		$counterparty_id = MS_Bonus_Account_Page::get_user_counterparty_id( $user_id );
		if ( '' === $counterparty_id ) {
			self::$spend_limits_cache[ $cache_key ] = null;
			return null;
		}

		$balance = MS_Bonus_Account_Page::get_cached_user_balance( $user_id, $counterparty_id, $timeout );
		if ( is_wp_error( $balance ) || $balance <= 0 ) {
			self::$spend_limits_cache[ $cache_key ] = null;
			return null;
		}

		$program = MS_Bonus_API::get_bonus_program( null, false, $timeout );
		if ( is_wp_error( $program ) ) {
			self::$spend_limits_cache[ $cache_key ] = null;
			return null;
		}

		$spend_rate  = isset( $program['spendRatePointsToRouble'] ) ? (float) $program['spendRatePointsToRouble'] : 0.0;
		$max_percent = self::SPEND_MAX_PERCENT;

		if ( $spend_rate <= 0 ) {
			self::$spend_limits_cache[ $cache_key ] = null;
			return null;
		}

		$order_total  = self::get_cart_total_before_bonus();
		$max_rubles   = $order_total * $max_percent / 100;
		$max_by_order = (int) floor( $max_rubles / $spend_rate );
		$max_points   = min( (int) $balance, max( 0, $max_by_order ) );

		if ( $max_points <= 0 ) {
			self::$spend_limits_cache[ $cache_key ] = null;
			return null;
		}

		$limits = array(
			'balance'     => (int) $balance,
			'max_points'  => $max_points,
			'spend_rate'  => $spend_rate,
			'max_percent' => $max_percent,
		);

		self::$spend_limits_cache[ $cache_key ] = $limits;

		return $limits;
	}

	/**
	 * Parse entity UUID from MoySklad meta href.
	 *
	 * @param string $href Meta href.
	 * @return string
	 */
	private static function extract_uuid_from_href( $href ) {
		$parts = explode( '/', untrailingslashit( $href ) );
		$uuid  = end( $parts );

		if ( ! is_string( $uuid ) || ! preg_match( '/^[0-9a-f-]{36}$/i', $uuid ) ) {
			return '';
		}

		return sanitize_text_field( $uuid );
	}

	/**
	 * Clear cached bonus balance for user.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function clear_balance_cache( $user_id ) {
		$user_id = absint( $user_id );

		delete_transient( 'ms_bonus_balance_' . $user_id );

		$counterparty_id = MS_Bonus_Account_Page::get_user_counterparty_id( $user_id );
		if ( '' !== $counterparty_id ) {
			MS_Bonus_API::clear_counterparty_balance_cache( $counterparty_id );
		}

		self::$spend_limits_cache = array();
	}

	/**
	 * Order amount used for 3% earning (paid total + bonus fee restored).
	 *
	 * @param WC_Order $order Order object.
	 * @return float
	 */
	public static function get_order_amount_for_earning( $order ) {
		$total = (float) $order->get_total();

		foreach ( $order->get_fees() as $fee ) {
			if ( self::FEE_CART_NAME === $fee->get_name() || __( 'Списание бонусов', 'ms-bonus-integration' ) === $fee->get_name() ) {
				$total += abs( (float) $fee->get_total() );
			}
		}

		return max( 0.0, $total );
	}

	/**
	 * Calculate earning points: floor( order_amount * 3% ).
	 *
	 * @param WC_Order $order Order object.
	 * @return int
	 */
	public static function calculate_earn_points( $order ) {
		$amount = self::get_order_amount_for_earning( $order );

		return (int) floor( $amount * self::EARN_RATE );
	}
}
