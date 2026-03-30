<?PHP

/**
 * Async stack list loader for Compose Manager
 * This file is called via AJAX to load the stack list without blocking page load
 */

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");

$cfg = parse_plugin_cfg($sName);

// Get stack state
$stackstate = shell_exec($plugin_root . "/scripts/compose.sh -c list");
$stackstate = json_decode($stackstate, TRUE);

$o = "";
$stackCount = 0;

foreach (StackInfo::allFromRoot($compose_root) as $stackInfo) {
    $stackCount++;

    $projectName = $stackInfo->getName();
    $id = str_replace(".", "-", $stackInfo->projectFolder);
    $id = str_replace(" ", "", $id);

    // Get the compose file path and override via StackInfo
    $composeFile = $stackInfo->composeFilePath ?? ($stackInfo->composeSource . '/' . COMPOSE_FILE_NAMES[0]);
    $overridePath = $stackInfo->getOverridePath();

    // Use StackInfo's getDefinedServices for accurate service count
    $definedServicesList = $stackInfo->getDefinedServices();
    $definedServices = count($definedServicesList);

    $projectContainers = $stackInfo->getContainerList();
    $runningCount = 0;
    $stoppedCount = 0;
    $pausedCount = 0;
    $restartingCount = 0;

    foreach ($projectContainers as $ct) {
        $ctState = $ct['State'] ?? '';
        if ($ctState === 'running') {
            $runningCount++;
        } elseif ($ctState === 'exited') {
            $stoppedCount++;
        } elseif ($ctState === 'paused') {
            $pausedCount++;
        } elseif ($ctState === 'restarting') {
            $restartingCount++;
        }
    }

    // Container counts
    $actualContainerCount = count($projectContainers);
    $containerCount = $definedServices > 0 ? $definedServices : $actualContainerCount;

    // Collect container names for the hide-from-docker feature (data attribute)
    $containerNamesList = [];
    // Collect short container IDs for CPU/MEM load mapping (docker stats uses 12-char short IDs)
    $containerIdsList = [];
    foreach ($projectContainers as $ct) {
        $n = $ct['Names'] ?? '';
        if ($n) $containerNamesList[] = $n;
        $ctId = $ct['ID'] ?? '';
        if ($ctId) $containerIdsList[] = substr($ctId, 0, 12);
    }
    $containerNamesAttr = htmlspecialchars(json_encode($containerNamesList), ENT_QUOTES, 'UTF-8');
    $containerIdsAttr = htmlspecialchars(implode(',', $containerIdsList), ENT_QUOTES, 'UTF-8');

    // Determine states
    $isrunning = $runningCount > 0;
    $isexited = $stoppedCount > 0;
    $ispaused = $pausedCount > 0;
    $isrestarting = $restartingCount > 0;
    $isup = $actualContainerCount > 0;

    // Read metadata via StackInfo lazy getters
    $descriptionRaw = $stackInfo->getDescription();
    if ($descriptionRaw) {
        $descriptionRaw = str_replace("\r", "", $descriptionRaw);
        $description = htmlspecialchars($descriptionRaw, ENT_QUOTES, 'UTF-8');
        $description = str_replace("\n", "<br>", $description);
    } else {
        $description = "";
    }

    $autostart = $stackInfo->getAutostart() ? 'checked' : '';

    $projectIcon = $stackInfo->getIconUrl();
    $webuiUrl = $stackInfo->getWebUIUrl();

    $profiles = $stackInfo->getProfiles();
    $profilesJson = htmlspecialchars(json_encode($profiles ?: []), ENT_QUOTES, 'UTF-8');

    // Determine status text and class for badge
    $statusText = "Stopped";
    $statusClass = "status-stopped";
    if ($isup) {
        if ($isexited && !$isrunning) {
            $statusText = "Exited";
            $statusClass = "status-exited";
        } elseif ($isrunning && !$isexited && !$ispaused && !$isrestarting) {
            $statusText = "Running";
            $statusClass = "status-running";
        } elseif ($ispaused && !$isexited && !$isrunning && !$isrestarting) {
            $statusText = "Paused";
            $statusClass = "status-paused";
        } elseif ($ispaused && !$isexited) {
            $statusText = "Partial";
            $statusClass = "status-partial";
        } elseif ($isrestarting) {
            $statusText = "Restarting";
            $statusClass = "status-restarting";
        } else {
            $statusText = "Mixed";
            $statusClass = "status-mixed";
        }
    }

    // Escape for HTML output
    $projectNameHtml = htmlspecialchars($stackInfo->displayName, ENT_QUOTES, 'UTF-8');
    $projectHtml = htmlspecialchars($stackInfo->projectFolder, ENT_QUOTES, 'UTF-8');
    $descriptionHtml = $description; // Already contains <br> tags from earlier processing
    $pathHtml = htmlspecialchars($stackInfo->path, ENT_QUOTES, 'UTF-8');
    $projectIconUrl = htmlspecialchars($stackInfo->getIconUrl() ?? '', ENT_QUOTES, 'UTF-8');
    $invalidIndirectPath = $stackInfo->invalidIndirectPath;
    $hasInvalidIndirect = ($invalidIndirectPath !== null && trim($invalidIndirectPath) !== '');
    $invalidIndirectPathHtml = htmlspecialchars($invalidIndirectPath ?? '', ENT_QUOTES, 'UTF-8');

    // Status like Docker tab (started/stopped with icon)
    $status = $isrunning ? ($runningCount == $containerCount ? 'started' : 'partial') : 'stopped';
    // Use exclamation icon for partial state so it looks like a warning
    if ($status === 'partial') {
        $shape = 'exclamation-circle';
    } elseif ($isrunning) {
        $shape = 'play';
    } else {
        $shape = 'square';
    }
    $color = $status == 'started' ? 'green-text' : ($status == 'partial' ? 'orange-text' : 'grey-text');
    // Use 'partial' outer class for partial state to allow correct styling
    $outerClass = $isrunning ? ($runningCount == $containerCount ? 'started' : 'partial') : 'stopped';

    $statusLabel = $status;
    if ($status == 'partial') {
        $statusLabel = "partial ($runningCount/$containerCount)";
    }

    // Get stack started_at timestamp via StackInfo
    $stackStartedAt = $stackInfo->getStartedAt();

    // Calculate uptime display from started_at timestamp
    $stackUptime = '';
    if ($stackStartedAt && $isrunning) {
        $startTime = strtotime($stackStartedAt);
        if ($startTime) {
            $diffSecs = time() - $startTime;
            $mins = floor($diffSecs / 60);
            $hours = floor($diffSecs / 3600);
            $days = floor($diffSecs / 86400);
            $weeks = floor($days / 7);
            $months = floor($days / 30);
            $years = floor($days / 365);

            if ($mins < 120) {
                $stackUptime = $mins . " min" . ($mins !== 1 ? "s" : "");
            } elseif ($hours < 48) {
                $stackUptime = $hours . " hour" . ($hours !== 1 ? "s" : "");
            } elseif ($days < 14) {
                $stackUptime = $days . " day" . ($days !== 1 ? "s" : "");
            } elseif ($weeks < 8) {
                $stackUptime = $weeks . " week" . ($weeks !== 1 ? "s" : "");
            } elseif ($months < 24) {
                $stackUptime = $months . " month" . ($months !== 1 ? "s" : "");
            } else {
                $stackUptime = $years . " year" . ($years !== 1 ? "s" : "");
            }
        }
    }
    if (!$stackUptime && $isrunning) {
        $stackUptime = "Uptime: running";
    } elseif (!$stackUptime) {
        $stackUptime = "stopped";
    }

    // Escape webui URL for HTML attribute
    $webuiUrlHtml = htmlspecialchars($webuiUrl, ENT_QUOTES, 'UTF-8');

    // Check if stack has build configurations (needs rebuild on update)
    $hasBuild = $stackInfo->hasBuildConfig() ? '1' : '0';

    // Main row - Docker tab structure with expand arrow on left
    $o .= "<tr class='compose-sortable' id='stack-row-$id' data-project='$projectHtml' data-projectname='$projectNameHtml' data-path='$pathHtml' data-isup='$isup' data-profiles='$profilesJson' data-webui='$webuiUrlHtml' data-containers='$containerNamesAttr' data-ctids='$containerIdsAttr' data-hasbuild='$hasBuild' data-invalid-indirect='" . ($hasInvalidIndirect ? '1' : '0') . "' data-invalid-indirect-path='$invalidIndirectPathHtml'>";

// Arrow column
    $o .= "<td class='col-arrow'>";
    $o .= "<i class='fa fa-chevron-right expand-icon' id='expand-icon-$id' onclick='toggleStackDetails(\"$id\");event.stopPropagation();' style='cursor:pointer;'></i>";
    $o .= "</td>";

    // Icon column
    $imgSrc = $projectIconUrl ?: '/plugins/dynamix.docker.manager/images/question.png';
    $o .= "<td class='col-icon'>";
    $o .= "<span class='outer $outerClass'>";
    $o .= "<span id='stack-$id' class='hand' data-stackid='$id' data-project='$projectHtml' data-projectname='$projectNameHtml' data-isup='$isup' data-running='" . ($isrunning ? '1' : '0') . "'>";
    $o .= "<img src='$imgSrc' class='img' onerror=\"this.src='/plugins/dynamix.docker.manager/images/question.png';\">"; 
    $o .= "</span>";
    $o .= "</span>";
    $o .= "</td>";

    // Name column
    $o .= "<td class='col-name'>";
    $o .= "<span class='inner'><span class='appname'>$projectNameHtml</span><br>";
    $o .= "<i class='fa fa-$shape $status $color compose-status-icon' data-status='$status'></i><span class='state'>$statusLabel</span>";
    if ($hasInvalidIndirect) {
        $o .= " <i class='fa fa-warning orange-text' title='External compose path is invalid or unavailable: $invalidIndirectPathHtml'></i>";
    }
    $o .= "<div class='cm-advanced compose-text-muted' style='margin-top:4px;font-size:0.85em;'>";
    $o .= "Project: $projectHtml";
    $o .= "</div>";
    $o .= "</span>";
    $o .= "</td>";

    // Update column (like Docker tab) - default to "not checked" until update check runs
    $o .= "<td class='col-update compose-updatecolumn'>";
    if ($isrunning) {
        $o .= "<span class='grey-text' style='white-space:nowrap;cursor:default;' title='Click Check for Updates to check'><i class='fa fa-question-circle fa-fw'></i> not checked</span>";
    } else {
        $o .= "<span class='grey-text' style='white-space:nowrap;'><i class='fa fa-stop fa-fw'></i> stopped</span>";
    }
    $o .= "</td>";

    // Containers column (shows running/total)
    $containersDisplay = $isrunning ? "$runningCount / $containerCount" : "0 / $containerCount";
    $containersClass = ($runningCount == $containerCount && $runningCount > 0) ? 'green-text' : ($runningCount > 0 ? 'orange-text' : 'grey-text');
    $o .= "<td class='col-containers'><span class='$containersClass'>$containersDisplay</span></td>";

    // Uptime column (both basic and advanced views)
    $uptimeDisplay = $stackUptime;
    $uptimeClass = $isrunning ? 'green-text' : 'grey-text';
    $o .= "<td class='col-uptime'><span class='$uptimeClass'>$uptimeDisplay</span></td>";

    // CPU & Memory column (advanced only) — populated in real-time via dockerload WebSocket
    $o .= "<td class='cm-advanced col-load compose-load-cell'>";
    if ($isrunning) {
        $o .= "<span class='compose-stack-cpu-$id compose-load-cpu'>0%</span>";
        $o .= "<div class='usage-disk mm'><span id='compose-stack-cpu-$id' style='width:0'></span><span></span></div>";
        $o .= "<span class='compose-stack-mem-$id compose-text-muted compose-load-mem'>0B / 0B</span>";
    } else {
        $o .= "<span class='compose-stack-cpu-$id compose-text-muted compose-load-cpu'>-</span>";
        $o .= "<span class='compose-stack-mem-$id compose-load-mem' style='display:none'></span>";
    }
    $o .= "</td>";

    // Description column (advanced only)
    $o .= "<td class='cm-advanced col-description' style='overflow-wrap:break-word;word-wrap:break-word;'>";
    if ($hasInvalidIndirect) {
        $o .= "<div class='orange-text' style='margin-bottom:4px;font-size:0.85em;'><i class='fa fa-warning'></i> External compose path unavailable, using local stack path.</div>";
    }
    $o .= "<span class='docker_readmore'>$descriptionHtml</span></td>";

    // Path column (advanced only)
    $o .= "<td class='cm-advanced col-path compose-text-muted' style='font-size:12px;'>$pathHtml</td>";

    // Auto Start toggle
    $o .= "<td class='nine col-autostart'>";
    $o .= "<input type='checkbox' class='auto_start' data-scriptName='$projectHtml' id='autostart-$id' $autostart>";
    $o .= "</td>";

    $o .= "</tr>";

    // Expandable details row
    $o .= "<tr class='stack-details-row' id='details-row-$id' style='display:none;'>";
    $o .= "<td colspan='10' class='stack-details-cell' style='padding:0 0 0 60px;background:var(--dynamix-tablesorter-tbody-row-bg-color);'>";
    $o .= "<div class='stack-details-container' id='details-container-$id' style='padding:8px 16px;'>";
    $o .= "<i class='fa fa-spinner fa-spin compose-spinner'></i> Loading containers...";
    $o .= "</div>";
    $o .= "</td>";
    $o .= "</tr>";
}

// If no stacks found, show a message
if ($stackCount === 0) {
    $o = "<tr><td colspan='10' style='text-align:center;padding:20px;color:var(--alt-text-color);'>No Docker Compose stacks found. Click 'Add New Stack' to create one.</td></tr>";
}

// Output the HTML
echo $o;
