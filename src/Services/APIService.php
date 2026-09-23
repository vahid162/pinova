<?php

namespace Pinova\Services;

use Pinova\API\AdminAPI;
use Pinova\API\BlocksAPI;
use Pinova\API\GatewayAPI;
use Pinova\API\RestAPI;
use Pinova\API\UserAPI;
use Pinova\Integrations\Woocommerce\API as WooCommerceAPI;

class APIService {

	public function __construct() {
		add_filter( 'rest_post_dispatch', [ RestAPI::class, 'correlation_header' ], 10, 3 );

		new AdminAPI();
		new GatewayAPI();
		new UserAPI();
		new BlocksAPI();

		// Integrations
		new WooCommerceAPI();
	}
}
