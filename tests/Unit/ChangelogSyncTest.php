<?php

declare(strict_types=1);

namespace Pinova\Tests\Unit;

use Pinova\Tools\ChangelogSync;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/tools/ChangelogSync.php';

final class ChangelogSyncTest extends TestCase {
	private const MARKDOWN = "# Changelog\n\n## 1.2.5\n\n- First change\n- تغییر دوم\n\n## 1.2.4 - 1405/01/01\n\n- Older change\n";

	private const README = "=== Plugin ===\n\n== Changelog ==\n= 1.2.5 =\n* First change\n* تغییر دوم\n= 1.2.4 - 1405/01/01 =\n* Older change\n== Upgrade Notice ==\n= 1.2.4 =\n* Notice\n";

	public function test_repository_changelogs_are_synchronized(): void {
		$root = dirname(__DIR__, 2);

		self::assertSame(
			12,
			ChangelogSync::assertSynchronized(
				(string) file_get_contents($root . '/CHANGELOG.md'),
				(string) file_get_contents($root . '/readme.txt')
			)
		);
	}

	public function test_equivalent_formats_are_accepted(): void {
		self::assertSame(2, ChangelogSync::assertSynchronized(self::MARKDOWN, self::README));
	}

	public function test_missing_entry_is_rejected(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('entries differ for version 1.2.5');

		ChangelogSync::assertSynchronized(
			str_replace("- تغییر دوم\n", '', self::MARKDOWN),
			self::README
		);
	}

	public function test_version_order_mismatch_is_rejected(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Version order mismatch');

		ChangelogSync::assertSynchronized(
			self::MARKDOWN,
			str_replace('= 1.2.5 =', '= 1.2.6 =', self::README)
		);
	}

	public function test_duplicate_release_heading_is_rejected(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Duplicate release heading');

		ChangelogSync::parseMarkdown(self::MARKDOWN . "\n## 1.2.5\n- Duplicate\n");
	}
}
