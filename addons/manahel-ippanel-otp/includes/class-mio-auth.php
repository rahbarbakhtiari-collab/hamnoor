<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MIO_Auth {
	public function hooks() {
		add_filter( 'login_url', array( $this, 'filter_login_url' ), 50, 3 );
		add_filter( 'register_url', array( $this, 'filter_register_url' ), 50 );
		add_filter( 'lostpassword_url', array( $this, 'filter_lostpassword_url' ), 50, 2 );
		add_filter( 'wp_authenticate_user', array( $this, 'block_password_login' ), 99, 2 );
		add_action( 'login_init', array( $this, 'redirect_wp_login' ), 1 );
		add_action( 'login_form', array( $this, 'emergency_login_hidden_field' ) );

		add_filter( 'learndash_login_url', array( $this, 'filter_learndash_login_url' ), 50, 3 );
		add_filter( 'learndash_login_model_register_url', array( $this, 'filter_register_url' ), 50 );
		add_filter( 'learndash_registration_login_link_redirect', array( $this, 'filter_register_url' ), 50 );
		add_filter( 'learndash_login_shortcode_atts', array( $this, 'filter_learndash_shortcode_atts' ), 50 );
		add_filter( 'learndash_course_join_redirect', array( $this, 'filter_course_join_redirect' ), 50, 2 );
	}

	public function filter_login_url( $login_url, $redirect, $force_reauth ) {
		unset( $force_reauth );
		$settings = MIO_Settings::get();
		if ( empty( $settings['enabled'] ) || empty( $settings['force_login_links'] ) || MIO_Utils::request_is_emergency_login() ) {
			return $login_url;
		}
		return MIO_Settings::login_url( $redirect );
	}

	public function filter_register_url( $url = '' ) {
		$settings = MIO_Settings::get();
		if ( empty( $settings['enabled'] ) || empty( $settings['force_login_links'] ) ) {
			return $url;
		}
		return MIO_Settings::login_url( $this->current_url() );
	}

	public function filter_lostpassword_url( $url, $redirect ) {
		$settings = MIO_Settings::get();
		if ( empty( $settings['enabled'] ) || empty( $settings['force_login_links'] ) ) {
			return $url;
		}
		return MIO_Settings::login_url( $redirect );
	}

	public function redirect_wp_login() {
		$settings = MIO_Settings::get();
		if ( empty( $settings['enabled'] ) || empty( $settings['redirect_wp_login'] ) || MIO_Utils::request_is_emergency_login() ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $action, array( 'logout', 'postpass', 'confirmaction', 'confirm_admin_email' ), true ) ) {
			return;
		}
		$redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( MIO_Settings::login_url( $redirect ) );
		exit;
	}

	public function block_password_login( $user, $password ) {
		unset( $password );
		$settings = MIO_Settings::get();
		if ( is_wp_error( $user ) || empty( $settings['enabled'] ) || empty( $settings['block_password_login'] ) ) {
			return $user;
		}
		if ( MIO_Utils::request_is_emergency_login() && user_can( $user, 'manage_options' ) ) {
			return $user;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return new WP_Error( 'mio_admin_otp_required', 'ورود رمزی غیرفعال است. مدیر نیز باید از ورود پیامکی استفاده کند.' );
		}
		return new WP_Error( 'mio_otp_required', 'برای ورود به حساب، از کد یکبار مصرف پیامکی استفاده کنید.' );
	}

	public function filter_learndash_login_url( $url, $action = 'login', $atts = array() ) {
		unset( $atts );
		$settings = MIO_Settings::get();
		if ( empty( $settings['enabled'] ) || empty( $settings['force_login_links'] ) || 'logout' === $action || is_user_logged_in() ) {
			return $url;
		}
		return MIO_Settings::login_url( $this->current_url() );
	}

	public function filter_learndash_shortcode_atts( $atts ) {
		$settings = MIO_Settings::get();
		if ( empty( $settings['enabled'] ) || empty( $settings['force_login_links'] ) ) {
			return $atts;
		}
		if ( is_array( $atts ) ) {
			$atts['login_modal'] = false;
			$atts['login_url']   = MIO_Settings::login_url( $this->current_url() );
		}
		return $atts;
	}

	public function filter_course_join_redirect( $url, $course_id ) {
		$settings = MIO_Settings::get();
		if ( empty( $settings['enabled'] ) || empty( $settings['force_login_links'] ) || is_user_logged_in() ) {
			return $url;
		}
		$target = $course_id ? get_permalink( $course_id ) : $this->current_url();
		return MIO_Settings::login_url( $target );
	}

	public function emergency_login_hidden_field() {
		if ( MIO_Utils::request_is_emergency_login() ) {
			echo '<input type="hidden" name="manahel_emergency" value="' . esc_attr( MIO_Utils::emergency_key_from_request() ) . '">';
		}
	}

	private function current_url() {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return MIO_Utils::safe_redirect( $scheme . $host . $uri );
	}
}
