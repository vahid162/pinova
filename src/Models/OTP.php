<?php

namespace Pinova\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Pinova\Helpers\IP;

/**
 * Class OTP
 *
 * @package Pinova\Models
 *
 * @property int         $id
 * @property int|null    $user_id
 * @property string|null $flow_id
 * @property string      $identifier
 * @property string      $code
 * @property string      $ip_address
 * @property int         $attempts
 * @property string      $type
 * @property array       $channels
 * @property Carbon      $expires_at
 * @property Carbon|null $verified_at
 */
class OTP extends Model {

	const TYPE_LOGIN    = 'login';
	const TYPE_REGISTER = 'register';
	const TYPE_FORGET   = 'forget';

	protected $table = 'pinova_otp';

	protected $fillable = [
		'user_id',
		'flow_id',
		'identifier',
		'code',
		'ip_address',
		'attempts',
		'type',
		'channels',
		'expires_at',
		'verified_at',
	];

	public $timestamps = false;

	public $casts = [
		'channels'    => 'array',
		'expires_at'  => 'datetime',
		'verified_at' => 'datetime',
	];

	public function save( array $options = [] ) {

		if ( empty( $this->id ) ) {
			$this->code       = wp_hash_password( $this->code );
			$this->ip_address = $this->ip_address ?: IP::get();
			$this->expires_at = $this->expires_at ?: Carbon::now()->addMinutes( 3 );
		}

		return parent::save( $options );
	}

	public function hasType( $types ): bool {

		$types = Arr::wrap( $types );

		return in_array( $this->type, $types, true );
	}

	public function isVerified(): bool {
		return ! is_null( $this->verified_at );
	}

	public function isExpired(): bool {
		return $this->expires_at->isPast();
	}

	public function markVerified(): bool {
		$verified_at = Carbon::now();
		$claimed     = 1 === static::query()
			->whereKey( $this->id )
			->whereNull( 'verified_at' )
			->where( 'attempts', '<', 5 )
			->where( 'expires_at', '>', $verified_at )
			->update( [ 'verified_at' => $verified_at ] );

		if ( $claimed ) {
			$this->verified_at = $verified_at;
		}

		return $claimed;
	}

	public function incrementAttempts(): bool {
		$incremented = 1 === static::query()
			->whereKey( $this->id )
			->whereNull( 'verified_at' )
			->where( 'attempts', '<', 5 )
			->where( 'expires_at', '>', Carbon::now() )
			->increment( 'attempts' );

		if ( $incremented ) {
			$this->refresh();
		}

		return $incremented;
	}
}
