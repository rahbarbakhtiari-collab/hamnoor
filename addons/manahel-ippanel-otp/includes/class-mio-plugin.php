<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MIO_Plugin {
	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function run() {
		$provider = new MIO_IPPanel();
		$otp      = new MIO_OTP( $provider );
		$auth     = new MIO_Auth();
		$frontend = new MIO_Frontend();

		add_action( 'rest_api_init', array( $otp, 'register_routes' ) );
		$auth->hooks();
		$frontend->hooks();
		MIO_Users::hooks();

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_mio_test_sms', array( $this, 'test_sms' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );
	}

	public static function activate() {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( plugin_basename( MIO_FILE ) );
			wp_die( 'این افزونه به PHP 7.4 یا جدیدتر نیاز دارد.' );
		}

		if ( false === get_option( MIO_Settings::OPTION, false ) ) {
			add_option( MIO_Settings::OPTION, MIO_Settings::defaults(), '', false );
		}
		self::ensure_login_page();
		update_option( 'mio_version', MIO_VERSION, false );
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public function maybe_upgrade() {
		if ( MIO_VERSION === (string) get_option( 'mio_version', '' ) ) {
			return;
		}
		$settings = MIO_Settings::get();
		unset( $settings['block_password_students'] );
		update_option( MIO_Settings::OPTION, $settings, false );
		self::ensure_login_page();
		update_option( 'mio_version', MIO_VERSION, false );
	}

	private static function ensure_login_page() {
		$settings = MIO_Settings::get();
		if ( ! empty( $settings['login_page_id'] ) && 'trash' !== get_post_status( (int) $settings['login_page_id'] ) ) {
			return;
		}

		$page = get_page_by_path( 'login' );
		if ( $page instanceof WP_Post ) {
			$page_id = (int) $page->ID;
			if ( ! has_shortcode( (string) $page->post_content, 'manahel_otp_login' ) ) {
				$content = trim( (string) $page->post_content );
				wp_update_post(
					array(
						'ID'           => $page_id,
						'post_content' => $content ? $content . "\n\n[manahel_otp_login]" : '[manahel_otp_login]',
					)
				);
			}
		} else {
			$page_id = wp_insert_post(
				array(
					'post_title'   => 'ورود و ثبت‌نام',
					'post_name'    => 'login',
					'post_content' => '[manahel_otp_login]',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
		}
		if ( ! is_wp_error( $page_id ) ) {
			$settings['login_page_id'] = (int) $page_id;
			update_option( MIO_Settings::OPTION, $settings, false );
		}
	}

	public function register_settings() {
		register_setting(
			'mio_settings_group',
			MIO_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'MIO_Settings', 'sanitize' ),
				'default'           => MIO_Settings::defaults(),
			)
		);
	}

	public function admin_menu() {
		add_options_page(
			'ورود پیامکی مناهل',
			'ورود پیامکی مناهل',
			'manage_options',
			'manahel-otp',
			array( $this, 'settings_page' )
		);
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s              = MIO_Settings::get();
		$api_constant   = defined( 'MANAHEL_IPPANEL_API_KEY' ) && MANAHEL_IPPANEL_API_KEY;
		$current_user   = wp_get_current_user();
		$manager_mobile      = (string) get_user_meta( $current_user->ID, MIO_Users::META_LOCAL, true );
		$manager_mobile_e164 = (string) get_user_meta( $current_user->ID, MIO_Users::META_E164, true );
		if ( $manager_mobile && ! $manager_mobile_e164 ) {
			$normalized = MIO_Utils::normalize_mobile_e164( $manager_mobile );
			$manager_mobile_e164 = is_wp_error( $normalized ) ? '' : $normalized;
		}
		?>
		<div class="wrap">
			<h1>ورود پیامکی مناهل با IPPanel</h1>
			<p>این افزونه ورود کاربران وردپرس، مدیران و LearnDash را از مسیر یکپارچه OTP انجام می‌دهد.</p>

			<?php if ( ! $manager_mobile_e164 ) : ?>
				<div class="notice notice-warning inline"><p><strong>شماره موبایل حساب مدیریتی فعلی ثبت نشده است.</strong> پیش از بستن ورود رمزی، از <a href="<?php echo esc_url( get_edit_user_link( $current_user->ID ) ); ?>">ویرایش شناسنامه</a> شماره موبایل ورود را ثبت و ورود OTP مدیر را در مرورگر ناشناس آزمایش کنید.</p></div>
			<?php else : ?>
				<div class="notice notice-success inline"><p>شماره ورود حساب مدیریتی فعلی ثبت شده است: <strong dir="ltr"><?php echo esc_html( MIO_Utils::mask_mobile( $manager_mobile_e164 ) ); ?></strong></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'mio_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">فعال‌سازی</th><td><label><input type="checkbox" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?>> ورود پیامکی فعال باشد</label></td></tr>
					<tr>
						<th scope="row"><label for="mio-api-key">API Key</label></th>
						<td>
							<?php if ( $api_constant ) : ?>
								<p><strong>کلید از ثابت MANAHEL_IPPANEL_API_KEY در wp-config.php خوانده می‌شود.</strong></p>
							<?php else : ?>
								<input id="mio-api-key" class="regular-text" type="password" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[api_key]" value="" autocomplete="new-password" placeholder="برای حفظ مقدار فعلی خالی بگذارید">
								<p class="description">روش امن‌تر: تعریف ثابت MANAHEL_IPPANEL_API_KEY در wp-config.php.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr><th scope="row"><label for="mio-sender">شماره فرستنده</label></th><td><input id="mio-sender" class="regular-text" type="text" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[sender]" value="<?php echo esc_attr( $s['sender'] ); ?>" dir="ltr" placeholder="+983000505"><p class="description">شماره اختصاص‌یافته در IPPanel با فرمت E.164.</p></td></tr>
					<tr><th scope="row"><label for="mio-pattern">کد پترن</label></th><td><input id="mio-pattern" class="regular-text" type="text" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[pattern_code]" value="<?php echo esc_attr( $s['pattern_code'] ); ?>" dir="ltr"></td></tr>
					<tr><th scope="row"><label for="mio-variable">نام متغیر پترن</label></th><td><input id="mio-variable" class="regular-text" type="text" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[pattern_variable]" value="<?php echo esc_attr( $s['pattern_variable'] ); ?>" dir="ltr"><p class="description">پیش‌فرض: code</p></td></tr>
					<tr><th scope="row"><label for="mio-page">برگه ورود</label></th><td><?php wp_dropdown_pages( array( 'name' => MIO_Settings::OPTION . '[login_page_id]', 'id' => 'mio-page', 'selected' => (int) $s['login_page_id'], 'show_option_none' => '— انتخاب کنید —' ) ); ?><p class="description">محتوای برگه باید شامل شورت‌کد [manahel_otp_login] باشد.</p></td></tr>
					<tr><th scope="row">یکپارچه‌سازی ورود</th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[force_login_links]" value="1" <?php checked( $s['force_login_links'], 1 ); ?>> جایگزینی لینک‌های ورود وردپرس و LearnDash</label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[redirect_wp_login]" value="1" <?php checked( $s['redirect_wp_login'], 1 ); ?>> هدایت wp-login.php به برگه OTP</label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[block_password_login]" value="1" <?php checked( $s['block_password_login'], 1 ); ?>> غیرفعال‌کردن ورود با رمز برای همه کاربران، از جمله مدیر</label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[remember_login]" value="1" <?php checked( $s['remember_login'], 1 ); ?>> نگه‌داشتن ورود کاربر</label>
					</td></tr>
					<tr><th scope="row">کد ملی</th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[enable_national_id]" value="1" <?php checked( $s['enable_national_id'], 1 ); ?>> نمایش فیلد کد ملی در اطلاعات ثبت‌نام</label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[require_national_id_new_users]" value="1" <?php checked( $s['require_national_id_new_users'], 1 ); ?>> کد ملی برای کاربران جدید الزامی باشد</label>
						<p class="description">کد ملی با الگوریتم رقم کنترل اعتبارسنجی می‌شود، باید یکتا باشد و در فرانت‌اند عمومی نمایش داده نمی‌شود.</p>
					</td></tr>
					<tr><th scope="row">تنظیمات کد OTP</th><td>
						<label>تعداد رقم <input class="small-text" type="number" min="4" max="8" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[otp_digits]" value="<?php echo esc_attr( $s['otp_digits'] ); ?>"></label>&nbsp;&nbsp;
						<label>اعتبار (ثانیه) <input class="small-text" type="number" min="60" max="600" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[otp_expiry]" value="<?php echo esc_attr( $s['otp_expiry'] ); ?>"></label>&nbsp;&nbsp;
						<label>ارسال مجدد (ثانیه) <input class="small-text" type="number" min="30" max="300" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[resend_delay]" value="<?php echo esc_attr( $s['resend_delay'] ); ?>"></label><br><br>
						<label>حد ساعتی هر شماره <input class="small-text" type="number" min="1" max="20" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[phone_hourly_limit]" value="<?php echo esc_attr( $s['phone_hourly_limit'] ); ?>"></label>&nbsp;&nbsp;
						<label>حد ساعتی هر IP <input class="small-text" type="number" min="1" max="100" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[ip_hourly_limit]" value="<?php echo esc_attr( $s['ip_hourly_limit'] ); ?>"></label>&nbsp;&nbsp;
						<label>حد تلاش کد <input class="small-text" type="number" min="3" max="10" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[verify_max_attempts]" value="<?php echo esc_attr( $s['verify_max_attempts'] ); ?>"></label>
					</td></tr>
					<tr><th scope="row"><label for="mio-fallback">آدرس بازگشت پیش‌فرض</label></th><td><input id="mio-fallback" class="regular-text" type="url" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[fallback_redirect]" value="<?php echo esc_attr( $s['fallback_redirect'] ); ?>" dir="ltr" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"></td></tr>
					<tr><th scope="row">گزارش خطا</th><td><label><input type="checkbox" name="<?php echo esc_attr( MIO_Settings::OPTION ); ?>[debug_log]" value="1" <?php checked( $s['debug_log'], 1 ); ?>> ثبت خطاهای پاک‌سازی‌شده در debug.log (فقط هنگام WP_DEBUG_LOG)</label></td></tr>
				</table>
				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>

			<hr>
			<h2>ارسال آزمایشی</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mio_test_sms">
				<?php wp_nonce_field( 'mio_test_sms' ); ?>
				<input class="regular-text" type="tel" name="mobile" placeholder="09123456789" required>
				<?php submit_button( 'ارسال کد آزمایشی', 'secondary', 'submit', false ); ?>
			</form>

			<h2>ورود اضطراری امن مدیر</h2>
			<p>مسیر ساده <code>?manahel_emergency=1</code> دیگر فعال نیست. ورود رمزی اضطراری به‌صورت پیش‌فرض بسته است و فقط با تعریف یک کلید محرمانه حداقل ۲۰ کاراکتری در <code>wp-config.php</code> فعال می‌شود:</p>
			<pre dir="ltr" style="max-width:760px;padding:12px;background:#fff;border:1px solid #ccd0d4;overflow:auto"><code>define( 'MANAHEL_OTP_EMERGENCY_KEY', 'A-LONG-RANDOM-SECRET-KEY-HERE' );</code></pre>
			<p>سپس نشانی اضطراری به شکل زیر خواهد بود:</p>
			<pre dir="ltr" style="max-width:760px;padding:12px;background:#fff;border:1px solid #ccd0d4;overflow:auto"><code><?php echo esc_html( site_url( 'wp-login.php', 'login' ) ); ?>?manahel_emergency=YOUR-SECRET-KEY</code></pre>
			<p>برای بستن قطعی آن، ثابت بالا را حذف کنید یا این مقدار را تعریف کنید: <code>define( 'MANAHEL_OTP_EMERGENCY_LOGIN', false );</code></p>
		</div>
		<?php
	}

	public function test_sms() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز.' );
		}
		check_admin_referer( 'mio_test_sms' );
		$mobile = MIO_Utils::normalize_mobile_e164( $_POST['mobile'] ?? '' );
		if ( is_wp_error( $mobile ) ) {
			$this->redirect_notice( 'error', $mobile->get_error_message() );
		}
		$settings = MIO_Settings::get();
		$digits   = (int) $settings['otp_digits'];
		$code     = (string) random_int( (int) pow( 10, $digits - 1 ), (int) pow( 10, $digits ) - 1 );
		$result   = ( new MIO_IPPanel() )->send_otp( $mobile, $code );
		if ( is_wp_error( $result ) ) {
			$this->redirect_notice( 'error', $result->get_error_message() );
		}
		$this->redirect_notice( 'success', 'پیامک آزمایشی با موفقیت به صف ارسال IPPanel تحویل شد.' );
	}

	private function redirect_notice( $type, $message ) {
		$url = add_query_arg(
			array(
				'page'       => 'manahel-otp',
				'mio_notice' => sanitize_key( $type ),
				'mio_msg'    => $message,
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! empty( $_GET['mio_notice'] ) && ! empty( $_GET['mio_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type = 'success' === sanitize_key( wp_unslash( $_GET['mio_notice'] ) ) ? 'success' : 'error'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg  = sanitize_text_field( wp_unslash( $_GET['mio_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}
}
