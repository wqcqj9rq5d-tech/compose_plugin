<?php

/**
 * Compose Manager Plugin - Test Bootstrap
 *
 * Uses the plugin-tests framework to set up the test environment.
 * This is a minimal bootstrap that delegates to the framework.
 */

declare(strict_types=1);

// Stub the logger function before source files are loaded.
// On Linux this is a syslog command; on Windows it doesn't exist.
if (!function_exists('logger')) {
    function logger(string $message = ''): void
    {
        // no-op in test environment
    }
}

// Stub clientDebug before source files are loaded.
// It calls exec("logger ...") internally which doesn't exist on Windows.
if (!function_exists('clientDebug')) {
    function clientDebug($message, $data = null, $type = 'daemon', $level = 'info'): void
    {
        // no-op in test environment
    }
}

// Load the plugin-tests framework
require_once __DIR__ . '/framework/src/php/bootstrap.php';

use PluginTests\PluginBootstrap;
use PluginTests\StreamWrapper\UnraidStreamWrapper;
use PluginTests\Mocks\FunctionMocks;

// Initialize the plugin test environment
// This auto-maps all .php files from source/compose.manager/include/ to Unraid paths
PluginBootstrap::init(
    'compose.manager',
    __DIR__ . '/../source/compose.manager/include',
    [
        'config' => [
            'PROJECTS_FOLDER' => sys_get_temp_dir() . '/compose_test_projects',
            'DEBUG_TO_LOG' => 'false',
            'OUTPUTSTYLE' => 'nchan',
        ],
        'subPath' => 'include',
    ]
);

// Set up test compose projects directory
$testComposeRoot = sys_get_temp_dir() . '/compose_test_projects';
if (!is_dir($testComposeRoot)) {
    mkdir($testComposeRoot, 0755, true);
}

// Set compose_root global (used by plugin)
$GLOBALS['compose_root'] = $testComposeRoot;
