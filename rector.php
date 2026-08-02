<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        // __DIR__ . '/bootstrap',
        __DIR__ . '/config',
        // __DIR__ . '/public',
        // __DIR__ . '/resources',
        // __DIR__ . '/routes',
        // __DIR__ . '/tests',
    ])
    // Upgrade syntax to PHP 8.4 (readonly props, typed props, match, enums, etc.)
    ->withPhpSets(php84: true)
    // Laravel-specific upgrade rules, cumulative up to Laravel 12
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_120,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
    ])
    ->withTypeCoverageLevel(10)
    ->withDeadCodeLevel(10)
    ->withCodeQualityLevel(10);
