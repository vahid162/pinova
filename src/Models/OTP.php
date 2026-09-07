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

	const TYPE_LOGIN = 'login';
	const TYPE_REGISTER = 'register';
	const TYPE_FORGET = 'forget';

	protected $table = 'pinova_otp';

	protected $fillable = [
		'user_id',
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
			$this->ip_address = IP::get();
			$this->expires_at = Carbon::now()->addMinutes( 3 );
		}

		return parent::save( $options );
	}

	public function hasType( $types ): bool {

		$types = Arr::wrap( $types );

		return in_array( $this->type, $types );
	}

	public function isVerified(): bool {
		return ! is_null( $this->verified_at );
	}

	public function isExpired(): bool {
		return $this->expires_at->isPast();
	}

	public function markVerified(): void {
		$this->verified_at = Carbon::now();
		$this->save();
	}

	public function incrementAttempts(): void {
		$this->attempts += 1;
		$this->save();
	}

}
