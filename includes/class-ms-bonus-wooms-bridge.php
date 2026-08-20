<?php
/**
 * Bridge with WooMS order sync — persist counterparty ID on orders.
 *
 * @package MS_Bonus_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MS_Bonus_Wooms_Bridge
 */
class MS_Bonus_Wooms_Bridge {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'wooms_order_update', array( __CLASS__, 'save_agent_uuid_from_sync' ), 5, 2 );
	}

	/**
	 * Save counterparty UUID to order meta after WooMS sync.
	 *
	 * WooMS writes agent_uuid only when creating a new counterparty.
	 * When an existing counterparty is matched by email/phone, the ID
	 * is available in the API response but not persisted — we backfill it here.
	 *
	 * @param WC_Order               $order  WooCommerce order.
	 * @param array<string, mixed>   $result MoySklad customerorder response.
	 * @return WC_Order
	 */
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
