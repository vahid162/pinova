<?php

declare(strict_types=1);

$root         = dirname( __DIR__ );
$plugin       = (string) file_get_contents( $root . '/pinova.php' );
$readme       = (string) file_get_contents( $root . '/readme.txt' );
$changelog    = (string) file_get_contents( $root . '/CHANGELOG.md' );
$versionClass = (string) file_get_contents( $root . '/src/Version.php' );
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

function pinova_php_declares_method( string $contents, string $method ): bool {
	$awaitingName = false;

	foreach ( token_get_all( $contents ) as $token ) {
		if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
			$awaitingName = true;
			continue;
		}

		if ( ! $awaitingName ) {
			continue;
		}

		if ( is_array( $token ) && in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
			continue;
		}

		if ( '&' === $token ) {
			continue;
		}

		if ( is_array( $token ) && T_STRING === $token[0] && $method === $token[1] ) {
			return true;
		}

		$awaitingName = false;
	}

	return false;
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

$runtimeVersion = '';
if ( preg_match( '/define\(\s*[\'\"]PINOVA_VERSION[\'\"]\s*,\s*[\'\"](\d+\.\d+\.\d+)[\'\"]\s*\)/', $plugin, $matches ) === 1 ) {
	$runtimeVersion = $matches[1];
}

if ( '' === $runtimeVersion ) {
	$errors[] = 'pinova.php does not define a valid PINOVA_VERSION.';
} elseif ( ( $pluginHeaders['Version'] ?? '' ) !== $runtimeVersion ) {
	$errors[] = 'Plugin Version and PINOVA_VERSION differ.';
}

if ( '' !== $runtimeVersion && array_filter( array_map( 'intval', explode( '.', $runtimeVersion ) ), static fn ( int $part ): bool => $part >= 10 ) ) {
	$errors[] = 'PINOVA_VERSION components must remain below 10 until the migration runner supports multi-digit components.';
}

$migrationMethod = 'update_' . str_replace( '.', '', $runtimeVersion );
if ( '' !== $runtimeVersion && ! pinova_php_declares_method( $versionClass, $migrationMethod ) ) {
	$errors[] = "src/Version.php must define {$migrationMethod}().";
}

$currentChangelogVersion = '';
if ( preg_match( '/^##\s+(\d+\.\d+\.\d+)(?:\s+-\s+\S.*?)?\s*$/m', $changelog, $matches ) === 1 ) {
	$currentChangelogVersion = $matches[1];
}

if ( '' === $currentChangelogVersion ) {
	$errors[] = 'CHANGELOG.md does not start with a valid release heading.';
} elseif ( ( $readmeHeaders['Stable tag'] ?? '' ) !== $currentChangelogVersion ) {
	$errors[] = 'Stable tag and current CHANGELOG.md release differ.';
}

if ( ( $pluginHeaders['License'] ?? '' ) !== ( $readmeHeaders['License'] ?? '' ) ) {
	$errors[] = 'Plugin and readme.txt License values differ.';
}

if ( ! is_array( $composerJson ) || ( $composerJson['license'] ?? '' ) !== ( $pluginHeaders['License'] ?? '' ) ) {
	$errors[] = 'Composer and plugin License values differ.';
}

$expectedComposerPhp = '>=' . ( $pluginHeaders['Requires PHP'] ?? '' );
if ( ! is_array( $composerJson ) || ( $composerJson['require']['php'] ?? '' ) !== $expectedComposerPhp ) {
	$errors[] = "Composer PHP requirement must be {$expectedComposerPhp}.";
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
