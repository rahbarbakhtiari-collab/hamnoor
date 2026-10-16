<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MIO_OTP {
	private $provider;

	public function __construct( MIO_IPPanel $provider ) {
		$this->provider = $provider;
	}

	public function register_routes() {
		register_rest_route(
			'manahel-otp/v1',
			'/send',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'manahel-otp/v1',
			'/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'verify' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function send( WP_REST_Request $request ) {
		$settings = MIO_Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return new WP_Error( 'mio_disabled', 'ورود پیامکی موقتاً غیرفعال است.', array( 'status' => 503 ) );
		}
		if ( ! $this->verify_public_nonce( $request ) ) {
			return new WP_Error( 'mio_invalid_request', 'درخواست معتبر نیست. صفحه را تازه‌سازی کنید.', array( 'status' => 403 ) );
		}
		if ( $request->get_param( 'website' ) ) {
			return new WP_Error( 'mio_spam', 'درخواست نامعتبر است.', array( 'status' => 400 ) );
		}

		$mobile = MIO_Utils::normalize_mobile_e164( $request->get_param( 'mobile' ) );
		if ( is_wp_error( $mobile ) ) {
			return $this->rest_error( $mobile, 400 );
		}

		$limited = $this->check_send_limits( $mobile );
		if ( is_wp_error( $limited ) ) {
			return $this->rest_error( $limited, 429 );
		}
		// Count every normalized public request to reduce enumeration and abuse, even when no SMS is sent.
		$this->increment_counter( 'ip', MIO_Utils::client_ip() );

		$user = MIO_Users::find_by_mobile( $mobile );
		if ( is_wp_error( $user ) ) {
			return $this->rest_error( $user, 409 );
		}

		$full_name = trim( sanitize_text_field( (string) $request->get_param( 'full_name' ) ) );
		$email     = trim( sanitize_email( (string) $request->get_param( 'email' ) ) );
		if ( ! $user && MIO_Utils::string_length( $full_name ) < 3 ) {
			return new WP_Error( 'mio_name_required', 'این شماره هنوز ثبت‌نام نشده است؛ نام و نام خانوادگی را وارد کنید.', array( 'status' => 422, 'needs_registration' => true ) );
		}
		if ( ! $user && $email && ! is_email( $email ) ) {
			return new WP_Error( 'mio_invalid_email', 'نشانی ایمیل معتبر نیست.', array( 'status' => 422, 'needs_registration' => true ) );
		}
		if ( ! $user && $email && email_exists( $email ) ) {
			return new WP_Error( 'mio_email_exists', 'این ایمیل قبلاً به حساب دیگری متصل است.', array( 'status' => 409, 'needs_registration' => true ) );
		}

		$national_id = '';
		if ( ! empty( $settings['enable_national_id'] ) ) {
			$raw_national_id = trim( sanitize_text_field( (string) $request->get_param( 'national_id' ) ) );
			if ( '' !== $raw_national_id ) {
				$national_id = MIO_Utils::normalize_national_id( $raw_national_id );
				if ( is_wp_error( $national_id ) ) {
					return $this->rest_error_with_registration( $national_id, 422, ! $user );
				}
				$national_validation = MIO_Users::validate_national_id_for_user( $national_id, $user instanceof WP_User ? $user->ID : 0 );
				if ( is_wp_error( $national_validation ) ) {
					return $this->rest_error_with_registration( $national_validation, 409, ! $user );
				}
				if ( $user instanceof WP_User ) {
					$existing_national_id = (string) get_user_meta( $user->ID, MIO_Users::META_NATIONAL_ID, true );
					if ( $existing_national_id && ! hash_equals( $existing_national_id, $national_id ) ) {
						return new WP_Error( 'mio_national_id_conflict', 'برای این حساب قبلاً کد ملی دیگری ثبت شده است. برای اصلاح با مدیر سایت تماس بگیرید.', array( 'status' => 409 ) );
					}
				}
			} elseif ( ! $user && ! empty( $settings['require_national_id_new_users'] ) ) {
				return new WP_Error( 'mio_national_id_required', 'برای ثبت‌نام، کد ملی را وارد کنید.', array( 'status' => 422, 'needs_registration' => true ) );
			}
		}

		$digits = (int) $settings['otp_digits'];
		$min    = (int) pow( 10, $digits - 1 );
		$max    = (int) pow( 10, $digits ) - 1;
		$otp    = (string) random_int( $min, $max );

		$sent = $this->provider->send_otp( $mobile, $otp );
		if ( is_wp_error( $sent ) ) {
			return $this->rest_error( $sent, 502 );
		}

		$request_token = wp_generate_password( 32, false, false );
		$payload = array(
			'hash'        => hash_hmac( 'sha256', $mobile . '|' . $otp, wp_salt( 'auth' ) ),
			'expires_at'  => time() + (int) $settings['otp_expiry'],
			'attempts'    => 0,
			'user_id'     => $user instanceof WP_User ? (int) $user->ID : 0,
			'full_name'   => $full_name,
			'email'       => $email,
			'national_id' => $national_id,
			'token_hash'  => hash_hmac( 'sha256', $request_token, wp_salt( 'secure_auth' ) ),
		);
		set_transient( $this->otp_key( $mobile ), $payload, (int) $settings['otp_expiry'] + 60 );
		$this->mark_send_limits( $mobile );

		return rest_ensure_response(
			array(
				'success'       => true,
				'message'       => 'کد تأیید ارسال شد.',
				'masked_mobile' => MIO_Utils::mask_mobile( $mobile ),
				'expires_in'    => (int) $settings['otp_expiry'],
				'resend_in'     => (int) $settings['resend_delay'],
				'request_token' => $request_token,
			)
		);
	}

	public function verify( WP_REST_Request $request ) {
		$settings = MIO_Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return new WP_Error( 'mio_disabled', 'ورود پیامکی موقتاً غیرفعال است.', array( 'status' => 503 ) );
		}
		if ( ! $this->verify_public_nonce( $request ) ) {
			return new WP_Error( 'mio_invalid_request', 'درخواست معتبر نیست. صفحه را تازه‌سازی کنید.', array( 'status' => 403 ) );
		}

		$mobile = MIO_Utils::normalize_mobile_e164( $request->get_param( 'mobile' ) );
		if ( is_wp_error( $mobile ) ) {
			return $this->rest_error( $mobile, 400 );
		}
		$code = MIO_Utils::fa_to_en_digits( (string) $request->get_param( 'code' ) );
		$code = preg_replace( '/\D+/', '', $code );
		if ( strlen( $code ) !== (int) $settings['otp_digits'] ) {
			return new WP_Error( 'mio_invalid_code_format', 'کد تأیید را کامل وارد کنید.', array( 'status' => 422 ) );
		}

		$key     = $this->otp_key( $mobile );
		$payload = get_transient( $key );
		if ( ! is_array( $payload ) || empty( $payload['expires_at'] ) || time() > (int) $payload['expires_at'] ) {
			delete_transient( $key );
			return new WP_Error( 'mio_code_expired', 'کد منقضی شده است؛ کد تازه دریافت کنید.', array( 'status' => 410 ) );
		}
		$request_token = sanitize_text_field( (string) $request->get_param( 'request_token' ) );
		$token_hash    = hash_hmac( 'sha256', $request_token, wp_salt( 'secure_auth' ) );
		if ( empty( $payload['token_hash'] ) || ! hash_equals( (string) $payload['token_hash'], $token_hash ) ) {
			return new WP_Error( 'mio_invalid_flow', 'نشست ورود معتبر نیست؛ کد تازه دریافت کنید.', array( 'status' => 403 ) );
		}

		$expected = hash_hmac( 'sha256', $mobile . '|' . $code, wp_salt( 'auth' ) );
		if ( ! hash_equals( (string) $payload['hash'], $expected ) ) {
			$payload['attempts'] = (int) $payload['attempts'] + 1;
			if ( $payload['attempts'] >= (int) $settings['verify_max_attempts'] ) {
				delete_transient( $key );
				return new WP_Error( 'mio_too_many_attempts', 'تعداد تلاش‌ها بیش از حد مجاز بود؛ کد تازه دریافت کنید.', array( 'status' => 429 ) );
			}
			set_transient( $key, $payload, max( 60, (int) $payload['expires_at'] - time() + 60 ) );
			return new WP_Error( 'mio_wrong_code', 'کد تأیید نادرست است.', array( 'status' => 422, 'remaining_attempts' => (int) $settings['verify_max_attempts'] - $payload['attempts'] ) );
		}

		$user = ! empty( $payload['user_id'] ) ? get_user_by( 'id', (int) $payload['user_id'] ) : null;
		if ( ! $user ) {
			$user = MIO_Users::find_by_mobile( $mobile );
		}
		if ( is_wp_error( $user ) ) {
			return $this->rest_error( $user, 409 );
		}

		$is_new = false;
		if ( ! $user ) {
			$user = MIO_Users::create( $mobile, $payload['full_name'] ?? '', $payload['email'] ?? '', $payload['national_id'] ?? '' );
			if ( is_wp_error( $user ) ) {
				return $this->rest_error( $user, 422 );
			}
			$is_new = true;
		} else {
			MIO_Users::sync_mobile_meta( $user->ID, $mobile );
			$national_result = MIO_Users::attach_national_id( $user->ID, $payload['national_id'] ?? '' );
			if ( is_wp_error( $national_result ) ) {
				return $this->rest_error( $national_result, 409 );
			}
		}

		delete_transient( $key );
		$remember = ! empty( $settings['remember_login'] );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );

		$redirect = MIO_Utils::safe_redirect( $request->get_param( 'redirect_to' ), MIO_Settings::fallback_redirect() );
		return rest_ensure_response(
			array(
				'success'  => true,
				'message'  => $is_new ? 'ثبت‌نام و ورود با موفقیت انجام شد.' : 'ورود با موفقیت انجام شد.',
				'redirect' => $redirect,
				'is_new'   => $is_new,
			)
		);
	}

	private function verify_public_nonce( WP_REST_Request $request ) {
		$nonce = sanitize_text_field( (string) $request->get_header( 'X-Manahel-Nonce' ) );
		return (bool) wp_verify_nonce( $nonce, 'mio_public' );
	}

	private function otp_key( $mobile_e164 ) {
		return 'mio_otp_' . substr( MIO_Utils::hash_key( $mobile_e164 ), 0, 32 );
	}

	private function check_send_limits( $mobile_e164 ) {
		$settings = MIO_Settings::get();
		$cooldown = get_transient( 'mio_cd_' . substr( MIO_Utils::hash_key( $mobile_e164 ), 0, 28 ) );
		if ( $cooldown ) {
			return new WP_Error( 'mio_resend_wait', 'برای ارسال دوباره کمی صبر کنید.' );
		}

		$phone_count = $this->counter_value( 'phone', $mobile_e164 );
		$ip_count    = $this->counter_value( 'ip', MIO_Utils::client_ip() );
		if ( $phone_count >= (int) $settings['phone_hourly_limit'] ) {
			return new WP_Error( 'mio_phone_rate_limit', 'تعداد پیامک‌های این شماره بیش از حد مجاز است. یک ساعت بعد تلاش کنید.' );
		}
		if ( $ip_count >= (int) $settings['ip_hourly_limit'] ) {
			return new WP_Error( 'mio_ip_rate_limit', 'تعداد درخواست‌ها از این اتصال بیش از حد مجاز است. کمی بعد تلاش کنید.' );
		}
		return true;
	}

	private function mark_send_limits( $mobile_e164 ) {
		$settings = MIO_Settings::get();
		set_transient( 'mio_cd_' . substr( MIO_Utils::hash_key( $mobile_e164 ), 0, 28 ), 1, (int) $settings['resend_delay'] );
		$this->increment_counter( 'phone', $mobile_e164 );
	}

	private function counter_key( $type, $value ) {
		return 'mio_rl_' . sanitize_key( $type ) . '_' . substr( MIO_Utils::hash_key( $value ), 0, 24 );
	}

	private function counter_value( $type, $value ) {
		return (int) get_transient( $this->counter_key( $type, $value ) );
	}

	private function increment_counter( $type, $value ) {
		$key = $this->counter_key( $type, $value );
		set_transient( $key, $this->counter_value( $type, $value ) + 1, HOUR_IN_SECONDS );
	}

	private function rest_error( WP_Error $error, $status ) {
		$data           = $error->get_error_data();
		$data           = is_array( $data ) ? $data : array();
		$data['status'] = $status;
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	private function rest_error_with_registration( WP_Error $error, $status, $needs_registration ) {
		$data                       = $error->get_error_data();
		$data                       = is_array( $data ) ? $data : array();
		$data['status']             = $status;
		$data['needs_registration'] = (bool) $needs_registration;
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}
}
