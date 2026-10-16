<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MIO_Users {
	const META_LOCAL       = 'manahel_mobile';
	const META_E164        = 'manahel_mobile_e164';
	const META_NATIONAL_ID = 'manahel_national_id';

	public static function hooks() {
		add_action( 'show_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_fields' ) );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'profile_validation' ), 10, 3 );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_fields' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_value' ), 10, 3 );
	}

	/**
	 * @return WP_User|null|WP_Error
	 */
	public static function find_by_mobile( $mobile_e164 ) {
		$local = MIO_Utils::mobile_local( $mobile_e164 );
		$login_candidates = array(
			self::legacy_username_from_mobile( $mobile_e164 ),
			$local,
			ltrim( $local, '0' ),
			preg_replace( '/\D+/', '', $mobile_e164 ),
			'00' . ltrim( $mobile_e164, '+' ),
			$mobile_e164,
		);
		foreach ( array_unique( $login_candidates ) as $login_candidate ) {
			$user = get_user_by( 'login', $login_candidate );
			if ( $user instanceof WP_User ) {
				self::sync_mobile_meta( $user->ID, $mobile_e164 );
				return $user;
			}
		}

		$canonical_users = self::query_users_by_meta(
			array( self::META_E164, self::META_LOCAL ),
			array( $mobile_e164, $local, ltrim( $local, '0' ) )
		);
		if ( count( $canonical_users ) > 1 ) {
			return new WP_Error( 'mio_duplicate_mobile', 'این شماره موبایل به بیش از یک حساب متصل است. مدیر سایت باید حساب‌ها را بررسی کند.' );
		}
		if ( 1 === count( $canonical_users ) ) {
			self::sync_mobile_meta( $canonical_users[0]->ID, $mobile_e164 );
			return $canonical_users[0];
		}

		// Legacy fields are only used when the account has no conflicting canonical Manahel mobile.
		$legacy_users = self::query_users_by_meta(
			array( 'billing_phone', 'digits_phone', 'digits_phone_no' ),
			array( $mobile_e164, $local, ltrim( $local, '0' ) )
		);
		$eligible = array();
		foreach ( $legacy_users as $legacy_user ) {
			$canonical = (string) get_user_meta( $legacy_user->ID, self::META_E164, true );
			if ( '' === $canonical || hash_equals( $canonical, $mobile_e164 ) ) {
				$eligible[] = $legacy_user;
			}
		}
		if ( count( $eligible ) > 1 ) {
			return new WP_Error( 'mio_duplicate_mobile', 'این شماره موبایل به بیش از یک حساب متصل است. مدیر سایت باید حساب‌ها را بررسی کند.' );
		}
		if ( 1 === count( $eligible ) ) {
			self::sync_mobile_meta( $eligible[0]->ID, $mobile_e164 );
			return $eligible[0];
		}

		return null;
	}

	private static function query_users_by_meta( array $keys, array $values, $number = 3 ) {
		$meta_query = array( 'relation' => 'OR' );
		foreach ( $keys as $key ) {
			$meta_query[] = array(
				'key'     => $key,
				'value'   => $values,
				'compare' => 'IN',
			);
		}
		$query = new WP_User_Query(
			array(
				'number'     => absint( $number ),
				'fields'     => 'all',
				'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		return $query->get_results();
	}

	/**
	 * @return WP_User|WP_Error
	 */
	public static function create( $mobile_e164, $full_name = '', $email = '', $national_id = '' ) {
		$full_name = trim( sanitize_text_field( $full_name ) );
		$email     = trim( sanitize_email( $email ) );

		if ( MIO_Utils::string_length( $full_name ) < 3 ) {
			return new WP_Error( 'mio_name_required', 'برای ثبت‌نام، نام و نام خانوادگی را وارد کنید.' );
		}
		if ( $email && ! is_email( $email ) ) {
			return new WP_Error( 'mio_invalid_email', 'نشانی ایمیل معتبر نیست.' );
		}
		if ( $email && email_exists( $email ) ) {
			return new WP_Error( 'mio_email_exists', 'این ایمیل قبلاً برای حساب دیگری ثبت شده است.' );
		}

		$mobile_validation = self::validate_mobile_for_user( $mobile_e164, 0 );
		if ( is_wp_error( $mobile_validation ) ) {
			return $mobile_validation;
		}

		$national_id = trim( (string) $national_id );
		if ( '' !== $national_id ) {
			$national_id = MIO_Utils::normalize_national_id( $national_id );
			if ( is_wp_error( $national_id ) ) {
				return $national_id;
			}
			$national_validation = self::validate_national_id_for_user( $national_id, 0 );
			if ( is_wp_error( $national_validation ) ) {
				return $national_validation;
			}
		}

		$parts      = preg_split( '/\s+/u', $full_name, 2 );
		$first_name = $parts[0] ?? '';
		$last_name  = $parts[1] ?? '';
		$username   = self::generate_private_username();
		$meta_input = array(
			self::META_LOCAL => MIO_Utils::mobile_local( $mobile_e164 ),
			self::META_E164  => $mobile_e164,
		);
		if ( $national_id ) {
			$meta_input[ self::META_NATIONAL_ID ] = $national_id;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => $email,
				'display_name' => $full_name,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'role'         => 'subscriber',
				'meta_input'   => $meta_input,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		self::sync_mobile_meta( $user_id, $mobile_e164 );
		if ( $national_id ) {
			update_user_meta( $user_id, self::META_NATIONAL_ID, $national_id );
		}
		$user = get_user_by( 'id', $user_id );
		return $user instanceof WP_User ? $user : new WP_Error( 'mio_user_create_failed', 'حساب کاربری ایجاد نشد.' );
	}

	public static function sync_mobile_meta( $user_id, $mobile_e164 ) {
		$local = MIO_Utils::mobile_local( $mobile_e164 );
		update_user_meta( $user_id, self::META_E164, $mobile_e164 );
		update_user_meta( $user_id, self::META_LOCAL, $local );
		if ( ! get_user_meta( $user_id, 'billing_phone', true ) ) {
			update_user_meta( $user_id, 'billing_phone', $local );
		}
	}

	/**
	 * Attach a national ID without overwriting a different existing value.
	 *
	 * @return true|WP_Error
	 */
	public static function attach_national_id( $user_id, $national_id ) {
		$national_id = trim( (string) $national_id );
		if ( '' === $national_id ) {
			return true;
		}
		$national_id = MIO_Utils::normalize_national_id( $national_id );
		if ( is_wp_error( $national_id ) ) {
			return $national_id;
		}
		$existing = (string) get_user_meta( $user_id, self::META_NATIONAL_ID, true );
		if ( $existing && ! hash_equals( $existing, $national_id ) ) {
			return new WP_Error( 'mio_national_id_conflict', 'برای این حساب قبلاً کد ملی دیگری ثبت شده است. برای اصلاح با مدیر سایت تماس بگیرید.' );
		}
		$validation = self::validate_national_id_for_user( $national_id, $user_id );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		update_user_meta( $user_id, self::META_NATIONAL_ID, $national_id );
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function validate_mobile_for_user( $mobile_e164, $user_id = 0 ) {
		$mobile_e164 = MIO_Utils::normalize_mobile_e164( $mobile_e164 );
		if ( is_wp_error( $mobile_e164 ) ) {
			return $mobile_e164;
		}
		$local = MIO_Utils::mobile_local( $mobile_e164 );
		$users = self::query_users_by_meta(
			array( self::META_E164, self::META_LOCAL, 'billing_phone', 'digits_phone', 'digits_phone_no' ),
			array( $mobile_e164, $local, ltrim( $local, '0' ) ),
			10
		);
		foreach ( $users as $user ) {
			if ( (int) $user->ID !== (int) $user_id ) {
				return new WP_Error( 'mio_mobile_exists', 'این شماره موبایل قبلاً به حساب دیگری متصل شده است.' );
			}
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function validate_national_id_for_user( $national_id, $user_id = 0 ) {
		$national_id = MIO_Utils::normalize_national_id( $national_id );
		if ( is_wp_error( $national_id ) ) {
			return $national_id;
		}
		$query = new WP_User_Query(
			array(
				'number'     => 2,
				'fields'     => 'ID',
				'meta_key'   => self::META_NATIONAL_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $national_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $query->get_results() as $found_user_id ) {
			if ( (int) $found_user_id !== (int) $user_id ) {
				return new WP_Error( 'mio_national_id_exists', 'این کد ملی قبلاً برای حساب دیگری ثبت شده است.' );
			}
		}
		return true;
	}

	public static function generate_private_username() {
		for ( $attempt = 0; $attempt < 10; $attempt++ ) {
			try {
				$suffix = bin2hex( random_bytes( 7 ) );
			} catch ( Exception $exception ) {
				unset( $exception );
				$suffix = strtolower( wp_generate_password( 14, false, false ) );
			}
			$username = sanitize_user( 'mn_' . $suffix, true );
			if ( $username && ! username_exists( $username ) ) {
				return $username;
			}
		}
		return 'mn_' . time() . '_' . wp_rand( 1000, 9999 );
	}

	public static function legacy_username_from_mobile( $mobile_e164 ) {
		return 'm_' . preg_replace( '/\D+/', '', $mobile_e164 );
	}

	public static function profile_fields( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$mobile      = (string) get_user_meta( $user->ID, self::META_LOCAL, true );
		$national_id = (string) get_user_meta( $user->ID, self::META_NATIONAL_ID, true );
		?>
		<h2>اطلاعات ورود پیامکی مناهل</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="mio_profile_mobile">شماره موبایل ورود</label></th>
				<td>
					<input type="tel" class="regular-text" dir="ltr" id="mio_profile_mobile" name="mio_profile_mobile" value="<?php echo esc_attr( $mobile ); ?>" placeholder="09123456789">
					<p class="description">این شماره باید یکتا باشد و برای ورود OTP همین حساب استفاده می‌شود. برای ورود مدیر نیز ابتدا شماره را در همین قسمت ثبت کنید.</p>
				</td>
			</tr>
			<tr>
				<th><label for="mio_profile_national_id">کد ملی</label></th>
				<td>
					<input type="text" class="regular-text" dir="ltr" inputmode="numeric" maxlength="10" id="mio_profile_national_id" name="mio_profile_national_id" value="<?php echo esc_attr( $national_id ); ?>">
					<p class="description">کد ملی ۱۰ رقمی، خصوصی و یکتا است و در فرانت‌اند عمومی نمایش داده نمی‌شود.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function profile_validation( $errors, $update, $user ) {
		unset( $update );
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		if ( array_key_exists( 'mio_profile_mobile', $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_mobile = trim( sanitize_text_field( wp_unslash( $_POST['mio_profile_mobile'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' !== $raw_mobile ) {
				$mobile = MIO_Utils::normalize_mobile_e164( $raw_mobile );
				if ( is_wp_error( $mobile ) ) {
					$errors->add( $mobile->get_error_code(), $mobile->get_error_message() );
				} else {
					$validation = self::validate_mobile_for_user( $mobile, $user->ID );
					if ( is_wp_error( $validation ) ) {
						$errors->add( $validation->get_error_code(), $validation->get_error_message() );
					}
				}
			}
		}

		if ( array_key_exists( 'mio_profile_national_id', $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_national_id = trim( sanitize_text_field( wp_unslash( $_POST['mio_profile_national_id'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' !== $raw_national_id ) {
				$national_id = MIO_Utils::normalize_national_id( $raw_national_id );
				if ( is_wp_error( $national_id ) ) {
					$errors->add( $national_id->get_error_code(), $national_id->get_error_message() );
				} else {
					$validation = self::validate_national_id_for_user( $national_id, $user->ID );
					if ( is_wp_error( $validation ) ) {
						$errors->add( $validation->get_error_code(), $validation->get_error_message() );
					}
				}
			}
		}
	}

	public static function save_profile_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( array_key_exists( 'mio_profile_mobile', $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_mobile = trim( sanitize_text_field( wp_unslash( $_POST['mio_profile_mobile'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' === $raw_mobile ) {
				delete_user_meta( $user_id, self::META_LOCAL );
				delete_user_meta( $user_id, self::META_E164 );
			} else {
				$mobile = MIO_Utils::normalize_mobile_e164( $raw_mobile );
				if ( ! is_wp_error( $mobile ) && ! is_wp_error( self::validate_mobile_for_user( $mobile, $user_id ) ) ) {
					self::sync_mobile_meta( $user_id, $mobile );
				}
			}
		}

		if ( array_key_exists( 'mio_profile_national_id', $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_national_id = trim( sanitize_text_field( wp_unslash( $_POST['mio_profile_national_id'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' === $raw_national_id ) {
				delete_user_meta( $user_id, self::META_NATIONAL_ID );
			} else {
				$national_id = MIO_Utils::normalize_national_id( $raw_national_id );
				if ( ! is_wp_error( $national_id ) && ! is_wp_error( self::validate_national_id_for_user( $national_id, $user_id ) ) ) {
					update_user_meta( $user_id, self::META_NATIONAL_ID, $national_id );
				}
			}
		}
	}

	public static function users_columns( $columns ) {
		$columns['mio_mobile']      = 'موبایل ورود';
		$columns['mio_national_id'] = 'کد ملی';
		return $columns;
	}

	public static function users_column_value( $value, $column_name, $user_id ) {
		if ( 'mio_mobile' === $column_name ) {
			$mobile = (string) get_user_meta( $user_id, self::META_E164, true );
			return $mobile ? esc_html( MIO_Utils::mask_mobile( $mobile ) ) : '—';
		}
		if ( 'mio_national_id' === $column_name ) {
			$national_id = (string) get_user_meta( $user_id, self::META_NATIONAL_ID, true );
			return $national_id ? esc_html( MIO_Utils::mask_national_id( $national_id ) ) : '—';
		}
		return $value;
	}
}
