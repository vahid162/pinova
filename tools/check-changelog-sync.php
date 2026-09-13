<?php

declare(strict_types=1);

use Pinova\Tools\ChangelogSync;

require_once __DIR__ . '/ChangelogSync.php';

$root          = dirname(__DIR__);
$markdownPath  = $argv[1] ?? $root . '/CHANGELOG.md';
$readmePath    = $argv[2] ?? $root . '/readme.txt';

if (isset($argv[3])) {
	fwrite(STDERR, "Usage: php tools/check-changelog-sync.php [CHANGELOG.md] [readme.txt]\n");
	exit(2);
}

if (!is_readable($markdownPath) || !is_readable($readmePath)) {
	fwrite(STDERR, "ERROR: Unable to read both changelog files.\n");
	exit(1);
}

$markdown = (string) file_get_contents($markdownPath);
$readme   = (string) file_get_contents($readmePath);

try {
	$count = ChangelogSync::assertSynchronized($markdown, $readme);
} catch (Throwable $throwable) {
	fwrite(STDERR, 'ERROR: ' . $throwable->getMessage() . "\n");
	exit(1);
}

printf("Changelog synchronization check passed (%d releases).\n", $count);
