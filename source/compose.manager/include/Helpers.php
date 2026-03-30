<?php

/**
 * Compose Util Functions for Compose Manager
 *
 * Contains utility functions used by compose_util.php for compose command execution.
 * Separated from compose_util.php to allow unit testing without triggering the switch statement.
 */

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");
require_once("/usr/local/emhttp/plugins/dynamix/include/Wrappers.php");

/**
 * Wait for ttyd UNIX socket to exist.
 *
 * @param string $socketName Base name of socket file (without path)
 * @param int $timeoutMs How long to wait in milliseconds (default 2000)
 * @param int $intervalMs Poll interval in milliseconds (default 100)
 * @return bool True if socket existed within timeout, false otherwise
 */
function waitForTtydSocket($socketName, $timeoutMs = 2000, $intervalMs = 100, $tmpDir = '/var/tmp')
{

    $socketPath = rtrim($tmpDir, '/') . "/$socketName.sock";
    $attempts = max(1, (int) ceil($timeoutMs / $intervalMs));
    for ($i = 0; $i < $attempts; $i++) {
        if (file_exists($socketPath)) {
            clientDebug("ttyd socket ready: $socketPath", ['socket' => $socketPath, 'attempt' => $i], 'daemon', 'debug');
            return true;
        }
        usleep($intervalMs * 1000);
    }
    clientDebug("ttyd socket timeout: $socketPath", ['socket' => $socketPath, 'timeoutMs' => $timeoutMs], 'daemon', 'warning');
    return false;
}

/**
 * Execute a compose command in a ttyd terminal, optionally capturing output to a log file.
 *
 * @param string $cmd The command to execute
 * @param bool $debug Whether to log debug messages
 * @param string $logFile Optional path to write a copy of the output
 */
function execComposeCommandInTTY($cmd, $debug, $logFile = '')
{
    global $socket_name;
    // Use pkill -f for more robust process matching instead of pgrep|awk pipeline
    exec("pkill -f " . escapeshellarg("$socket_name.sock") . " 2>/dev/null");
    usleep(300000); // 300ms for process to exit
    @unlink("/var/tmp/$socket_name.sock");
    $socketPath = escapeshellarg("/var/tmp/$socket_name.sock");
    if ($logFile !== '') {
        // Preserve interactive TTY behavior by running the command under "script"
        // (PTY capture). A plain tee pipeline breaks terminal redraw/spinner output.
        $scriptCmd = "script -qefc " . escapeshellarg($cmd) . " " . escapeshellarg($logFile);
        $innerCmd = "bash -lc " . escapeshellarg($scriptCmd);
        $command = "ttyd -R -o -i $socketPath $innerCmd > /dev/null &";
    } else {
        $command = "ttyd -R -o -i $socketPath $cmd > /dev/null &";
    }
    exec($command);
    clientDebug("Executing command in ttyd: " . $cmd, ['command' => $cmd], 'daemon', 'debug');

    // Wait for the socket to be created to avoid 502 on first open.
    waitForTtydSocket($socket_name);
}

/**
 * Get the last command log file path for a given compose action.
 *
 * @param string $action Compose action (up, down, update, pull, stop, logs)
 * @param string $path Stack path
 * @return string Log file path or empty string when logs should not be saved.
 */
function getLastCmdLogFileForComposeAction($action, $path)
{
    if ($action === 'logs') {
        return '';
    }
    return rtrim($path, '/') . '/last_cmd.log';
}

/**
 * Build and echo a compose command for a single stack.
 *
 * @param string $action The compose action (up, down, update, pull, stop, logs)
 * @param bool $recreate Whether to force recreate containers (adds --force-recreate flag)
 * @param bool $background Whether to run in the background (no terminal window; sends notification on finish)
 */
