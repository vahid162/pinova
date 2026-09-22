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
 * @param list<string> $errors
 * @return array<string, string>
 */
function pinova_parse_plugin_headers( string $contents, array &$errors ): array {
	$tokens           = token_get_all( $contents );
	$headerBlock      = null;
	$headerTokenIndex = null;

	foreach ( $tokens as $index => $token ) {
		if ( is_array( $token ) && in_array( $token[0], [ T_OPEN_TAG, T_WHITESPACE ], true ) ) {
			continue;
		}

		if ( is_array( $token ) && in_array( $token[0], [ T_DOC_COMMENT, T_COMMENT ], true ) && str_starts_with( ltrim( $token[1] ), '/*' ) ) {
			$headerBlock      = $token[1];
			$headerTokenIndex = $index;
		}
		break;
	}

	if ( null === $headerBlock || preg_match( '/^[ \t]*\*[ \t]*Plugin Name:[ \t]*\S.*$/m', $headerBlock ) !== 1 ) {
		$errors[] = 'pinova.php must begin with one canonical plugin header block.';

		return [];
	}

	$headers    = [];
	$duplicates = [];
	$pattern    = '/^[ \t]*\*[ \t]*([^:\r\n]+):[ \t]*(.*?)[ \t]*$/m';

	if ( preg_match_all( $pattern, $headerBlock, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$name = trim( $match[1] );
			if ( array_key_exists( $name, $headers ) ) {
				$duplicates[ $name ] = true;
				continue;
			}

			$headers[ $name ] = trim( $match[2] );
		}
	}

	foreach ( array_slice( $tokens, (int) $headerTokenIndex + 1 ) as $token ) {
		if ( ! is_array( $token ) || ! in_array( $token[0], [ T_DOC_COMMENT, T_COMMENT ], true ) ) {
			continue;
		}

		if ( preg_match_all( $pattern, $token[1], $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name = trim( $match[1] );
				if ( array_key_exists( $name, $headers ) ) {
					$duplicates[ $name ] = true;
				}
			}
		}
	}

	foreach ( array_keys( $duplicates ) as $name ) {
		$errors[] = "Plugin header field {$name} must appear exactly once in the canonical header block.";
	}

	return $headers;
}

/**
 * @param list<string> $errors
 * @return array<string, string>
 */
function pinova_parse_readme_headers( string $contents, array &$errors ): array {
	$lines      = preg_split( '/\R/', $contents );
	$headers    = [];
	$duplicates = [];
	$bodyOffset = null;

	if ( false === $lines ) {
		$errors[] = 'Unable to parse readme.txt headers.';

		return [];
	}

	foreach ( array_slice( $lines, 1, null, true ) as $index => $line ) {
		if ( '' === trim( $line ) ) {
			$bodyOffset = $index + 1;
			break;
		}

		if ( preg_match( '/^([A-Za-z][A-Za-z ]+):\s*(.*?)\s*$/', $line, $match ) !== 1 ) {
			continue;
		}

		$name = trim( $match[1] );
		if ( array_key_exists( $name, $headers ) ) {
			$duplicates[ $name ] = true;
			continue;
		}

		$headers[ $name ] = trim( $match[2] );
	}

	foreach ( array_slice( $lines, $bodyOffset ?? count( $lines ) ) as $line ) {
		if ( preg_match( '/^([A-Za-z][A-Za-z ]+):\s*(.*?)\s*$/', $line, $match ) === 1 && array_key_exists( trim( $match[1] ), $headers ) ) {
			$duplicates[ trim( $match[1] ) ] = true;
		}
	}

	foreach ( array_keys( $duplicates ) as $name ) {
		$errors[] = "readme.txt header field {$name} must appear exactly once in the leading header block.";
	}

	return $headers;
}

/**
 * @param array<int, array{int, string, int}|string> $tokens
 */
function pinova_next_significant_token_index( array $tokens, int $index ): ?int {
	$tokenCount = count( $tokens );
	for ( ; $index < $tokenCount; $index++ ) {
		$token = $tokens[ $index ];
		if ( is_array( $token ) && in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
			continue;
		}

		return $index;
	}

	return null;
}

