<?php

/**
 * Unit Tests for ExecHelpers.php (REAL SOURCE)
 * 
 * Tests the actual source functions from source/compose.manager/include/ExecHelpers.php
 * 
 * ExecHelpers.php contains these functions:
 * - getElement($element) - converts element name to safe HTML ID
 * - normalizeImageForUpdateCheck($image) - normalizes Docker image names for update checking
 * - sanitizeFolderName($stackName) - sanitizes stack names for folder creation
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

// Load the actual source functions file directly (no switch statement to bypass)
require_once '/usr/local/emhttp/plugins/compose.manager/include/ExecHelpers.php';

/**
 * @covers ::getElement
 * @covers ::normalizeImageForUpdateCheck
 * @covers ::sanitizeFolderName
 */
class ExecFunctionsTest extends TestCase
{
    // ===========================================
    // getElement() Tests
    // ===========================================

    /**
     * Test getElement replaces dots with dashes
     */
    public function testGetElementReplacesDots(): void
    {
        $result = getElement('my.stack.name');
        $this->assertEquals('my-stack-name', $result);
    }

    /**
     * Test getElement removes spaces
     */
    public function testGetElementRemovesSpaces(): void
    {
        $result = getElement('my stack name');
        $this->assertEquals('mystackname', $result);
    }

    /**
     * Test getElement handles combined cases
     */
    public function testGetElementCombined(): void
    {
        $result = getElement('My.Stack Name');
        $this->assertEquals('My-StackName', $result);
    }

    /**
     * Test getElement with empty string
     */
    public function testGetElementEmptyString(): void
    {
        $result = getElement('');
        $this->assertEquals('', $result);
    }

    /**
     * Test getElement preserves other characters
     */
    public function testGetElementPreservesOtherChars(): void
    {
        $result = getElement('stack-name_123');
        $this->assertEquals('stack-name_123', $result);
    }

    /**
     * Test getElement with only dots
     */
    public function testGetElementOnlyDots(): void
    {
        $result = getElement('...');
        $this->assertEquals('---', $result);
    }

    /**
     * Test getElement with mixed special chars
     */
    public function testGetElementMixedChars(): void
    {
        $result = getElement('my.stack name-test_123');
        $this->assertEquals('my-stackname-test_123', $result);
    }

    // ===========================================
    // normalizeImageForUpdateCheck() Tests  
    // ===========================================

    /**
     * Test normalizeImageForUpdateCheck strips docker.io prefix
     */
    public function testNormalizeImageStripsDockerIoPrefix(): void
    {
        $result = normalizeImageForUpdateCheck('docker.io/library/nginx:latest');
        $this->assertEquals('library/nginx:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck strips sha256 digest
     */
    public function testNormalizeImageStripsSha256Digest(): void
    {
        $result = normalizeImageForUpdateCheck('nginx:latest@sha256:abc123def456');
        $this->assertEquals('library/nginx:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck handles Docker Hub official images
     */
    public function testNormalizeImageHandlesOfficialImages(): void
    {
        // Official images should get library/ prefix added
        $result = normalizeImageForUpdateCheck('nginx');
        $this->assertEquals('library/nginx:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck handles images without tag
     */
    public function testNormalizeImageAddsLatestTag(): void
    {
        $result = normalizeImageForUpdateCheck('myuser/myapp');
        $this->assertEquals('myuser/myapp:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with docker.io and sha256
     */
    public function testNormalizeImageCombined(): void
    {
        $result = normalizeImageForUpdateCheck('docker.io/myuser/myapp:v1.0@sha256:abc123');
        $this->assertEquals('myuser/myapp:v1.0', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck preserves registry prefix for non-Docker Hub
     */
    public function testNormalizeImagePreservesCustomRegistry(): void
    {
        $result = normalizeImageForUpdateCheck('ghcr.io/owner/repo:tag');
        $this->assertEquals('ghcr.io/owner/repo:tag', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with full Docker Hub format
     */
    public function testNormalizeImageFullDockerHub(): void
    {
        $result = normalizeImageForUpdateCheck('docker.io/nginx');
        $this->assertEquals('library/nginx:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with versioned tag
     */
    public function testNormalizeImageVersionedTag(): void
    {
        $result = normalizeImageForUpdateCheck('nginx:1.25.0');
        $this->assertEquals('library/nginx:1.25.0', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with user image and tag
     */
    public function testNormalizeImageUserWithTag(): void
    {
        $result = normalizeImageForUpdateCheck('linuxserver/plex:latest');
        $this->assertEquals('linuxserver/plex:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with quay.io registry
     */
    public function testNormalizeImageQuayRegistry(): void
    {
        $result = normalizeImageForUpdateCheck('quay.io/prometheus/alertmanager:v0.25.0');
        $this->assertEquals('quay.io/prometheus/alertmanager:v0.25.0', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with digest only (no tag)
     */
    public function testNormalizeImageDigestOnly(): void
    {
        // docker.io/library/nginx@sha256:abc123... -> library/nginx:latest
        $result = normalizeImageForUpdateCheck('docker.io/library/nginx@sha256:abc123def456');
        $this->assertEquals('library/nginx:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with lscr.io (Linuxserver new registry)
     */
    public function testNormalizeImageLscrRegistry(): void
    {
        $result = normalizeImageForUpdateCheck('lscr.io/linuxserver/plex:latest');
        $this->assertEquals('lscr.io/linuxserver/plex:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with port in registry
     */
    public function testNormalizeImageRegistryWithPort(): void
    {
        $result = normalizeImageForUpdateCheck('registry.local:5000/myapp:v1');
        $this->assertEquals('registry.local:5000/myapp:v1', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with deeply nested path
     */
    public function testNormalizeImageDeepPath(): void
    {
        $result = normalizeImageForUpdateCheck('gcr.io/google-containers/kube-apiserver:v1.28.0');
        $this->assertEquals('gcr.io/google-containers/kube-apiserver:v1.28.0', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with sha256 in middle of tag (edge case)
     */
    public function testNormalizeImageSha256InTag(): void
    {
        // A tag that happens to contain "sha256" shouldn't be stripped
        $result = normalizeImageForUpdateCheck('myuser/myapp:sha256test');
        $this->assertEquals('myuser/myapp:sha256test', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck handles empty string gracefully
     */
    public function testNormalizeImageEmptyString(): void
    {
        $result = normalizeImageForUpdateCheck('');
        $this->assertEquals('library/:latest', $result);
    }

    /**
     * Test normalizeImageForUpdateCheck with uppercase in image name
     */
    public function testNormalizeImageUppercase(): void
    {
        // Docker image names should be lowercase but we don't change case
        $result = normalizeImageForUpdateCheck('MyUser/MyApp:Latest');
        $this->assertEquals('MyUser/MyApp:Latest', $result);
    }
}
