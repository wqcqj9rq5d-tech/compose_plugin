<?php
/**
 * Tests for AutoUpdateRunner.php
 */
declare(strict_types=1);
namespace ComposeManager\Tests;

use PluginTests\TestCase;

class AutoupdateRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $plugin_root, $compose_root;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager/';
        $compose_root = sys_get_temp_dir() . '/compose_test_runner_' . getmypid();
        if (!is_dir($compose_root)) mkdir($compose_root, 0755, true);
        // ensure scripts dir
        if (!is_dir($plugin_root . 'scripts')) mkdir($plugin_root . 'scripts', 0755, true);
        // ensure include helper path and create shim
        if (!is_dir($plugin_root . 'include')) mkdir($plugin_root . 'include', 0755, true);
        $shim = <<<'PHP'
<?php
$marker = getenv('AUTOTEST_MARKER');
if ($marker) file_put_contents($marker, "RAN\n");
exit(0);
PHP;
        file_put_contents($plugin_root . 'include/sh_wrapper.php', $shim);
        // redirect cron writes to plugin root in tests (not strictly needed here but harmless)
        putenv('COMPOSE_MANAGER_CRON_DIR=' . $plugin_root);
        // ensure autoupdate config file resolves to plugin_root in tests
        putenv('COMPOSE_MANAGER_AUTOUPDATE_FILE=' . $plugin_root . 'autoupdate.json');
    }

    protected function tearDown(): void
    {
        global $compose_root;
        putenv('COMPOSE_MANAGER_CRON_DIR');
        putenv('COMPOSE_MANAGER_SH');
        putenv('AUTOTEST_MARKER');
        putenv('COMPOSE_MANAGER_AUTOUPDATE_FILE');
        if (is_dir($compose_root)) {
            foreach (scandir($compose_root) as $f) {
                if ($f === '.' || $f === '..') continue;
                $p = $compose_root . '/' . $f;
                if (is_dir($p)) {
                    foreach (scandir($p) as $ff) { if ($ff==='.'||$ff==='..') continue; unlink($p . '/' . $ff); }
                    rmdir($p);
                }
            }
            rmdir($compose_root);
        }
        parent::tearDown();
    }

    public function testRunnerTriggersScriptForDueStack(): void
    {
        global $plugin_root, $compose_root;
        // Create a stack folder
        $stack = 'mystack';
        $path = $compose_root . '/' . $stack;
        mkdir($path, 0755, true);
        file_put_contents($path . '/docker-compose.yml', "services:\n web:\n  image: busybox\n");

        // Create autoupdate.json with entry scheduled for now
        $now = new \DateTime('now');
        $time = $now->format('H:i');
        $cfg = [ $path => ['enabled' => true, 'schedule' => 'daily', 'time' => $time] ];
        file_put_contents($plugin_root . 'autoupdate.json', json_encode($cfg, JSON_PRETTY_PRINT));

        // Create stub script that writes marker
        $marker = sys_get_temp_dir() . '/autoupdate_runner_marker_' . getmypid();
        $scriptPath = $plugin_root . 'scripts/compose_autoupdate.sh';
        $script = "#!/bin/sh\necho RAN > " . escapeshellarg($marker) . "\nexit 0\n";
        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);

        // configure shim so the runner will invoke our PHP wrapper instead of a real sh
        putenv('AUTOTEST_MARKER=' . $marker);
        $wrapper = $plugin_root . 'include/sh_wrapper.php';
        putenv('COMPOSE_MANAGER_SH=' . PHP_BINARY . ' ' . escapeshellarg($wrapper));

        // Run runner (include directly)
        include $plugin_root . 'include/AutoUpdateRunner.php';

        // The runner backgrounds the script; wait briefly
        sleep(1);
        $this->assertFileExists($marker);

        // cleanup
        unlink($marker);
        unlink($scriptPath);
    }
}
