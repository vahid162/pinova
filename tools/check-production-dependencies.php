<?php

declare(strict_types=1);

/**
 * Verify that a production-only Composer install exactly matches composer.lock.
 */

$root           = dirname(__DIR__);
$lock_path      = $root . '/composer.lock';
$installed_path = $root . '/vendor/composer/installed.json';

/**
 * Decode a required JSON object.
 *
 * @return array<string, mixed>
 */
function pinova_read_json(string $path): array
{
	if (! is_file($path) || ! is_readable($path)) {
		fwrite(STDERR, "Required JSON file is unavailable: {$path}\n");
		exit(1);
	}

	try {
		$data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	} catch (JsonException $exception) {
		fwrite(STDERR, "Invalid JSON in {$path}: {$exception->getMessage()}\n");
		exit(1);
	}

	if (! is_array($data)) {
		fwrite(STDERR, "Expected a JSON object in {$path}.\n");
		exit(1);
	}

	return $data;
}

/**
 * Normalize dependency identity fields used by Composer's lock and install data.
 *
 * @param array<int, mixed> $packages Package records.
 * @return array<string, array{version: string, source_reference: string, dist_reference: string}>
 */
function pinova_package_map(array $packages): array
{
	$result = array();

	foreach ($packages as $package) {
		if (! is_array($package) || ! isset($package['name'], $package['version'])) {
			fwrite(STDERR, "A dependency record is missing its name or version.\n");
			exit(1);
		}

		$name = (string) $package['name'];
		if (isset($result[$name])) {
			fwrite(STDERR, "Duplicate dependency record: {$name}\n");
			exit(1);
		}

		$result[$name] = array(
			'version'          => (string) $package['version'],
			'source_reference' => isset($package['source']['reference']) ? (string) $package['source']['reference'] : '',
			'dist_reference'   => isset($package['dist']['reference']) ? (string) $package['dist']['reference'] : '',
		);
	}

	ksort($result);

	return $result;
}

$lock      = pinova_read_json($lock_path);
$installed = pinova_read_json($installed_path);

if (! isset($lock['packages']) || ! is_array($lock['packages'])) {
	fwrite(STDERR, "composer.lock has no production package list.\n");
	exit(1);
}

if (! isset($installed['packages']) || ! is_array($installed['packages'])) {
	fwrite(STDERR, "Composer installed metadata has no package list.\n");
	exit(1);
}

$expected = pinova_package_map($lock['packages']);
$actual   = pinova_package_map($installed['packages']);
$errors   = array();

foreach (array_diff_key($expected, $actual) as $name => $_package) {
	$errors[] = "Missing installed production dependency: {$name}";
}

foreach (array_diff_key($actual, $expected) as $name => $_package) {
	$errors[] = "Unexpected installed dependency: {$name}";
}

foreach (array_intersect_key($expected, $actual) as $name => $package) {
	if ($package !== $actual[$name]) {
		$errors[] = "Installed dependency differs from composer.lock: {$name}";
	}
}

$compatible_licenses = array(
	'0BSD',
	'Apache-2.0',
	'BSD-2-Clause',
	'BSD-3-Clause',
	'CC0-1.0',
	'GPL-2.0-or-later',
	'GPL-3.0-only',
	'GPL-3.0-or-later',
	'ISC',
	'LGPL-2.1-or-later',
	'LGPL-3.0-only',
	'LGPL-3.0-or-later',
	'MIT',
	'MPL-2.0',
	'Unlicense',
	'Zlib',
);

foreach ($lock['packages'] as $package) {
	if (! is_array($package) || ! isset($package['name'])) {
		continue;
	}

	$licenses = isset($package['license']) && is_array($package['license'])
		? array_map('strval', $package['license'])
		: array();

	if (array() === $licenses) {
		$errors[] = "Production dependency has no declared license: {$package['name']}";
		continue;
	}

	if (array() === array_intersect($licenses, $compatible_licenses)) {
		$errors[] = sprintf(
			'Production dependency has no approved GPL-3.0-compatible license: %s (%s)',
			(string) $package['name'],
			implode(' OR ', $licenses)
		);
	}
}

if (array() !== $errors) {
	foreach ($errors as $error) {
		fwrite(STDERR, $error . "\n");
	}
	exit(1);
}

printf(
	"Verified %d production dependencies against composer.lock; every declared license is approved.\n",
	count($expected)
);
