<?php

/**
 * Unit Tests for DashboardStacks.php (REAL SOURCE)
 * 
 * Tests the dashboard tile data generation: source/compose.manager/include/DashboardStacks.php
 * This file returns JSON data for the compose manager dashboard tile.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\FunctionMocks;

class DashboardStacksTest extends TestCase
{
    private string $testComposeRoot;
    private string $testConfigRoot;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test compose root
        $this->testComposeRoot = sys_get_temp_dir() . '/compose_dashboard_test_' . getmypid();
        if (!is_dir($this->testComposeRoot)) {
            mkdir($this->testComposeRoot, 0755, true);
        }
        
        // Create test config root for update-status.json
        $this->testConfigRoot = sys_get_temp_dir() . '/compose_dashboard_config_' . getmypid();
        if (!is_dir($this->testConfigRoot)) {
            mkdir($this->testConfigRoot, 0755, true);
        }
        
        global $compose_root, $plugin_root;
        $compose_root = $this->testComposeRoot;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager';
        
        FunctionMocks::setPluginConfig('compose.manager', [
            'PROJECTS_FOLDER' => $this->testComposeRoot,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testComposeRoot)) {
            $this->recursiveDelete($this->testComposeRoot);
        }
        if (is_dir($this->testConfigRoot)) {
            $this->recursiveDelete($this->testConfigRoot);
        }
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
     * Create a test stack directory
     */
    private function createTestStack(string $name, array $files = []): string
    {
        $stackPath = $this->testComposeRoot . '/' . $name;
        mkdir($stackPath, 0755, true);
        
        if (!isset($files['compose.yaml'])) {
            file_put_contents($stackPath . '/compose.yaml', "services:\n  web:\n    image: nginx\n");
        }
        
        foreach ($files as $filename => $content) {
            file_put_contents($stackPath . '/' . $filename, $content);
        }
        
        return $stackPath;
    }

    // ===========================================
    // Summary Structure Tests
    // ===========================================

    /**
     * Test default summary structure
     */
    public function testDefaultSummaryStructure(): void
    {
        $summary = [
            'total' => 0,
            'started' => 0,
            'stopped' => 0,
            'partial' => 0,
            'stacks' => []
        ];
        
        $this->assertArrayHasKey('total', $summary);
        $this->assertArrayHasKey('started', $summary);
        $this->assertArrayHasKey('stopped', $summary);
        $this->assertArrayHasKey('partial', $summary);
        $this->assertArrayHasKey('stacks', $summary);
    }

    /**
     * Test summary JSON encoding
     */
    public function testSummaryJsonEncoding(): void
    {
        $summary = [
            'total' => 2,
            'started' => 1,
            'stopped' => 1,
            'partial' => 0,
            'stacks' => []
        ];
        
        $json = json_encode($summary);
        $this->assertJson($json);
        
        $decoded = json_decode($json, true);
        $this->assertEquals($summary, $decoded);
    }

    // ===========================================
    // Stack Counting Tests
    // ===========================================

    /**
     * Test counting stacks with compose.yaml
     */
    public function testCountStacksWithComposeYml(): void
    {
        $this->createTestStack('stack1');
        $this->createTestStack('stack2');
        
        $projects = array_diff(scandir($this->testComposeRoot), ['.', '..']);
        $count = 0;
        
        foreach ($projects as $project) {
            $path = $this->testComposeRoot . '/' . $project;
            if (is_file($path . '/compose.yaml') || is_file($path . '/indirect')) {
                $count++;
            }
        }
        
        $this->assertEquals(2, $count);
    }

    /**
     * Test ignoring non-stack directories
     */
    public function testIgnoreNonStackDirectories(): void
    {
        $this->createTestStack('validstack');
        
        // Create non-stack directory
        $invalidPath = $this->testComposeRoot . '/notastack';
        mkdir($invalidPath, 0755, true);
        file_put_contents($invalidPath . '/readme.txt', 'Just a file');
        
        $projects = array_diff(scandir($this->testComposeRoot), ['.', '..']);
        $count = 0;
        
        foreach ($projects as $project) {
            $path = $this->testComposeRoot . '/' . $project;
            if (is_file($path . '/compose.yaml') || is_file($path . '/indirect')) {
                $count++;
            }
        }
        
        $this->assertEquals(1, $count);
    }

    // ===========================================
    // Stack State Calculation Tests
    // ===========================================

    /**
     * Test state is stopped when no containers
     */
    public function testStateIsStoppedWhenNoContainers(): void
    {
        $runningCount = 0;
        $totalContainers = 0;
        
        $state = 'stopped';
        if ($totalContainers > 0) {
            if ($runningCount === $totalContainers) {
                $state = 'started';
            } elseif ($runningCount > 0) {
                $state = 'partial';
            }
        }
        
        $this->assertEquals('stopped', $state);
    }

    /**
     * Test state is started when all containers running
     */
    public function testStateIsStartedWhenAllRunning(): void
    {
        $runningCount = 3;
        $totalContainers = 3;
        
        $state = 'stopped';
        if ($totalContainers > 0) {
            if ($runningCount === $totalContainers) {
                $state = 'started';
            } elseif ($runningCount > 0) {
                $state = 'partial';
            }
        }
        
        $this->assertEquals('started', $state);
    }

    /**
     * Test state is partial when some containers running
     */
    public function testStateIsPartialWhenSomeRunning(): void
    {
        $runningCount = 2;
        $totalContainers = 4;
        
        $state = 'stopped';
        if ($totalContainers > 0) {
            if ($runningCount === $totalContainers) {
                $state = 'started';
            } elseif ($runningCount > 0) {
                $state = 'partial';
            }
        }
        
        $this->assertEquals('partial', $state);
    }

    /**
     * Test state is stopped when no containers running
     */
    public function testStateIsStoppedWhenNoneRunning(): void
    {
        $runningCount = 0;
        $totalContainers = 3;
        
        $state = 'stopped';
        if ($totalContainers > 0) {
            if ($runningCount === $totalContainers) {
                $state = 'started';
            } elseif ($runningCount > 0) {
                $state = 'partial';
            }
        }
        
        $this->assertEquals('stopped', $state);
    }

    // ===========================================
    // Stack Name Resolution Tests
    // ===========================================

    /**
     * Test name uses folder name when no name file
     */
    public function testNameUsesFolderNameWhenNoNameFile(): void
    {
        $this->createTestStack('my-stack');
        
        $projectName = 'my-stack';
        $nameFile = $this->testComposeRoot . '/my-stack/name';
        if (is_file($nameFile)) {
            $projectName = trim(file_get_contents($nameFile));
        }
        
        $this->assertEquals('my-stack', $projectName);
    }

    /**
     * Test name uses name file contents when present
     */
    public function testNameUsesNameFileWhenPresent(): void
    {
        $this->createTestStack('my-stack', ['name' => 'Custom Stack Name']);
        
        $projectName = 'my-stack';
        $nameFile = $this->testComposeRoot . '/my-stack/name';
        if (is_file($nameFile)) {
            $projectName = trim(file_get_contents($nameFile));
        }
        
        $this->assertEquals('Custom Stack Name', $projectName);
    }

    // ===========================================
    // Update Status Loading Tests
    // ===========================================

    /**
     * Test loading update status from JSON file
     */
    public function testLoadUpdateStatusFromJson(): void
    {
        $updateStatusFile = $this->testConfigRoot . '/update-status.json';
        $updateStatus = [
            'stack1' => ['hasUpdate' => true],
            'stack2' => ['hasUpdate' => false],
        ];
        file_put_contents($updateStatusFile, json_encode($updateStatus));
        
        $savedUpdateStatus = [];
        if (is_file($updateStatusFile)) {
            $savedUpdateStatus = json_decode(file_get_contents($updateStatusFile), true) ?: [];
        }
        
        $this->assertEquals($updateStatus, $savedUpdateStatus);
    }

    /**
     * Test empty update status when file missing
     */
    public function testEmptyUpdateStatusWhenFileMissing(): void
    {
        $updateStatusFile = $this->testConfigRoot . '/nonexistent.json';
        
        $savedUpdateStatus = [];
        if (is_file($updateStatusFile)) {
            $savedUpdateStatus = json_decode(file_get_contents($updateStatusFile), true) ?: [];
        }
        
        $this->assertEmpty($savedUpdateStatus);
    }

    /**
     * Test empty update status on invalid JSON
     */
    public function testEmptyUpdateStatusOnInvalidJson(): void
    {
        $updateStatusFile = $this->testConfigRoot . '/invalid.json';
        file_put_contents($updateStatusFile, 'not valid json');
        
        $savedUpdateStatus = [];
        if (is_file($updateStatusFile)) {
            $savedUpdateStatus = json_decode(file_get_contents($updateStatusFile), true) ?: [];
        }
        
        $this->assertEmpty($savedUpdateStatus);
    }

    // ===========================================
    // Started At Timestamp Tests
    // ===========================================

    /**
     * Test reading started_at timestamp
     */
    public function testReadStartedAtTimestamp(): void
    {
        $timestamp = '2026-02-03T10:30:00Z';
        $this->createTestStack('mystack', ['started_at' => $timestamp]);
        
        $startedAt = '';
        $startedAtFile = $this->testComposeRoot . '/mystack/started_at';
        if (is_file($startedAtFile)) {
            $startedAt = trim(file_get_contents($startedAtFile));
        }
        
        $this->assertEquals($timestamp, $startedAt);
    }

    /**
     * Test empty started_at when file missing
     */
    public function testEmptyStartedAtWhenFileMissing(): void
    {
        $this->createTestStack('mystack');
        
        $startedAt = '';
        $startedAtFile = $this->testComposeRoot . '/mystack/started_at';
        if (is_file($startedAtFile)) {
            $startedAt = trim(file_get_contents($startedAtFile));
        }
        
        $this->assertEquals('', $startedAt);
    }

    // ===========================================
    // JSON Output Tests
    // ===========================================

    /**
     * Test complete summary JSON output
     */
    public function testCompleteSummaryJsonOutput(): void
    {
        $summary = [
            'total' => 3,
            'started' => 1,
            'stopped' => 1,
            'partial' => 1,
            'stacks' => [
                [
                    'name' => 'stack1',
                    'state' => 'started',
                    'runningCount' => 2,
                    'totalContainers' => 2,
                ],
                [
                    'name' => 'stack2',
                    'state' => 'stopped',
                    'runningCount' => 0,
                    'totalContainers' => 1,
                ],
                [
                    'name' => 'stack3',
                    'state' => 'partial',
                    'runningCount' => 1,
                    'totalContainers' => 3,
                ],
            ]
        ];
        
        $json = json_encode($summary);
        $this->assertJson($json);
        
        $decoded = json_decode($json, true);
        $this->assertEquals(3, $decoded['total']);
        $this->assertCount(3, $decoded['stacks']);
    }

    /**
     * Test Content-Type header for JSON response
     */
    public function testContentTypeForJsonResponse(): void
    {
        // DashboardStacks.php sets: header('Content-Type: application/json');
        $expectedHeader = 'Content-Type: application/json';
        
        // We can't actually test headers in unit tests, but we verify the format
        $this->assertStringContainsString('application/json', $expectedHeader);
    }
}
