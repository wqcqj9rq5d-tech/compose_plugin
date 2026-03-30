<?php

/**
 * Compose Manager - Backup & Restore Functions
 *
 * Handles creation and restoration of stack backup archives.
 * Archives are .tar.gz files containing stack directories.
 */

require_once("/usr/local/emhttp/plugins/compose.manager/include/Defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/include/Util.php");

/**
 * Get the backup destination path from config, falling back to default.
 */
function getBackupDestination()
{
    $cfg = parse_plugin_cfg('compose.manager');
    $dest = $cfg['BACKUP_DESTINATION'] ?? '/boot/config/plugins/compose.manager/backups';
    return rtrim($dest, '/');
}

/**
 * Get the backup source path (projects folder).
 */
function getBackupSource()
{
    global $compose_root;
    return rtrim($compose_root, '/');
}

/**
 * Create a backup archive of all stack directories.
 *
 * @return array Result with 'result' and 'message' keys.
 */
function createBackup()
{
    $source = getBackupSource();
    $destination = getBackupDestination();

    // Validate source
    if (!is_dir($source)) {
        return ['result' => 'error', 'message' => 'Projects folder does not exist: ' . $source];
    }

    // Ensure destination directory exists
    if (!is_dir($destination)) {
        @mkdir($destination, 0755, true);
        if (!is_dir($destination)) {
            return ['result' => 'error', 'message' => 'Cannot create backup destination: ' . $destination];
        }
    }

    // Collect stack directories, skipping reserved root-level files (e.g. 'version')
    if (@scandir($source) === false) {
        return ['result' => 'error', 'message' => 'Cannot read projects folder: ' . $source];
    }
    $stacks = StackInfo::listProjectFolders($source);

    if (empty($stacks)) {
        return ['result' => 'error', 'message' => 'No stacks found to back up.'];
    }

    // Generate archive filename
    $timestamp = date('Y-m-d_H-i');
    $archiveName = "backup_{$timestamp}.tar.gz";
    $archivePath = $destination . '/' . $archiveName;

    // Avoid overwriting — append seconds if file exists
    if (file_exists($archivePath)) {
        $timestamp = date('Y-m-d_H-i-s');
        $archiveName = "backup_{$timestamp}.tar.gz";
        $archivePath = $destination . '/' . $archiveName;
    }

    // Build tar.gz — cd into source dir so paths inside the archive are relative
    $escapedArchive = escapeshellarg($archivePath);
    $tarItems = '';
    foreach ($stacks as $stack) {
        $tarItems .= ' ' . escapeshellarg($stack);
    }

    $cmd = "cd " . escapeshellarg($source) . " && tar czf {$escapedArchive}{$tarItems} 2>&1";
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0) {
        return ['result' => 'error', 'message' => 'tar command failed (exit ' . $exitCode . '): ' . implode("\n", $output)];
    }

    // Apply retention policy
    applyRetentionPolicy($destination);

    $sizeBytes = filesize($archivePath);
    $sizeHuman = formatBytes($sizeBytes);

    // Note: detailed logging is handled by the caller (exec.php or backup_cron.sh)

    return [
        'result' => 'success',
        'message' => "Backup created successfully.",
        'archive' => $archiveName,
        'size' => $sizeHuman,
        'stacks' => count($stacks)
    ];
}

/**
 * Apply retention policy — delete oldest archives exceeding the retention count.
 */
function applyRetentionPolicy($destination)
{
    $cfg = parse_plugin_cfg('compose.manager');
    $retention = intval($cfg['BACKUP_RETENTION'] ?? 5);

    if ($retention <= 0) return; // 0 = unlimited

    $archives = listBackupArchives($destination);

    if (count($archives) > $retention) {
        // Archives are sorted newest-first; remove from the end (oldest)
        $toDelete = array_slice($archives, $retention);
        foreach ($toDelete as $archive) {
            $filePath = $destination . '/' . $archive['filename'];
            @unlink($filePath);
            logger("Retention: deleted old backup " . $archive['filename']);
        }
    }
}

