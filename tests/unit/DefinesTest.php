<?php

/**
 * Unit Tests for defines.php
 * 
 * Tests the locate_compose_root() function in source/compose.manager/include/Defines.php
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\FunctionMocks;

// Load the actual source file via stream wrapper
require_once '/usr/local/emhttp/plugins/compose.manager/include/Defines.php';

class DefinesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset mocks before each test
        FunctionMocks::reset();
    }

    // ===========================================
    // locate_compose_root() Tests  
    // ===========================================

    /**
     * Test locate_compose_root returns config value when set
     */
    public function testLocateComposeRootReturnsConfigValue(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'PROJECTS_FOLDER' => '/mnt/user/compose-projects',
        ]);

        $result = locate_compose_root('compose.manager');

        $this->assertEquals('/mnt/user/compose-projects', $result);
    }

    /**
     * Test locate_compose_root returns default when not configured
     */
    public function testLocateComposeRootReturnsDefaultWhenNotSet(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', []);

        $result = locate_compose_root('compose.manager');

        $this->assertEquals('/boot/config/plugins/compose.manager/projects', $result);
    }

    /**
     * Test locate_compose_root with custom plugin name
     */
    public function testLocateComposeRootWithDifferentPluginName(): void
    {
        FunctionMocks::setPluginConfig('other.plugin', [
            'PROJECTS_FOLDER' => '/custom/path',
        ]);

        $result = locate_compose_root('other.plugin');

        $this->assertEquals('/custom/path', $result);
    }

    /**
     * Test locate_compose_root with path containing special characters
     */
    public function testLocateComposeRootWithSpecialCharsInPath(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'PROJECTS_FOLDER' => '/mnt/user/My Compose Projects',
        ]);

        $result = locate_compose_root('compose.manager');

        $this->assertEquals('/mnt/user/My Compose Projects', $result);
    }

    /**
     * Test locate_compose_root with empty string config
     */
    public function testLocateComposeRootWithEmptyString(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'PROJECTS_FOLDER' => '',
        ]);

        // Empty string is falsy, should return default via null coalescing
        $result = locate_compose_root('compose.manager');

        // Since empty string is not null, it returns empty string
        $this->assertEquals('', $result);
    }

    /**
     * Test locate_compose_root with null-like config (key not present)
     */
    public function testLocateComposeRootWithMissingKey(): void
    {
        FunctionMocks::setPluginConfig('compose.manager', [
            'SOME_OTHER_KEY' => 'value',
        ]);

        $result = locate_compose_root('compose.manager');

        // Should use default since PROJECTS_FOLDER is not set
        $this->assertEquals('/boot/config/plugins/compose.manager/projects', $result);
    }
}
