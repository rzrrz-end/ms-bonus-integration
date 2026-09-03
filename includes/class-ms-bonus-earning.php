<?php


defined( 'ABSPATH' ) || exit;


class MS_Bonus_Earning {


	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 20, 4 );
		add_action( MS_Bonus_Helpers::CRON_EARN_HOOK, array( __CLASS__, 'retry_earning' ), 10, 1 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_admin_notice' ) );
	}


	public static function on_order_status_changed( $order_id, $old_status, $new_status, $order ) {
		unset( $old_status );

		$settings       = ms_bonus_get_settings();
		$target_status  = $settings['order_status'] ?? 'processing';

		if ( $new_status !== $target_status ) {
			return;
		}

		if ( ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		self::process_earning( $order, true );
	}


	public static function retry_earning( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return;
		}

		self::process_earning( $order, true );
	}


	public static function process_earning( $order, $allow_schedule = true ) {
		if ( $order->meta_exists( MS_Bonus_Helpers::META_EARNED ) ) {
			return;
		}
		if ( $order->get_meta( MS_Bonus_Helpers::META_EARN_ATTEMPTED, true ) ) {
			return;
		}

		if ( ! $order->get_meta( 'wooms_id', true ) ) {
			if ( $allow_schedule && self::can_schedule_retry( $order ) ) {
				self::schedule_retry( $order );
				return;
			}

			self::mark_earn_error(
				$order,
				'',
				0,
				'wooms_id is missing after retries — WooMS has not synced the order yet.'
			);
			return;
		}

		$points = MS_Bonus_Helpers::calculate_earn_points( $order );

		if ( $points <= 0 ) {
			$order->update_meta_data( MS_Bonus_Helpers::META_EARNED, 0 );
			$order->update_meta_data( MS_Bonus_Helpers::META_EARN_ATTEMPTED, 1 );
			$order->save();
			return;
		}

		$counterparty_id = MS_Bonus_Helpers::get_order_counterparty_id( $order );

		if ( '' === $counterparty_id ) {
			if ( $allow_schedule && self::can_schedule_retry( $order ) ) {
				self::schedule_retry( $order );
				return;
			}

			self::mark_earn_error(
				$order,
				'',
				$points,
				'Counterparty ID not found for earning.'
			);
			return;
		}

		$settings         = ms_bonus_get_settings();
		$bonus_program_id = $settings['bonus_program_id'];

		$transaction = MS_Bonus_API::create_bonus_transaction(
			$counterparty_id,
			$bonus_program_id,
			$points,
			'EARNING'
		);

		if ( is_wp_error( $transaction ) ) {
			self::mark_earn_error(
				$order,
				$counterparty_id,
				$points,
				sprintf(
					'[%s] %s',
					$transaction->get_error_code(),
					$transaction->get_error_message()
				)
			);
			return;
		}

		$order->update_meta_data( MS_Bonus_Helpers::META_EARNED, $points );
		$order->delete_meta_data( MS_Bonus_Helpers::META_EARN_ERROR );
		$order->update_meta_data( MS_Bonus_Helpers::META_EARN_ATTEMPTED, 1 );
		$order->save();

		$user_id = $order->get_user_id();
		if ( $user_id ) {
			MS_Bonus_Helpers::clear_balance_cache( $user_id );
		}

		$order->add_order_note(
			sprintf(
				__( 'Начислено бонусных баллов МойСклад: %d', 'ms-bonus-integration' ),
				$points
			)
		);
	}


	private static function can_schedule_retry( $order ) {
		$retries = (int) $order->get_meta( MS_Bonus_Helpers::META_EARN_RETRIES, true );
		return $retries < MS_Bonus_Helpers::EARN_MAX_RETRIES;
	}

	private static function schedule_retry( $order ) {
		$order_id = $order->get_id();
		$hook     = MS_Bonus_Helpers::CRON_EARN_HOOK;
		$args     = array( $order_id );

		$retries = (int) $order->get_meta( MS_Bonus_Helpers::META_EARN_RETRIES, true );
		$order->update_meta_data( MS_Bonus_Helpers::META_EARN_RETRIES, $retries + 1 );
		$order->save();

		if ( wp_next_scheduled( $hook, $args ) ) {
			return;
		}

		wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, $hook, $args );
	}


	private static function mark_earn_error( $order, $counterparty_id, $points, $error_detail ) {
		$message = sprintf(
			'Order %d: bonus EARNING failed (points=%d, counterparty=%s): %s',
			$order->get_id(),
			$points,
			$counterparty_id ?: 'n/a',
			$error_detail
		);

		MS_Bonus_Helpers::log_error( $message );

		$order->update_meta_data( MS_Bonus_Helpers::META_EARN_ATTEMPTED, 1 );
		$order->update_meta_data( MS_Bonus_Helpers::META_EARN_ERROR, $message );
		$order->save();

		$order->add_order_note(
			sprintf(
				__( 'Ошибка начисления бонусов МойСклад. Требуется ручная проверка. %s', 'ms-bonus-integration' ),
				$error_detail
			)
		);
	}


	public static function render_admin_notice( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$error = $order->get_meta( MS_Bonus_Helpers::META_EARN_ERROR, true );
		if ( empty( $error ) ) {
			return;
		}

		echo '<div class="notice notice-error" style="margin: 1em 0;">';
		echo '<p><strong>' . esc_html__( 'Бонусы МойСклад', 'ms-bonus-integration' ) . ':</strong> ';
		echo esc_html__( 'Начисление бонусов не выполнено — требуется ручная проверка.', 'ms-bonus-integration' );
		echo '</p>';
		echo '<p><code>' . esc_html( (string) $error ) . '</code></p>';
		echo '</div>';
	}
}