/**
 * List backup archives in a directory, sorted newest-first.
 *
 * @param string|null $directory Override directory, or null for configured destination.
 * @return array List of archive info arrays.
 */
function listBackupArchives($directory = null)
{
    $dir = $directory ?? getBackupDestination();
    $archives = [];

    if (!is_dir($dir)) return $archives;

    $entries = @scandir($dir);
    if ($entries === false) return $archives;

    foreach ($entries as $entry) {
        if (preg_match('/^backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}(-\d{2})?\.tar\.gz$/', $entry)) {
            $fullPath = $dir . '/' . $entry;
            $archives[] = [
                'filename' => $entry,
                'size' => formatBytes(filesize($fullPath)),
                'sizeBytes' => filesize($fullPath),
                'modified' => date('c', filemtime($fullPath))
            ];
        }
    }

    // Sort newest first by filename (which contains the timestamp)
    usort($archives, function ($a, $b) {
        return strcmp($b['filename'], $a['filename']);
    });

    return $archives;
}

/**
 * Read the top-level directory names (stacks) from a backup archive.
 *
 * @param string $archivePath Full path to the .tar.gz file.
 * @return array Result with 'stacks' array, or error.
 */
function readArchiveStacks($archivePath)
{
    if (!file_exists($archivePath)) {
        return ['result' => 'error', 'message' => 'Archive not found: ' . basename($archivePath)];
    }

    // List archive contents and extract top-level directory names
    $escaped = escapeshellarg($archivePath);
    $cmd = "tar tzf {$escaped} 2>/dev/null";
    $output = shell_exec($cmd);

    if (empty($output)) {
        return ['result' => 'error', 'message' => 'Cannot read archive contents or archive is empty.'];
    }

    $lines = explode("\n", trim($output));
    $stacks = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $parts = explode('/', $line);
        $topDir = $parts[0];
        if (!empty($topDir) && $topDir !== '.' && !in_array($topDir, $stacks)) {
            $stacks[] = $topDir;
        }
    }

    sort($stacks);

    return ['result' => 'success', 'stacks' => $stacks];
}

/**
 * Restore selected stacks from a backup archive.
 *
 * @param string $archivePath Full path to the .tar.gz file.
 * @param array $stacks Array of stack directory names to restore.
 * @return array Result with 'result', 'message', and 'restored' keys.
 */
function restoreStacks($archivePath, $stacks)
{
    $source = getBackupSource();

    if (!file_exists($archivePath)) {
        return ['result' => 'error', 'message' => 'Archive not found: ' . basename($archivePath)];
    }

    if (empty($stacks)) {
        return ['result' => 'error', 'message' => 'No stacks selected for restore.'];
    }

    // Ensure projects folder exists
    if (!is_dir($source)) {
        @mkdir($source, 0755, true);
    }

    $escaped = escapeshellarg($archivePath);
    $destEscaped = escapeshellarg($source);
    $restored = [];
    $errors = [];

    foreach ($stacks as $stack) {
        $stackEscaped = escapeshellarg($stack . '/');

        // Extract the stack directory, overwriting existing files
        $cmd = "tar xzf {$escaped} -C {$destEscaped} {$stackEscaped} 2>&1";
        exec($cmd, $output, $exitCode);

        if ($exitCode === 0) {
            $restored[] = $stack;
        } else {
            $errors[] = $stack . ' (exit ' . $exitCode . ')';
        }
    }

    $result = [
        'result' => empty($errors) ? 'success' : (empty($restored) ? 'error' : 'warning'),
        'restored' => $restored,
        'errors' => $errors,
        'message' => count($restored) . ' stack(s) restored successfully: ' . implode(', ', $restored)
    ];

    if (!empty($errors)) {
        $result['message'] .= ' Failed: ' . implode(', ', $errors);
    }

    return $result;
}

