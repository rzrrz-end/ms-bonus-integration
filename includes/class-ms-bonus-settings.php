<?php
/**
 * WooCommerce settings page for MS Bonus Integration.
 *
 * @package MS_Bonus_Integration
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Settings_Page', false ) ) {
	include_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
}

/**
 * Class MS_Bonus_Settings
 */
class MS_Bonus_Settings extends WC_Settings_Page {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_get_settings_pages', array( __CLASS__, 'register_settings_page' ) );
		add_filter( 'pre_option_ms_bonus_bonus_program_id', array( __CLASS__, 'filter_bonus_program_id_option' ) );
		add_filter( 'pre_option_ms_bonus_order_status', array( __CLASS__, 'filter_order_status_option' ) );
		add_action( 'woocommerce_admin_field_ms_bonus_test_connection', array( __CLASS__, 'render_test_connection_field' ) );
		add_action( 'woocommerce_admin_field_ms_bonus_program_info', array( __CLASS__, 'render_program_info_field' ) );
		add_action( 'wp_ajax_ms_bonus_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	/**
	 * Provide bonus program ID to WooCommerce settings field renderer.
	 *
	 * @param mixed $value Stored option value.
	 * @return string
	 */
	public static function filter_bonus_program_id_option( $value ) {
		if ( false !== $value ) {
			return $value;
		}

		return ms_bonus_get_settings()['bonus_program_id'];
	}

	/**
	 * Provide order status to WooCommerce settings field renderer.
	 *
	 * @param mixed $value Stored option value.
	 * @return string
	 */
	public static function filter_order_status_option( $value ) {
		if ( false !== $value ) {
			return $value;
		}

		return ms_bonus_get_settings()['order_status'];
	}

	/**
	 * Register settings page instance.
	 *
	 * @param WC_Settings_Page[] $settings Existing settings pages.
	 * @return WC_Settings_Page[]
	 */
	public static function register_settings_page( $settings ) {
		$settings[] = new self();
		return $settings;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'ms_bonus';
		$this->label = __( 'Бонусы МойСклад', 'ms-bonus-integration' );

		parent::__construct();
	}

	/**
	 * Settings sections.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_sections() {
		return array(
			'' => __( 'Основные настройки', 'ms-bonus-integration' ),
		);
	}

	/**
	 * Settings fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings() {
		$settings = ms_bonus_get_settings();

		return apply_filters(
			'woocommerce_ms_bonus_settings',
			array(
				array(
					'title' => __( 'Бонусная программа МойСклад', 'ms-bonus-integration' ),
					'type'  => 'title',
					'desc'  => __( 'Плагин использует логин и пароль из настроек WooMS (woomss_login / woomss_pass). Логика баллов: приветственные 1000 баллов через 10 дней после регистрации; 3% с каждого оплаченного заказа; списание до 20% суммы заказа; баллы не сгорают; списание несовместимо с промокодами.', 'ms-bonus-integration' ),
					'id'    => 'ms_bonus_section_title',
				),
				array(
					'title'    => __( 'ID бонусной программы', 'ms-bonus-integration' ),
					'desc'     => __( 'UUID бонусной программы в МойСклад.', 'ms-bonus-integration' ),
					'id'       => 'ms_bonus_bonus_program_id',
					'type'     => 'text',
					'default'  => $settings['bonus_program_id'],
					'css'      => 'min-width: 420px;',
					'desc_tip' => true,
				),
				array(
					'title'   => __( 'Статус заказа для начисления 3%', 'ms-bonus-integration' ),
					'desc'    => __( 'При переходе заказа в этот статус начисляется 3% от суммы заказа (floor). Без условий по приветственному бонусу.', 'ms-bonus-integration' ),
					'id'      => 'ms_bonus_order_status',
					'type'    => 'select',
					'default' => $settings['order_status'],
					'options' => array(
						'processing' => __( 'Обработка (processing)', 'ms-bonus-integration' ),
						'completed'  => __( 'Выполнен (completed)', 'ms-bonus-integration' ),
					),
				),
				array(
					'title' => __( 'Проверка соединения', 'ms-bonus-integration' ),
					'type'  => 'ms_bonus_test_connection',
					'id'    => 'ms_bonus_test_connection',
				),
				array(
					'title' => __( 'Данные программы в МойСклад', 'ms-bonus-integration' ),
					'type'  => 'ms_bonus_program_info',
					'id'    => 'ms_bonus_program_info',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'ms_bonus_section_end',
				),
			)
		);
	}

	/**
	 * Save settings into ms_bonus_settings option.
	 */
	public function save() {
		$bonus_program_id = isset( $_POST['ms_bonus_bonus_program_id'] )
			? sanitize_text_field( wp_unslash( $_POST['ms_bonus_bonus_program_id'] ) )
			: '';

		$order_status = isset( $_POST['ms_bonus_order_status'] )
			? sanitize_text_field( wp_unslash( $_POST['ms_bonus_order_status'] ) )
			: 'processing';

		if ( ! in_array( $order_status, array( 'processing', 'completed' ), true ) ) {
			$order_status = 'processing';
		}

		$previous = ms_bonus_get_settings();

		update_option(
			'ms_bonus_settings',
			array(
				'bonus_program_id' => $bonus_program_id,
				'order_status'     => $order_status,
			)
		);

		if ( $previous['bonus_program_id'] !== $bonus_program_id ) {
			delete_transient( MS_Bonus_API::CACHE_KEY );
			MS_Bonus_API::clear_runtime_program_cache( $bonus_program_id );
		}
	}

	/**
	 * Render test connection button field.
	 *
	 * @param array<string, mixed> $value Field config.
	 */
	public static function render_test_connection_field( $value ) {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp">
				<button type="button" class="button button-secondary" id="ms-bonus-test-connection">
					<?php esc_html_e( 'Проверить соединение с бонусной программой', 'ms-bonus-integration' ); ?>
				</button>
				<p class="description">
					<?php esc_html_e( 'Запрос к API МойСклад с учётными данными WooMS.', 'ms-bonus-integration' ); ?>
				</p>
				<div id="ms-bonus-test-result" class="ms-bonus-test-result" aria-live="polite"></div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render placeholder for bonus program info block.
	 *
	 * @param array<string, mixed> $value Field config.
	 */
	public static function render_program_info_field( $value ) {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp">
				<div id="ms-bonus-program-info" class="ms-bonus-program-info">
					<p class="description">
						<?php esc_html_e( 'Нажмите «Проверить соединение», чтобы загрузить параметры программы из МойСклад. Начисление 3% и лимит списания 20% задаются плагином, а не этими полями.', 'ms-bonus-integration' ); ?>
					</p>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Enqueue admin assets on plugin settings page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['tab'] ) || 'ms_bonus' !== sanitize_text_field( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}

		wp_enqueue_style(
			'ms-bonus-admin',
			MS_BONUS_PLUGIN_URL . 'assets/css/admin-settings.css',
			array(),
			MS_BONUS_VERSION
		);

		wp_enqueue_script(
			'ms-bonus-admin',
			MS_BONUS_PLUGIN_URL . 'assets/js/admin-settings.js',
			array( 'jquery' ),
			MS_BONUS_VERSION,
			true
		);

		wp_localize_script(
			'ms-bonus-admin',
			'msBonusAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ms_bonus_test_connection' ),
				'i18n'    => array(
					'testing'            => __( 'Проверка соединения...', 'ms-bonus-integration' ),
					'success'            => __( 'Соединение успешно установлено.', 'ms-bonus-integration' ),
					'earnRate'           => __( 'Курс начисления в МойСклад (справочно)', 'ms-bonus-integration' ),
					'spendRate'          => __( 'Курс списания (балл -> руб.)', 'ms-bonus-integration' ),
					'maxPaidRate'        => __( 'Лимит в МойСклад (справочно; плагин использует 20%)', 'ms-bonus-integration' ),
					'earnWhileRedeeming' => __( 'Начисление при списании (справочно)', 'ms-bonus-integration' ),
					'yes'                => __( 'да', 'ms-bonus-integration' ),
					'no'                 => __( 'нет', 'ms-bonus-integration' ),
					'notAvailable'       => __( 'не указано', 'ms-bonus-integration' ),
					'error'              => __( 'Ошибка проверки соединения.', 'ms-bonus-integration' ),
				),
			)
		);
	}

	/**
	 * AJAX: test bonus program connection.
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'ms_bonus_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Недостаточно прав.', 'ms-bonus-integration' ),
				),
				403
			);
		}

		$bonus_program_id = isset( $_POST['bonus_program_id'] )
			? sanitize_text_field( wp_unslash( $_POST['bonus_program_id'] ) )
			: ms_bonus_get_settings()['bonus_program_id'];

		if ( empty( $bonus_program_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Укажите ID бонусной программы.', 'ms-bonus-integration' ),
				)
			);
		}

		$result = MS_Bonus_API::get_bonus_program( $bonus_program_id, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				)
			);
		}

		$payload = array(
			'message' => __( 'Соединение успешно установлено.', 'ms-bonus-integration' ),
			'program' => $result,
		);

		wp_send_json_success( $payload );
	}
}