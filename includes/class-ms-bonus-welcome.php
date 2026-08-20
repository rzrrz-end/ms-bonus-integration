<?php
/**
 * Welcome bonus: 1000 points 10 days after user registration.
 *
 * @package MS_Bonus_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MS_Bonus_Welcome
 */
class MS_Bonus_Welcome {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'user_register', array( __CLASS__, 'schedule_welcome_bonus' ), 20, 1 );
		add_action( MS_Bonus_Helpers::CRON_WELCOME_HOOK, array( __CLASS__, 'process_welcome_bonus' ), 10, 1 );
	}

	/**
	 * Schedule welcome bonus 10 days after registration.
	 *
	 * @param int $user_id Newly registered user ID.
	 */
	public static function schedule_welcome_bonus( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return;
		}

		if ( get_user_meta( $user_id, MS_Bonus_Helpers::META_WELCOME_CREDITED_AT, true ) ) {
			return;
		}

		$hook = MS_Bonus_Helpers::CRON_WELCOME_HOOK;
		$args = array( $user_id );

		if ( wp_next_scheduled( $hook, $args ) ) {
			return;
		}

		$timestamp = time() + ( MS_Bonus_Helpers::WELCOME_DELAY_DAYS * DAY_IN_SECONDS );

		wp_schedule_single_event( $timestamp, $hook, $args );
	}

	/**
	 * Cron: credit welcome bonus via MoySklad API.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function process_welcome_bonus( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return;
		}

		if ( get_user_meta( $user_id, MS_Bonus_Helpers::META_WELCOME_CREDITED_AT, true ) ) {
			return;
		}

		$counterparty_id = MS_Bonus_Account_Page::get_user_counterparty_id( $user_id );

		if ( '' === $counterparty_id ) {
			self::reschedule_retry( $user_id, 'Counterparty not linked yet.' );
			return;
		}

		$settings         = ms_bonus_get_settings();
		$bonus_program_id = $settings['bonus_program_id'];

		if ( empty( $bonus_program_id ) ) {
			MS_Bonus_Helpers::log_error(
				sprintf( 'Welcome bonus skipped for user %d: bonus program ID is empty.', $user_id )
			);
			return;
		}

		$transaction = MS_Bonus_API::create_bonus_transaction(
			$counterparty_id,
			$bonus_program_id,
			MS_Bonus_Helpers::WELCOME_BONUS_POINTS,
			'EARNING'
		);

		if ( is_wp_error( $transaction ) ) {
			self::reschedule_retry(
				$user_id,
				sprintf(
					'[%s] %s',
					$transaction->get_error_code(),
					$transaction->get_error_message()
				)
			);
			return;
		}

		update_user_meta( $user_id, MS_Bonus_Helpers::META_WELCOME_CREDITED_AT, gmdate( 'c' ) );
		delete_user_meta( $user_id, MS_Bonus_Helpers::META_WELCOME_RETRIES );
		MS_Bonus_Helpers::clear_balance_cache( $user_id );

		MS_Bonus_Helpers::log_error(
			sprintf(
				'Welcome bonus credited: user=%d, counterparty=%s, points=%d',
				$user_id,
				$counterparty_id,
				MS_Bonus_Helpers::WELCOME_BONUS_POINTS
			)
		);
	}

	/**
	 * Reschedule welcome bonus retry for next day (until max retries).
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $reason  Log reason.
	 */
	private static function reschedule_retry( $user_id, $reason ) {
		$retries = (int) get_user_meta( $user_id, MS_Bonus_Helpers::META_WELCOME_RETRIES, true );

		if ( $retries >= MS_Bonus_Helpers::WELCOME_MAX_RETRIES ) {
			MS_Bonus_Helpers::log_error(
				sprintf(
					'Welcome bonus abandoned for user %d after %d retries: %s',
					$user_id,
					$retries,
					$reason
				)
			);
			return;
		}

		update_user_meta( $user_id, MS_Bonus_Helpers::META_WELCOME_RETRIES, $retries + 1 );

		$hook = MS_Bonus_Helpers::CRON_WELCOME_HOOK;
		$args = array( $user_id );

		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + DAY_IN_SECONDS, $hook, $args );
		}

		MS_Bonus_Helpers::log_error(
			sprintf(
				'Welcome bonus deferred for user %d (retry %d/%d): %s',
				$user_id,
				$retries + 1,
				MS_Bonus_Helpers::WELCOME_MAX_RETRIES,
				$reason
			)
		);
	}
}
