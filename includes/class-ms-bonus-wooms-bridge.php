<?php


defined( 'ABSPATH' ) || exit;


class MS_Bonus_Wooms_Bridge {


	public static function init() {
		add_filter( 'wooms_order_update', array( __CLASS__, 'save_agent_uuid_from_sync' ), 5, 2 );
	}


	public static function save_agent_uuid_from_sync( $order, $result ) {
		if ( ! is_a( $order, 'WC_Order' ) || empty( $result ) || ! is_array( $result ) ) {
			return $order;
		}

		if ( $order->get_meta( MS_Bonus_Account_Page::WOOMS_AGENT_META_KEY, true ) ) {
			return $order;
		}

		$agent_id = MS_Bonus_Helpers::get_agent_id_from_result( $result );

		if ( '' !== $agent_id ) {
			$order->update_meta_data( MS_Bonus_Account_Page::WOOMS_AGENT_META_KEY, $agent_id );
		}

		return $order;
	}
}
