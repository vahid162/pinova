<?php

namespace Pinova;

use Illuminate\Database\Schema\Blueprint;
use Nabik_Net_Database;

class Install extends \Nabik\Utils\V1\Install {

	public function tasks() {
		self::create_tables();
	}

	public static function create_tables() {

		try {
			Nabik_Net_Database::Schema()->table( 'users', function ( Blueprint $table ) {
				$table->unique( 'user_login', 'user_login_pinova_unique' );
			} );
		} catch ( \Exception $e ) {
		}

		if ( ! Nabik_Net_Database::Schema()->hasTable( 'pinova_otp' ) ) {

			Nabik_Net_Database::Schema()->create( 'pinova_otp', function ( Blueprint $table ) {
				$table->bigIncrements( 'id' );
				$table->foreignId( 'user_id' )->nullable();
				$table->string( 'identifier' );
				$table->string( 'code' );
				$table->ipAddress( 'ip_address' )->index();
				$table->unsignedTinyInteger( 'attempts' )->default( 0 );
				$table->string( 'type', 25 );
				$table->json( 'channels' );
				$table->timestamp( 'expires_at' )->nullable();
				$table->timestamp( 'verified_at' )->nullable();
				$table->index( [ 'identifier', 'expires_at' ] );
			} );

		}

		if ( ! Nabik_Net_Database::Schema()->hasTable( 'pinova_blocks' ) ) {

			Nabik_Net_Database::Schema()->create( 'pinova_blocks', function ( Blueprint $table ) {
				$table->bigIncrements( 'id' );
				$table->string( 'identifier' )->unique();
				$table->foreignId( 'blocked_by' )->nullable();
				$table->timestamp( 'blocked_until' )->nullable();
			} );

		}

	}

}