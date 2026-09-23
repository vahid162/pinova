<?php

namespace Pinova\Logging;

final class BuildMetadata {

	/**
	 * Build provenance is generated only in the installable package. A source
	 * checkout must not claim a release tag or commit it cannot prove.
	 *
	 * @return array<string, string>
	 */
	public static function info(): array {
		static $info = null;

		if ( null !== $info ) {
			return $info;
		}

		$info = [ 'package_identity' => 'source' ];
		$path = dirname( __DIR__, 2 ) . '/build-info.json';
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return $info;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This is an optional local package manifest, not a remote URL.
		$contents = @file_get_contents( $path, false, null, 0, 513 );
		$manifest = is_string( $contents ) && strlen( $contents ) <= 512 ? json_decode( $contents, true ) : null;
		if ( ! is_array( $manifest ) ||
			! isset( $manifest['build_commit'], $manifest['package_identity'] ) ||
			! is_string( $manifest['build_commit'] ) ||
			! preg_match( '/\A[0-9a-f]{40}\z/D', $manifest['build_commit'] ) ||
			'pinova-release-zip' !== $manifest['package_identity'] ) {
			return $info;
		}

		$info = [
			'build_commit'     => $manifest['build_commit'],
			'package_identity' => 'pinova-release-zip',
		];

		if ( isset( $manifest['release_tag'] ) &&
			is_string( $manifest['release_tag'] ) &&
			preg_match( '/\Av[0-9]+\.[0-9]+\.[0-9]+-rc[1-9][0-9]*\z/D', $manifest['release_tag'] ) ) {
			$info['release_tag'] = $manifest['release_tag'];
		}

		return $info;
	}
}