/**
 * @return list<string>
 */
function pinova_php_defined_string_constants( string $contents, string $constant ): array {
	$tokens                    = token_get_all( $contents );
	$values                    = [];
	$braceDepth               = 0;
	$parenthesisDepth         = 0;
	$bracketDepth             = 0;
	$nonBootstrapScopeDepths  = [];
	$nonBootstrapScopePending = false;
	$arrowFunctionDepths      = [];

	foreach ( $tokens as $index => $token ) {
		if ( is_string( $token ) && in_array( $token, [ ',', ';', ')', ']', '}' ], true ) ) {
			while ( $arrowFunctionDepths ) {
				$arrowDepth = end( $arrowFunctionDepths );
				if ( $arrowDepth['parenthesis'] !== $parenthesisDepth ||
					$arrowDepth['bracket'] !== $bracketDepth ||
					$arrowDepth['brace'] !== $braceDepth ) {
					break;
				}

				array_pop( $arrowFunctionDepths );
			}
		}

		if ( is_array( $token ) && in_array( $token[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) {
			$braceDepth++;
			continue;
		}

		if ( '(' === $token ) {
			$parenthesisDepth++;
		} elseif ( ')' === $token ) {
			$parenthesisDepth--;
		} elseif ( '[' === $token ) {
			$bracketDepth++;
		} elseif ( ']' === $token ) {
			$bracketDepth--;
		}

		if ( '{' === $token ) {
			$braceDepth++;
			if ( $nonBootstrapScopePending ) {
				$nonBootstrapScopeDepths[]  = $braceDepth;
				$nonBootstrapScopePending = false;
			}
			continue;
		}

		if ( '}' === $token ) {
			if ( $nonBootstrapScopeDepths && end( $nonBootstrapScopeDepths ) === $braceDepth ) {
				array_pop( $nonBootstrapScopeDepths );
			}
			$braceDepth--;
			continue;
		}

		if ( ';' === $token ) {
			$nonBootstrapScopePending = false;
			continue;
		}

		if ( is_array( $token ) && T_FN === $token[0] ) {
			$arrowFunctionDepths[] = [
				'parenthesis' => $parenthesisDepth,
				'bracket'     => $bracketDepth,
				'brace'       => $braceDepth,
			];
			continue;
		}

		if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
			$nonBootstrapScopePending = true;
			continue;
		}

		if ( is_array( $token ) && in_array( $token[0], [ T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ], true ) ) {
			$previousIndex = $index - 1;
			while ( $previousIndex >= 0 ) {
				$previousToken = $tokens[ $previousIndex ];
				if ( ! is_array( $previousToken ) || ! in_array( $previousToken[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
					break;
				}
				$previousIndex--;
			}

			$previousToken = $previousIndex >= 0 ? $tokens[ $previousIndex ] : null;
			if ( ! is_array( $previousToken ) || T_DOUBLE_COLON !== $previousToken[0] ) {
				$nonBootstrapScopePending = true;
			}
			continue;
		}

		if ( $nonBootstrapScopeDepths || $arrowFunctionDepths ) {
			continue;
		}

		if ( ! is_array( $token ) || T_STRING !== $token[0] || 0 !== strcasecmp( $token[1], 'define' ) ) {
			continue;
		}

		$previousIndex = $index - 1;
		while ( $previousIndex >= 0 ) {
			$previousToken = $tokens[ $previousIndex ];
			if ( ! is_array( $previousToken ) || ! in_array( $previousToken[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				break;
			}
			$previousIndex--;
		}

		$previousToken = $previousIndex >= 0 ? $tokens[ $previousIndex ] : null;
		if ( is_array( $previousToken ) && in_array( $previousToken[0], [ T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ], true ) ) {
			continue;
		}

		$openIndex = pinova_next_significant_token_index( $tokens, $index + 1 );
		$nameIndex = null === $openIndex ? null : pinova_next_significant_token_index( $tokens, $openIndex + 1 );
		$commaIndex = null === $nameIndex ? null : pinova_next_significant_token_index( $tokens, $nameIndex + 1 );
		$valueIndex = null === $commaIndex ? null : pinova_next_significant_token_index( $tokens, $commaIndex + 1 );
		$closeIndex = null === $valueIndex ? null : pinova_next_significant_token_index( $tokens, $valueIndex + 1 );

		if ( null === $openIndex || '(' !== $tokens[ $openIndex ] ||
			null === $nameIndex || ! is_array( $tokens[ $nameIndex ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $nameIndex ][0] ||
			! in_array( $tokens[ $nameIndex ][1], [ "'{$constant}'", "\"{$constant}\"" ], true ) ||
			null === $commaIndex || ',' !== $tokens[ $commaIndex ] ||
			null === $valueIndex || ! is_array( $tokens[ $valueIndex ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $valueIndex ][0] ||
			null === $closeIndex || ')' !== $tokens[ $closeIndex ] ) {
			continue;
		}

		$values[] = substr( $tokens[ $valueIndex ][1], 1, -1 );
	}

	return $values;
}

function pinova_php_class_declares_public_method( string $contents, string $class, string $method ): bool {
	$tokens             = token_get_all( $contents );
	$braceDepth         = 0;
	$currentNamespace   = '';
	$targetClassPending = false;
	$targetClassDepth   = null;
	$tokenCount         = count( $tokens );

	for ( $index = 0; $index < $tokenCount; $index++ ) {
		$token = $tokens[ $index ];
		if ( is_array( $token ) && in_array( $token[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) {
			$braceDepth++;
			continue;
		}

		if ( null === $targetClassDepth && 0 === $braceDepth && is_array( $token ) && T_NAMESPACE === $token[0] ) {
			$namespace = '';
			for ( $namespaceIndex = $index + 1; $namespaceIndex < $tokenCount; $namespaceIndex++ ) {
				$namespaceToken = $tokens[ $namespaceIndex ];
				if ( is_string( $namespaceToken ) && in_array( $namespaceToken, [ ';', '{' ], true ) ) {
					break;
				}
				if ( is_array( $namespaceToken ) && in_array( $namespaceToken[0], [ T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR ], true ) ) {
					$namespace .= $namespaceToken[1];
				}
			}
			$currentNamespace = trim( $namespace, '\\' );
			continue;
		}

		if ( null === $targetClassDepth && 0 === $braceDepth && is_array( $token ) && T_CLASS === $token[0] ) {
			for ( $nameIndex = $index + 1; $nameIndex < $tokenCount; $nameIndex++ ) {
				$nameToken = $tokens[ $nameIndex ];
				if ( is_array( $nameToken ) && in_array( $nameToken[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
					continue;
				}

				$declaredClass      = is_array( $nameToken ) && T_STRING === $nameToken[0]
					? ltrim( $currentNamespace . '\\' . $nameToken[1], '\\' )
					: '';
				$targetClassPending = $class === $declaredClass;
				break;
			}
		}

		if ( '{' === $token ) {
			$braceDepth++;
			if ( $targetClassPending ) {
				$targetClassDepth   = $braceDepth;
				$targetClassPending = false;
			}
			continue;
		}

		if ( '}' === $token ) {
			if ( $targetClassDepth === $braceDepth ) {
				$targetClassDepth = null;
			}
			$braceDepth--;
			continue;
		}

		if ( null === $targetClassDepth || $targetClassDepth !== $braceDepth || ! is_array( $token ) || T_FUNCTION !== $token[0] ) {
			continue;
		}

		$declaredMethod = '';
		for ( $nameIndex = $index + 1; $nameIndex < $tokenCount; $nameIndex++ ) {
			$nameToken = $tokens[ $nameIndex ];
			if ( is_array( $nameToken ) && in_array( $nameToken[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			if ( '&' === $nameToken || ( is_array( $nameToken ) && '&' === $nameToken[1] ) ) {
				continue;
			}
			if ( is_array( $nameToken ) && T_STRING === $nameToken[0] ) {
				$declaredMethod = $nameToken[1];
			}
			break;
		}

		if ( $method !== $declaredMethod ) {
			continue;
		}

		$isPublic   = false;
		$isStatic   = false;
		$isAbstract = false;
		for ( $visibilityIndex = $index - 1; $visibilityIndex >= 0; $visibilityIndex-- ) {
			$visibilityToken = $tokens[ $visibilityIndex ];
			if ( is_string( $visibilityToken ) && in_array( $visibilityToken, [ ';', '{', '}' ], true ) ) {
				break;
			}
			if ( is_array( $visibilityToken ) && in_array( $visibilityToken[0], [ T_PRIVATE, T_PROTECTED ], true ) ) {
				return false;
			}
			if ( is_array( $visibilityToken ) && T_PUBLIC === $visibilityToken[0] ) {
				$isPublic = true;
			}
			if ( is_array( $visibilityToken ) && T_STATIC === $visibilityToken[0] ) {
				$isStatic = true;
			}
			if ( is_array( $visibilityToken ) && T_ABSTRACT === $visibilityToken[0] ) {
				$isAbstract = true;
			}
		}

		if ( ! $isPublic || $isStatic || $isAbstract ) {
			return false;
		}

		$parametersOpen  = false;
		$parametersClose = null;
		for ( $signatureIndex = $nameIndex + 1; $signatureIndex < $tokenCount; $signatureIndex++ ) {
			$signatureToken = $tokens[ $signatureIndex ];
			if ( is_array( $signatureToken ) && in_array( $signatureToken[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			if ( ! $parametersOpen && '(' === $signatureToken ) {
				$parametersOpen = true;
				continue;
			}
			if ( $parametersOpen && ')' === $signatureToken ) {
				$parametersClose = $signatureIndex;
				break;
			}
			return false;
		}

		if ( null === $parametersClose ) {
			return false;
		}

		for ( $bodyIndex = $parametersClose + 1; $bodyIndex < $tokenCount; $bodyIndex++ ) {
			$bodyToken = $tokens[ $bodyIndex ];
			if ( '{' === $bodyToken ) {
				return true;
			}
			if ( ';' === $bodyToken ) {
				return false;
			}
		}

		return false;
	}

	return false;
}

$pluginHeaders = pinova_parse_plugin_headers( $plugin, $errors );
$readmeHeaders = pinova_parse_readme_headers( $readme, $errors );
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

$runtimeVersions = pinova_php_defined_string_constants( $plugin, 'PINOVA_VERSION' );
$runtimeVersion  = count( $runtimeVersions ) === 1 ? $runtimeVersions[0] : '';
$runtimeVersionCanonical = preg_match( '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)$/', $runtimeVersion ) === 1;

if ( '' === $runtimeVersion ) {
	$errors[] = 'pinova.php must contain exactly one executable string definition of PINOVA_VERSION.';
} elseif ( ! $runtimeVersionCanonical ) {
	$errors[] = 'PINOVA_VERSION must use canonical non-zero-padded semantic-version components.';
} elseif ( ( $pluginHeaders['Version'] ?? '' ) !== $runtimeVersion ) {
	$errors[] = 'Plugin Version and PINOVA_VERSION differ.';
}

if ( $runtimeVersionCanonical ) {
	[ , $minorVersion, $patchVersion ] = array_map( 'intval', explode( '.', $runtimeVersion ) );
	if ( $minorVersion >= 10 || $patchVersion >= 10 ) {
		$errors[] = 'PINOVA_VERSION minor and patch components must remain below 10 until the migration runner supports multi-digit components.';
	}
}

$migrationMethod = 'update_' . str_replace( '.', '', $runtimeVersion );
if ( $runtimeVersionCanonical && ! pinova_php_class_declares_public_method( $versionClass, 'Pinova\\Version', $migrationMethod ) ) {
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
