<?php

namespace Pinova\Logging;

interface HandlerInterface {

	/**
	 * Persist an already-sanitized log record.
	 *
	 * Logging must never interrupt authentication or another user request.
	 *
	 * @param array<string, mixed> $record
	 */
	public function write( array $record ): void;
}
