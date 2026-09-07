<?php

namespace Pinova\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Blacklist
 *
 * @package Pinova\Models
 *
 * @property int    $id
 * @property string $identifier
 * @property int    $blocked_by
 * @property string $blocked_by_label
 * @property Carbon $blocked_until
 *
 * @method static Block create( array $attributes = [] )
 */
class Block extends Model {

	protected $table = 'pinova_blocks';

	protected $fillable = [
		'identifier',
		'blocked_by',
		'blocked_until',
	];

	public $timestamps = false;

	public $casts = [
		'blocked_until' => 'datetime',
	];

	public function getBlockedByLabelAttribute() {

		if ( is_null( $this->blocked_by ) ) {
			return 'سیستمی';
		}

		return get_userdata( $this->blocked_by )->display_name;
	}
}
