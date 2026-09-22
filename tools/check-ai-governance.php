<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$durableFiles = [
    'AGENTS.md',
    'README.md',
    'CONTRIBUTING.md',
    'SECURITY.md',
    'CODE_OF_CONDUCT.md',
    '.github/repository-governance.md',
    '.agents/skills/pinova-development/SKILL.md',
    '.agents/skills/pinova-development/references/project-map.md',
    '.agents/skills/pinova-development/references/quality-and-release.md',
    '.agents/skills/pinova-development/references/logging.md',
];

$adapterFiles = [
    'CLAUDE.md',
    'GEMINI.md',
    '.github/copilot-instructions.md',
    '.cursor/rules/00-agents-first.mdc',
];

$requiredRepositoryFiles = [
    'LICENSE',
    '.github/CODEOWNERS',
    '.github/pull_request_template.md',
];

$forbiddenPatterns = [
    '/^#{1,6}\s+(?:current|historical)\s+(?:release|rc|snapshot|milestone)/imu' => 'operational status heading',
    '/^#{1,6}\s+(?:next expected milestones?|current .* boundary)/imu' => 'changing milestone heading',
    '/^#{1,6}\s+وضعیت فعلی/umu' => 'current-state heading',
    '/^#{1,6}\s+(?:snapshot|checkpoint)\b/imu' => 'snapshot heading',
    '/\bv\d+\.\d+\.\d+-rc\d+\b/i' => 'exact release-candidate tag',
    '/\b[0-9a-f]{40}\b/i' => 'full commit SHA',
    '/\b[0-9a-f]{64}\b/i' => 'checksum',
    '/\b20\d{2}-\d{2}-\d{2}\b/' => 'dated snapshot',
    '/github\.com\/[^\s)]+\/pull\/\d+/i' => 'pull-request evidence link',
    '/github\.com\/[^\s)]+\/actions\/runs\/\d+/i' => 'workflow-run evidence link',
];

foreach ($durableFiles as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        $errors[] = "Missing durable document: {$relative}";
        continue;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        $errors[] = "Unable to read durable document: {$relative}";
        continue;
    }

    foreach ($forbiddenPatterns as $pattern => $description) {
        if (preg_match($pattern, $contents) === 1) {
            $errors[] = "{$relative} contains forbidden {$description}. Put exact evidence under .agents/reviews/.";
        }
    }

    if (preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $contents, $matches) === false) {
        $errors[] = "Unable to inspect Markdown links in {$relative}.";
        continue;
    }

    foreach ($matches[1] as $target) {
        $target = trim($target);
        if ($target === '' || str_starts_with($target, '#') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $target) === 1) {
            continue;
        }

        $target = explode('#', $target, 2)[0];
        $resolved = dirname($path) . '/' . rawurldecode($target);
        if (!file_exists($resolved)) {
            $errors[] = "Broken relative link in {$relative}: {$target}";
        }
    }
}

$agents = file_get_contents($root . '/AGENTS.md');
if ($agents === false || !str_contains($agents, 'Mandatory first read')) {
    $errors[] = 'AGENTS.md must declare the mandatory first-read contract.';
}

$skill = file_get_contents($root . '/.agents/skills/pinova-development/SKILL.md');
if ($skill === false || !str_contains($skill, 'Read the root [`AGENTS.md`](../../../AGENTS.md)')) {
    $errors[] = 'The Pinova skill must route to root AGENTS.md before task work.';
}

foreach ($adapterFiles as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        $errors[] = "Missing AI adapter: {$relative}";
        continue;
    }

    $contents = file_get_contents($path);
    if ($contents === false || !str_contains($contents, 'AGENTS.md')) {
        $errors[] = "AI adapter does not route to AGENTS.md: {$relative}";
        continue;
    }

    $nonEmptyLines = array_filter(array_map('trim', preg_split('/\R/', $contents) ?: []));
    if (count($nonEmptyLines) > 8) {
        $errors[] = "AI adapter must remain a thin pointer without duplicated rules: {$relative}";
    }
}

foreach ($requiredRepositoryFiles as $relative) {
    if (!is_file($root . '/' . $relative)) {
        $errors[] = "Missing repository policy file: {$relative}";
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "AI and durable-document governance checks passed.\n");