function echoComposeCommand($action, $recreate = false, $background = false)
{
    /**
     * Note: This function is called from an AJAX endpoint and must be careful to only echo the intended command or JSON response.
     * 
     * POST parameters:
     * 
     * path: the stack path (required)
     * profile: optional comma-separated list of profiles to enable
     * 
     * Security: The 'path' parameter is validated to ensure it is within allowed directories to prevent command injection or unauthorized file access.
     */
    global $plugin_root;
    global $sName;
    global $compose_root;
    $cfg = parse_plugin_cfg($sName);
    $debug = $cfg['DEBUG_TO_LOG'] == "true";
    $path = isset($_POST['path']) ? trim($_POST['path']) : "";
    $profile = isset($_POST['profile']) ? trim($_POST['profile']) : "";
    $unRaidVars = parse_ini_file("/var/local/emhttp/var.ini");
    if ($unRaidVars['mdState'] != "STARTED") {
        echo $plugin_root . "/scripts/arrayNotStarted.sh";
        clientDebug("Cannot perform action: array not started", ['action' => $action, 'path' => $path], 'daemon', 'debug');
    } else {
        clientDebug("Preparing compose command", ['action' => $action, 'path' => $path, 'profile' => $profile, 'recreate' => $recreate, 'background' => $background], 'daemon', 'debug');
        $composeCommand = array($plugin_root . "scripts/compose.sh");

        // Resolve stack identity via StackInfo
        $stackInfo = StackInfo::fromProject($compose_root, basename($path));

        $composeCommand[] = "-c" . $action;
        $composeCommand[] = "-p" . $stackInfo->projectName;

        $composeFile = $stackInfo->composeFilePath ?? ($stackInfo->composeSource . '/' . COMPOSE_FILE_NAMES[0]);
        $composeCommand[] = "-f$composeFile";

        // Prune orphaned services from override before compose up
        if ($action === 'up') {
            $stackInfo->pruneOrphanOverrideServices();
        }

        $composeCommand[] = "-f" . $stackInfo->getOverridePath();

        $envFilePath = $stackInfo->getEnvFilePath();
        if ($envFilePath !== null) {
            $composeCommand[] = "-e" . $envFilePath;
        }

        // Support multiple profiles (comma-separated)
        if ($profile) {
            $profileList = array_map('trim', explode(',', $profile));
            foreach ($profileList as $p) {
                if ($p) {
                    $composeCommand[] = "-g $p";
                }
            }
        }

        // Pass stack path for timestamp saving
        $composeCommand[] = "-s$path";

        // Add recreate flag if requested
        if ($recreate) {
            $composeCommand[] = "--recreate";
        }

        if ($debug) {
            $composeCommand[] = "--debug";
        }

        if ($background) {
            // Run fully in the background using compose_background.sh.
            // Output is captured to last_cmd.log; notification sent on completion.
            $bgScript = $plugin_root . "scripts/compose_background.sh";
            $bgCmd = escapeshellarg($bgScript);
            foreach ($composeCommand as $arg) {
                $bgCmd .= ' ' . escapeshellarg($arg);
            }
            $bgCmd .= ' > /dev/null 2>&1 &';
            exec($bgCmd);
            clientDebug("Background command: " . $bgCmd, ['command' => $bgCmd], 'daemon', 'debug');
            // Signal to JS that this ran in background (no terminal window to open)
            echo json_encode(['background' => true]);
        } elseif ($cfg['OUTPUTSTYLE'] == "ttyd") {
            $logFile = getLastCmdLogFileForComposeAction($action, $path);
            $composeCommandEscaped = array_map(function ($item) {
                return escapeshellarg($item);
            }, $composeCommand);
            $composeCommandStr = join(" ", $composeCommandEscaped);
            execComposeCommandInTTY($composeCommandStr, $debug, $logFile);
            clientDebug("Executing command in ttyd: " . $composeCommandStr, ['command' => $composeCommandStr], 'daemon', 'debug');
            $composeCommand = "/plugins/compose.manager/php/show_ttyd.php" . ($action !== 'logs' ? "?done=1" : "");
            echo $composeCommand;
        } else {
            $i = 0;
            $composeCommand = array_reduce($composeCommand, function ($v1, $v2) use (&$i) {
                if ($v2[0] == "-") {
                    $i++; // increment $i
                    return $v1 . "&arg" . $i . "=" . $v2;
                } else {
                    return $v1 . $v2;
                }
            }, "");
            echo $composeCommand;
        }
        clientDebug("Final compose command: " . $composeCommand, ['command' => $composeCommand], 'daemon', 'debug');
    }
}

