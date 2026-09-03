<?php

defined( 'ABSPATH' ) || exit;


class MS_Bonus_Checkout {


	public static function init() {
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'render_checkout_fields' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'update_session_from_post' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_bonus_fee' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_bonus_meta' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_bonus_input' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'clear_session' ), 10, 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'woocommerce_cart_totals_fee_html', array( __CLASS__, 'filter_fee_label' ), 10, 2 );
	}


	public static function enqueue_assets() {
		if ( ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_enqueue_style(
			'ms-bonus-checkout',
			MS_BONUS_PLUGIN_URL . 'assets/css/checkout.css',
			array(),
			MS_BONUS_VERSION
		);

		wp_enqueue_script(
			'ms-bonus-checkout',
			MS_BONUS_PLUGIN_URL . 'assets/js/checkout.js',
			array( 'jquery' ),
			MS_BONUS_VERSION,
			true
		);
	}


	private static function reset_session() {
		if ( ! WC()->session ) {
			return;
		}

		WC()->session->set( 'ms_bonus_apply', false );
		WC()->session->set( 'ms_bonus_points', 0 );
	}


	public static function render_checkout_fields() {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! WC()->session ) {
			return;
		}

		if ( MS_Bonus_Helpers::cart_has_coupon() ) {
			self::reset_session();
			return;
		}

		$limits = MS_Bonus_Helpers::get_spend_limits_for_user( $user_id, MS_Bonus_Helpers::CHECKOUT_API_TIMEOUT );

		if ( null === $limits ) {
			return;
		}

		$session_apply  = (bool) WC()->session->get( 'ms_bonus_apply', false );
		$session_points = (int) WC()->session->get( 'ms_bonus_points', 0 );
		$session_points = min( $session_points, $limits['max_points'] );

		?>
		<div class="ms-bonus-checkout" id="ms-bonus-checkout">
			<h3><?php esc_html_e( 'Списать бонусы', 'ms-bonus-integration' ); ?></h3>
			<p class="form-row ms-bonus-checkout__balance">
				<?php
				printf(
					esc_html__( 'Доступно: %1$d баллов. Максимум к списанию в этом заказе: %2$d баллов (не более %3$s%% суммы).', 'ms-bonus-integration' ),
					(int) $limits['balance'],
					(int) $limits['max_points'],
					wc_format_decimal( $limits['max_percent'], 0 )
				);
				?>
			</p>
			<p class="form-row form-row-wide ms-bonus-checkout__toggle">
				<label for="ms_bonus_apply">
					<input
						type="checkbox"
						name="ms_bonus_apply"
						id="ms_bonus_apply"
						value="1"
						<?php checked( $session_apply ); ?>
					/>
					<?php esc_html_e( 'Использовать бонусы для оплаты части заказа', 'ms-bonus-integration' ); ?>
				</label>
			</p>
			<p class="form-row form-row-wide ms-bonus-checkout__amount">
				<label for="ms_bonus_points">
					<?php esc_html_e( 'Количество баллов', 'ms-bonus-integration' ); ?>
				</label>
				<input
					type="number"
					class="input-text"
					name="ms_bonus_points"
					id="ms_bonus_points"
					min="0"
					max="<?php echo esc_attr( (string) $limits['max_points'] ); ?>"
					step="1"
					value="<?php echo esc_attr( (string) ( $session_apply ? $session_points : 0 ) ); ?>"
					<?php disabled( ! $session_apply ); ?>
				/>
			</p>
			<p class="form-row ms-bonus-checkout__note description">
				<?php esc_html_e( 'Списание бонусов нельзя совмещать с промокодом.', 'ms-bonus-integration' ); ?>
			</p>
		</div>
		<?php
	}


	public static function update_session_from_post( $post_data ) {
		if ( ! WC()->session ) {
			return;
		}

		if ( MS_Bonus_Helpers::cart_has_coupon() ) {
			self::reset_session();
			return;
		}

		parse_str( $post_data, $data );

		$apply  = ! empty( $data['ms_bonus_apply'] );
		$points = isset( $data['ms_bonus_points'] ) ? absint( $data['ms_bonus_points'] ) : 0;

		if ( ! $apply ) {
			$points = 0;
		}

		$user_id = get_current_user_id();
		$limits  = $user_id ? MS_Bonus_Helpers::get_spend_limits_for_user( $user_id, MS_Bonus_Helpers::CHECKOUT_API_TIMEOUT ) : null;

		if ( null === $limits ) {
			self::reset_session();
			return;
		}

		$points = min( $points, $limits['max_points'] );

		WC()->session->set( 'ms_bonus_apply', $apply && $points > 0 );
		WC()->session->set( 'ms_bonus_points', $apply ? $points : 0 );
	}

