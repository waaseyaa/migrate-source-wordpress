<?php

declare(strict_types=1);

test('CI lint uses the tracked non-interactive configuration', function (): void {
    $root = dirname(__DIR__, 2);
    $workflow = file_get_contents($root . '/.github/workflows/ci.yml');

    expect($workflow)->not->toBeFalse()
        ->and(is_file($root . '/.php-cs-fixer.dist.php'))->toBeTrue()
        ->and($workflow)->toContain(
            'vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no',
        );
});

test('CI runs for pull requests targeting stacked branches', function (): void {
    $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');

    expect($workflow)->not->toBeFalse()
        ->and($workflow)->toContain('pull_request: {}')
        ->and($workflow)->not->toContain("pull_request:\n    branches: [main]");
});
