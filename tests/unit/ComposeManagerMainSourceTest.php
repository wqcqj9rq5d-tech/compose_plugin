<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

/**
 * Source-level tests for ComposeManager.php.
 *
 * These verify key CPU/memory load logic markers in the page source so
 * regressions are caught even when the page cannot be executed in unit tests.
 */
class ComposeManagerMainSourceTest extends TestCase
{
    private string $mainPagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mainPagePath = __DIR__ . '/../../source/compose.manager/include/ComposeManager.php';
        $this->assertFileExists($this->mainPagePath, 'ComposeManager.php must exist');
    }

    private function getPageSource(): string
    {
        return file_get_contents($this->mainPagePath);
    }

    public function testCpuSpecCountHelperExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('function compose_manager_cpu_spec_count($cpuSpec)', $source);
        $this->assertStringContainsString('explode(\',\', trim((string)$cpuSpec))', $source);
    }

    public function testCpuCountSumsAllCpuSpecs(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('$cpuCount = 0;', $source);
        $this->assertStringContainsString('foreach ($cpus as $cpuSpec)', $source);
        $this->assertStringContainsString('$cpuCount += compose_manager_cpu_spec_count($cpuSpec);', $source);
    }

    public function testCpuCountHasFallbackGuards(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString("trim(shell_exec('nproc 2>/dev/null') ?: '1')", $source);
        $this->assertStringContainsString('if ($cpuCount <= 0) {', $source);
        $this->assertStringContainsString('$cpuCount = 1;', $source);
    }

    public function testStackAggregationTracksMemoryLimits(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('var totalMemLimitBytes = 0;', $source);
        $this->assertStringContainsString('totalMemLimitBytes += composeLoadById[ctId].memLimitBytes || 0;', $source);
        $this->assertStringContainsString('var stackMemTotalBytes = 0;', $source);
        $this->assertStringContainsString('stackMemTotalBytes = Math.min(totalMemLimitBytes, composeSystemMemBytes);', $source);
    }

    public function testDockerLoadMapStoresParsedLimitBytes(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('var memPair = parseMemUsagePair(parts[2]);', $source);
        $this->assertStringContainsString('memLimitBytes: memPair.limit,', $source);
    }
}
