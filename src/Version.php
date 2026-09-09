<?php

namespace Pinova;

use Exception;
use Illuminate\Database\Schema\Blueprint;
use Nabik_Net_Database;
use Pinova\Gateways\MaxSMS;
use Pinova\Models\OTP;
use Pinova\Services\SMSService;

class Version extends \Nabik\Utils\V1\Version {

	protected string $current_version = PINOVA_VERSION;

	public function updated() {

		flush_rewrite_rules();

	}

	public function update_107() {

		// Change message code option id
		$message_code = Pinova::get_option( 'sms.message' );
		Pinova::set_option( 'sms.message_code', $message_code );

		// Fix maxsms panel id
		$gateway = Pinova::get_option( 'sms.gateway' );

		if ( $gateway == 'Pinova\Gateways\IPPanel' ) {
			$gateway = MaxSMS::class;
			Pinova::set_option( 'sms.gateway', MaxSMS::class );
		} elseif ( empty( $gateway ) ) {
			return;
		}

		// Move old general option to gateway option
		try {
			$gateway = SMSService::get_gateway_instance( $gateway );
		} catch ( Exception $e ) {
			wp_die( $e->getMessage() );
		}

		foreach ( get_option( 'pinova_sms', [] ) as $key => $value ) {
			$gateway->set_option( $key, $value );

			if ( $key == 'token' ) {
				$gateway->set_option( 'api_key', $value );
			}
		}

	}

	public function update_108() {
		global $wpdb;

		Nabik_Net_Database::Schema()->table( 'pinova_blocks', function ( Blueprint $table ) {
			$table->after( 'identifier', function ( Blueprint $table ) {
				$table->foreignId( 'blocked_by' )->nullable();
			} );
		} );

		$table = $wpdb->prefix . 'pinova_blocks';
		$query = sprintf( 'ALTER TABLE `%s` MODIFY COLUMN `blocked_until` timestamp NULL;', $table );
		Nabik_Net_Database::DB()->statement( $query );
	}

	public function update_109() {

		/** @var OTP[] $OTPs */
		$OTPs = OTP::all();

		foreach ( $OTPs as $OTP ) {
			$OTP->channels = array_fill_keys( $OTP->channels, true );
			$OTP->save();
		}

	}

	public function update_115() {
		global $wpdb;

		$indexes = Nabik_Net_Database::DB()->select( "SHOW INDEX FROM `{$wpdb->users}`" );

		$email_unique_indexes = collect( $indexes )
			->where( 'Non_unique', 0 )
			->where( 'Column_name', 'user_email' )
			->pluck( 'Key_name' );

		foreach ( $email_unique_indexes as $email_unique_index ) {
			Nabik_Net_Database::DB()->statement( sprintf( "ALTER TABLE `{$wpdb->users}` DROP INDEX `%s`", $email_unique_index ) );
		}

		Nabik_Net_Database::DB()->statement( "UPDATE `{$wpdb->users}` SET `user_email` = '' WHERE `user_email` IS NULL" );

		Nabik_Net_Database::DB()->statement( "ALTER TABLE `{$wpdb->users}` MODIFY COLUMN `user_email` VARCHAR(100) NOT NULL DEFAULT ''" );

		$site_host = parse_url( site_url(), PHP_URL_HOST );
		$query     = sprintf( "UPDATE `%s` SET `user_email` = '' WHERE `user_email` LIKE 'nomail%%%s'", $wpdb->users, $site_host );

		Nabik_Net_Database::DB()->statement( $query );
	}

	public function update_123() {
		Install::create_wordpress_tables();
	}

}
