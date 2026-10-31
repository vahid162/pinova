<?php

declare(strict_types=1);

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$woocommerce = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( ! is_readable( $woocommerce ) ) {
			throw new RuntimeException( 'WooCommerce is required by the configured Pinova integration matrix.' );
		}

		require_once $woocommerce;

		$GLOBALS['pinova_doing_it_wrong_functions'] = [];
		add_action(
			'doing_it_wrong_run',
			static function ( string $function_name ): void {
				$GLOBALS['pinova_doing_it_wrong_functions'][] = $function_name;
			},
			10,
			1
		);

		$hpos = getenv( 'PINOVA_TEST_HPOS' );
		if ( ! in_array( $hpos, [ 'yes', 'no' ], true ) ) {
			throw new RuntimeException( 'PINOVA_TEST_HPOS must be forwarded as yes or no.' );
		}

		update_option( 'woocommerce_custom_orders_table_enabled', $hpos );

		require dirname( __DIR__ ) . '/pinova.php';

		if ( ! is_object( \Illuminate\Database\Eloquent\Model::getConnectionResolver() ) ) {
			throw new RuntimeException( 'Pinova must bootstrap its Illuminate connection on every fresh WordPress request.' );
		}
	}
);

require $_tests_dir . '/includes/bootstrap.php';

// Proof-branch-only synthetic salt; never part of the canonical plugin or a live site.
if ( '1' === getenv( 'PINOVA_LOG_ISOLATION_PROOF' ) ) {
    $proof_slot = static function ( string $ip, string $reason, string $salt ): int {
        return hexdec( substr( hash_hmac( 'sha256', inet_pton( $ip ) . '|' . $reason, $salt ), 0, 4 ) ) % 1024;
    };
    $proof_salt = null;
    for ( $proof_index = 0; $proof_index < 65536; ++$proof_index ) {
        $candidate = 'pinova-isolated-collision-proof:' . $proof_index;
        if ( $proof_slot( '192.0.2.232', 'invalid_code', $candidate ) === $proof_slot( '192.0.2.81', 'policy_rejected', $candidate ) ) {
            $proof_salt = $candidate;
            break;
        }
    }
    if ( null === $proof_salt ) {
        throw new RuntimeException( 'The controlled collision witness is unavailable.' );
    }
    add_filter( 'salt', static fn( $salt, $scheme ) => 'auth' === $scheme ? $proof_salt : $salt, PHP_INT_MAX, 2 );
    if ( $proof_slot( '192.0.2.232', 'invalid_code', wp_salt( 'auth' ) ) !== $proof_slot( '192.0.2.81', 'policy_rejected', wp_salt( 'auth' ) ) ) {
        throw new RuntimeException( 'The controlled collision witness was not installed.' );
    }
    echo "PINOVA_LOG_COLLISION_PROOF_READY=1" . PHP_EOL;
}
