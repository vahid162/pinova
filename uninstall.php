<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

if ( null === \Illuminate\Database\Eloquent\Model::getConnectionResolver() ) {
	new \Nabik_Net_Database();
}

\Pinova\Install::uninstall();