	public static function apply_bonus_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! WC()->session ) {
			return;
		}

		if ( MS_Bonus_Helpers::cart_has_coupon() ) {
			self::reset_session();
			return;
		}

		$apply  = (bool) WC()->session->get( 'ms_bonus_apply', false );
		$points = (int) WC()->session->get( 'ms_bonus_points', 0 );

		if ( ! $apply || $points <= 0 ) {
			return;
		}

		$user_id = get_current_user_id();
		$limits  = $user_id ? MS_Bonus_Helpers::get_spend_limits_for_user( $user_id, MS_Bonus_Helpers::CHECKOUT_API_TIMEOUT ) : null;

		if ( null === $limits ) {
			self::reset_session();
			return;
		}

		$points   = min( $points, $limits['max_points'] );
		$discount = round( $points * $limits['spend_rate'], wc_get_price_decimals() );

		if ( $discount <= 0 ) {
			return;
		}

		$cart->add_fee(
			MS_Bonus_Helpers::FEE_CART_NAME,
			-$discount,
			false
		);
	}


	public static function save_bonus_meta( $order, $data ) {
		unset( $data );

		if ( ! WC()->session ) {
			return;
		}

		if ( MS_Bonus_Helpers::cart_has_coupon() ) {
			self::reset_session();
			$order->update_meta_data( MS_Bonus_Helpers::META_REQUESTED, 0 );
			return;
		}

		$apply  = (bool) WC()->session->get( 'ms_bonus_apply', false );
		$points = (int) WC()->session->get( 'ms_bonus_points', 0 );

		if ( ! $apply || $points <= 0 ) {
			$order->update_meta_data( MS_Bonus_Helpers::META_REQUESTED, 0 );
			return;
		}

		$user_id = $order->get_user_id();
		$limits  = $user_id ? MS_Bonus_Helpers::get_spend_limits_for_user( $user_id, MS_Bonus_Helpers::CHECKOUT_API_TIMEOUT ) : null;

		if ( null === $limits ) {
			$order->update_meta_data( MS_Bonus_Helpers::META_REQUESTED, 0 );
			return;
		}

		$points = min( $points, $limits['max_points'] );
		$order->update_meta_data( MS_Bonus_Helpers::META_REQUESTED, $points );
	}


	public static function validate_bonus_input( $data, $errors ) {
		unset( $data );

		if ( ! WC()->session ) {
			return;
		}

		$apply  = ! empty( $_POST['ms_bonus_apply'] ); 
		$points = isset( $_POST['ms_bonus_points'] ) ? absint( wp_unslash( $_POST['ms_bonus_points'] ) ) : 0; 

		if ( MS_Bonus_Helpers::cart_has_coupon() ) {
			self::reset_session();

			if ( $apply || $points > 0 ) {
				$errors->add(
					'ms_bonus_coupon_conflict',
					__( 'Списание бонусов нельзя совмещать с промокодом. Снимите промокод или отключите бонусы.', 'ms-bonus-integration' )
				);
			}
			return;
		}

		if ( ! $apply || $points <= 0 ) {
			self::reset_session();
			return;
		}

		$user_id = get_current_user_id();
		$limits  = $user_id ? MS_Bonus_Helpers::get_spend_limits_for_user( $user_id, MS_Bonus_Helpers::CHECKOUT_API_TIMEOUT ) : null;

		if ( null === $limits ) {
			$errors->add(
				'ms_bonus_unavailable',
				__( 'Списание бонусов недоступно для вашего аккаунта.', 'ms-bonus-integration' )
			);
			return;
		}

		if ( $points > $limits['max_points'] ) {
			$errors->add(
				'ms_bonus_too_many',
				sprintf(
					__( 'Можно списать не более %d баллов (лимит 20%% суммы заказа).', 'ms-bonus-integration' ),
					(int) $limits['max_points']
				)
			);
		}
	}


	public static function filter_fee_label( $html, $fee ) {
		if ( isset( $fee->name ) && MS_Bonus_Helpers::FEE_CART_NAME === $fee->name ) {
			return esc_html__( 'Списание бонусов', 'ms-bonus-integration' );
		}

		return $html;
	}
	public static function clear_session( $order_id ) {
		unset( $order_id );
		self::reset_session();
	}
}
