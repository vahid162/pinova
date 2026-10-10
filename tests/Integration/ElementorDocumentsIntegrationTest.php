<?php

declare(strict_types=1);

namespace ElementorPro\Modules\Woocommerce\Documents {
	// Behavioral doubles only; proprietary Elementor Pro source is not bundled.
	class Product {
		public $post;
		public int $calls = 0;
		public function __construct( $post = null ) { $this->post = $post; }
		public function get_post() { return $this->post; }
		public function enqueue_scripts(): void {
			if ( null === $this->post ) { throw new \LogicException( 'Postless document callback executed.' ); }
			++$this->calls;
		}
		public function other_callback(): void {}
	}
	class Product_Archive extends Product {}
}

namespace Pinova\Tests\Integration {
	use ElementorPro\Modules\Woocommerce\Documents\Product;
	use ElementorPro\Modules\Woocommerce\Documents\Product_Archive;
	use Pinova\Integrations\Woocommerce\ElementorDocuments;

	/**
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class ElementorDocumentsIntegrationTest extends \WP_UnitTestCase {
		public function test_native_hook_skips_only_postless_prototypes_and_preserves_real_documents(): void {
			define( 'ELEMENTOR_VERSION', '4.3.3' );
			define( 'ELEMENTOR_PRO_VERSION', '4.3.0' );
			global $wp_filter;
			self::assertSame( 10, has_action( 'wp_enqueue_scripts', [ ElementorDocuments::class, 'guard_enqueue_scripts' ] ) );
			// Isolate script callbacks, then exercise the real WordPress priority dispatch.
			$wp_filter['wp_enqueue_scripts'] = new \WP_Hook();
			add_action( 'wp_enqueue_scripts', [ ElementorDocuments::class, 'guard_enqueue_scripts' ], 10 );
			$post = new \WP_Post( (object) [ 'ID' => 123 ] );
			$valid = [ new Product( $post ), new Product_Archive( $post ) ];
			$empty = [ new Product(), new Product_Archive() ];
			foreach ( array_merge( $valid, $empty ) as $document ) {
				add_action( 'wp_enqueue_scripts', [ $document, 'enqueue_scripts' ], 11 );
			}
			$unrelated_calls = 0;
			add_action( 'wp_enqueue_scripts', static function() use ( &$unrelated_calls ): void { ++$unrelated_calls; }, 11 );
			do_action( 'wp_enqueue_scripts' );
			do_action( 'wp_enqueue_scripts' );
			self::assertSame( 2, $unrelated_calls );
			foreach ( $valid as $document ) {
				self::assertSame( 2, $document->calls );
				self::assertSame( $post, $document->get_post() );
				self::assertSame( 11, has_action( 'wp_enqueue_scripts', [ $document, 'enqueue_scripts' ] ) );
			}
			foreach ( $empty as $document ) {
				self::assertSame( 0, $document->calls );
				self::assertFalse( has_action( 'wp_enqueue_scripts', [ $document, 'enqueue_scripts' ] ) );
			}
		}

		public function test_unrelated_methods_priorities_classes_and_non_null_posts_remain(): void {
			define( 'ELEMENTOR_VERSION', '4.3.3' );
			define( 'ELEMENTOR_PRO_VERSION', '4.3.0' );
			$empty = new Product();
			$derived = new class() extends Product {};
			$callbacks = [
				[ [ $empty, 'enqueue_scripts' ], 12 ],
				[ [ $empty, 'other_callback' ], 11 ],
				[ [ $derived, 'enqueue_scripts' ], 11 ],
				[ [ new Product( false ), 'enqueue_scripts' ], 11 ],
				[ '__return_false', 11 ],
			];
			foreach ( $callbacks as [ $callback, $priority ] ) { add_action( 'wp_enqueue_scripts', $callback, $priority ); }
			ElementorDocuments::guard_enqueue_scripts();
			foreach ( $callbacks as [ $callback, $priority ] ) { self::assertSame( $priority, has_action( 'wp_enqueue_scripts', $callback ) ); }
		}

		/** @dataProvider unsupported_versions */
		public function test_missing_or_uncharacterized_dependencies_are_untouched( ?string $core, ?string $pro ): void {
			if ( null !== $core ) { define( 'ELEMENTOR_VERSION', $core ); }
			if ( null !== $pro ) { define( 'ELEMENTOR_PRO_VERSION', $pro ); }
			$callback = [ new Product(), 'enqueue_scripts' ];
			add_action( 'wp_enqueue_scripts', $callback, 11 );
			ElementorDocuments::guard_enqueue_scripts();
			self::assertSame( 11, has_action( 'wp_enqueue_scripts', $callback ) );
		}

		public static function unsupported_versions(): array {
			return [ [ null, null ], [ '4.3.3', null ], [ null, '4.3.0' ], [ '4.3.4', '4.3.0' ], [ '4.3.3', '4.3.1' ] ];
		}
	}
}
