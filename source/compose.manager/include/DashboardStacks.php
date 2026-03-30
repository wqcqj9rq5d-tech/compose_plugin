<?PHP
/**
 * AJAX endpoint for dashboard tile - returns compose stacks data
 * Called asynchronously to avoid blocking dashboard load
 */

$plugin_root = "/usr/local/emhttp/plugins/compose.manager";

require_once("$plugin_root/include/Defines.php");
require_once("$plugin_root/include/Util.php");

$summary = [
    'total' => 0,
    'started' => 0,
    'stopped' => 0,
    'partial' => 0,
    'stacks' => [],
    'composeContainerNames' => []
];

if (!is_dir($compose_root)) {
    header('Content-Type: application/json');
    echo json_encode($summary);
    exit;
}

// Load saved update status from central JSON file
$composeUpdateStatusFile = COMPOSE_UPDATE_STATUS_FILE;
$savedUpdateStatus = [];
if (is_file($composeUpdateStatusFile)) {
    $savedUpdateStatus = json_decode(file_get_contents($composeUpdateStatusFile), true) ?: [];
}

foreach (StackInfo::allFromRoot($compose_root) as $stackInfo) {
    $summary['total']++;

    $projectContainers = $stackInfo->getContainerList();
    $runningCount = 0;
    $totalContainers = count($projectContainers);

    // Read stack started_at timestamp via StackInfo
    $startedAt = $stackInfo->getStartedAt();

    foreach ($projectContainers as $ct) {
        if (($ct['State'] ?? '') === 'running') {
            $runningCount++;
        }
        // Collect container names for hiding from Docker tile
        $name = ltrim(trim($ct['Names'] ?? ''), '/');
        if ($name) {
            $summary['composeContainerNames'][] = $name;
        }
    }

    $state = 'stopped';
    if ($totalContainers > 0) {
        if ($runningCount === $totalContainers) {
            $state = 'started';
            $summary['started']++;
        } elseif ($runningCount > 0) {
            $state = 'partial';
            $summary['partial']++;
        } else {
            $summary['stopped']++;
        }
    } else {
        $summary['stopped']++;
    }

    // Get custom project icon and webui URL via StackInfo
    $icon = $stackInfo->getIconUrl();
    $webui = $stackInfo->getWebUIUrl();

    // Check update status from central update-status.json file (set by "Check for Updates" button)
    $updateStatus = 'unknown';
    if (isset($savedUpdateStatus[$stackInfo->projectFolder])) {
        $stackUpdateInfo = $savedUpdateStatus[$stackInfo->projectFolder];
        if (isset($stackUpdateInfo['hasUpdate'])) {
            $updateStatus = $stackUpdateInfo['hasUpdate'] ? 'update-available' : 'up-to-date';
        }
    }

    $summary['stacks'][] = [
        'name' => $stackInfo->getName(),
        'folder' => $stackInfo->projectFolder,
        'state' => $state,
        'running' => $runningCount,
        'total' => $totalContainers,
        'icon' => $icon,
        'webui' => $webui,
        'startedAt' => $startedAt,
        'update' => $updateStatus
    ];
}

header('Content-Type: application/json');

echo json_encode($summary);
?>