/**
 * Build and echo a compose command for multiple stacks.
 *
 * @param string $action The compose action (up, down, update)
 * @param array $paths Array of stack paths
 * @param bool $background Whether to run in the background (no terminal window; sends notification on finish)
 */
function echoComposeCommandMultiple($action, $paths, $background = false)
{
    global $plugin_root;
    global $sName;
    global $compose_root;
    $cfg = parse_plugin_cfg($sName);
    $debug = $cfg['DEBUG_TO_LOG'] == "true";
    $unRaidVars = parse_ini_file("/var/local/emhttp/var.ini");

    if ($unRaidVars['mdState'] != "STARTED") {
        echo $plugin_root . "/scripts/arrayNotStarted.sh";
        clientDebug("Multi Compose operation aborted: Array not started", null, 'daemon', 'warning');
        return;
    }

    // Build a combined command that runs compose up/down for each stack sequentially
    $commands = array();
    $stackNames = array();

    foreach ($paths as $path) {
        clientDebug("Processing stack for multi-compose action: " . $path, ['path' => $path, 'action' => $action], 'daemon', 'debug');
        $composeCommand = array($plugin_root . "scripts/compose.sh");

        $project = basename($path);

        // Resolve stack identity via StackInfo
        $stackInfo = StackInfo::fromProject($compose_root, $project);
        $stackNames[] = $stackInfo->getName();

        $composeCommand[] = "-c" . $action;
        $composeCommand[] = "-p" . $stackInfo->projectName;

        $composeFile = $stackInfo->composeFilePath ?? ($stackInfo->composeSource . '/' . COMPOSE_FILE_NAMES[0]);
        $composeCommand[] = "-f$composeFile";

        // Prune orphaned services from override before compose up
        if ($action === 'up') {
            $stackInfo->pruneOrphanOverrideServices();
        }

        $composeCommand[] = "-f" . $stackInfo->getOverridePath();

        // Add env-file if available for this stack
        $envFilePath = $stackInfo->getEnvFilePath();
        if ($envFilePath !== null) {
            $composeCommand[] = "-e" . $envFilePath;
        }

        // Add default profiles for multi-stack operations
        $defaultProfiles = $stackInfo->getDefaultProfiles();
        foreach ($defaultProfiles as $p) {
            $composeCommand[] = "-g $p";
        }

        // Pass stack path for timestamp saving
        $composeCommand[] = "-s$path";

        if ($debug) {
            $composeCommand[] = "--debug";
        }

        $commands[] = $composeCommand;
    }

    // Human-readable action label for terminal headings
    $actionLabelByType = [
        'up' => 'Starting',
        'down' => 'Stopping',
        'update' => 'Updating',
    ];
    $actionLabel = $actionLabelByType[$action] ?? ucfirst($action);

    if ($background) {
        // Queue stacks sequentially in a single background wrapper script
        // so we don't slam the system with parallel compose operations.
        $bgScript = $plugin_root . "scripts/compose_background.sh";
        $tmpScript = "/tmp/compose_multi_bg_" . uniqid() . ".sh";
        $scriptContent = "#!/bin/bash\n";
        foreach ($commands as $cmd) {
            $line = escapeshellarg($bgScript);
            foreach ($cmd as $arg) {
                $line .= ' ' . escapeshellarg($arg);
            }
            $scriptContent .= "$line\n";
        }
        $scriptContent .= "rm -f " . escapeshellarg($tmpScript) . "\n";
        file_put_contents($tmpScript, $scriptContent);
        chmod($tmpScript, 0700);
        exec(escapeshellarg($tmpScript) . ' > /dev/null 2>&1 &');
        clientDebug("Background multi-stack queued: " . $tmpScript, ['script' => $tmpScript, 'stacks' => count($commands)], 'daemon', 'debug');
        echo json_encode(['background' => true]);
        return;
    }

    if ($cfg['OUTPUTSTYLE'] == "ttyd") {
        // Create a temporary script and execute it via ttyd.
        // This avoids nested shell-quote edge cases and continues after per-stack failures.
        $tmpScript = "/tmp/compose_multi_" . uniqid() . ".sh";
        $scriptContent = "#!/bin/bash\n";
        $scriptContent .= "# Multi-stack compose script (ttyd) - auto-generated\n\n";

        foreach ($commands as $idx => $cmd) {
            $cmdStr = implode(" ", array_map('escapeshellarg', $cmd));
            $stackTitle = str_replace(['\\', '"'], ['\\\\', '\\"'], $stackNames[$idx]);

            $scriptContent .= "echo \"\"\n";
            $scriptContent .= "echo \"=== " . $actionLabel . ": " . $stackTitle . " ===\"\n";
            $scriptContent .= "echo \"\"\n";
            $scriptContent .= $cmdStr . "\n";
            $scriptContent .= "rc=$?\n";
            $scriptContent .= "if [ \$rc -ne 0 ]; then\n";
            $scriptContent .= "  echo \"X Stack " . $stackTitle . " failed to " . strtolower($actionLabel) . " (exit code: \$rc)\"\n";
            $scriptContent .= "fi\n";
            $scriptContent .= "echo \"\"\n";
        }

        $scriptContent .= "echo \"========================================\"\n";
        $scriptContent .= "echo \"=== All operations complete ===\"\n";
        $scriptContent .= "echo \"========================================\"\n";
        $scriptContent .= "rm -f " . escapeshellarg($tmpScript) . "\n";

        file_put_contents($tmpScript, $scriptContent);
        chmod($tmpScript, 0755);

        $ttydCommand = "bash " . escapeshellarg($tmpScript);
        execComposeCommandInTTY($ttydCommand, $debug);
        clientDebug("Multi-stack script created: " . $tmpScript, null, 'daemon', 'debug');
        echo "/plugins/compose.manager/php/show_ttyd.php?done=1";
    } else {
        // For nchan/traditional output, create a temporary bash script that runs all commands
        $tmpScript = "/tmp/compose_multi_" . uniqid() . ".sh";
        $scriptContent = "#!/bin/bash\n";
        $scriptContent .= "# Multi-stack compose script - auto-generated\n\n";

        foreach ($commands as $idx => $cmd) {
            $cmdStr = implode(" ", array_map('escapeshellarg', $cmd));
            $scriptContent .= "echo \"\"\n";
            $scriptContent .= "echo \"========================================\"\n";
            $scriptContent .= "echo \"=== " . str_replace('"', '\\"', $stackNames[$idx]) . " ===\"\n";
            $scriptContent .= "echo \"========================================\"\n";
            $scriptContent .= "echo \"\"\n";
            $scriptContent .= "$cmdStr\n";
            $scriptContent .= "echo \"\"\n";
        }

        // Add cleanup at the end
        $scriptContent .= "\necho \"\"\n";
        $scriptContent .= "echo \"========================================\"\n";
        $scriptContent .= "echo \"=== All operations complete ===\"\n";
        $scriptContent .= "echo \"========================================\"\n";
        $scriptContent .= "rm -f " . escapeshellarg($tmpScript) . "\n";

        file_put_contents($tmpScript, $scriptContent);
        chmod($tmpScript, 0755);

        clientDebug("Multi-stack script created: $tmpScript", null, 'daemon', 'debug');

        echo $tmpScript;
    }
}
