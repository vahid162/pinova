<?php

namespace Pinova\Services;

use Pinova\API\AdminAPI;
use Pinova\API\BlocksAPI;
use Pinova\API\GatewayAPI;
use Pinova\API\UserAPI;
use Pinova\Integrations\Woocommerce\API as WooCommerceAPI;

class APIService {

	public function __construct() {
		new AdminAPI();
		new GatewayAPI();
		new UserAPI();
		new BlocksAPI();

		// Integrations
		new WooCommerceAPI();
	}

}