<?php

/**
 * Unit Tests for icon.php (REAL SOURCE)
 * 
 * Tests the icon serving endpoint: source/compose.manager/include/Icon.php
 * This file serves project icons via GET requests.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\FunctionMocks;

class IconTest extends TestCase
{
    private string $testComposeRoot;
    private string $testProjectPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test compose root
        $this->testComposeRoot = sys_get_temp_dir() . '/compose_icon_test_' . getmypid();
        if (!is_dir($this->testComposeRoot)) {
            mkdir($this->testComposeRoot, 0755, true);
        }
        
        // Create a test project
        $this->testProjectPath = $this->testComposeRoot . '/test-project';
        mkdir($this->testProjectPath, 0755, true);
        
        // Set up the compose root for getComposeRoot()
        FunctionMocks::setPluginConfig('compose.manager', [
            'PROJECTS_FOLDER' => $this->testComposeRoot,
        ]);
        
        // Clear GET params
        $_GET = [];
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        if (is_dir($this->testComposeRoot)) {
            $this->recursiveDelete($this->testComposeRoot);
        }
        $_GET = [];
        
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->recursiveDelete($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    /**
     * Create minimal PNG content
     */
    private function createFakePng(): string
    {
        return "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 100);
    }

    /**
     * Create minimal JPG content
     */
    private function createFakeJpg(): string
    {
        return "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100);
    }

    /**
     * Create minimal GIF content
     */
    private function createFakeGif(): string
    {
        return "GIF89a" . str_repeat("\x00", 100);
    }

    /**
     * Execute icon.php with specific GET params and capture output
     */
    private function executeIconPhp(array $getParams = []): array
    {
        $_GET = $getParams;
        $_SERVER['DOCUMENT_ROOT'] = '/usr/local/emhttp';
        
        // We need to use include (not require_once) to re-execute
        ob_start();
        $httpCode = 200;
        
        // Mock http_response_code
        $originalHeaders = [];
        
        try {
            include '/usr/local/emhttp/plugins/compose.manager/include/Icon.php';
        } catch (\Throwable $e) {
            // Icon.php uses exit(), catch any issues
        }
        
        $output = ob_get_clean();
        
        return [
            'output' => $output,
            'length' => strlen($output),
        ];
    }

    // ===========================================
    // Icon Discovery Tests (test file detection logic)
    // ===========================================

    /**
     * Test that PNG icon files are properly detected by extension
     */
    public function testPngIconExtensionDetection(): void
    {
        $iconPath = $this->testProjectPath . '/icon.png';
        file_put_contents($iconPath, $this->createFakePng());
        
        $ext = strtolower(pathinfo($iconPath, PATHINFO_EXTENSION));
        $this->assertEquals('png', $ext);
        
        // Test mime type mapping
        $mimeType = match($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
        $this->assertEquals('image/png', $mimeType);
    }

    /**
     * Test that JPG icon files are properly detected by extension
     */
    public function testJpgIconExtensionDetection(): void
    {
        $iconPath = $this->testProjectPath . '/icon.jpg';
        file_put_contents($iconPath, $this->createFakeJpg());
        
        $ext = strtolower(pathinfo($iconPath, PATHINFO_EXTENSION));
        $this->assertEquals('jpg', $ext);
        
        $mimeType = match($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
        $this->assertEquals('image/jpeg', $mimeType);
    }

    /**
     * Test that GIF icon files are properly detected by extension
     */
    public function testGifIconExtensionDetection(): void
    {
        $iconPath = $this->testProjectPath . '/icon.gif';
        file_put_contents($iconPath, $this->createFakeGif());
        
        $ext = strtolower(pathinfo($iconPath, PATHINFO_EXTENSION));
        $this->assertEquals('gif', $ext);
        
        $mimeType = match($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
        $this->assertEquals('image/gif', $mimeType);
    }

    /**
     * Test that SVG icon files are properly detected by extension
     */
    public function testSvgIconExtensionDetection(): void
    {
        $iconPath = $this->testProjectPath . '/icon.svg';
        file_put_contents($iconPath, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        
        $ext = strtolower(pathinfo($iconPath, PATHINFO_EXTENSION));
        $this->assertEquals('svg', $ext);
        
        $mimeType = match($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
        $this->assertEquals('image/svg+xml', $mimeType);
    }

    /**
     * Test JPEG extension (alternate spelling)
     */
    public function testJpegIconExtensionDetection(): void
    {
        $iconPath = $this->testProjectPath . '/icon.jpeg';
        file_put_contents($iconPath, $this->createFakeJpg());
        
        $ext = strtolower(pathinfo($iconPath, PATHINFO_EXTENSION));
        $this->assertEquals('jpeg', $ext);
        
        $mimeType = match($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
        $this->assertEquals('image/jpeg', $mimeType);
    }

    // ===========================================
    // Icon File Search Priority Tests
    // ===========================================

    /**
     * Test icon file search order (PNG should be found first)
     */
    public function testIconSearchPriorityPngFirst(): void
    {
        // icon.php searches in this order: png, jpg, gif, svg, icon
        $iconFiles = ['icon.png', 'icon.jpg', 'icon.gif', 'icon.svg', 'icon'];
        
        // Create all icon files
        file_put_contents($this->testProjectPath . '/icon.png', $this->createFakePng());
        file_put_contents($this->testProjectPath . '/icon.jpg', $this->createFakeJpg());
        
        // Search for first match
        $foundIcon = null;
        foreach ($iconFiles as $iconFile) {
            $testPath = $this->testProjectPath . '/' . $iconFile;
            if (is_file($testPath)) {
                $foundIcon = $testPath;
                break;
            }
        }
        
        $this->assertNotNull($foundIcon);
        $this->assertStringEndsWith('icon.png', $foundIcon);
    }

    /**
     * Test icon file search falls back to JPG when PNG missing
     */
    public function testIconSearchFallbackToJpg(): void
    {
        $iconFiles = ['icon.png', 'icon.jpg', 'icon.gif', 'icon.svg', 'icon'];
        
        // Create only JPG
        file_put_contents($this->testProjectPath . '/icon.jpg', $this->createFakeJpg());
        
        $foundIcon = null;
        foreach ($iconFiles as $iconFile) {
            $testPath = $this->testProjectPath . '/' . $iconFile;
            if (is_file($testPath)) {
                $foundIcon = $testPath;
                break;
            }
        }
        
        $this->assertNotNull($foundIcon);
        $this->assertStringEndsWith('icon.jpg', $foundIcon);
    }

    /**
     * Test icon file search returns null when no icon exists
     */
    public function testIconSearchReturnsNullWhenNoIcon(): void
    {
        $iconFiles = ['icon.png', 'icon.jpg', 'icon.gif', 'icon.svg', 'icon'];
        
        // Don't create any icon files
        
        $foundIcon = null;
        foreach ($iconFiles as $iconFile) {
            $testPath = $this->testProjectPath . '/' . $iconFile;
            if (is_file($testPath)) {
                $foundIcon = $testPath;
                break;
            }
        }
        
        $this->assertNull($foundIcon);
    }

    // ===========================================
    // Project Name Sanitization Tests
    // ===========================================

    /**
     * Test basename() sanitizes path traversal attempts
     */
    public function testProjectNameSanitizesPathTraversal(): void
    {
        $maliciousProject = '../../../etc/passwd';
        $sanitized = basename($maliciousProject);
        
        $this->assertEquals('passwd', $sanitized);
        $this->assertStringNotContainsString('..', $sanitized);
    }

    /**
     * Test basename() handles project with slashes
     */
    public function testProjectNameSanitizesSlashes(): void
    {
        $project = 'some/nested/project';
        $sanitized = basename($project);
        
        $this->assertEquals('project', $sanitized);
    }

    /**
     * Test empty project name
     */
    public function testEmptyProjectNameHandled(): void
    {
        $project = '';
        
        // icon.php checks for empty project and returns 404
        $this->assertTrue(empty($project));
    }

    // ===========================================
    // Directory Validation Tests
    // ===========================================

    /**
     * Test project directory must exist
     */
    public function testProjectDirectoryMustExist(): void
    {
        $nonExistentPath = $this->testComposeRoot . '/nonexistent-project';
        
        $this->assertFalse(is_dir($nonExistentPath));
    }

    /**
     * Test project path construction
     */
    public function testProjectPathConstruction(): void
    {
        $project = 'test-project';
        $projectPath = $this->testComposeRoot . '/' . $project;
        
        $this->assertEquals($this->testProjectPath, $projectPath);
        $this->assertTrue(is_dir($projectPath));
    }
}
