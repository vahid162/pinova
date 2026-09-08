<?php

define( 'ABSPATH', getenv( 'WP_TESTS_ABSPATH' ) ?: '/wordpress/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WORDPRESS_DB_NAME' ) ?: 'tests-wordpress' );
define( 'DB_USER', getenv( 'WORDPRESS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) ?: 'password' );
define( 'DB_HOST', getenv( 'WORDPRESS_DB_HOST' ) ?: 'pinova-integration-db' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'pinova-integration-auth-key' );
define( 'SECURE_AUTH_KEY', 'pinova-integration-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'pinova-integration-logged-in-key' );
define( 'NONCE_KEY', 'pinova-integration-nonce-key' );
define( 'AUTH_SALT', 'pinova-integration-auth-salt' );
define( 'SECURE_AUTH_SALT', 'pinova-integration-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'pinova-integration-logged-in-salt' );
define( 'NONCE_SALT', 'pinova-integration-nonce-salt' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Pinova Integration Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
