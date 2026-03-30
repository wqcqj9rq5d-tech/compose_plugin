<?php
/**
 * Tests for autoupdate.php endpoints
 */
declare(strict_types=1);
namespace ComposeManager\Tests;

use PluginTests\TestCase;

class AutoupdateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure plugin_root writable mapping exists (framework provides)
        global $plugin_root;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager/';
        // Clean any existing autoupdate.json
        if (is_file($plugin_root . 'autoupdate.json')) unlink($plugin_root . 'autoupdate.json');
        // Ensure scripts path exists
        if (!is_dir($plugin_root . 'scripts')) mkdir($plugin_root . 'scripts', 0755, true);
        // Ensure php helper path exists for test shim
        if (!is_dir($plugin_root . 'php')) mkdir($plugin_root . 'php', 0755, true);
        // create a simple PHP shim that will be invoked instead of a real `sh` during tests
        $shim = <<<'PHP'
    <?php
    $marker = getenv('AUTOTEST_MARKER');
    if ($marker) file_put_contents($marker, "OK\n");
    exit(0);
    PHP;
        file_put_contents($plugin_root . 'php/sh_wrapper.php', $shim);
        // redirect cron writes to plugin root in tests
        putenv('COMPOSE_MANAGER_CRON_DIR=' . $plugin_root);
    }

    protected function tearDown(): void
    {
        putenv('COMPOSE_MANAGER_CRON_DIR');
        putenv('COMPOSE_MANAGER_SH');
        putenv('AUTOTEST_MARKER');
        putenv('COMPOSE_MANAGER_AUTOUPDATE_FILE');
        $_POST = [];
        parent::tearDown();
    }

    public function testGetConfigWhenMissingReturnsEmptyObject(): void
    {
        $_POST = ['action' => 'getConfig'];
        ob_start();
        include '/usr/local/emhttp/plugins/compose.manager/include/AutoUpdate.php';
        $out = ob_get_clean();
        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertEmpty($json);
        $_POST = [];
    }

    public function testRunNowMissingPathReturnsError(): void
    {
        $_POST = ['action' => 'runNow'];
        ob_start(); include '/usr/local/emhttp/plugins/compose.manager/include/AutoUpdate.php'; $out = ob_get_clean();
        $r = json_decode($out, true);
        $this->assertArrayHasKey('error', $r);
        $_POST = [];
    }

    public function testRunNowExecutesScript(): void
    {
        // Create a fake stack under compose_root so path validation passes
        global $plugin_root, $compose_root;
        $tmp = $compose_root . '/autoupdate_test_' . getmypid();
        if (!is_dir($tmp)) mkdir($tmp, 0755, true);
        file_put_contents($tmp . '/docker-compose.yml', "services:\n  a:\n    image: busybox\n");

        // Create stub script that writes marker file
        $marker = sys_get_temp_dir() . '/autoupdate_marker_' . getmypid();
        $scriptPath = $plugin_root . 'scripts/compose_autoupdate.sh';
        // real script file (not executed directly in tests because we override COMPOSE_MANAGER_SH)
        $script = "#!/bin/sh\necho SHOULD_NOT_RUN > /dev/null\nexit 0\n";
        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);

        // configure test shim so the autoupdate code executes our PHP wrapper instead of a system sh
        putenv('AUTOTEST_MARKER=' . $marker);
        $wrapper = $plugin_root . 'php/sh_wrapper.php';
        putenv('COMPOSE_MANAGER_SH=' . PHP_BINARY . ' ' . escapeshellarg($wrapper));

        $_POST = ['action' => 'runNow', 'path' => $tmp];
        ob_start(); include '/usr/local/emhttp/plugins/compose.manager/include/AutoUpdate.php'; $out = ob_get_clean();
        $r = json_decode($out, true);
        // rc should be 0 for our stub
        $this->assertEquals(0, $r['rc']);
        $this->assertFileExists($marker);

        // cleanup
        unlink($marker);
        unlink($scriptPath);
        // remove tmp
        unlink($tmp . '/docker-compose.yml'); rmdir($tmp);
        $_POST = [];
    }

    public function testInstallCronUsesAbsolutePhpBinary(): void
    {
        global $plugin_root;

        $cronFile = $plugin_root . 'compose_manager_autoupdate';
        if (is_file($cronFile)) {
            unlink($cronFile);
        }

        $_POST = ['action' => 'installCron'];
        ob_start();
        include '/usr/local/emhttp/plugins/compose.manager/include/AutoUpdate.php';
        $out = ob_get_clean();

        $response = json_decode($out, true);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('ok', $response);
        $this->assertTrue((bool)$response['ok']);
        $this->assertFileExists($cronFile);

        $line = file_get_contents($cronFile);
        $this->assertStringContainsString('/usr/bin/php', $line);
        $this->assertStringContainsString('AutoUpdateRunner.php', $line);

        if (is_file($cronFile)) {
            unlink($cronFile);
        }
        $_POST = [];
    }
}
