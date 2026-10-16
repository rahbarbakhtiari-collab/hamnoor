<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MIO_Frontend {
	private $assets_enqueued = false;

	public function hooks() {
		add_shortcode( 'manahel_otp_login', array( $this, 'shortcode' ) );
		add_action( 'template_redirect', array( $this, 'protect_login_page' ), 1 );
		add_filter( 'wp_robots', array( $this, 'noindex_login_page' ) );
	}

	public function protect_login_page() {
		$settings = MIO_Settings::get();
		$page_id  = absint( $settings['login_page_id'] );
		if ( $page_id && is_page( $page_id ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}

	public function noindex_login_page( $robots ) {
		$settings = MIO_Settings::get();
		if ( ! empty( $settings['login_page_id'] ) && is_page( (int) $settings['login_page_id'] ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public function shortcode() {
		$settings = MIO_Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return '<div class="mio-notice mio-notice--error">ورود پیامکی موقتاً غیرفعال است.</div>';
		}

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			return sprintf(
				'<div class="mio-shell"><div class="mio-card"><h2>شما وارد شده‌اید</h2><p>%s</p><p><a class="mio-button mio-button--secondary" href="%s">خروج از حساب</a></p></div></div>',
				esc_html( $user->display_name ),
				esc_url( wp_logout_url( home_url( '/' ) ) )
			);
		}

		$this->enqueue_assets();
		$redirect = isset( $_GET['redirect_to'] ) ? MIO_Utils::safe_redirect( sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ), MIO_Settings::fallback_redirect() ) : MIO_Settings::fallback_redirect(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		?>
		<div class="mio-shell" dir="rtl">
			<div class="mio-card" data-mio-root>
				<div class="mio-card__header">
					<h1>ورود و ثبت‌نام</h1>
					<p>کد یکبار مصرف به شماره موبایل شما ارسال می‌شود.</p>
				</div>

				<div class="mio-status" data-mio-status role="status" aria-live="polite" hidden></div>

				<form class="mio-form" data-mio-send-form novalidate>
					<label for="mio-mobile">شماره موبایل</label>
					<input id="mio-mobile" name="mobile" type="tel" dir="ltr" inputmode="numeric" autocomplete="tel" placeholder="09123456789" maxlength="16" required>

					<details class="mio-register-fields" data-mio-register-fields>
						<summary>اطلاعات ثبت‌نام کاربر جدید</summary>
						<label for="mio-full-name">نام و نام خانوادگی</label>
						<input id="mio-full-name" name="full_name" type="text" autocomplete="name" maxlength="100">
						<label for="mio-email">ایمیل <span>(اختیاری)</span></label>
						<input id="mio-email" name="email" type="email" autocomplete="email" maxlength="100">
						<?php if ( ! empty( $settings['enable_national_id'] ) ) : ?>
							<label for="mio-national-id">کد ملی <span><?php echo ! empty( $settings['require_national_id_new_users'] ) ? '(برای ثبت‌نام الزامی)' : '(اختیاری)'; ?></span></label>
							<input id="mio-national-id" name="national_id" type="text" dir="ltr" inputmode="numeric" autocomplete="off" maxlength="10" pattern="[0-9۰-۹٠-٩]{10}" placeholder="کد ملی ۱۰ رقمی">
							<p class="mio-field-help">کد ملی خصوصی است و در بخش عمومی سایت نمایش داده نمی‌شود.</p>
						<?php endif; ?>
					</details>

					<input class="mio-hp" name="website" type="text" tabindex="-1" autocomplete="off" aria-hidden="true">
					<input name="redirect_to" type="hidden" value="<?php echo esc_attr( $redirect ); ?>">
					<button class="mio-button" type="submit" data-mio-send-button>ارسال کد تأیید</button>
				</form>

				<form class="mio-form" data-mio-verify-form hidden novalidate>
					<p class="mio-sent-to">کد ارسال‌شده به <strong data-mio-masked-mobile></strong> را وارد کنید.</p>
					<label for="mio-code">کد تأیید</label>
					<input id="mio-code" name="code" type="text" dir="ltr" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="<?php echo esc_attr( (int) $settings['otp_digits'] ); ?>" required>
					<input name="mobile" type="hidden">
					<input name="request_token" type="hidden">
					<input name="redirect_to" type="hidden" value="<?php echo esc_attr( $redirect ); ?>">
					<button class="mio-button" type="submit" data-mio-verify-button>تأیید و ورود</button>
					<div class="mio-actions">
						<button type="button" class="mio-link-button" data-mio-edit-mobile>تغییر شماره</button>
						<button type="button" class="mio-link-button" data-mio-resend disabled>ارسال دوباره <span data-mio-countdown></span></button>
					</div>
				</form>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private function enqueue_assets() {
		if ( $this->assets_enqueued ) {
			return;
		}
		$this->assets_enqueued = true;
		wp_enqueue_style( 'mio-frontend', MIO_URL . 'assets/css/frontend.css', array(), MIO_VERSION );
		wp_enqueue_script( 'mio-frontend', MIO_URL . 'assets/js/frontend.js', array(), MIO_VERSION, true );
		wp_localize_script(
			'mio-frontend',
			'ManahelOTP',
			array(
				'sendUrl'   => esc_url_raw( rest_url( 'manahel-otp/v1/send' ) ),
				'verifyUrl' => esc_url_raw( rest_url( 'manahel-otp/v1/verify' ) ),
				'nonce'     => wp_create_nonce( 'mio_public' ),
				'otpDigits' => (int) MIO_Settings::get()['otp_digits'],
			)
		);
	}
}
