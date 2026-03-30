<?php

/**
 * Unit Tests for Compose List HTML Structure
 * 
 * Tests the HTML output structure in compose_list.php, verifying column classes,
 * update column selector, expand arrow spacing, and autostart cell class.
 * These are source-level tests that verify the PHP template markup.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

class ComposeListHtmlTest extends TestCase
{
    private string $listPagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listPagePath = __DIR__ . '/../../source/compose.manager/include/ComposeList.php';
        $this->assertFileExists($this->listPagePath, 'ComposeList.php must exist');
    }

    private function getPageSource(): string
    {
        return file_get_contents($this->listPagePath);
    }

    // ===========================================
    // Update Column Class Tests
    // ===========================================

    public function testUpdateCellHasCorrectClass(): void
    {
        $source = $this->getPageSource();
        // The update cell class must include 'compose-updatecolumn' (not 'updatecolumn')
        $this->assertStringContainsString('compose-updatecolumn', $source);
    }

    public function testUpdateCellDoesNotUseBareUpdateColumn(): void
    {
        $source = $this->getPageSource();
        // Ensure we don't have the unprefixed class that would conflict with Docker tab
        $this->assertStringNotContainsString("class='updatecolumn'", $source);
    }

    // ===========================================
    // Expand Arrow Tests
    // ===========================================

    public function testExpandArrowIconClassExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('expand-icon', $source);
        $this->assertStringContainsString('fa-chevron-right', $source);
    }

    // ===========================================
    // Autostart Cell Tests
    // ===========================================

    public function testAutostartCellHasClassNine(): void
    {
        $source = $this->getPageSource();
        // Autostart cell includes class 'nine' for CSS targeting
        $this->assertMatchesRegularExpression("/class='[^']*\\bnine\\b[^']*'/", $source);
    }

    public function testAutostartCheckboxHasDataAttribute(): void
    {
        $source = $this->getPageSource();
        // Autostart checkbox should have data-scriptName for the stack
        $this->assertStringContainsString("class='auto_start'", $source);
        $this->assertStringContainsString('data-scriptName', $source);
    }

    // ===========================================
    // Stack Row Structure Tests
    // ===========================================

    public function testStackRowHasSortableClass(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString("class='compose-sortable'", $source);
    }

    public function testStackRowHasDataAttributes(): void
    {
        $source = $this->getPageSource();
        // Stack rows must have data attributes for JS interaction
        $this->assertStringContainsString("data-project=", $source);
        $this->assertStringContainsString("data-projectname=", $source);
        $this->assertStringContainsString("data-isup=", $source);
        $this->assertStringContainsString("data-ctids=", $source);
    }

    // ===========================================
    // Advanced View Column Tests
    // ===========================================

    public function testAdvancedColumnsUseCmAdvancedClass(): void
    {
        $source = $this->getPageSource();
        // Advanced-only columns should include 'cm-advanced' (not bare 'advanced')
        $this->assertMatchesRegularExpression("/class='[^']*\\bcm-advanced\\b[^']*'/", $source);
    }

    public function testAdvancedLoadColumnMarkupExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString("col-load compose-load-cell", $source);
        $this->assertStringContainsString("compose-stack-cpu-", $source);
        $this->assertStringContainsString("compose-stack-mem-", $source);
    }

    // ===========================================
    // Container Count Display Tests
    // ===========================================

    public function testContainerCountDisplayExists(): void
    {
        $source = $this->getPageSource();
        // Container count uses running/total format
        $this->assertStringContainsString('$runningCount', $source);
        $this->assertStringContainsString('$containerCount', $source);
    }

    // ===========================================
    // Empty State Tests
    // ===========================================

    public function testNoStacksMessageExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('No Docker Compose stacks found', $source);
        $this->assertStringContainsString('Add New Stack', $source);
        $this->assertStringContainsString("colspan='10'", $source);
    }

    // ===========================================
    // Loading Spinner Tests
    // ===========================================

    public function testLoadingSpinnerInDetailRow(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('fa-spinner fa-spin compose-spinner', $source);
        $this->assertStringContainsString('Loading containers...', $source);
    }

    // ===========================================
    // Status Icon Tests
    // ===========================================

    public function testStatusIconHasDataAttribute(): void
    {
        $source = $this->getPageSource();
        // Status icon should have data-status for debugging
        $this->assertStringContainsString("data-status='", $source);
        $this->assertStringContainsString('compose-status-icon', $source);
    }
}