/**
 * Install or remove the backup cron job.
 * Uses root's crontab directly for maximum compatibility with Unraid's cron daemon.
 */
function updateBackupCron()
{
    $cfg = parse_plugin_cfg('compose.manager');
    $script = '/usr/local/emhttp/plugins/compose.manager/scripts/backup_cron.sh';
    $marker = '#compose-manager-backup';

    // Also clean up old /etc/cron.d file if it exists from previous versions
    $oldCronFile = '/etc/cron.d/compose-manager-backup';
    if (file_exists($oldCronFile)) {
        @unlink($oldCronFile);
    }

    // Read current crontab, stripping any existing compose-manager-backup lines
    $existing = '';
    exec('crontab -l 2>/dev/null', $lines, $rc);
    if ($rc === 0 && !empty($lines)) {
        $filtered = array_filter($lines, function($line) use ($marker) {
            return strpos($line, $marker) === false;
        });
        $existing = implode("\n", $filtered);
        // Ensure it ends with a newline
        $existing = rtrim($existing) . "\n";
    }

    $enabled = ($cfg['BACKUP_SCHEDULE_ENABLED'] ?? 'false') === 'true';

    if (!$enabled) {
        // Write back crontab without our line
        $tmpFile = tempnam('/tmp', 'compose-cron-');
        file_put_contents($tmpFile, $existing);
        exec("crontab " . escapeshellarg($tmpFile));
        @unlink($tmpFile);
        return;
    }

    $frequency = $cfg['BACKUP_SCHEDULE_FREQUENCY'] ?? 'daily';
    $time = $cfg['BACKUP_SCHEDULE_TIME'] ?? '03:00';
    $dayOfWeek = $cfg['BACKUP_SCHEDULE_DAY'] ?? '1'; // Monday

    // Parse time - use directly as cron runs in server's local timezone
    $parts = explode(':', $time);
    $hour = isset($parts[0]) ? intval($parts[0]) : 3;
    $minute = isset($parts[1]) ? intval($parts[1]) : 0;

    if ($frequency === 'weekly') {
        $cronLine = "{$minute} {$hour} * * {$dayOfWeek} {$script} {$marker}";
    } else {
        // daily
        $cronLine = "{$minute} {$hour} * * * {$script} {$marker}";
    }

    // Write updated crontab
    $tmpFile = tempnam('/tmp', 'compose-cron-');
    file_put_contents($tmpFile, $existing . $cronLine . "\n");
    exec("crontab " . escapeshellarg($tmpFile));
    @unlink($tmpFile);
}

/**
 * Format bytes to human-readable string.
 */
function formatBytes($bytes)
{
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Log a message to syslog with compose.manager tag.
 * Guarded to avoid redeclaration when Helpers.php is also loaded.
 */
if (!function_exists('logger')) {
    function logger($message)
    {
        exec("logger -t 'compose.manager' " . escapeshellarg("[backup] " . $message));
    }
}

/**
 * Resolve the full path to an archive given a filename.
 * Looks in the optional directory first, then configured destination, falls back to the provided path.
 */
function resolveArchivePath($filenameOrPath, $directory = null)
{
    // Always use basename to prevent path traversal — the archive is resolved
    // relative to the backup destination, never from an arbitrary absolute path.
    $filename = basename($filenameOrPath);

    // Try the explicitly provided directory first
    if ($directory !== null && $directory !== '') {
        $candidate = rtrim($directory, '/') . '/' . $filename;
        if (file_exists($candidate)) {
            return $candidate;
        }
    }

    // Otherwise look in the configured backup destination
    $dest = getBackupDestination();
    $candidate = $dest . '/' . $filename;
    if (file_exists($candidate)) {
        return $candidate;
    }

    // Return as-is for the caller to handle the error
    return $filenameOrPath;
}
