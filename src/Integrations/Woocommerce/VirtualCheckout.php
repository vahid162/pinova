<?php

namespace Pinova\Integrations\Woocommerce;

use Pinova\Logging\EventThrottle;
use Pinova\Pinova;
use Throwable;
use WC_Customer;
use WC_Order;
use WC_Payment_Gateway;
use WC_Product;

/** Optional address reduction for verified virtual carts on classic checkout. */
final class VirtualCheckout {

	public const SETTING = 'woocommerce_virtual_checkout';

	private const BILLING_FIELDS = [
		'billing_company',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_postcode',
		'billing_country',
		'billing_state',
	];

	/** @var string[] Fields actually removed during this checkout request. */
	private array $removed = [];

	public function __construct() {
		add_action( 'after_setup_theme', [ $this, 'replace_legacy_callback' ], PHP_INT_MAX );
		add_filter( 'woocommerce_checkout_fields', [ $this, 'checkout_fields' ], 10 );
		add_filter( 'woocommerce_checkout_posted_data', [ $this, 'posted_data' ], 10 );
		add_action( 'woocommerce_checkout_update_order_review', [ $this, 'update_order_review' ], PHP_INT_MAX, 0 );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'preserve_order_address' ], 10, 2 );
	}

	/** @param mixed $value */
	public static function sanitize_enabled( $value ): bool {
		return in_array( $value, [ true, 1, '1' ], true );
	}

	public static function enabled(): bool {
		return self::sanitize_enabled( Pinova::get_option( 'general.' . self::SETTING, false ) );
	}

	/** Remove only the characterized child-theme callback after themes register. */
	public function replace_legacy_callback(): void {
		if ( self::enabled() ) {
			remove_filter( 'woocommerce_checkout_fields', 'pante_custom_override_checkout_fields', 10 );
		}
	}

	public static function is_block_checkout(): bool {
		return function_exists( 'wc_get_page_id' ) && function_exists( 'has_block' )
			&& has_block( 'woocommerce/checkout', wc_get_page_id( 'checkout' ) );
	}

	/** Stable settings status; never constructs checkout or exposes customer data. */
	public static function description(): string {
		if ( ! function_exists( 'WC' ) ) {
			return 'ووکامرس فعال نیست.';
		}
		if ( self::is_block_checkout() ) {
			return 'صفحهٔ تسویه‌حساب از Checkout Blocks استفاده می‌کند؛ این قابلیت فقط تسویه‌حساب کلاسیک را پشتیبانی می‌کند و در Blocks اجرا نمی‌شود.';
		}
		return 'فقط برای تسویه‌حساب کلاسیک و سبد غیرخالیِ کاملاً مجازی. نام، تلفن، ایمیل و فیلدهای افزونه‌ها حفظ می‌شوند. نیاز به نشانی ارسال، مالیات وابسته به نشانی مشتری یا درگاهِ بدون قراردادِ مشخص، فرم کامل را حفظ می‌کند. در حالت فعال فقط فیلتر قدیمی شناخته‌شدهٔ قالب جایگزین می‌شود.';
	}

	/** @param array<string, array<string, mixed>> $fields
	 * @return array<string, array<string, mixed>>
	 */
	public function checkout_fields( array $fields ): array {
		$this->removed = [];
		$original      = $fields;
		try {
			if ( ! self::enabled() || self::is_block_checkout() || ! $this->virtual_cart() ) {
				return $fields;
			}
			// Extensions can require a native shipping address even for virtual products.
			if ( WC()->cart->needs_shipping_address() ) {
				return $fields;
			}
			// Customer-location taxes require the ordinary editable address, including postcode/state.
			if ( wc_tax_enabled() && 'base' !== get_option( 'woocommerce_tax_based_on', 'shipping' ) ) {
				return $fields;
			}
			$required = $this->gateway_fields();
			if ( null === $required ) {
				return $fields;
			}
			foreach ( self::BILLING_FIELDS as $key ) {
				if ( isset( $fields['billing'][ $key ] ) && ! in_array( $key, $required, true ) ) {
					unset( $fields['billing'][ $key ] );
					$this->removed[] = $key;
				}
			}
			if ( ! in_array( 'order_comments', $required, true ) ) {
				unset( $fields['order']['order_comments'] );
			}
		} catch ( Throwable $throwable ) {
			$fields        = $original;
			$this->removed = [];
			self::record_failure();
			unset( $throwable );
		}
		return $fields;
	}

	/** Verify persisted products as well as cart objects; never trust an empty/partial cart. */
	private function virtual_cart(): bool {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return false;
		}
		$items = $cart->get_cart();
		if ( ! $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['quantity'] ) || ! is_numeric( $item['quantity'] )
				|| ! is_finite( (float) $item['quantity'] ) || 0 >= (float) $item['quantity']
				|| ! ( ( $item['data'] ?? null ) instanceof WC_Product ) ) {
				return false;
			}
			$product_id   = self::product_id( $item['product_id'] ?? null );
			$variation_id = self::product_id( $item['variation_id'] ?? 0 );
			if ( 0 >= $product_id || 0 > $variation_id ) {
				return false;
			}
			$id      = $variation_id ?: $product_id;
			$product = wc_get_product( $id );
			if ( ! $product instanceof WC_Product || ! $product->exists() || ! $product->is_virtual()
				|| $id !== $item['data']->get_id() || ! $item['data']->is_virtual()
				|| ( $variation_id && ( ! $product->is_type( 'variation' ) || $product_id !== $product->get_parent_id() ) )
				|| ( ! $variation_id && ( $product->is_type( 'variation' ) || $product->is_type( 'variable' ) ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param mixed $id */
	private static function product_id( $id ): int {
		return ( is_int( $id ) && 0 <= $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ? (int) $id : -1;
	}

	/** @return string[]|null Unknown or malformed requirements preserve the complete form. */
	private function gateway_fields(): ?array {
		$required = [];
		$enabled  = false;
		// A default zero total before calculation is not proof that payment is unnecessary.
		$total      = WC()->cart->get_total( 'edit' );
		$calculated = is_numeric( $total ) && is_finite( (float) $total ) && 0 <= (float) $total;
		foreach ( WC()->cart->get_cart() as $item ) {
			foreach ( [ 'line_total', 'line_tax' ] as $key ) {
				if ( ! isset( $item[ $key ] ) || ! is_numeric( $item[ $key ] ) || ! is_finite( (float) $item[ $key ] ) ) {
					$calculated = false;
				}
			}
		}
		// A coupon may be removed without rebuilding address inputs: check enabled gateways even while free.
		// Inspect enabled registrations, not availability: extensions may read checkout during is_available().
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( ! $gateway instanceof WC_Payment_Gateway ) {
				return null;
			}
			if ( 'yes' !== $gateway->enabled ) {
				continue;
			}
			$enabled = true;
			$native  = in_array( get_class( $gateway ), [ 'WC_Gateway_BACS', 'WC_Gateway_Cheque', 'WC_Gateway_COD' ], true );
			/**
			 * Declare characterized gateway requirements without changing native validation.
			 * null: unknown, []: no address/notes needed, string[]: retain these known fields.
			 * The contract covers every enabled gateway, even if currently unavailable.
			 */
			$keys = apply_filters( 'pinova/virtual_checkout/gateway_required_fields', $native ? [] : null, $gateway );
			if ( ! is_array( $keys ) || array_filter(
				$keys,
				static fn( $key ): bool => ! is_string( $key ) || ! in_array( $key, array_merge( self::BILLING_FIELDS, [ 'order_comments' ] ), true )
			) ) {
				return null;
			}
			$required = array_merge( $required, $keys );
		}
		return $enabled || ( $calculated && ! WC()->cart->needs_payment() ) ? array_values( array_unique( $required ) ) : null;
	}

	/** Native order-review AJAX otherwise saves empty aliases for absent address inputs. */
	public function update_order_review(): void {
		if ( ! self::enabled() || self::is_block_checkout() ) {
			return;
		}
		$this->reconcile_removed_fields();
		foreach ( [
			'billing_country'   => 'country',
			'billing_state'     => 'state',
			'billing_postcode'  => 'postcode',
			'billing_city'      => 'city',
			'billing_address_1' => 'address',
			'billing_address_2' => 'address_2',
		] as $key => $alias ) {
			if ( in_array( $key, $this->removed, true ) ) {
				// WooCommerce has checked the AJAX nonce before this action. Absence produces native null setters.
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				unset( $_POST[ $alias ], $_POST[ 's_' . $alias ] );
			}
		}
	}

	/** Resolve only definitions that remain absent after all checkout-field filters. */
	private function reconcile_removed_fields(): void {
		$final_fields  = WC()->checkout()->get_checkout_fields( 'billing' );
		$this->removed = array_values( array_filter( $this->removed, static fn( string $key ): bool => ! array_key_exists( $key, $final_fields ) ) );
	}

	/** Prevent native shipping-to-billing fallback from clearing omitted stored addresses.
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public function posted_data( array $data ): array {
		if ( ! self::enabled() || self::is_block_checkout() || ! $this->removed ) {
			return $data;
		}
		// Parsing has completed: reading the cached final fields cannot re-enter early parsing.
		$this->reconcile_removed_fields();
		foreach ( $this->removed as $key ) {
			// WC normally omits removed billing fields. Do not admit a synthetic posted address.
			unset( $data[ $key ], $data[ 'shipping_' . substr( $key, 8 ) ] );
		}
		return $data;
	}

	/** Snapshot existing addresses on the order without reintroducing them into customer updates.
	 * @param array<string, mixed> $data
	 */
	public function preserve_order_address( WC_Order $order, array $data ): void {
		if ( ! self::enabled() || self::is_block_checkout() || ! $this->removed || ! WC()->customer instanceof WC_Customer ) {
			return;
		}
		try {
			foreach ( $this->removed as $key ) {
				foreach ( [ $key, 'shipping_' . substr( $key, 8 ) ] as $address_key ) {
					if ( ! array_key_exists( $address_key, $data ) ) {
						$order->{'set_' . $address_key}( WC()->customer->{'get_' . $address_key}( 'edit' ) );
					}
				}
			}
		} catch ( Throwable $throwable ) {
			self::record_failure();
			unset( $throwable );
		}
	}

	private static function record_failure(): void {
		EventThrottle::log(
			'integration.operation_failed',
			[
				'operation' => 'woocommerce',
				'reason'    => 'virtual_checkout_contract_failed',
			]
		);
	}
}
