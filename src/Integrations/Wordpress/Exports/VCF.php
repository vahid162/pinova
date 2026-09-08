<?php

namespace Pinova\Integrations\Wordpress\Exports;

use Pinova\Integrations\Wordpress\ExportUsers;
use Pinova\Objects\Mobile;
use Pinova\Services\UserService;
use WP_User;

class VCF {

	private const ACTION = 'pinova_export_users_vcf';
	private const NONCE_ACTION = 'pinova_export_users_vcf';

	public function __construct() {
		add_action( 'admin_footer', [ $this, 'inject_export_button' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_export' ] );
	}

	public function handle_export(): void {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دریافت خروجی کاربران را ندارید.', 'pinova' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::NONCE_ACTION );

		try {
			$this->output( $this->generate( ExportUsers::get_users() ) );
		} catch ( \RuntimeException $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}

		exit;
	}

	public function inject_export_button(): void {
		global $pagenow;

		if ( 'users.php' !== $pagenow || ! current_user_can( 'list_users' ) ) {
			return;
		}

		$export_url = add_query_arg(
			array_merge( $_GET, [ 'action' => self::ACTION ] ),
			admin_url( 'admin-post.php' )
		);
		$export_url = wp_nonce_url( $export_url, self::NONCE_ACTION );
		?>
		<script>
            document.addEventListener("DOMContentLoaded", function () {
                const add_new = document.querySelector(".page-title-action");
                if (!add_new) return;

                const btn = document.createElement("a");
                btn.className = "page-title-action button-primary";
                btn.textContent = "برون‌بری مخاطبین";
                btn.style.marginInlineStart = "5px";
                btn.style.color = "var(--wp-admin-theme-color)";
                btn.href = "<?php echo esc_url_raw( $export_url ); ?>";

                add_new.insertAdjacentElement("afterend", btn);
            });
		</script>
		<?php
	}

	/**
	 * @param WP_User[] $users
	 *
	 * @return string
	 */
	public function generate( array $users ): string {

		$cards     = [];
		$site_name = self::escape( (string) get_bloginfo( 'name' ) );
		$site_url  = self::escape( (string) get_site_url() );

		foreach ( $users as $user ) {

			$user_id = $user->get( 'ID' );

			$first_name   = self::escape( (string) $user->get( 'first_name' ) );
			$last_name    = self::escape( (string) $user->get( 'last_name' ) );
			$display_name = self::escape( (string) $user->get( 'display_name' ) );
			$user_login   = self::escape( (string) $user->get( 'user_login' ) );

			$full_name = ! empty( $first_name ) || ! empty( $last_name )
				? trim( "$first_name $last_name" )
				: $display_name;

			$note_fallback = [];

			$card = [];

			$card['vcard_begin']   = 'BEGIN:VCARD';
			$card['vcard_version'] = 'VERSION:3.0';

			$card['name']      = "N:{$last_name};{$first_name};;;";
			$card['full_name'] = "FN:{$full_name}";

			$company  = self::escape( (string) $user->get( 'billing_company' ) );
			$org_name = ! empty( $company ) ? "{$company};{$site_name}" : $site_name;

			$card['organization'] = "ORG:{$org_name}";

			$mobiles   = [];
			$mobiles[] = UserService::get_mobile( $user_id );

			foreach ( UserService::mobile_possible_meta_keys() as $meta_key ) {

				$extracted_mobile = trim( $user->get( $meta_key ) );

				if ( empty( $extracted_mobile ) ) {
					continue;
				}

				$mobile = new Mobile( $extracted_mobile );

				if ( ! $mobile->is_valid() ) {
					continue;
				}

				$mobiles[] = $mobile->get_formatted();

			}

			$mobiles = array_values( array_unique( array_filter( $mobiles ) ) );
			$mobiles = (array) apply_filters( 'pinova/export_users_vcf_mobiles', $mobiles, $user_id );

			foreach ( $mobiles as $index => $mobile ) {
				if ( ! is_scalar( $mobile ) ) {
					continue;
				}

				$mobile = self::escape( (string) $mobile );

				$type = $index === 0 ? 'CELL' : 'X-PHONE' . $index;

				$key = strtolower( $type );

				if ( isset( $card[ $key ] ) ) {
					$key .= '_' . $index;
				}

				$card[ $key ] = "TEL;TYPE={$type}:{$mobile}";

			}

			$email = $user->get( 'user_email' );

			if ( is_email( $email ) ) {
				$card['email'] = 'EMAIL;TYPE=INTERNET:' . self::escape( $email );
			}

			$address_1 = self::escape( (string) $user->get( 'billing_address_1' ) );
			$city      = self::escape( ExportUsers::state_city_name( (string) $user->get( 'billing_city' ) ) );
			$state     = self::escape( ExportUsers::state_city_name( (string) $user->get( 'billing_state' ) ) );
			$post      = self::escape( (string) $user->get( 'billing_postcode' ) );
			$country   = self::escape( (string) $user->get( 'billing_country' ) );

			if ( strtolower( $country ) === 'ir' ) {
				$country = 'ایران';
			}

			if ( ! empty( $address_1 ) || ! empty( $city ) ) {
				$card['address'] = "ADR;TYPE=HOME:;;{$address_1};{$city};{$state};{$post};{$country}";
			}

			$card['site_url'] = "URL;TYPE=WORK:{$site_url}";

			$edit_profile_url = self::escape( (string) get_edit_user_link( $user_id ) );

			if ( ! empty( $edit_profile_url ) ) {
				$card['edit_profile_url'] = "URL;TYPE=WORK:{$edit_profile_url}";
			}

			$user_url = self::escape( (string) $user->get( 'user_url' ) );

			if ( ! empty( $user_url ) ) {
				$card['user_url'] = "URL;TYPE=HOME:{$user_url}";
			}

			$card['x_site']      = "X-SITE:{$site_url}";
			$card['x_user_link'] = "X-WP-USER-URL:{$user_url}";

			$description = $user->get( 'description' );

			if ( ! empty( $description ) ) {
				$card['note'] = 'NOTE:' . self::escape( $description );
			}

			$registered = $user->get( 'user_registered' );

			if ( ! empty( $registered ) ) {

				$value                     = wp_date( 'Y-m-d', strtotime( $registered ) );
				$card['registration_date'] = "X-WP-Registered:{$value}";
				$note_fallback[]           = "زمان ثبت نام: {$value}";

			}

			$roles         = array_map( [ self::class, 'escape' ], ExportUsers::translate_roles( $user->roles ) );
			$card['roles'] = "X-WP-Roles:" . implode( ',', $roles );

			if ( ! empty( $roles ) ) {
				$note_fallback[] = "نقش کاربر: " . implode( ', ', $roles );
			}

			$card['wp_username'] = "X-WP-USERNAME:{$user_login}";
			$card['wp_id']       = "X-WP-ID:{$user_id}";
			$note_fallback[]     = "نام کاربری: {$user_login}";
			$note_fallback[]     = "نام نمایشی: {$display_name}";
			$note_fallback[]     = "شناسه کاربر: {$user_id}";

			$card['note_fallback'] = 'NOTE:' . implode( '\n', $note_fallback );

			$card['vcard_end'] = 'END:VCARD';

			$card = apply_filters( 'pinova/export_users_vcf_card', $card, $user_id );

			$cards[] = implode( "\r\n", $card );
		}

		return implode( "\r\n", $cards );
	}

	private static function escape( string $value ): string {
		$value = str_replace( "\r\n", "\n", $value );
		$value = str_replace( "\r", "\n", $value );

		return str_replace(
			[ '\\', "\n", ';', ',' ],
			[ '\\\\', '\\n', '\\;', '\\,' ],
			$value
		);
	}

	protected function output( string $contents ): void {

		$filename = 'pinova-exported-users-' . wp_date( 'Y-m-d' ) . '.vcf';

		header( 'Content-Type: text/vcard; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: max-age=0' );

		echo $contents;
	}
}
