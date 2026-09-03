<?php


defined( 'ABSPATH' ) || exit;


class MS_Bonus_Order_Sync {


	public static function init() {
		add_filter( 'wooms_order_update', 'ms_bonus_handle_order_synced', 20, 2 );
	}

	public static function handle_order_synced( $order, $result ) {
		if ( ! is_a( $order, 'WC_Order' ) || empty( $result ) || ! is_array( $result ) ) {
			return $order;
		}

		if ( ! MS_Bonus_Helpers::is_first_moysklad_sync( $order ) ) {
			return $order;
		}

		if ( $order->get_meta( MS_Bonus_Helpers::META_PROCESSED, true ) ) {
			return $order;
		}

		$requested = (int) $order->get_meta( MS_Bonus_Helpers::META_REQUESTED, true );
		if ( $requested <= 0 ) {
			return $order;
		}

		if ( $order->get_meta( MS_Bonus_Helpers::META_SPENT, true ) ) {
			return $order;
		}

		$counterparty_id = MS_Bonus_Helpers::get_order_counterparty_id( $order, $result );
		if ( '' === $counterparty_id ) {
			self::mark_error(
				$order,
				sprintf(
					'Order %d: counterparty ID not found for bonus spending (%d points).',
					$order->get_id(),
					$requested
				)
			);
			return $order;
		}

		$settings         = ms_bonus_get_settings();
		$bonus_program_id = $settings['bonus_program_id'];

		$transaction = MS_Bonus_API::create_bonus_transaction(
			$counterparty_id,
			$bonus_program_id,
			$requested,
			'SPENDING'
		);

		if ( is_wp_error( $transaction ) ) {
			self::mark_error(
				$order,
				sprintf(
					'Order %d: bonus SPENDING failed for %d points, counterparty %s: [%s] %s',
					$order->get_id(),
					$requested,
					$counterparty_id,
					$transaction->get_error_code(),
					$transaction->get_error_message()
				)
			);
			return $order;
		}

		$order->update_meta_data( MS_Bonus_Helpers::META_SPENT, $requested );
		$order->delete_meta_data( MS_Bonus_Helpers::META_ERROR );
		$order->update_meta_data( MS_Bonus_Helpers::META_PROCESSED, 1 );

		$user_id = $order->get_user_id();
		if ( $user_id ) {
			MS_Bonus_Helpers::clear_balance_cache( $user_id );
		}

		return $order;
	}


	private static function mark_error( $order, $message ) {
		$order->update_meta_data( MS_Bonus_Helpers::META_ERROR, $message );
		$order->update_meta_data( MS_Bonus_Helpers::META_PROCESSED, 1 );
		MS_Bonus_Helpers::log_error( $message );
	}
}


function ms_bonus_handle_order_synced( $order, $result ) {
	return MS_Bonus_Order_Sync::handle_order_synced( $order, $result );
}
