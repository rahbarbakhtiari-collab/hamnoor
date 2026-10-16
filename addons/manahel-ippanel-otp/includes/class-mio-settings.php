<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MIO_Settings {
	const OPTION = 'mio_settings';

	public static function defaults() {
		return array(
			'enabled'                         => 1,
			'api_key'                         => '',
			'sender'                          => '',
			'pattern_code'                    => '',
			'pattern_variable'                => 'code',
			'otp_digits'                      => 5,
			'otp_expiry'                      => 120,
			'resend_delay'                    => 60,
			'phone_hourly_limit'               => 5,
			'ip_hourly_limit'                  => 60,
			'verify_max_attempts'              => 5,
			'login_page_id'                    => 0,
			'force_login_links'                => 1,
			'redirect_wp_login'                => 1,
			'block_password_login'             => 1,
			'remember_login'                   => 1,
			'enable_national_id'               => 1,
			'require_national_id_new_users'    => 0,
			'fallback_redirect'                => '',
			'debug_log'                        => 0,
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		if ( ! array_key_exists( 'block_password_login', $saved ) && array_key_exists( 'block_password_students', $saved ) ) {
			$saved['block_password_login'] = empty( $saved['block_password_students'] ) ? 0 : 1;
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function api_key() {
		if ( defined( 'MANAHEL_IPPANEL_API_KEY' ) && MANAHEL_IPPANEL_API_KEY ) {
			return trim( (string) MANAHEL_IPPANEL_API_KEY );
		}
		$settings = self::get();
		return trim( (string) $settings['api_key'] );
	}

	public static function sanitize( $input ) {
		$current = self::get();
		$input   = is_array( $input ) ? $input : array();
		$output  = self::defaults();

		$output['enabled']                      = empty( $input['enabled'] ) ? 0 : 1;
		$output['force_login_links']            = empty( $input['force_login_links'] ) ? 0 : 1;
		$output['redirect_wp_login']            = empty( $input['redirect_wp_login'] ) ? 0 : 1;
		$output['block_password_login']         = empty( $input['block_password_login'] ) ? 0 : 1;
		$output['remember_login']                = empty( $input['remember_login'] ) ? 0 : 1;
		$output['enable_national_id']            = empty( $input['enable_national_id'] ) ? 0 : 1;
		$output['require_national_id_new_users'] = empty( $input['require_national_id_new_users'] ) || empty( $output['enable_national_id'] ) ? 0 : 1;
		$output['debug_log']                     = empty( $input['debug_log'] ) ? 0 : 1;

		$new_key          = isset( $input['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $input['api_key'] ) ) ) : '';
		$output['api_key'] = $new_key ? $new_key : $current['api_key'];
		$output['sender'] = isset( $input['sender'] ) ? trim( sanitize_text_field( wp_unslash( $input['sender'] ) ) ) : '';
		$output['pattern_code'] = isset( $input['pattern_code'] ) ? trim( sanitize_text_field( wp_unslash( $input['pattern_code'] ) ) ) : '';
		$output['pattern_variable'] = isset( $input['pattern_variable'] ) ? sanitize_key( wp_unslash( $input['pattern_variable'] ) ) : 'code';
		$output['pattern_variable'] = $output['pattern_variable'] ? $output['pattern_variable'] : 'code';

		$output['otp_digits']          = min( 8, max( 4, absint( $input['otp_digits'] ?? 5 ) ) );
		$output['otp_expiry']          = min( 600, max( 60, absint( $input['otp_expiry'] ?? 120 ) ) );
		$output['resend_delay']        = min( 300, max( 30, absint( $input['resend_delay'] ?? 60 ) ) );
		$output['phone_hourly_limit']  = min( 20, max( 1, absint( $input['phone_hourly_limit'] ?? 5 ) ) );
		$output['ip_hourly_limit']     = min( 100, max( 1, absint( $input['ip_hourly_limit'] ?? 60 ) ) );
		$output['verify_max_attempts'] = min( 10, max( 3, absint( $input['verify_max_attempts'] ?? 5 ) ) );
		$output['login_page_id']       = absint( $input['login_page_id'] ?? 0 );
		$output['fallback_redirect']   = isset( $input['fallback_redirect'] ) ? esc_url_raw( wp_unslash( $input['fallback_redirect'] ) ) : '';

		return $output;
	}

	public static function login_url( $redirect_to = '' ) {
		$settings = self::get();
		$page_id  = absint( $settings['login_page_id'] );
		$url      = $page_id ? get_permalink( $page_id ) : home_url( '/login/' );

		if ( ! $url ) {
			$url = home_url( '/login/' );
		}

		if ( $redirect_to ) {
			$url = add_query_arg( 'redirect_to', MIO_Utils::safe_redirect( $redirect_to ), $url );
		}

		return $url;
	}

	public static function fallback_redirect() {
		$settings = self::get();
		return $settings['fallback_redirect'] ? MIO_Utils::safe_redirect( $settings['fallback_redirect'] ) : home_url( '/' );
	}
}
