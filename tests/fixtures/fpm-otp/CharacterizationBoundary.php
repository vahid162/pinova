<?php
/** Test-only candidate. Passing this does not certify a later production adapter. */

declare(strict_types=1);

namespace Pinova\Tests\FPM;

/** Force a genuinely false native completion by finishing this synthetic request twice. */
function fastcgi_finish_request(): bool {
	if ( 'finish_false' === ( $GLOBALS['pinova_fpm_case'] ?? '' ) ) {
		\pinova_fpm_marker( \fastcgi_finish_request() ? 'prior_finish_ok' : 'prior_finish_false' );
	}
	return \fastcgi_finish_request();
}

final class CharacterizationBoundary {
	private bool $valid = true;
	private bool $post_dispatch = false;
	private bool $pre_serve = false;
	private bool $pre_echo = false;
	private bool $body_closed = false;
	private bool $late_closed = false;
	private string $body = '';
	private string $late = '';
	private int $late_level = 0;
	private array $expected;
	private \Closure $dispatch;

	public function __construct( private \WP_REST_Response $response, private \WP_REST_Request $request, callable $dispatch ) {
		$this->expected = $response->get_data();
		$this->dispatch = \Closure::fromCallable( $dispatch );
	}

	public function arm(): void {
		$buffers_safe = true;
		foreach ( ob_get_status( true ) as $buffer ) {
			$buffers_safe = $buffers_safe && 'default output handler' === $buffer['name'] && 0 === $buffer['buffer_used'];
		}
		if ( 'fpm-fcgi' !== PHP_SAPI || ! function_exists( 'fastcgi_finish_request' ) || headers_sent() || ! $buffers_safe ) {
			\pinova_fpm_marker( 'rejected_unsupported_or_output' );
			return;
		}
		ob_start( [ $this, 'observe_body' ] );
		add_filter( 'rest_post_dispatch', [ $this, 'post_dispatch' ], PHP_INT_MAX, 3 );
		add_filter( 'rest_pre_serve_request', [ $this, 'pre_serve' ], PHP_INT_MAX, 4 );
		add_filter( 'rest_pre_echo_response', [ $this, 'pre_echo' ], PHP_INT_MAX, 3 );
		// Scope the replacement to this armed request and preserve the native priority.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
		add_action( 'shutdown', [ $this, 'flush_core_buffers' ], 1 );
		register_shutdown_function( [ $this, 'finish' ] );
		\pinova_fpm_marker( 'armed' );
	}

	public function post_dispatch( $response, $server, $request ) {
		$this->post_dispatch = $response === $this->response && $request === $this->request
			&& 200 === $response->get_status() && $this->expected === $response->get_data();
		return $response;
	}

	public function pre_serve( $served, $response, $request, $server ) {
		$this->pre_serve = false === $served && $response === $this->response && $request === $this->request;
		return $served;
	}

	public function pre_echo( $data, $server, $request ) {
		$this->pre_echo = $request === $this->request && $data === $this->expected;
		return $data;
	}

	public function observe_body( string $chunk, int $phase ): string {
		$this->body .= $chunk;
		$this->valid = $this->valid && strlen( $this->body ) <= 8192 && 0 === ( $phase & PHP_OUTPUT_HANDLER_CLEAN );
		if ( 0 !== ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			$this->body_closed = true;
			\pinova_fpm_marker( 'body_observer_closed' );
		}
		return $chunk;
	}

	public function observe_late( string $chunk, int $phase ): string {
		$this->late .= $chunk;
		$this->valid = $this->valid && '' === $this->late && 0 === ( $phase & PHP_OUTPUT_HANDLER_CLEAN );
		if ( 0 !== ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			$this->late_closed = true;
		}
		return $chunk;
	}

	public function flush_core_buffers(): void {
		wp_ob_end_flush_all();
		ob_start( [ $this, 'observe_late' ] );
		$this->late_level = ob_get_level();
		\pinova_fpm_marker( 'core_flush_completed' );
	}

	public function finish(): void {
		$error = error_get_last();
		$fatal = null !== $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ], true );
		$status = ob_get_status();
		$observer = ( $status['name'] ?? '' ) === self::class . '::observe_late';
		$healthy = $this->valid && $this->post_dispatch && $this->pre_serve && $this->pre_echo && $this->body_closed
			&& ! $this->late_closed && $observer && $this->late_level === ob_get_level() && '' === ob_get_contents()
			&& ! $fatal && 200 === http_response_code() && $this->expected === json_decode( $this->body, true );
		if ( ! $healthy ) {
			\pinova_fpm_marker( 'rejected_final_response' );
			return;
		}
		ob_end_flush();
		\pinova_fpm_marker( 'finish_enter' );
		if ( true !== fastcgi_finish_request() ) {
			\pinova_fpm_marker( 'finish_false' );
			return;
		}
		\pinova_fpm_marker( 'finish_ok' );
		( $this->dispatch )();
	}
}
