<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MIO_IPPanel {
	const ENDPOINT = 'https://edge.ippanel.com/v1/api/send';

	/**
	 * @return true|WP_Error
	 */
	public function send_otp( $mobile_e164, $otp ) {
		$settings = MIO_Settings::get();
		$api_key  = MIO_Settings::api_key();
		$sender   = trim( (string) $settings['sender'] );
		$pattern  = trim( (string) $settings['pattern_code'] );
		$variable = sanitize_key( $settings['pattern_variable'] );

		if ( ! $api_key ) {
			return new WP_Error( 'mio_missing_api_key', 'کلید API پنل پیامک تنظیم نشده است.' );
		}
		if ( ! preg_match( '/^\+[1-9]\d{4,15}$/', $sender ) ) {
			return new WP_Error( 'mio_invalid_sender', 'شماره فرستنده باید با فرمت E.164 ثبت شود؛ مانند +983000505.' );
		}
		if ( ! $pattern ) {
			return new WP_Error( 'mio_missing_pattern', 'کد پترن ورود تنظیم نشده است.' );
		}
		if ( ! $variable ) {
			$variable = 'code';
		}

		$body = array(
			'sending_type' => 'pattern',
			'from_number'  => $sender,
			'code'         => $pattern,
			'recipients'   => array( $mobile_e164 ),
			'params'       => array(
				$variable => (string) $otp,
			),
		);

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			MIO_Utils::log( 'IPPanel transport error', array( 'error' => $response->get_error_message(), 'mobile' => $mobile_e164 ) );
			return new WP_Error( 'mio_sms_transport', 'ارتباط با سرویس پیامک برقرار نشد. کمی بعد دوباره تلاش کنید.' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			$message = $this->extract_error_message( $data );
			MIO_Utils::log( 'IPPanel rejected request', array( 'status' => $status, 'response' => MIO_Utils::string_slice( wp_strip_all_tags( $raw ), 0, 500 ), 'mobile' => $mobile_e164 ) );
			return new WP_Error( 'mio_sms_rejected', $message ? 'ارسال پیامک ناموفق بود: ' . $message : 'پنل پیامک درخواست را نپذیرفت.' );
		}

		if ( is_array( $data ) && isset( $data['success'] ) && false === $data['success'] ) {
			$message = $this->extract_error_message( $data );
			return new WP_Error( 'mio_sms_failed', $message ? $message : 'ارسال پیامک ناموفق بود.' );
		}

		return true;
	}

	private function extract_error_message( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		foreach ( array( 'message', 'error', 'detail' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				return sanitize_text_field( $data[ $key ] );
			}
		}
		if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
			return sanitize_text_field( wp_json_encode( $data['errors'], JSON_UNESCAPED_UNICODE ) );
		}
		return '';
	}
}
