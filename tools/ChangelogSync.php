<?php

declare(strict_types=1);

namespace Pinova\Tools;

use RuntimeException;

final class ChangelogSync {
	/**
	 * @return array<string, list<string>>
	 */
	public static function parseMarkdown(string $contents): array {
		$releases = [];
		$current  = null;

		foreach (self::lines($contents) as $index => $line) {
			if (preg_match('/^##\s+(.+?)\s*$/u', $line, $matches) === 1) {
				$current = self::startRelease($releases, $matches[1], 'CHANGELOG.md', $index + 1);
				continue;
			}

			if ($current === null || trim($line) === '') {
				continue;
			}

			if (preg_match('/^-\s+(.+?)\s*$/u', $line, $matches) === 1) {
				$releases[$current][] = trim($matches[1]);
				continue;
			}

			throw new RuntimeException(sprintf('Unsupported CHANGELOG.md content on line %d.', $index + 1));
		}

		self::assertComplete($releases, 'CHANGELOG.md');

		return $releases;
	}

	/**
	 * @return array<string, list<string>>
	 */
	public static function parseWordPressReadme(string $contents): array {
		$releases = [];
		$current  = null;
		$inside   = false;

		foreach (self::lines($contents) as $index => $line) {
			$trimmed = trim($line);

			if (!$inside) {
				$inside = $trimmed === '== Changelog ==';
				continue;
			}

			if (preg_match('/^==\s+.+?\s+==$/u', $trimmed) === 1) {
				break;
			}

			if (preg_match('/^=\s+(.+?)\s+=$/u', $trimmed, $matches) === 1) {
				$current = self::startRelease($releases, $matches[1], 'readme.txt', $index + 1);
				continue;
			}

			if ($trimmed === '') {
				continue;
			}

			if ($current !== null && preg_match('/^\*\s+(.+?)\s*$/u', $line, $matches) === 1) {
				$releases[$current][] = trim($matches[1]);
				continue;
			}

			throw new RuntimeException(sprintf('Unsupported readme.txt changelog content on line %d.', $index + 1));
		}

		if (!$inside) {
			throw new RuntimeException('readme.txt does not contain a Changelog section.');
		}

		self::assertComplete($releases, 'readme.txt');

		return $releases;
	}

	public static function assertSynchronized(string $markdown, string $readme): int {
		$markdownReleases = self::parseMarkdown($markdown);
		$readmeReleases   = self::parseWordPressReadme($readme);
		$latestVersion    = array_key_first($markdownReleases);

		if ($latestVersion === null || array_keys($readmeReleases) !== [$latestVersion]) {
			throw new RuntimeException(sprintf(
				'readme.txt must contain only the latest CHANGELOG.md release. Expected: %s; readme.txt: %s.',
				$latestVersion ?? '(none)',
				implode(', ', array_keys($readmeReleases))
			));
		}

		if ($markdownReleases[$latestVersion] !== $readmeReleases[$latestVersion]) {
			throw new RuntimeException(sprintf('Changelog entries differ for version %s.', $latestVersion));
		}

		return 1;
	}

	/**
	 * @return list<string>
	 */
	private static function lines(string $contents): array {
		$lines = preg_split('/\R/u', $contents);

		return $lines === false ? [] : $lines;
	}

	/**
	 * @param array<string, list<string>> $releases
	 */
	private static function startRelease(array &$releases, string $label, string $source, int $line): string {
		$label = trim($label);

		if (preg_match('/^\d+\.\d+\.\d+(?:\s+-\s+\S.*)?$/u', $label) !== 1) {
			throw new RuntimeException(sprintf('Invalid release heading in %s on line %d: %s.', $source, $line, $label));
		}

		if (array_key_exists($label, $releases)) {
			throw new RuntimeException(sprintf('Duplicate release heading in %s: %s.', $source, $label));
		}

		$releases[$label] = [];

		return $label;
	}

	/**
	 * @param array<string, list<string>> $releases
	 */
	private static function assertComplete(array $releases, string $source): void {
		if ($releases === []) {
			throw new RuntimeException(sprintf('%s does not contain any releases.', $source));
		}

		foreach ($releases as $version => $entries) {
			if ($entries === []) {
				throw new RuntimeException(sprintf('%s release %s has no entries.', $source, $version));
			}
		}
	}
}
