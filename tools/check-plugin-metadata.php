<?php

declare(strict_types=1);

$root         = dirname( __DIR__ );
$plugin       = (string) file_get_contents( $root . '/pinova.php' );
$readme       = (string) file_get_contents( $root . '/readme.txt' );
$composerJson = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
$errors       = [];

/**
 * @return array<string, string>
 */
function pinova_parse_plugin_headers( string $contents ): array {
	$headers = [];

	if ( preg_match_all( '/^[ \t]*\*[ \t]*([^:\r\n]+):[ \t]*(.*?)[ \t]*$/m', $contents, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$headers[ trim( $match[1] ) ] = trim( $match[2] );
		}
	}

	return $headers;
}

/**
 * @return array<string, string>
 */
function pinova_parse_readme_headers( string $contents ): array {
	$headers = [];

	if ( preg_match_all( '/^([A-Za-z][A-Za-z ]+):\s*(.*?)\s*$/m', $contents, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$headers[ trim( $match[1] ) ] = trim( $match[2] );
		}
	}

	return $headers;
}

$pluginHeaders = pinova_parse_plugin_headers( $plugin );
$readmeHeaders = pinova_parse_readme_headers( $readme );
$required      = [
	'plugin' => [ 'Plugin Name', 'Plugin URI', 'Version', 'Text Domain', 'License', 'Requires at least', 'Requires PHP' ],
	'readme' => [ 'Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License', 'License URI' ],
];

foreach ( $required['plugin'] as $header ) {
	if ( ! isset( $pluginHeaders[ $header ] ) || '' === $pluginHeaders[ $header ] ) {
		$errors[] = "Missing plugin header: {$header}.";
	}
}

foreach ( $required['readme'] as $header ) {
	if ( ! isset( $readmeHeaders[ $header ] ) || '' === $readmeHeaders[ $header ] ) {
		$errors[] = "Missing readme.txt header: {$header}.";
	}
}

$expectedPluginHeaders = [
	'Plugin URI'  => 'https://github.com/vahid162/pinova',
	'Text Domain' => 'pinova',
	'License'     => 'GPL-3.0-or-later',
];

foreach ( $expectedPluginHeaders as $header => $expected ) {
	if ( ( $pluginHeaders[ $header ] ?? '' ) !== $expected ) {
		$errors[] = "Plugin {$header} must be {$expected}.";
	}
}

foreach ( [ 'Requires at least', 'Requires PHP' ] as $header ) {
	if ( ( $pluginHeaders[ $header ] ?? '' ) !== ( $readmeHeaders[ $header ] ?? '' ) ) {
		$errors[] = "Plugin and readme.txt {$header} values differ.";
	}
}

if ( ( $pluginHeaders['Version'] ?? '' ) !== ( $readmeHeaders['Stable tag'] ?? '' ) ) {
	$errors[] = 'Plugin Version and readme.txt Stable tag differ.';
}

if ( ( $pluginHeaders['License'] ?? '' ) !== ( $readmeHeaders['License'] ?? '' ) ) {
	$errors[] = 'Plugin and readme.txt License values differ.';
}

if ( ! is_array( $composerJson ) || ( $composerJson['license'] ?? '' ) !== ( $pluginHeaders['License'] ?? '' ) ) {
	$errors[] = 'Composer and plugin License values differ.';
}

if ( strlen( $readme ) >= 10 * 1024 ) {
	$errors[] = sprintf( 'readme.txt must stay below 10 KiB; found %d bytes.', strlen( $readme ) );
}

if ( preg_match( '/\A===\s+.+?\s+===\R/u', $readme ) !== 1 ) {
	$errors[] = 'readme.txt must start with a valid plugin title.';
}

foreach ( [ 'Description', 'Installation', 'Changelog' ] as $section ) {
	if ( ! str_contains( $readme, "== {$section} ==" ) ) {
		$errors[] = "readme.txt is missing the {$section} section.";
	}
}

$tags = array_filter( array_map( 'trim', explode( ',', $readmeHeaders['Tags'] ?? '' ) ) );
if ( count( $tags ) > 5 ) {
	$errors[] = 'readme.txt may contain at most five tags.';
}

if ( $errors ) {
	foreach ( $errors as $error ) {
		fwrite( STDERR, "ERROR: {$error}\n" );
	}
	exit( 1 );
}

printf( "Plugin metadata and readme validation passed (%d-byte readme.txt).\n", strlen( $readme ) );
