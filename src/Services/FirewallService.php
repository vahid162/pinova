<?php

namespace Pinova\Services;

use Carbon\Carbon;
use Pinova\Helpers\IP;
use Pinova\Models\Block;

class FirewallService {

	/**
	 * @param string $identifier
	 * @param int    $minutes
	 *
	 * @return Block
	 */
	public static function block( string $identifier, int $minutes ): Block {
		/** @var Block $blockList */
		$blockList = Block::query()->updateOrCreate( [
			'identifier' => $identifier,
		], [
			'blocked_until' => Carbon::now()->addMinutes( $minutes ),
		] );

		return $blockList;
	}

	/**
	 * @param string $identifier
	 *
	 * @return Block|null
	 */
	public static function is_blocked( string $identifier ): ?Block {

		/** @var Block $blockList */
		$blockList = Block::query()
		                  ->where( 'identifier', $identifier )
		                  ->where( 'blocked_until', '>', Carbon::now() )
		                  ->first();

		return $blockList;
	}

	public static function is_ip_blocked(): ?Block {
		return self::is_blocked( IP::get() );
	}

	public static function delete_expired() {
		Block::query()
		     ->where( 'blocked_until', '<', Carbon::now() )
		     ->delete();
	}
}