<?php
/**
 * MoySklad Bonus API client.
 *
 * @package MS_Bonus_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MS_Bonus_API
 */
class MS_Bonus_API {

	const API_BASE                = 'https://api.moysklad.ru/api/remap/1.2/';
	const CACHE_KEY               = 'ms_bonus_program_cache';
	const BALANCE_CACHE_PREFIX    = 'ms_bonus_cp_balance_';
	const CACHE_TTL               = HOUR_IN_SECONDS;
	const BALANCE_CACHE_TTL       = 300;
	const CREDENTIAL_OPTION_LOGIN = 'woomss_login';
	const CREDENTIAL_OPTION_PASS  = 'woomss_pass';
	const DEFAULT_TIMEOUT         = 45;
	const DEFAULT_RETRY_SECONDS   = 3;

	/**
	 * In-request cache for bonus program data keyed by program UUID.
	 *
	 * @var array<string, array|WP_Error>
	 */
	private static $runtime_program_cache = array();

	/**
	 * In-request cache for counterparty balances keyed by counterparty UUID.
	 *
	 * @var array<string, int|WP_Error>
	 */
	private static $runtime_balance_cache = array();

	/**
	 * Get MoySklad credentials from WooMS settings.
	 *
	 * @return array{login: string, password: string}|WP_Error
	 */
	public static function get_credentials() {
		$login    = (string) get_option( self::CREDENTIAL_OPTION_LOGIN, '' );
		$password = (string) get_option( self::CREDENTIAL_OPTION_PASS, '' );

		if ( '' === $login || '' === $password ) {
			return new WP_Error(
				'ms_bonus_missing_credentials',
				__( 'Учётные данные МойСклад не настроены в WooMS (woomss_login / woomss_pass).', 'ms-bonus-integration' )
			);
		}

		return array(
			'login'    => $login,
			'password' => $password,
		);
	}

	/**
	 * Get bonus program settings with transient and in-request cache.
	 *
	 * @param string|null $bonus_program_id Optional program UUID. Uses plugin settings by default.
	 * @param bool        $force_refresh    Skip persistent cache when true.
	 * @param int         $timeout          Request timeout in seconds.
	 * @return array{earnRateRoublesToPoint: int|float|null, spendRatePointsToRouble: int|float|null, maxPaidRatePercents: int|float|null, earnWhileRedeeming: bool|null}|WP_Error
	 */
	public static function get_bonus_program( $bonus_program_id = null, $force_refresh = false, $timeout = self::DEFAULT_TIMEOUT ) {
		$settings         = ms_bonus_get_settings();
		$bonus_program_id = $bonus_program_id ?: $settings['bonus_program_id'];

		if ( empty( $bonus_program_id ) ) {
			return new WP_Error(
				'ms_bonus_missing_program_id',
				__( 'ID бонусной программы не указан.', 'ms-bonus-integration' )
			);
		}

		$runtime_key = $bonus_program_id . '|' . (int) $force_refresh;

		if ( ! $force_refresh && array_key_exists( $runtime_key, self::$runtime_program_cache ) ) {
			return self::$runtime_program_cache[ $runtime_key ];
		}

		if ( ! $force_refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && ( $cached['bonus_program_id'] ?? '' ) === $bonus_program_id ) {
				unset( $cached['bonus_program_id'] );
				self::$runtime_program_cache[ $runtime_key ] = $cached;
				return $cached;
			}
		}

		$response = self::request( 'entity/bonusprogram/' . rawurlencode( $bonus_program_id ), array(), 'GET', $timeout );

		if ( is_wp_error( $response ) ) {
			self::$runtime_program_cache[ $runtime_key ] = $response;
			return $response;
		}

		if ( empty( $response['id'] ) ) {
			$message = self::extract_error_message( $response );

			$error = new WP_Error(
				'ms_bonus_program_not_found',
				$message ?: __( 'Бонусная программа не найдена.', 'ms-bonus-integration' ),
				$response
			);
			self::$runtime_program_cache[ $runtime_key ] = $error;
			return $error;
		}

