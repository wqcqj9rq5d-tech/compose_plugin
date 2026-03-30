<?php

/**
 * Unit Tests for Docker Update Checking
 * 
 * Tests the normalizeImageForUpdateCheck function and DockerUpdate integration.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;
use PluginTests\Mocks\DockerUtilMock;

// Load the actual source file via stream wrapper using includeWithSwitch()
// This safely includes exec.php which has a switch($_POST['action']) block
includeWithSwitch('/usr/local/emhttp/plugins/compose.manager/include/Exec.php');

class DockerUpdateTest extends TestCase
{
    // ===========================================
    // Image Normalization for Update Checking
    // ===========================================

    /**
     * Test normalizeImageForUpdateCheck with various Docker Hub formats
     */
    public function testNormalizeDockerHubFormats(): void
    {
        // docker.io prefix is stripped
        $this->assertEquals(
            'library/nginx:latest',
            normalizeImageForUpdateCheck('docker.io/nginx')
        );
        
        // docker.io/library prefix is stripped to just library
        $this->assertEquals(
            'library/nginx:latest',
            normalizeImageForUpdateCheck('docker.io/library/nginx')
        );
        
        // User images from docker.io
        $this->assertEquals(
            'linuxserver/plex:latest',
            normalizeImageForUpdateCheck('docker.io/linuxserver/plex')
        );
    }

    /**
     * Test sha256 digest stripping
     */
    public function testStripsSha256Digest(): void
    {
        // Image with both tag and digest
        $this->assertEquals(
            'library/nginx:1.25',
            normalizeImageForUpdateCheck('nginx:1.25@sha256:abc123def456')
        );
        
        // Image with only digest (no tag)
        $this->assertEquals(
            'library/redis:latest',
            normalizeImageForUpdateCheck('redis@sha256:abc123')
        );
        
        // Full docker.io path with digest
        $this->assertEquals(
            'myuser/myapp:v2.0',
            normalizeImageForUpdateCheck('docker.io/myuser/myapp:v2.0@sha256:xyz789')
        );
    }

    // ===========================================
    // DockerUpdate Mock Integration
    // ===========================================

    /**
     * Test image is up to date when local and remote SHA match
     */
    public function testImageUpToDate(): void
    {
        $image = 'library/nginx:latest';
        $sha = 'sha256:abc123def456';
        
        $this->mockUpdateStatus($image, $sha, $sha);
        
        $update = new \DockerUpdate();
        $update->reloadUpdateStatus($image);
        
        $this->assertTrue($update->getUpdateStatus($image));
    }

    /**
     * Test image has update available when SHA differs
     */
    public function testImageHasUpdate(): void
    {
        $image = 'library/redis:7';
        
        $this->mockUpdateStatus($image, 'sha256:old111', 'sha256:new222');
        
        $update = new \DockerUpdate();
        $update->reloadUpdateStatus($image);
        
        $this->assertFalse($update->getUpdateStatus($image));
    }

    /**
     * Test unknown image returns null status
     */
    public function testUnknownImageStatus(): void
    {
        $update = new \DockerUpdate();
        $update->reloadUpdateStatus('nonexistent/image:tag');
        
        $this->assertNull($update->getUpdateStatus('nonexistent/image:tag'));
    }

    /**
     * Test image with null local SHA returns unknown
     */
    public function testMissingLocalSha(): void
    {
        $image = 'library/mysql:8';
        
        $this->mockUpdateStatus($image, null, 'sha256:remote123');
        
        $update = new \DockerUpdate();
        $update->reloadUpdateStatus($image);
        
        $this->assertNull($update->getUpdateStatus($image));
    }

    // ===========================================
    // DockerClient Mock Integration
    // ===========================================

    /**
     * Test DockerClient returns mocked containers
     */
    public function testDockerClientGetContainers(): void
    {
        $this->mockContainers([
            'compose_nginx_1' => [
                'Name' => 'compose_nginx_1',
                'Image' => 'nginx:latest',
                'State' => 'running',
                'Labels' => 'com.docker.compose.project=mystack',
            ],
            'compose_redis_1' => [
                'Name' => 'compose_redis_1', 
                'Image' => 'redis:7',
                'State' => 'running',
                'Labels' => 'com.docker.compose.project=mystack',
            ],
        ]);
        
        $client = new \DockerClient();
        $containers = $client->getDockerContainers();
        
        $this->assertCount(2, $containers);
        $this->assertEquals('compose_nginx_1', $containers[0]['Name']);
        $this->assertEquals('running', $containers[0]['State']);
    }

    /**
     * Test filtering running containers
     */
    public function testGetRunningContainers(): void
    {
        $this->mockContainers([
            'running_container' => [
                'Name' => 'running_container',
                'Image' => 'nginx:latest',
                'State' => 'running',
            ],
            'stopped_container' => [
                'Name' => 'stopped_container',
                'Image' => 'redis:latest',
                'State' => 'exited',
            ],
        ]);
        
        $running = \DockerUtil::getRunningContainers();
        
        $this->assertCount(1, $running);
        $this->assertArrayHasKey('running_container', $running);
        $this->assertArrayNotHasKey('stopped_container', $running);
    }

    // ===========================================
    // DockerUtil JSON Methods
    // ===========================================

    /**
     * Test DockerUtil loadJSON and saveJSON
     */
    public function testDockerUtilJsonMethods(): void
    {
        // Use temp directory instead of /var/lib/docker which may not be writable
        $testDir = sys_get_temp_dir() . '/compose_test_' . getmypid();
        @mkdir($testDir, 0755, true);
        $path = $testDir . '/unraid-update-status.json';
        
        $data = [
            'library/nginx:latest' => [
                'local' => 'sha256:abc',
                'remote' => 'sha256:abc',
            ],
            'library/redis:7' => [
                'local' => 'sha256:old',
                'remote' => 'sha256:new',
            ],
        ];
        
        // Save JSON
        \DockerUtil::saveJSON($path, $data);
        
        // Load it back
        $loaded = \DockerUtil::loadJSON($path);
        
        $this->assertEquals($data, $loaded);
        $this->assertArrayHasKey('library/nginx:latest', $loaded);
        
        // Cleanup
        @unlink($path);
        @rmdir($testDir);
    }

    /**
     * Test loading non-existent JSON file returns empty array
     */
    public function testLoadNonExistentJson(): void
    {
        $result = \DockerUtil::loadJSON('/nonexistent/path.json');
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ===========================================
    // DockerClient Container Operations Tests
    // ===========================================

    /**
     * Test DockerClient.doesContainerExist returns true when container exists
     */
    public function testDockerClientContainerExists(): void
    {
        $this->mockContainers([
            'my_container' => [
                'Name' => 'my_container',
                'Image' => 'nginx:latest',
                'State' => 'running',
            ],
        ]);
        
        $client = new \DockerClient();
        
        $this->assertTrue($client->doesContainerExist('my_container'));
        $this->assertFalse($client->doesContainerExist('nonexistent_container'));
    }

    /**
     * Test DockerClient.getContainerID returns correct ID
     */
    public function testDockerClientGetContainerID(): void
    {
        $this->mockContainers([
            'webserver' => [
                'Name' => 'webserver',
                'Id' => 'abc123def456',
                'Image' => 'nginx:latest',
                'State' => 'running',
            ],
        ]);
        
        $client = new \DockerClient();
        
        $this->assertEquals('abc123def456', $client->getContainerID('webserver'));
        $this->assertNull($client->getContainerID('unknown'));
    }

    /**
     * Test DockerClient container operations return success
     */
    public function testDockerClientContainerOperations(): void
    {
        $this->mockContainers([
            'test_container' => [
                'Name' => 'test_container',
                'Id' => 'container123',
                'Image' => 'nginx:latest',
                'State' => 'running',
            ],
        ]);
        
        $client = new \DockerClient();
        
        // Test start
        $this->assertTrue($client->startContainer('container123'));
        
        // Test stop
        $this->assertTrue($client->stopContainer('container123'));
        
        // Test restart
        $this->assertTrue($client->restartContainer('container123'));
        
        // Test pause/resume
        $this->assertTrue($client->pauseContainer('container123'));
        $this->assertTrue($client->resumeContainer('container123'));
        
        // Test remove
        $this->assertTrue($client->removeContainer('test_container', 'container123'));
    }

    /**
     * Test DockerClient.getInfo returns docker info
     */
    public function testDockerClientGetInfo(): void
    {
        $client = new \DockerClient();
        $info = $client->getInfo();
        
        $this->assertIsArray($info);
        $this->assertArrayHasKey('ServerVersion', $info);
        $this->assertArrayHasKey('NCPU', $info);
        $this->assertArrayHasKey('MemTotal', $info);
    }

    /**
     * Test DockerClient.humanTiming formats time correctly
     */
    public function testDockerClientHumanTiming(): void
    {
        $client = new \DockerClient();
        
        // Test seconds ago
        $result = $client->humanTiming(time() - 30);
        $this->assertStringContainsString('second', $result);
        
        // Test minutes ago
        $result = $client->humanTiming(time() - 120);
        $this->assertStringContainsString('minute', $result);
        
        // Test hours ago
        $result = $client->humanTiming(time() - 7200);
        $this->assertStringContainsString('hour', $result);
    }

    /**
     * Test DockerClient.formatBytes formats sizes correctly
     */
    public function testDockerClientFormatBytes(): void
    {
        $client = new \DockerClient();
        
        $this->assertEquals('0 B', $client->formatBytes(0));
        $this->assertStringContainsString('B', $client->formatBytes(512));
        $this->assertStringContainsString('KB', $client->formatBytes(1024));
        $this->assertStringContainsString('MB', $client->formatBytes(1024 * 1024));
        $this->assertStringContainsString('GB', $client->formatBytes(1024 * 1024 * 1024));
    }

    /**
     * Test DockerClient.getRegistryAuth parses Docker Hub images
     */
    public function testDockerClientGetRegistryAuth(): void
    {
        $client = new \DockerClient();
        
        // Test Docker Hub official image
        $auth = $client->getRegistryAuth('library/nginx:latest');
        
        $this->assertIsArray($auth);
        $this->assertArrayHasKey('imageName', $auth);
        $this->assertArrayHasKey('imageTag', $auth);
        $this->assertArrayHasKey('apiUrl', $auth);
    }

    /**
     * Test DockerClient.flushCaches clears cache
     */
    public function testDockerClientFlushCaches(): void
    {
        $this->mockContainers([
            'container1' => [
                'Name' => 'container1',
                'Image' => 'nginx:latest',
                'State' => 'running',
            ],
        ]);
        
        $client = new \DockerClient();
        
        // First call populates cache
        $containers = $client->getDockerContainers();
        $this->assertCount(1, $containers);
        
        // Flush caches
        $client->flushCaches();
        
        // Should still work after flush
        $containers = $client->getDockerContainers();
        $this->assertCount(1, $containers);
    }

    // ===========================================
    // DockerUtil Additional Tests
    // ===========================================

    /**
     * Test DockerUtil.getContainer returns container by name
     */
    public function testDockerUtilGetContainer(): void
    {
        $this->mockContainers([
            'webserver' => [
                'Name' => 'webserver',
                'Image' => 'nginx:latest',
                'State' => 'running',
                'IPAddress' => '172.17.0.5',
            ],
        ]);
        
        $container = \DockerUtil::getContainer('webserver');
        
        $this->assertIsArray($container);
        $this->assertEquals('webserver', $container['Name']);
        $this->assertEquals('172.17.0.5', $container['IPAddress']);
    }

    /**
     * Test DockerUtil.getContainer returns null for unknown container
     */
    public function testDockerUtilGetContainerUnknown(): void
    {
        $this->mockContainers([]);
        
        $container = \DockerUtil::getContainer('nonexistent');
        
        $this->assertNull($container);
    }

    /**
     * Test DockerUtil.myIP returns container IP
     */
    public function testDockerUtilMyIP(): void
    {
        $this->mockContainers([
            'webserver' => [
                'Name' => 'webserver',
                'Image' => 'nginx:latest',
                'State' => 'running',
                'IPAddress' => '172.17.0.5',
            ],
        ]);
        
        $ip = \DockerUtil::myIP('webserver');
        
        $this->assertEquals('172.17.0.5', $ip);
    }

    /**
     * Test DockerUtil.docker executes command (mock returns empty)
     */
    public function testDockerUtilDockerCommand(): void
    {
        // String return
        $result = \DockerUtil::docker('ps');
        $this->assertEquals('', $result);
        
        // Array return
        $result = \DockerUtil::docker('ps', true);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test DockerUtil network methods
     */
    public function testDockerUtilNetworkMethods(): void
    {
        $drivers = \DockerUtil::driver();
        $this->assertIsArray($drivers);
        $this->assertArrayHasKey('bridge', $drivers);
        $this->assertArrayHasKey('host', $drivers);
        
        $custom = \DockerUtil::custom();
        $this->assertIsArray($custom);
        
        $networks = \DockerUtil::network($custom);
        $this->assertIsArray($networks);
        $this->assertArrayHasKey('bridge', $networks);
    }

    /**
     * Test DockerUtil.cpus returns available CPUs
     */
    public function testDockerUtilCpus(): void
    {
        $cpus = \DockerUtil::cpus();
        
        $this->assertIsArray($cpus);
        $this->assertNotEmpty($cpus);
    }

    /**
     * Test DockerUtil.host returns host IP
     */
    public function testDockerUtilHost(): void
    {
        $host = \DockerUtil::host();
        
        $this->assertIsString($host);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+\.\d+$/', $host);
    }

    /**
     * Test DockerUtil.port returns network port
     */
    public function testDockerUtilPort(): void
    {
        $port = \DockerUtil::port();
        
        $this->assertIsString($port);
        $this->assertNotEmpty($port);
    }

    /**
     * Test DockerUtil.ctMap returns container property
     */
    public function testDockerUtilCtMap(): void
    {
        $this->mockContainers([
            'webserver' => [
                'Name' => 'webserver',
                'Image' => 'nginx:latest',
                'State' => 'running',
            ],
        ]);
        
        $name = \DockerUtil::ctMap('webserver', 'Name');
        $this->assertEquals('webserver', $name);
        
        $image = \DockerUtil::ctMap('webserver', 'Image');
        $this->assertEquals('nginx:latest', $image);
        
        // Unknown property
        $unknown = \DockerUtil::ctMap('webserver', 'Unknown');
        $this->assertEquals('', $unknown);
    }
}
