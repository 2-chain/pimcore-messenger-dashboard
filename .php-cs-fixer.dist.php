<?php

declare(strict_types=1);

/**
 * PHP-CS-Fixer config for the bundle.
 *
 * Symfony preset on top of PHP 8.2 baseline + the `:risky` variants that
 * are safe for a strictly-typed PHP 8.2+ codebase (final on classes,
 * declare(strict_types) on every file, etc.).
 *
 * CI runs in dry-run mode: `vendor/bin/php-cs-fixer fix --dry-run --diff`.
 * Locally, drop `--dry-run` to autofix.
 */

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/Command',
        __DIR__ . '/Controller',
        __DIR__ . '/DependencyInjection',
        __DIR__ . '/Entity',
        __DIR__ . '/EventSubscriber',
        __DIR__ . '/Migrations',
        __DIR__ . '/Repository',
        __DIR__ . '/Service',
        __DIR__ . '/Stamp',
        __DIR__ . '/tests',
    ])
    ->append([
        __FILE__,
        __DIR__ . '/Installer.php',
        __DIR__ . '/PimcoreMessengerDashboardBundle.php',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@PER-CS:risky' => true,
        '@PHP82Migration' => true,
        '@PHP82Migration:risky' => true,
        // Project-specific tweaks layered on top of the baseline.
        'declare_strict_types' => true,
        'native_function_invocation' => false,  // we use both `\count()` and `count()` styles
        'phpdoc_to_comment' => false,           // PHPStan-style inline @var comments are useful
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
    ])
    ->setFinder($finder);