		$data = array(
			'earnRateRoublesToPoint'  => isset( $response['earnRateRoublesToPoint'] ) ? $response['earnRateRoublesToPoint'] : null,
			'spendRatePointsToRouble' => isset( $response['spendRatePointsToRouble'] ) ? $response['spendRatePointsToRouble'] : null,
			'maxPaidRatePercents'     => isset( $response['maxPaidRatePercents'] ) ? $response['maxPaidRatePercents'] : null,
			'earnWhileRedeeming'      => isset( $response['earnWhileRedeeming'] ) ? (bool) $response['earnWhileRedeeming'] : null,
		);

		$cache_payload                     = $data;
		$cache_payload['bonus_program_id'] = $bonus_program_id;
		set_transient( self::CACHE_KEY, $cache_payload, self::CACHE_TTL );

		self::$runtime_program_cache[ $runtime_key ] = $data;

		return $data;
	}

	/**
	 * Get counterparty bonus balance with transient and in-request cache.
	 *
	 * @param string $counterparty_id Counterparty UUID.
	 * @param int    $timeout         Request timeout in seconds.
	 * @return int|WP_Error
	 */
	public static function get_counterparty_balance( $counterparty_id, $timeout = self::DEFAULT_TIMEOUT ) {
		if ( empty( $counterparty_id ) ) {
			return new WP_Error(
				'ms_bonus_missing_counterparty',
				__( 'ID контрагента не указан.', 'ms-bonus-integration' )
			);
		}

		$counterparty_id = sanitize_text_field( $counterparty_id );
		$runtime_key     = $counterparty_id . '|' . (int) $timeout;

		if ( array_key_exists( $runtime_key, self::$runtime_balance_cache ) ) {
			return self::$runtime_balance_cache[ $runtime_key ];
		}

		$transient_key = self::get_balance_transient_key( $counterparty_id );
		$cached        = get_transient( $transient_key );

		if ( false !== $cached && is_array( $cached ) && isset( $cached['balance'] ) ) {
			$balance = (int) $cached['balance'];
			self::$runtime_balance_cache[ $runtime_key ] = $balance;
			return $balance;
		}

		$response = self::request( 'entity/counterparty/' . rawurlencode( $counterparty_id ), array(), 'GET', $timeout );

		if ( is_wp_error( $response ) ) {
			self::$runtime_balance_cache[ $runtime_key ] = $response;
			return $response;
		}

		if ( empty( $response['id'] ) ) {
			$message = self::extract_error_message( $response );

			$error = new WP_Error(
				'ms_bonus_counterparty_not_found',
				$message ?: __( 'Контрагент не найден.', 'ms-bonus-integration' ),
				$response
			);
			self::$runtime_balance_cache[ $runtime_key ] = $error;
			return $error;
		}

		$balance = isset( $response['bonusPoints'] ) ? (int) $response['bonusPoints'] : 0;

		set_transient(
			$transient_key,
			array(
				'counterparty_id' => $counterparty_id,
				'balance'         => $balance,
			),
			self::BALANCE_CACHE_TTL
		);

		self::$runtime_balance_cache[ $runtime_key ] = $balance;

		return $balance;
	}

	/**
	 * Clear persistent and in-request balance cache for counterparty.
	 *
	 * @param string $counterparty_id Counterparty UUID.
	 */
	public static function clear_counterparty_balance_cache( $counterparty_id ) {
		if ( empty( $counterparty_id ) ) {
			return;
		}

		delete_transient( self::get_balance_transient_key( $counterparty_id ) );
		self::clear_runtime_balance_cache( $counterparty_id );
	}

	/**
	 * Clear in-request bonus program cache.
	 *
	 * @param string|null $bonus_program_id Optional program UUID.
	 */
	public static function clear_runtime_program_cache( $bonus_program_id = null ) {
		if ( null === $bonus_program_id ) {
			self::$runtime_program_cache = array();
			return;
		}

		foreach ( array_keys( self::$runtime_program_cache ) as $key ) {
			if ( 0 === strpos( $key, $bonus_program_id . '|' ) ) {
				unset( self::$runtime_program_cache[ $key ] );
			}
		}
	}

	/**
	 * Clear in-request counterparty balance cache.
	 *
	 * @param string|null $counterparty_id Optional counterparty UUID.
	 */
	public static function clear_runtime_balance_cache( $counterparty_id = null ) {
		if ( null === $counterparty_id ) {
			self::$runtime_balance_cache = array();
			return;
		}

		foreach ( array_keys( self::$runtime_balance_cache ) as $key ) {
			if ( 0 === strpos( $key, $counterparty_id . '|' ) ) {
				unset( self::$runtime_balance_cache[ $key ] );
			}
		}
	}

	/**
	 * Create bonus transaction (EARNING or SPENDING).
	 *
	 * @param string $counterparty_id  Counterparty UUID.
	 * @param string $bonus_program_id Bonus program UUID.
	 * @param int    $amount           Bonus points amount.
	 * @param string $type             EARNING or SPENDING.
	 * @return array|WP_Error
	 */
	public static function create_bonus_transaction( $counterparty_id, $bonus_program_id, $amount, $type ) {
		$counterparty_id  = sanitize_text_field( $counterparty_id );
		$bonus_program_id = sanitize_text_field( $bonus_program_id );
		$amount           = absint( $amount );
		$type             = strtoupper( sanitize_text_field( $type ) );

		if ( empty( $counterparty_id ) || empty( $bonus_program_id ) ) {
			return new WP_Error(
				'ms_bonus_invalid_transaction_args',
				__( 'Для бонусной операции нужны ID контрагента и бонусной программы.', 'ms-bonus-integration' )
			);
		}

		if ( $amount <= 0 ) {
			return new WP_Error(
				'ms_bonus_invalid_amount',
				__( 'Количество бонусных баллов должно быть больше нуля.', 'ms-bonus-integration' )
			);
		}

		if ( ! in_array( $type, array( 'EARNING', 'SPENDING' ), true ) ) {
			return new WP_Error(
				'ms_bonus_invalid_transaction_type',
				__( 'Тип операции должен быть EARNING или SPENDING.', 'ms-bonus-integration' )
			);
		}

		$payload = array(
			'agent'           => array(
				'meta' => self::entity_meta( 'counterparty', $counterparty_id ),
			),
			'bonusProgram'    => array(
				'meta' => self::entity_meta( 'bonusprogram', $bonus_program_id ),
			),
			'bonusValue'      => $amount,
			'transactionType' => $type,
		);

		$response = self::request( 'entity/bonustransaction', $payload, 'POST' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['id'] ) ) {
			$message = self::extract_error_message( $response );

			return new WP_Error(
				'ms_bonus_transaction_failed',
				$message ?: __( 'Не удалось создать бонусную операцию.', 'ms-bonus-integration' ),
				$response
			);
		}

		return $response;
	}

	/**
	 * Perform HTTP request to MoySklad API.
	 *
	 * @param string               $path    API path or full URL.
	 * @param array<string, mixed> $data    Request body.
	 * @param string               $method  HTTP method.
	 * @param int                  $timeout Request timeout in seconds.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function request( $path, $data = array(), $method = 'GET', $timeout = self::DEFAULT_TIMEOUT ) {
		$credentials = self::get_credentials();

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		if ( false !== strpos( $path, self::API_BASE ) ) {
			$url = $path;
		} else {
			$url = self::API_BASE . ltrim( $path, '/' );
		}

		$method = strtoupper( $method );
		$body   = null;

		if ( 'GET' === $method ) {
			$data = null;
		} else {
			$body = wp_json_encode( $data );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => max( 1, (int) $timeout ),
			'redirection' => 5,
			'headers'     => array(
				'Content-Type'    => 'application/json;charset=utf-8',
				'Accept-Encoding' => 'gzip',
				'Authorization'   => 'Basic ' . base64_encode( $credentials['login'] . ':' . $credentials['password'] ),
			),
			'body'        => $body,
		);

		return self::perform_http_request( $url, $args, $path, $method, false );
	}

	/**
	 * Execute HTTP request with optional single retry on HTTP 429.
	 *
	 * @param string $url      Request URL.
	 * @param array  $args     wp_remote_request args.
	 * @param string $path     Original API path for logging.
	 * @param string $method   HTTP method.
	 * @param bool   $is_retry Whether this is the retry attempt.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function perform_http_request( $url, $args, $path, $method, $is_retry ) {
		self::log_api_request( $path, $method, $is_retry );

		$http_response = wp_remote_request( $url, $args );

		if ( is_wp_error( $http_response ) ) {
			return new WP_Error(
				'ms_bonus_http_error',
				$http_response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $http_response );

		if ( 429 === $code ) {
			if ( ! $is_retry ) {
				$wait_seconds = self::get_retry_interval_seconds( $http_response );
				usleep( (int) ( $wait_seconds * 1000000 ) );
				return self::perform_http_request( $url, $args, $path, $method, true );
			}

			return new WP_Error(
				'ms_bonus_rate_limited',
				__( 'Превышен лимит запросов к API МойСклад (429).', 'ms-bonus-integration' ),
				array(
					'status' => 429,
				)
			);
		}

		$raw = wp_remote_retrieve_body( $http_response );

		if ( '' === $raw ) {
			return new WP_Error(
				'ms_bonus_empty_response',
				__( 'МойСклад вернул пустой ответ.', 'ms-bonus-integration' ),
				array( 'status' => $code )
			);
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'ms_bonus_invalid_json',
				__( 'Не удалось разобрать ответ МойСклад.', 'ms-bonus-integration' ),
				array( 'status' => $code, 'body' => $raw )
			);
		}

		if ( $code >= 400 || ! empty( $decoded['errors'] ) ) {
			$message = self::extract_error_message( $decoded );

			return new WP_Error(
				'ms_bonus_api_error',
				$message ?: __( 'Ошибка API МойСклад.', 'ms-bonus-integration' ),
				array(
					'status'   => $code,
					'response' => $decoded,
				)
			);
		}

		return $decoded;
	}

	/**
	 * Parse retry interval from MoySklad rate-limit response headers.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @return float Seconds to wait before retry.
	 */
	private static function get_retry_interval_seconds( $response ) {
		$header = wp_remote_retrieve_header( $response, 'x-lognex-retry-timeinterval' );

		if ( empty( $header ) ) {
			return (float) self::DEFAULT_RETRY_SECONDS;
		}

		$value = (float) $header;

		// Header is usually milliseconds; values above 1000 are treated as ms.
		if ( $value > 1000 ) {
			return max( 1.0, $value / 1000 );
		}

		return max( 1.0, $value );
	}

	/**
	 * Log real (non-cache) HTTP request to error_log.
	 *
	 * @param string $path     API path.
	 * @param string $method   HTTP method.
	 * @param bool   $is_retry Whether this is a retry attempt.
	 */
	private static function log_api_request( $path, $method, $is_retry ) {
		$suffix = $is_retry ? ' [retry]' : '';

		error_log(
			sprintf(
				'[MS Bonus Integration] API request %s %s%s @ %s',
				$method,
				$path,
				$suffix,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Build transient key for counterparty balance cache.
	 *
	 * @param string $counterparty_id Counterparty UUID.
	 * @return string
	 */
	private static function get_balance_transient_key( $counterparty_id ) {
		return self::BALANCE_CACHE_PREFIX . md5( $counterparty_id );
	}

	/**
	 * Build entity meta reference for MoySklad API.
	 *
	 * @param string $type Entity type slug.
	 * @param string $id   Entity UUID.
	 * @return array<string, string>
	 */
	private static function entity_meta( $type, $id ) {
		return array(
			'href'      => self::API_BASE . 'entity/' . $type . '/' . $id,
			'type'      => $type,
			'mediaType' => 'application/json',
		);
	}

	/**
	 * Extract first error message from MoySklad response.
	 *
	 * @param array<string, mixed> $response API response.
	 * @return string
	 */
	private static function extract_error_message( $response ) {
		if ( empty( $response['errors'] ) || ! is_array( $response['errors'] ) ) {
			return '';
		}

		$error = reset( $response['errors'] );

		if ( is_array( $error ) && ! empty( $error['error'] ) ) {
			return (string) $error['error'];
		}

		return '';
	}
}
