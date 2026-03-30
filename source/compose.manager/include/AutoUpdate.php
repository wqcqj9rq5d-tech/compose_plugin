<?php
require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");

$action = isset($_POST['action']) ? $_POST['action'] : '';
$autofile = getAutoUpdateConfigFilePath();
$legacyAutofile = rtrim($plugin_root ?? '', '/') . "/autoupdate.json";

if ($autofile !== $legacyAutofile && !is_file($autofile) && is_file($legacyAutofile)) {
    $targetDir = dirname($autofile);
    if (is_dir($targetDir) || @mkdir($targetDir, 0755, true)) {
        @copy($legacyAutofile, $autofile);
    }
}

header('Content-Type: application/json');

switch ($action) {
    case 'getConfig':
        if (is_file($autofile)) {
            $raw = file_get_contents($autofile);
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                echo json_encode(array());
            } else {
                echo $raw;
            }
        } else {
            echo json_encode(array());
        }
        break;
    case 'saveConfig':
        $data = isset($_POST['data']) ? $_POST['data'] : '{}';
        // basic validation
        $arr = json_decode($data, true);
        if ($arr === null && $data !== 'null') {
            http_response_code(400);
            echo json_encode(array('error' => 'Invalid JSON'));
            break;
        }
        // Normalize keys: ensure top-level is array
        if (!is_array($arr)) $arr = array();
        // Filter top-level keys: only allow stack paths that pass isAllowedAutoUpdatePath
        // Skip validation for 'defaults' key which stores default settings
        $filtered = array();
        foreach ($arr as $stackPath => $config) {
            if ($stackPath === 'defaults' || (is_string($stackPath) && isAllowedAutoUpdatePath($stackPath))) {
                $filtered[$stackPath] = $config;
            }
        }
        // ensure directory exists (test environment may map plugin_root differently)
        $dir = dirname($autofile);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                http_response_code(500);
                echo json_encode(array('error' => 'Failed to create config directory'));
                break;
            }
        }
        if (file_put_contents($autofile, json_encode($filtered, JSON_PRETTY_PRINT)) === false) {
            http_response_code(500);
            echo json_encode(array('error' => 'Failed to write config file'));
            break;
        }
        echo json_encode(array('ok' => true));
        break;
    case 'installCron':
        // Cron runs with a minimal PATH; use absolute PHP binary to avoid exit status 127.
        $phpBinary = '/usr/bin/php';
        $cronDirEnv = getenv('COMPOSE_MANAGER_CRON_DIR');
        // If a cron directory is explicitly provided (e.g., in tests), keep legacy file-based behavior.
        if ($cronDirEnv !== false && $cronDirEnv !== '') {
            $cronDir = $cronDirEnv;
            $cronFile = rtrim($cronDir, '/') . '/compose_manager_autoupdate';
            $runner = escapeshellarg($plugin_root . "include/AutoUpdateRunner.php");
            $line = "*/15 * * * * root " . escapeshellarg($phpBinary) . " " . $runner . " >/dev/null 2>&1\n";
            // ensure cron directory exists for test environments
            $cdir = dirname($cronFile);
            if (!is_dir($cdir)) mkdir($cdir, 0755, true);
            if (file_put_contents($cronFile, $line) !== false) {
                echo json_encode(array('ok' => true));
            } else {
                http_response_code(500);
                echo json_encode(array('error' => 'Failed to write cron file'));
            }
        } else {
            // Default behavior: use plugin-owned cron file and let update_cron sync it to the system
            $pluginCron = '/boot/config/plugins/compose.manager/compose.manager.cron';
            $runner = escapeshellarg($plugin_root . "include/AutoUpdateRunner.php");
            $line = "*/15 * * * * root " . escapeshellarg($phpBinary) . " " . $runner . " >/dev/null 2>&1\n";

            if (!is_dir(dirname($pluginCron))) {
                @mkdir(dirname($pluginCron), 0755, true);
            }
            if (file_put_contents($pluginCron, $line) === false) {
                http_response_code(500);
                echo json_encode(array('error' => 'Failed to write plugin cron file'));
                break;
            }

            // Sync into /etc/cron.d
            exec('/usr/local/sbin/update_cron 2>/dev/null', $output, $returnVar);
            if ($returnVar === 0) {
                echo json_encode(array('ok' => true));
            } else {
                http_response_code(500);
                echo json_encode(array('error' => 'Failed to update cron (update_cron)'));
            }
        }
        break;
    case 'removeCron':
        $cronDirEnv = getenv('COMPOSE_MANAGER_CRON_DIR');
        if ($cronDirEnv !== false && $cronDirEnv !== '') {
            // Legacy/test behavior: remove cron.d file if it exists.
            $cronDir = $cronDirEnv;
            $cronFile = rtrim($cronDir, '/') . '/compose_manager_autoupdate';
            if (is_file($cronFile)) unlink($cronFile);
            echo json_encode(array('ok' => true));
        } else {
            // Default behavior: remove the plugin cron file and sync via update_cron
            $pluginCron = '/boot/config/plugins/compose.manager/compose.manager.cron';
            if (is_file($pluginCron)) {
                unlink($pluginCron);
            }
            exec('/usr/local/sbin/update_cron 2>/dev/null', $output, $returnVar);
            if ($returnVar === 0) {
                echo json_encode(array('ok' => true));
            } else {
                http_response_code(500);
                echo json_encode(array('error' => 'Failed to update cron (update_cron)'));
            }
        }
        break;
    case 'getCronStatus':
        $cronDirEnv = getenv('COMPOSE_MANAGER_CRON_DIR');
        if ($cronDirEnv !== false && $cronDirEnv !== '') {
            // Legacy/test behavior: check for cron.d file existence.
            $cronDir = $cronDirEnv;
            $cronFile = rtrim($cronDir, '/') . '/compose_manager_autoupdate';
            echo json_encode(array('installed' => is_file($cronFile)));
        } else {
            // Default behavior: check for the plugin cron file.
            $pluginCron = '/boot/config/plugins/compose.manager/compose.manager.cron';
            echo json_encode(array('installed' => is_file($pluginCron)));
        }
        break;
    case 'runNow':
        $path = isset($_POST['path']) ? $_POST['path'] : '';
        if (!$path) {
            http_response_code(400);
            echo json_encode(array('error' => 'Missing path'));
            break;
        }
        // Security: validate path is under allowed directories
        if (!isAllowedAutoUpdatePath($path)) {
            clientDebug("[autoupdate] Rejected invalid path: " . sanitizeLogText($path), null, 'daemon', 'error');
            http_response_code(403);
            echo json_encode(array('error' => 'Path not allowed'));
            break;
        }
        $composeFile = findComposeFile($path);
        if (!$composeFile) {
            http_response_code(404);
            echo json_encode(array('error' => 'Compose file not found'));
            break;
        }
        // Resolve project name - try to find the StackInfo, fall back to basename
        $stackInfo = StackInfo::fromComposePath($compose_root, $path);
        if ($stackInfo !== null) {
            $projectName = $stackInfo->projectFolder;
        } else {
            $projectName = basename($path);
            if (is_file("$path/name")) {
                $projectName = trim(file_get_contents("$path/name"));
            }
            $projectName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $projectName);
        }

        clientDebug("[autoupdate] Running manual auto-update for: $projectName", null, 'daemon', 'info');

        $script = $plugin_root . "scripts/compose_autoupdate.sh";
        // Allow overriding the shell command via environment for tests; default to sh
        $shCmd = getenv('COMPOSE_MANAGER_SH') ? getenv('COMPOSE_MANAGER_SH') : 'sh';
        $cmd = $shCmd . ' ' . escapeshellarg($script) . " " . escapeshellarg($composeFile) . " " . escapeshellarg($projectName) . " 2>&1";
        exec($cmd, $output, $rc);
        echo json_encode(array('rc' => $rc, 'output' => $output));
        break;
    default:
        http_response_code(400);
        echo json_encode(array('error' => 'Unknown action'));
        break;
}
