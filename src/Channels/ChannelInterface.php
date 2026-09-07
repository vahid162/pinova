<?php

namespace Pinova\Channels;

use Exception;

interface ChannelInterface {

	/**
	 * @param string $identifier
	 * @param int    $code
	 *
	 * @return bool
	 *
	 * @throws Exception
	 */
	public static function send_code( string $identifier, int $code ): bool;

	/**
	 * @param string $identifier
	 * @param string $message
	 *
	 * @return bool
	 *
	 * @throws Exception
	 */
	public static function send_message( string $identifier, string $message ): bool;
}
