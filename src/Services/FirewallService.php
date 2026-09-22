<?php

namespace Pinova\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Pinova\Helpers\IP;
use Pinova\Helpers\Number;
use Pinova\Models\Block;
use Pinova\Objects\Identifier;

class FirewallService {
	public const IDENTIFIER_TYPES = [
		'mobile',
		'email',
		'ip',
		'username',
	];

	/**
	 * @param string $identifier
	 * @param int    $minutes
	 *
	 * @return Block
	 */
	public static function block( string $identifier, int $minutes ): Block {
		$normalized_ip = self::normalize_identifier( 'ip', $identifier );
		$identifier    = $normalized_ip ?? $identifier;

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
		$candidates      = [ $identifier ];
		$identifier_type = self::identifier_type( $identifier );
		$normalized      = self::normalize_identifier( $identifier_type, $identifier );

		if ( null !== $normalized ) {
			$candidates[] = $normalized;
		}

		$candidates = array_values( array_unique( $candidates ) );

		/** @var Block $blockList */
		$blockList = Block::query()
		                  ->whereIn( 'identifier', $candidates )
		                  ->where( static function ( Builder $query ): void {
			                  $query->whereNull( 'blocked_until' )
			                        ->orWhere( 'blocked_until', '>', Carbon::now() );
		                  } )
		                  ->first();

		return $blockList;
	}

	public static function is_ip_blocked(): ?Block {
		return self::is_blocked( IP::get() );
	}

	public static function delete_expired(): int {
		return Block::query()
		            ->whereNotNull( 'blocked_until' )
		            ->where( 'blocked_until', '<', Carbon::now() )
		            ->delete();
	}

	public static function normalize_identifier( string $identifier_type, string $raw_identifier ): ?string {
		$value = trim( Number::en( $raw_identifier ) );
		if ( '' === $value ) {
			return null;
		}

		switch ( $identifier_type ) {
			case 'mobile':
				$identifier = new Identifier( $value );

				return $identifier->is_mobile() ? $identifier->get_value() : null;
			case 'email':
				if ( ! is_email( $value ) ) {
					return null;
				}

				return strtolower( sanitize_email( $value ) );
			case 'ip':
				if ( ! IP::is_valid( $value ) ) {
					return null;
				}

				$binary = inet_pton( $value );
				if ( false === $binary ) {
					return null;
				}

				$normalized = inet_ntop( $binary );

				return false === $normalized ? null : $normalized;
			case 'username':
				$identifier = new Identifier( $value );

				return $identifier->is_username() && ! IP::is_valid( $value )
					? $identifier->get_value()
					: null;
			default:
				return null;
		}
	}

	public static function identifier_type( string $identifier ): string {
		if ( IP::is_valid( $identifier ) ) {
			return 'ip';
		}

		$normalized = new Identifier( $identifier );

		return $normalized->is_valid() ? $normalized->get_type() : 'identifier';
	}
}
