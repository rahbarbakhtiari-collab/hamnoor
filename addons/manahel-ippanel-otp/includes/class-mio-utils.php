<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MIO_Utils {
	public static function fa_to_en_digits( $value ) {
		return strtr(
			(string) $value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
	}

	/**
	 * Normalize Iranian mobile numbers to +989xxxxxxxxx.
	 *
	 * @return string|WP_Error
	 */
	public static function normalize_mobile_e164( $mobile ) {
		$mobile = self::fa_to_en_digits( $mobile );
		$mobile = preg_replace( '/[^0-9+]/', '', $mobile );

		if ( 0 === strpos( $mobile, '0098' ) ) {
			$mobile = '+' . substr( $mobile, 2 );
		} elseif ( 0 === strpos( $mobile, '98' ) && 12 === strlen( $mobile ) ) {
			$mobile = '+' . $mobile;
		} elseif ( 0 === strpos( $mobile, '09' ) && 11 === strlen( $mobile ) ) {
			$mobile = '+98' . substr( $mobile, 1 );
		} elseif ( 0 === strpos( $mobile, '9' ) && 10 === strlen( $mobile ) ) {
			$mobile = '+98' . $mobile;
		}

		if ( ! preg_match( '/^\+989\d{9}$/', $mobile ) ) {
			return new WP_Error( 'mio_invalid_mobile', 'شماره موبایل معتبر نیست. نمونه صحیح: 09123456789' );
		}

		return $mobile;
	}

	/**
	 * Normalize and validate an Iranian national ID.
	 *
	 * @return string|WP_Error
	 */
	public static function normalize_national_id( $national_id ) {
		$national_id = self::fa_to_en_digits( $national_id );
		$national_id = preg_replace( '/\D+/', '', $national_id );

		if ( 10 !== strlen( $national_id ) || preg_match( '/^(\d)\1{9}$/', $national_id ) ) {
			return new WP_Error( 'mio_invalid_national_id', 'کد ملی معتبر نیست؛ کد ملی باید ۱۰ رقم صحیح باشد.' );
		}

		$sum = 0;
		for ( $i = 0; $i < 9; $i++ ) {
			$sum += (int) $national_id[ $i ] * ( 10 - $i );
		}
		$remainder = $sum % 11;
		$expected  = $remainder < 2 ? $remainder : 11 - $remainder;

		if ( (int) $national_id[9] !== $expected ) {
			return new WP_Error( 'mio_invalid_national_id', 'کد ملی واردشده از نظر رقم کنترل معتبر نیست.' );
		}

		return $national_id;
	}

	public static function mask_national_id( $national_id ) {
		$national_id = preg_replace( '/\D+/', '', self::fa_to_en_digits( $national_id ) );
		if ( 10 !== strlen( $national_id ) ) {
			return '';
		}
		return substr( $national_id, 0, 3 ) . '*****' . substr( $national_id, -2 );
	}

	public static function string_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
	}

	public static function string_slice( $value, $start, $length = null ) {
		if ( function_exists( 'mb_substr' ) ) {
			return null === $length ? mb_substr( (string) $value, $start, null, 'UTF-8' ) : mb_substr( (string) $value, $start, $length, 'UTF-8' );
		}
		return null === $length ? substr( (string) $value, $start ) : substr( (string) $value, $start, $length );
	}

	public static function mobile_local( $mobile_e164 ) {
		return '0' . substr( (string) $mobile_e164, 3 );
	}

	public static function mask_mobile( $mobile_e164 ) {
		$local = self::mobile_local( $mobile_e164 );
		return substr( $local, 0, 4 ) . '***' . substr( $local, -4 );
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	public static function hash_key( $value ) {
		return hash_hmac( 'sha256', (string) $value, wp_salt( 'auth' ) );
	}

	public static function safe_redirect( $requested, $fallback = '' ) {
		$fallback = $fallback ? $fallback : home_url( '/' );
		return wp_validate_redirect( esc_url_raw( (string) $requested ), $fallback );
	}

	public static function is_rest_request() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	public static function log( $message, array $context = array() ) {
		$settings = MIO_Settings::get();
		if ( empty( $settings['debug_log'] ) || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		unset( $context['api_key'], $context['authorization'], $context['code'], $context['otp'], $context['national_id'] );
		if ( isset( $context['mobile'] ) ) {
			$normalized = self::normalize_mobile_e164( $context['mobile'] );
			$context['mobile'] = is_wp_error( $normalized ) ? 'invalid' : self::mask_mobile( $normalized );
		}

		error_log( '[Manahel OTP] ' . sanitize_text_field( $message ) . ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	public static function emergency_login_enabled() {
		if ( defined( 'MANAHEL_OTP_EMERGENCY_LOGIN' ) && false === (bool) MANAHEL_OTP_EMERGENCY_LOGIN ) {
			return false;
		}
		return defined( 'MANAHEL_OTP_EMERGENCY_KEY' ) && strlen( (string) MANAHEL_OTP_EMERGENCY_KEY ) >= 20;
	}

	public static function emergency_key_from_request() {
		return isset( $_REQUEST['manahel_emergency'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['manahel_emergency'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function request_is_emergency_login() {
		if ( ! self::emergency_login_enabled() ) {
			return false;
		}
		$provided = self::emergency_key_from_request();
		return '' !== $provided && hash_equals( (string) MANAHEL_OTP_EMERGENCY_KEY, $provided );
	}
}
