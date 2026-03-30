<?php

require_once("/usr/local/emhttp/plugins/compose.manager/php/defines.php");
require_once("/usr/local/emhttp/plugins/dynamix/include/Wrappers.php");

/**
 * Utility functions for Compose Manager
 *
 * This file contains shared utility functions used across the Compose Manager plugin.
 * Functions include logging, string sanitization, path handling, and stack operation locking.
 *
 * These functions are designed to be reusable and testable. They are used by both the
 * main plugin code and the AJAX action handlers.
 */

if (!function_exists('clientDebug')) {
    function clientDebug($message, $data = null, $type = 'daemon', $level = 'info')
    {
        if ($type == '' || $type == null) {
            $type = 'daemon';
        }
        switch ($level) {
            case 'debug':
                $logLevel = "$type.debug";
                break;
            case 'error':
            case 'err':
                $logLevel = "$type.err";
                break;
            case 'warning':
            case 'warn':
                $logLevel = "$type.warning";
                break;
            case 'info':
            default:
                $logLevel = "$type.info";
        }
        $cfg = @parse_ini_file("/boot/config/plugins/compose.manager/compose.manager.cfg", true, INI_SCANNER_RAW);
        // Skip debug messages if debug logging is disabled in plugin settings
        if ((($cfg['DEBUG_TO_LOG'] ?? 'false') == 'false') && $level == 'debug') {
            return;
        }
        if ($data !== null && $data !== '' && $data !== 'null') {
            if (is_array($data) || is_object($data)) {
                $data = json_encode($data);
            }
            exec("logger -t 'compose.manager' -p '$logLevel' " . escapeshellarg($message) . ' - Data: ' . escapeshellarg($data));
        } else {
            exec("logger -t 'compose.manager' -p '$logLevel' " . escapeshellarg($message));
        }
    }
}

function sanitizeLogText(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Find the first compose file in a directory using Docker Compose spec priority.
 *
 * @param string $dir The directory to check
 * @return string|false Full path to compose file, or false if none found
 */
function findComposeFile($dir)
{
    foreach (COMPOSE_FILE_NAMES as $name) {
        if (is_file("$dir/$name")) {
            return "$dir/$name";
        }
    }
    return false;
}

/**
 * Check whether a stack directory has a compose file (any of the supported names).
 *
 * @param string $dir The directory to check
 * @return bool
 */
function hasComposeFile($dir)
{
    return findComposeFile($dir) !== false;
}

/**
 * Resolve the config file path used by auto-update.
 *
 * Prefers a persistent path under /boot/config when available and writable,
 * with environment override support for tests.
 *
 * @return string
 */
function getAutoUpdateConfigFilePath(): string
{
    global $plugin_root;

    $override = getenv('COMPOSE_MANAGER_AUTOUPDATE_FILE');
    if ($override !== false && $override !== '') {
        return $override;
    }

    $persistentPath = '/boot/config/plugins/compose.manager/autoupdate.json';
    $persistentDir  = dirname($persistentPath);

    // Prefer a persistent, writable location under /boot/config when possible.
    if ((is_dir('/boot/config') || is_dir($persistentDir) || @mkdir($persistentDir, 0755, true)) && is_dir($persistentDir)) {
        if (is_writable($persistentDir)) {
            // If the file already exists but is not writable, fall back to plugin_root.
            if (!file_exists($persistentPath) || is_writable($persistentPath)) {
                return $persistentPath;
            }
        }
    }

    return rtrim($plugin_root ?? '', '/') . '/autoupdate.json';
}

/**
 * Validate that a path is allowed for auto-update operations.
 * Must be under compose_root, /mnt/, or /boot/config/.
 *
 * @param string $path The path to validate
 * @return bool
 */
function isAllowedAutoUpdatePath($path): bool
{
    global $compose_root;

    $realPath = realpath($path);
    if ($realPath === false) {
        return false;
    }

    $realComposeRoot = realpath($compose_root);
    if ($realComposeRoot !== false) {
        $realComposeRoot = rtrim($realComposeRoot, DIRECTORY_SEPARATOR);
        if ($realPath === $realComposeRoot || strpos($realPath, $realComposeRoot . DIRECTORY_SEPARATOR) === 0) {
            return true;
        }
    }

    if ($realPath === '/mnt' || strpos($realPath, '/mnt/') === 0) {
        return true;
    }
    if ($realPath === '/boot/config' || strpos($realPath, '/boot/config/') === 0) {
        return true;
    }

    return false;
}

/**
 * Pure string checks for path validation.
 */
class Path
{
    public static function hasNewline(string $path): bool
    {
        return strpos($path, "\n") !== false;
    }

    public static function hasSeparator(string $path): bool
    {
        return strpos($path, '/') !== false || strpos($path, DIRECTORY_SEPARATOR) !== false;
    }

    public static function hasWindowsStylePath(string $path): bool
    {
        return DIRECTORY_SEPARATOR === '/' && strpos($path, '\\') !== false;
    }

    public static function hasTraversal(string $path): bool
    {
        return strpos($path, '/..') !== false || strpos($path, '../') !== false;
    }
}


function pruneOverrideContentServices(string $overrideContent, array $validServices): array
{
    $validMap = [];
    foreach ($validServices as $service) {
        if (is_string($service) && $service !== '') {
            $validMap[$service] = true;
        }
    }

    if (empty($validMap) || $overrideContent === '') {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    $lineEnding = (strpos($overrideContent, "\r\n") !== false) ? "\r\n" : "\n";
    $normalized = str_replace(["\r\n", "\r"], "\n", $overrideContent);
    $hadTrailingNewline = substr($normalized, -1) === "\n";
    $lines = explode("\n", $normalized);

    $servicesStart = null;
    $servicesEnd = count($lines);

    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        if ($servicesStart === null) {
            if (preg_match('/^services\s*:/', $line)) {
                $servicesStart = $i;
            }
            continue;
        }

        // Detect the end of the services section by finding the next top-level YAML key:
        // - a non-indented, non-comment line that looks like "key:" (optionally followed by a comment)
        // - excluding the "services:" key itself
        if (preg_match('/^[^\s#][^:]*:\s*(?:#.*)?$/', $line) && !preg_match('/^services\s*:/', $line)) {
            $servicesEnd = $i;
            break;
        }
    }

    if ($servicesStart === null || $servicesStart + 1 >= $servicesEnd) {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    $serviceRanges = [];
    $currentServiceName = null;
    $currentServiceStart = null;

    for ($i = $servicesStart + 1; $i < $servicesEnd; $i++) {
        $line = $lines[$i];
        // Match a single service definition line under `services:` with:
        // - exactly two spaces of indentation
        // - an optional quote character (single or double) around the service name (captured in group 1)
        // - a service name that cannot start with or contain quotes, colon, hash, or whitespace
        // - a trailing colon after the service name, optional whitespace, and an optional inline `# comment`
        if (preg_match('/^ {2}(["\']?)([^"\':#\s][^"\':#]*)\1\s*:\s*(?:#.*)?$/', $line, $matches)) {
            if ($currentServiceName !== null) {
                $serviceRanges[] = [
                    'name' => $currentServiceName,
                    'start' => $currentServiceStart,
                    'end' => $i - 1
                ];
            }
            $currentServiceName = trim($matches[2]);
            $currentServiceStart = $i;
        }
    }

    if ($currentServiceName !== null) {
        $serviceRanges[] = [
            'name' => $currentServiceName,
            'start' => $currentServiceStart,
            'end' => $servicesEnd - 1
        ];
    }

    if (empty($serviceRanges)) {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    $removedRanges = [];
    $removedServices = [];
    foreach ($serviceRanges as $range) {
        if (!isset($validMap[$range['name']])) {
            $removedRanges[] = $range;
            $removedServices[] = $range['name'];
        }
    }

    if (empty($removedRanges)) {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    // Safety check: if ALL services in the override would be removed, don't prune.
    // This likely indicates a rename scenario rather than genuine orphans.
    // Wiping to "services: {}" would destroy user data (icons, webui labels, etc).
    if (count($removedRanges) === count($serviceRanges)) {
        return ['content' => $overrideContent, 'removed' => [], 'changed' => false];
    }

    // Only prune specific orphaned services while preserving others
    $removeByLine = [];
    foreach ($removedRanges as $range) {
        for ($lineIndex = $range['start']; $lineIndex <= $range['end']; $lineIndex++) {
            $removeByLine[$lineIndex] = true;
        }
    }

    $newLines = [];
    foreach ($lines as $lineIndex => $line) {
        if (!isset($removeByLine[$lineIndex])) {
            $newLines[] = $line;
        }
    }

    $newContent = implode("\n", $newLines);
    if ($hadTrailingNewline && substr($newContent, -1) !== "\n") {
        $newContent .= "\n";
    }

    if ($lineEnding === "\r\n") {
        $newContent = str_replace("\n", "\r\n", $newContent);
    }

    return ['content' => $newContent, 'removed' => $removedServices, 'changed' => true];
}

/**
 * Data class representing the override file information for a stack, including paths and usage flags.
 * This class encapsulates the logic for determining which override file to use (project vs indirect),
 * handles legacy override migration, and provides utility methods for pruning and migrating override content.
 * 
 * @property string $computedName The computed override filename based on the compose file (e.g. compose.override.yaml)
 * @property string|null $projectOverride The path to the project override file
 * @property string|null $indirectOverride The path to the indirect override file (if applicable)
 * @property bool $useIndirect Whether the indirect override should be used instead of the project override
 * @property bool $mismatchIndirectLegacy Whether there is a legacy-named indirect override that doesn't match the computed name
 * @property string|null $composeFilePath The resolved path to the main compose file for the stack
 * @method static OverrideInfo fromStackInfo(StackInfo $stackInfo) Create an OverrideInfo from a StackInfo instance (preferred)
 * @method static OverrideInfo fromStack(string $composeRoot, string $stack) Create an OverrideInfo by resolving paths from scratch (deprecated)
 * @method string|null getOverridePath() Get the path to the override file that should be used (indirect if present, else project)
 * @method array{changed: bool, removed: string[]} pruneOrphanServices(array $validServices) Prune orphaned services from the override file based on a list of valid service names
 * @method array{migrated: bool, migrations: array<array{oldName: string, newName: string}>} migrateRenamedServices(string $oldComposeContent, string $newComposeContent) Migrate override entries when services are renamed by comparing old and new compose content
 * 
 */
class OverrideInfo
{
    /**
     * @var string Computed override filename (e.g. compose.override.yaml)
     */
    public string $computedName = '';
    /**
     * @var string|null Path to project override file
     */
    public ?string $projectOverride = null;
    /**
     * @var string|null Path to indirect override file
     */
    public ?string $indirectOverride = null;
    /**
     * @var bool True if indirect override should be used
     */
    public bool $useIndirect = false;
    /**
     * @var bool True if indirect contains legacy-named override but not correctly-named one
     */
    public bool $mismatchIndirectLegacy = false;
    /**
     * @var string|null Resolved path to the main compose file
     */
    public ?string $composeFilePath = null;

    private function __construct() {}

    /**
     * Create an OverrideInfo from a StackInfo instance.
     *
     * Primary factory — uses the pre-resolved identity fields from StackInfo
     * (path, composeSource, composeFilePath, isIndirect) so that no duplicate
     * filesystem resolution is needed.
     *
     * @param StackInfo $stackInfo The owning stack (must have identity fields populated)
     * @return OverrideInfo
     */
    public static function fromStackInfo(StackInfo $stackInfo): self
    {
        $indirectPath = $stackInfo->isIndirect ? $stackInfo->composeSource : null;
        return self::resolveOverride($stackInfo->path, $indirectPath, $stackInfo->composeFilePath);
    }

    /**
     * Create an OverrideInfo by resolving paths from scratch.
     *
     * @deprecated Use StackInfo::fromProject() which provides OverrideInfo automatically
     *             via fromStackInfo(), avoiding duplicate filesystem resolution.
     *
     * @param string $composeRoot
     * @param string $stack
     * @return OverrideInfo
     */
    public static function fromStack(string $composeRoot, string $stack): self
    {
        $projectPath = rtrim($composeRoot, '/') . '/' . $stack;
        $indirectPath = is_file("$projectPath/indirect")
            ? trim(file_get_contents("$projectPath/indirect"))
            : null;
        if ($indirectPath === '') {
            $indirectPath = null;
        }

        $composeSource = $indirectPath ?? $projectPath;
        $foundCompose = findComposeFile($composeSource);
        $composeFilePath = $foundCompose !== false ? $foundCompose : null;

        return self::resolveOverride($projectPath, $indirectPath, $composeFilePath);
    }

     /**
      * Core override resolution logic shared by both factories.
      *
      * Computes the override filename from the compose file, resolves project
      * and indirect override paths while preserving legacy filenames as-is,
      * and auto-creates a project override template if needed.
      *
      * @param string      $projectPath     Full path to the stack directory
      * @param string|null $indirectPath     Indirect target directory, or null if not indirect
      * @param string|null $composeFilePath  Resolved main compose file path, or null if none
      * @return OverrideInfo
      */
    private static function resolveOverride(string $projectPath, ?string $indirectPath, ?string $composeFilePath): self
    {
        $info = new self();
        $info->composeFilePath = $composeFilePath;

        $composeBaseName = $composeFilePath !== null ? basename($composeFilePath) : COMPOSE_FILE_NAMES[0];
        $info->computedName = preg_replace('/(\.[^.]+)$/', '.override$1', $composeBaseName);

        $computedProjectOverride = $projectPath . '/' . $info->computedName;
        $computedIndirectOverride = $indirectPath !== null ? ($indirectPath . '/' . $info->computedName) : null;

        $legacyProject = $projectPath . '/docker-compose.override.yml';
        $legacyIndirect = $indirectPath !== null ? ($indirectPath . '/docker-compose.override.yml') : null;

        if (is_file($computedProjectOverride)) {
            $info->projectOverride = $computedProjectOverride;
        } elseif (is_file($legacyProject)) {
            $info->projectOverride = $legacyProject;
        } else {
            $info->projectOverride = $computedProjectOverride;
        }

        if ($computedIndirectOverride !== null && is_file($computedIndirectOverride)) {
            $info->indirectOverride = $computedIndirectOverride;
        } elseif ($legacyIndirect !== null && is_file($legacyIndirect)) {
            $info->indirectOverride = $legacyIndirect;
        } else {
            $info->indirectOverride = $computedIndirectOverride;
        }

        $info->useIndirect = ($info->indirectOverride && is_file($info->indirectOverride));
        $info->mismatchIndirectLegacy = false;

        if (!is_file($info->projectOverride) && !$info->useIndirect) {
            $overrideContent = "# Override file for UI labels (icon, webui, shell)\n";
            $overrideContent .= "# This file is managed by Compose Manager\n";
            $overrideContent .= "services: {}\n";
            file_put_contents($info->projectOverride, $overrideContent);
            clientDebug("[override] Created missing project override template at $info->projectOverride", null, 'daemon', 'info');
        }

        return $info;
    }

    /**
     * Get the override file to use (indirect if present, else project override)
     * @return string|null
     */
    public function getOverridePath(): ?string
    {
        return $this->useIndirect ? $this->indirectOverride : $this->projectOverride;
    }

    /**
     * Prune orphaned services from the override file.
     *
     * Removes any services in the override that are not present in the
     * provided list of valid service names. The override file is rewritten
     * in place.
     *
     * @param string[] $validServices Service names that are defined in the main compose file
     * @return array{changed: bool, removed: string[]}
     */
    public function pruneOrphanServices(array $validServices): array
    {
        $overridePath = $this->getOverridePath();
        if ($overridePath === null || $overridePath === '' || !is_file($overridePath)) {
            return ['changed' => false, 'removed' => []];
        }

        if (empty($validServices)) {
            return ['changed' => false, 'removed' => []];
        }

        $overrideContent = file_get_contents($overridePath);
        if ($overrideContent === false || $overrideContent === '') {
            return ['changed' => false, 'removed' => []];
        }

        $result = pruneOverrideContentServices($overrideContent, $validServices);
        if (!($result['changed'] ?? false)) {
            return ['changed' => false, 'removed' => []];
        }

        file_put_contents($overridePath, $result['content']);

        $removedServices = $result['removed'] ?? [];
        if (!empty($removedServices)) {
            clientDebug(
                "[override] Pruned orphaned override services from " . basename($overridePath) . ": " . implode(', ', $removedServices),
                null,
                'daemon',
                'info'
            );
        }

        return ['changed' => true, 'removed' => $removedServices];
    }

    /**
     * Migrate override entries when services are renamed.
     *
     * Compares old and new compose content to detect service renames and
     * automatically updates the override file to match.
     *
     * @param string $oldComposeContent The old compose file content
     * @param string $newComposeContent The new compose file content
     * @return array{migrated: bool, migrations: array<array{from: string, to: string}>}
     */
    public function migrateOnRename(string $oldComposeContent, string $newComposeContent): array
    {
        $result = ['migrated' => false, 'migrations' => []];

        $overridePath = $this->getOverridePath();
        if ($overridePath === null || !is_file($overridePath)) {
            return $result;
        }

        $overrideContent = file_get_contents($overridePath);
        if ($overrideContent === false || trim($overrideContent) === '') {
            return $result;
        }

        // Parse services from old and new compose content using simple YAML parsing
        $oldServices = self::parseServicesFromYaml($oldComposeContent);
        $newServices = self::parseServicesFromYaml($newComposeContent);
        $overrideServices = self::parseServicesFromYaml($overrideContent);

        if (empty($oldServices) || empty($newServices) || empty($overrideServices)) {
            return $result;
        }

        // Find removed services (in old but not in new)
        $removedServices = array_diff(array_keys($oldServices), array_keys($newServices));
        // Find added services (in new but not in old)
        $addedServices = array_diff(array_keys($newServices), array_keys($oldServices));

        if (empty($removedServices) || empty($addedServices)) {
            return $result;
        }

        // Build rename map: match by image, or if same count assume positional rename
        $renameMap = [];

        // First try to match by image
        foreach ($removedServices as $oldName) {
            $oldImage = $oldServices[$oldName]['image'] ?? '';
            if ($oldImage === '') {
                continue;
            }

            foreach ($addedServices as $newName) {
                if (isset($renameMap[$oldName]) || in_array($newName, $renameMap)) {
                    continue;
                }
                $newImage = $newServices[$newName]['image'] ?? '';
                if ($oldImage === $newImage) {
                    $renameMap[$oldName] = $newName;
                    break;
                }
            }
        }

        // If counts match and we couldn't match by image, assume 1:1 positional rename
        if (empty($renameMap) && count($removedServices) === count($addedServices)) {
            $removedList = array_values($removedServices);
            $addedList = array_values($addedServices);
            for ($i = 0; $i < count($removedList); $i++) {
                // Only map if the old name exists in override
                if (isset($overrideServices[$removedList[$i]])) {
                    $renameMap[$removedList[$i]] = $addedList[$i];
                }
            }
        }

        if (empty($renameMap)) {
            return $result;
        }

        // Apply renames to override content
        $newOverrideContent = $overrideContent;
        foreach ($renameMap as $oldName => $newName) {
            // Only migrate if old name exists in override and new name doesn't
            if (!isset($overrideServices[$oldName]) || isset($overrideServices[$newName])) {
                continue;
            }

            // Replace service name in override (careful YAML replacement)
            // Match "  oldname:" at start of line (2 space indent under services:)
            $pattern = '/^(  )' . preg_quote($oldName, '/') . '(\s*:)/m';
            $replacement = '$1' . $newName . '$2';
            $newOverrideContent = preg_replace($pattern, $replacement, $newOverrideContent, 1, $count);

            if ($count > 0) {
                $result['migrations'][] = ['from' => $oldName, 'to' => $newName];
            }
        }

        if (!empty($result['migrations'])) {
            file_put_contents($overridePath, $newOverrideContent);
            $result['migrated'] = true;

            $migrationLog = array_map(fn($m) => "{$m['from']} -> {$m['to']}", $result['migrations']);
            clientDebug(
                "[override] Migrated renamed services: " . implode(', ', $migrationLog),
                null,
                'daemon',
                'info'
            );
        }

        return $result;
    }

    /**
     * Parse service definitions from YAML content.
     *
     * Simple parser that extracts service names and their image values.
     * Public static method for use in tests and other contexts.
     *
     * @param string $yamlContent The YAML content to parse
     * @return array<string, array{image?: string}> Service name => service data
     */
    public static function parseServicesFromYaml(string $yamlContent): array
    {
        $services = [];
        $lines = explode("\n", $yamlContent);
        $inServices = false;
        $currentService = null;

        foreach ($lines as $line) {
            // Skip empty lines and comments
            if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
                continue;
            }

            // Check for services: section
            if (preg_match('/^services\s*:/', $line)) {
                $inServices = true;
                continue;
            }

            // Check for end of services section (another top-level key)
            if ($inServices && preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*\s*:/', $line) && !preg_match('/^services\s*:/', $line)) {
                $inServices = false;
                continue;
            }

            if (!$inServices) {
                continue;
            }

            // Match service name (2 spaces indent)
            if (preg_match('/^  (["\']?)([a-zA-Z0-9_-]+)\1\s*:/', $line, $matches)) {
                $currentService = $matches[2];
                $services[$currentService] = [];
                continue;
            }

            // Match image under service (4+ spaces indent)
            if ($currentService && preg_match('/^    image\s*:\s*["\']?([^"\'#\n]+)["\']?/', $line, $matches)) {
                $services[$currentService]['image'] = trim($matches[1]);
            }
        }

        return $services;
    }
}

/**
 * Normalized container information.
 *
 * Provides a single canonical shape for container data regardless of source
 * (docker inspect response, update-check response, or cached status).
 * Eliminates PascalCase/camelCase drift and container-name aliasing issues.
 * 
 * @property string $name Canonical container name (from Name/Service/container)
 * @property string $id Docker container ID
 * @property string $service Compose service name
 * @property string $image Full image reference (e.g. library/nginx:latest)
 * @property string $state Container state (running/exited/paused/restarting)
 * @property bool $isRunning Whether the container is currently running
 * @property bool $hasUpdate Whether an image update is available
 * @property string $updateStatus Update status text (unknown/up-to-date/update-available)
 * @property string $localSha Local image SHA (truncated)
 * @property string $remoteSha Remote image SHA (truncated)
 * @property bool $isPinned Whether the image is pinned to a specific digest
 * @property string|null $pinnedDigest Pinned digest if isPinned is true
 * @property string $icon Icon URL from Docker label
 * @property string $shell Shell path from Docker label
 * @property string $webUI Resolved WebUI URL
 * @property array $ports Port mappings (e.g. ["192.168.1.50:8080->80/tcp"])
 * @property array $networks Network info [{name, ip, driver}]
 * @property array $volumes Volume mounts [{source, destination, type}]
 * @property string $created ISO datetime when container was created
 * @property string $startedAt ISO datetime when container was started
 * @method static ContainerInfo fromDockerInspect(array $raw) Create a ContainerInfo from a docker inspect + compose ps result array
 * @method static ContainerInfo fromUpdateResponse(array $raw) Create a ContainerInfo from an update-check response element
 * 
 */
class ContainerInfo
{
    /** @var string Canonical container name (from Name/Service/container) */
    public string $name = '';
    /** @var string Docker container ID */
    public string $id = '';
    /** @var string Compose service name */
    public string $service = '';
    /** @var string Full image reference (e.g. library/nginx:latest) */
    public string $image = '';
    /** @var string Container state (running/exited/paused/restarting) */
    public string $state = '';
    /** @var bool Whether the container is currently running */
    public bool $isRunning = false;
    /** @var bool Whether an image update is available */
    public bool $hasUpdate = false;
    /** @var string Update status text (unknown/up-to-date/update-available) */
    public string $updateStatus = 'unknown';
    /** @var string Local image SHA (truncated) */
    public string $localSha = '';
    /** @var string Remote image SHA (truncated) */
    public string $remoteSha = '';
    /** @var bool Whether the image is pinned to a specific digest */
    public bool $isPinned = false;
    /** @var string|null Pinned digest if isPinned is true */
    public ?string $pinnedDigest = null;
    /** @var string Icon URL from Docker label */
    public string $icon = '';
    /** @var string Shell path from Docker label */
    public string $shell = '/bin/bash';
    /** @var string Resolved WebUI URL */
    public string $webUI = '';
    /** @var array Port mappings (e.g. ["192.168.1.1:8080->80/tcp"]) */
    public array $ports = [];
    /** @var array Network info [{name, ip, driver}] */
    public array $networks = [];
    /** @var array Volume mounts [{source, destination, type}] */
    public array $volumes = [];
    /** @var string ISO datetime when container was created */
    public string $created = '';
    /** @var string ISO datetime when container was started */
    public string $startedAt = '';

    private function __construct() {}

    /**
     * Create a ContainerInfo from a fully-assembled docker inspect + compose ps result.
     *
     * This is the shape built by the getStackContainers action in exec.php.
     * Accepts either PascalCase or camelCase keys and normalizes them.
     *
     * @param array $raw Associative array with container data
     * @return ContainerInfo
     */
    public static function fromDockerInspect(array $raw): self
    {
        $info = new self();
        $info->name = $raw['Name'] ?? $raw['name'] ?? '';
        $info->id = $raw['ID'] ?? $raw['Id'] ?? $raw['id'] ?? '';
        $info->service = $raw['Service'] ?? $raw['service'] ?? '';
        $info->image = $raw['Image'] ?? $raw['image'] ?? '';
        $info->state = strtolower($raw['State'] ?? $raw['state'] ?? '');
        $info->isRunning = ($info->state === 'running');
        $info->icon = $raw['Icon'] ?? $raw['icon'] ?? '';
        $info->shell = $raw['Shell'] ?? $raw['shell'] ?? '/bin/bash';
        $info->webUI = $raw['WebUI'] ?? $raw['webUI'] ?? $raw['webui'] ?? '';
        $info->ports = $raw['Ports'] ?? $raw['ports'] ?? [];
        $info->networks = $raw['Networks'] ?? $raw['networks'] ?? [];
        $info->volumes = $raw['Volumes'] ?? $raw['volumes'] ?? [];
        $info->created = $raw['Created'] ?? $raw['created'] ?? '';
        $info->startedAt = $raw['StartedAt'] ?? $raw['startedAt'] ?? '';

        // Normalize update status (accept PascalCase or camelCase)
        $info->updateStatus = $raw['updateStatus'] ?? $raw['UpdateStatus'] ?? $raw['status'] ?? 'unknown';
        $info->localSha = $raw['localSha'] ?? $raw['LocalSha'] ?? '';
        $info->remoteSha = $raw['remoteSha'] ?? $raw['RemoteSha'] ?? '';
        $info->hasUpdate = $raw['hasUpdate']
            ?? ($info->updateStatus === 'update-available');

        // Derive pinned status from @sha256: in image reference
        $info->derivePinned();

        return $info;
    }

    /**
     * Create a ContainerInfo from an update-check response element.
     *
     * This is the per-container shape returned by checkStackUpdates (keys:
     * container, image, hasUpdate, status, localSha, remoteSha).
     *
     * @param array $raw Associative array from update check
     * @return ContainerInfo
     */
    public static function fromUpdateResponse(array $raw): self
    {
        $info = new self();
        $info->name = $raw['container'] ?? $raw['name'] ?? $raw['Name'] ?? '';
        $info->id = $raw['ID'] ?? $raw['Id'] ?? $raw['id'] ?? '';
        $info->service = $raw['service'] ?? $raw['Service'] ?? '';
        $info->image = $raw['image'] ?? $raw['Image'] ?? '';
        $info->hasUpdate = $raw['hasUpdate'] ?? false;
        $info->updateStatus = $raw['status'] ?? $raw['updateStatus'] ?? 'unknown';
        $info->localSha = $raw['localSha'] ?? '';
        $info->remoteSha = $raw['remoteSha'] ?? '';

        $info->derivePinned();

        return $info;
    }

    /**
     * Merge update-check fields from another ContainerInfo without
     * overwriting identity or runtime state fields.
     *
     * @param ContainerInfo $update The newer update data to merge in
     */
    public function mergeUpdateStatus(ContainerInfo $update): void
    {
        if ($update->hasUpdate) {
            $this->hasUpdate = true;
        }
        if ($update->updateStatus !== '' && $update->updateStatus !== 'unknown') {
            $this->updateStatus = $update->updateStatus;
        }
        if ($update->localSha !== '') {
            $this->localSha = $update->localSha;
        }
        if ($update->remoteSha !== '') {
            $this->remoteSha = $update->remoteSha;
        }
        if ($update->isPinned) {
            $this->isPinned = $update->isPinned;
            $this->pinnedDigest = $update->pinnedDigest;
        }
    }

    /**
     * Serialize to a consistently camelCase associative array for JSON responses.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'id' => $this->id,
            'service' => $this->service,
            'image' => $this->image,
            'state' => $this->state,
            'isRunning' => $this->isRunning,
            'hasUpdate' => $this->hasUpdate,
            'updateStatus' => $this->updateStatus,
            'localSha' => $this->localSha,
            'remoteSha' => $this->remoteSha,
            'isPinned' => $this->isPinned,
            'pinnedDigest' => $this->pinnedDigest,
            'icon' => $this->icon,
            'shell' => $this->shell,
            'webUI' => $this->webUI,
            'ports' => $this->ports,
            'networks' => $this->networks,
            'volumes' => $this->volumes,
            'created' => $this->created,
            'startedAt' => $this->startedAt,
        ];
    }

    /**
     * Serialize only the update-related fields (for update-check responses).
     *
     * @return array
     */
    public function toUpdateArray(): array
    {
        return [
            'name' => $this->name,
            'image' => $this->image,
            'hasUpdate' => $this->hasUpdate,
            'updateStatus' => $this->updateStatus,
            'localSha' => $this->localSha,
            'remoteSha' => $this->remoteSha,
            'isPinned' => $this->isPinned,
            'pinnedDigest' => $this->pinnedDigest,
        ];
    }

    /**
     * Create a ContainerInfo from a `docker ps --format '{{json .}}'` row.
     *
     * This is a lightweight factory that populates only the fields available
     * from a docker ps row (no inspect, no ports/networks/volumes detail).
     *
     * @param array $raw Decoded JSON row from docker ps
     * @return ContainerInfo
     */
    public static function fromDockerPs(array $raw): self
    {
        $info = new self();
        $info->name = ltrim(trim($raw['Names'] ?? $raw['Name'] ?? $raw['name'] ?? ''), '/');
        $info->id = $raw['ID'] ?? $raw['Id'] ?? $raw['id'] ?? '';
        $info->image = $raw['Image'] ?? $raw['image'] ?? '';
        $info->state = strtolower($raw['State'] ?? $raw['state'] ?? '');
        $info->isRunning = ($info->state === 'running');
        $info->derivePinned();
        return $info;
    }

    /**
     * Derive isPinned and pinnedDigest from the image reference.
     */
    private function derivePinned(): void
    {
        if ($this->image !== '' && strpos($this->image, '@sha256:') !== false) {
            $this->isPinned = true;
            $parts = explode('@sha256:', $this->image, 2);
            $this->pinnedDigest = $parts[1] ?? null;
        }
    }
}

/**
 * Centralized stack identity and metadata.
 *
 * Resolves and caches the canonical identity for a compose stack: directory
 * name (project), sanitized Docker project name, compose file path, indirect
 * target, override info, and provides lazy access to metadata files (name,
 * description, envpath, icon_url, webui_url, etc.).
 *
 * Construction is intentionally eager for identity fields and override
 * resolution (preserving current side-effect behavior). Metadata files are
 * loaded lazily on first access.
 * 
 * @property string $projectFolder Compose project folder basename (also project string)
 * @property string $displayName Display name (from ./name)
 * @property string $path Full path to the stack directory ($composeRoot/$project)
 * @property string|null $indirectPath Indirect path or null if not indirect
 * @property string $composeSource Resolved compose source directory, direct or indirect
 * @property string|null $composeFilePath Full path to the main compose file, direct or indirect
 * @property bool $isIndirect Whether this stack uses an indirect compose path
 * @property OverrideInfo $overrideInfo Resolved override info (eager)
 * @method static StackInfo fromProject(string $composeRoot, string $project) Create a StackInfo for a project directory under the compose root, with caching
 * @method static void clearCache(?string $key = null) Clear the static instance cache (all or specific key) - primarily for testing
 * @method string|null getMetadata(string $field) Get the value of a metadata file (e.g. name, description, envpath) with lazy loading and caching
 * 
 */
class StackInfo
{
    /** @var string Compose project folder basename (actual directory name on disk) */
    public string $projectFolder;
    /** @var string Sanitized Docker Compose project name (always lowercase, valid for -p flag) */
    public string $projectName;
    /** @var string Display name (from ./name) */
    public string $displayName;
    /** @var string Full path to the stack directory ($composeRoot/$project) */
    public string $path;
    /** @var string|null Indirect path or null if not indirect */
    public ?string $indirectPath;
    /** @var string Resolved compose source directory, direct or indirect */
    public string $composeSource;
    /** @var string Full path to the main compose file, direct or indirect */
    public ?string $composeFilePath;
    /** @var bool Whether this stack uses an indirect compose path */
    public bool $isIndirect;
    /** @var string|null Path from a renamed indirect.invalid file, if present (needs user fix) */
    public ?string $invalidIndirectPath;
    /** @var OverrideInfo Resolved override info (eager) */
    public OverrideInfo $overrideInfo;

    /** @var string Compose root directory */
    private string $composeRoot;

    /** @var array Lazy-loaded metadata cache (field => value|null, unset = not loaded) */
    private array $metadataCache = [];

    /** @var array<string, StackInfo> Static instance cache keyed by composeRoot/project */
    private static array $instances = [];

    /** @var array[]|null Per-instance lazy cache of docker compose ps rows (null = not yet fetched) */
    private ?array $cachedContainerList = null;

    /**
     * Pre-populate the container list cache for this stack.
     *
     * Used by allFromRoot() to inject batch-fetched container data so that
     * getContainerList() returns immediately without a per-stack docker call.
     *
     * @param array[] $containers Raw rows (docker ps JSON format)
     */
    public function setContainerList(array $containers): void
    {
        $this->cachedContainerList = $containers;
    }

    /**
     * @param string $composeRoot Compose root directory
     * @param string $projectFolder Directory basename of the stack
     */
    private function __construct(string $composeRoot, string $projectFolder)
    {
        // Set the things we know right away: compose root and project folder.
        $this->composeRoot = rtrim($composeRoot, '/');
        $this->projectFolder = $projectFolder;
        $this->setPath();

        if (!is_dir($this->path)) {
            throw new \RuntimeException("Project path is not a directory: $this->path");
        }

        // projectFolder is the directory name as-is; projectName is the sanitized
        // version used for the Docker Compose -p flag. We never rename the folder.
        $this->projectName = self::sanitizeProjectString($this->projectFolder);

        // Resolve display name from metadata (or default to folder name).
        $this->displayName = $this->getDisplayName();

        // Resolve indirect path and compose source (indirect if present, else direct)
        $this->isIndirect = $this->isIndirect();
        $this->indirectPath = $this->readMetadata('indirect');
        $this->invalidIndirectPath = $this->readInvalidIndirect();
        if ($this->invalidIndirectPath === null && !$this->isIndirect && $this->indirectPath !== null && $this->indirectPath !== '') {
            // Invalid indirect path still present in ./indirect (non-destructive handling).
            $this->invalidIndirectPath = $this->indirectPath;
        }
        $this->composeSource = $this->isIndirect ? $this->indirectPath : $this->path;

        // Resolve compose file
        $this->composeFilePath = self::getComposeFilePath($this->composeSource);
        if ($this->composeFilePath === null) {
            if ($this->invalidIndirectPath !== null) {
                // Stack has a broken indirect reference — allow degraded construction
                // so the user can fix it in the Settings editor.
                clientDebug("[stack] Stack $this->projectFolder has an invalid indirect path; loading in degraded mode", null, 'daemon', 'warning');
                $this->overrideInfo = OverrideInfo::fromStackInfo($this);
                return;
            }
            throw new \RuntimeException("Not a valid compose stack: no compose file found at $this->composeSource");
        }

        // Eagerly resolve override info using pre-resolved identity (no duplicate I/O)
        $this->overrideInfo = OverrideInfo::fromStackInfo($this);
    }

    /**
     * Create a StackInfo for a project directory under the compose root.
     *
     * Returns a cached instance if one already exists for this composeRoot/project
     * combination, avoiding redundant filesystem and override resolution work.
     *
     * @param string $composeRoot The compose projects root directory
     * @param string $project Directory basename of the stack
     * @return StackInfo
     */
    public static function fromProject(string $composeRoot, string $project): self
    {
        $key = rtrim($composeRoot, '/') . '/' . $project;
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($composeRoot, $project);
        }
        return self::$instances[$key];
    }

    /**
     * Clear the static instance cache.
     *
     * Primarily useful in tests to ensure a clean state between test cases.
     *
     * @param string|null $key Optional specific key (composeRoot/project) to clear; null clears all.
     */
    public static function clearCache(?string $key = null): void
    {
        if ($key !== null) {
            unset(self::$instances[$key]);
        } else {
            self::$instances = [];
        }
    }

    /**
     * Find the compose file in a directory using Docker Compose spec priority.
     *
     * Checks for compose.yaml, compose.yml, docker-compose.yaml, docker-compose.yml
     * in that order and returns the first one found.
     *
     * @return string|null Full path to the compose file if found, or null if none found
     */
    private static function getComposeFilePath($path): string|null
    {
        $composeFilePath = null;
        foreach (COMPOSE_FILE_NAMES as $name) {
            if (is_file("$path/$name")) {
                $composeFilePath = "$path/$name";
                break;
            }
        }
        return $composeFilePath;
    }


    /**
     * Determine if the stack uses an indirect compose path by checking for a valid 'indirect' file.
     * Validate file path to prevent security issues (e.g. directory traversal, invalid paths) and ensure it points to an existing directory.
     * 
     * @return bool True if the stack is indirect, false otherwise
     */
    private function isIndirect(): bool
    {
        $indirectPath = $this->readMetadata('indirect');
        if ($indirectPath !== null) {
            if (
                $indirectPath === ''
                || Path::hasNewline($indirectPath)
                || !Path::hasSeparator($indirectPath)
                || Path::hasWindowsStylePath($indirectPath)
                || Path::hasTraversal($indirectPath)
            ) {
                // Path is structurally invalid — ignore it and keep stack local without mutating files.
                clientDebug("[stack] Ignoring structurally invalid indirect path at $this->path/indirect: " . sanitizeLogText($indirectPath), null, 'daemon', 'warning');
                return false;
            }
            if (!is_dir($indirectPath)) {
                // Directory doesn't exist — may be a temporarily unmounted share (NFS, etc.).
                // Ignore it and keep stack local without mutating files.
                clientDebug("[stack] Ignoring unavailable indirect path (may be temporarily unavailable): " . sanitizeLogText($indirectPath), null, 'daemon', 'warning');
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Read the indirect.invalid file if present.
     *
     * Legacy compatibility: older versions wrote this file when an indirect
     * path was invalid. Newer versions keep ./indirect unchanged and use
     * non-destructive warning behavior, but still read indirect.invalid.
     *
     * @return string|null The path stored in indirect.invalid, or null if not present
     */
    private function readInvalidIndirect(): ?string
    {
        $invalidFile = "$this->path/indirect.invalid";
        if (is_file($invalidFile)) {
            $content = @file_get_contents($invalidFile);
            return $content !== false ? trim($content) : null;
        }
        return null;
    }

    /**
     * Canonical sanitizer for Display Name to Docker Compose project name.
     *
     * Docker Compose project names must match: [a-z0-9][a-z0-9_-]*
     *
     * @param string $rawProjectString Directory basename of the stack
     * @return string Canonical compose project name
     */
    public static function sanitizeProjectString(string $rawProjectString): string
    {

        // Trim and lowercase the input to start normalization.
        $sanitizedProjectString = strtolower(trim($rawProjectString));

        // Replace unsupported characters with underscore.
        $sanitizedProjectString = preg_replace('/[^a-z0-9_-]/', '_', $sanitizedProjectString) ?? '';

        // Collapse multiple underscores into one.
        $sanitizedProjectString = preg_replace('/_+/', '_', $sanitizedProjectString);

        // Collapse multiple dashes into one.
        $sanitizedProjectString = preg_replace('/-+/', '-', $sanitizedProjectString);

        // Remove leading or trailing underscores or dashes.
        $sanitizedProjectString = trim($sanitizedProjectString, '_-');

        // If the result is empty, default to 'compose' to ensure a valid project name.
        if ($sanitizedProjectString === '') {
            clientDebug("Sanitized project string is empty after processing; defaulting to 'compose'", ['input' => $rawProjectString], 'daemon', 'warning');
            return 'compose';
        }

        return $sanitizedProjectString;
    }

    // ---------------------------------------------------------------
    // Lazy metadata getters — read from file on first access, cache
    // ---------------------------------------------------------------

    /**
     * Get the display name (from `name` file, falls back to project folder).
     * @return string
     */
    public function getDisplayName(): string
    {
        $displayName = $this->readMetadata('name') ?? null;
        if ($displayName === null || $displayName === '') {
            // If no display name is set, initialize it from the project folder name.
            $displayName = $this->projectFolder;
            $this->writeMetadata('name', $displayName);
            clientDebug("Initialized missing display name from project folder: '$displayName'", ['project' => $this->projectFolder, 'displayName' => $displayName], 'daemon', 'warning');
        }
        $this->displayName = $displayName;
        return $this->displayName;
    }

    /**
     * Alias for getDisplayName() — backward compatibility.
     * @return string
     */
    public function getName(): string
    {
        return $this->displayName;
    }

    /**
     * Get the stack description.
     * @return string
     */
    public function getDescription(): string
    {
        return $this->readMetadata('description') ?? '';
    }

    /**
     * Get the custom env file path (from `envpath` file).
     * @return string|null
     */
    public function getEnvFilePath(): ?string
    {
        $val = $this->readMetadata('envpath');
        return ($val !== null && $val !== '') ? $val : null;
    }

    /**
     * Get the icon URL (from `icon_url` file), validated.
     * @return string|null
     */
    public function getIconUrl(): ?string
    {
        $url = $this->readMetadata('icon_url');
        if (
            $url !== null && filter_var($url, FILTER_VALIDATE_URL)
            && (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0)
        ) {
            return $url;
        }
        return null;
    }

    /**
     * Get the stack-level WebUI URL (from `webui_url` file), validated.
     * @return string|null
     */
    public function getWebUIUrl(): ?string
    {
        $url = $this->readMetadata('webui_url');
        if (
            $url !== null && filter_var($url, FILTER_VALIDATE_URL)
            && (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0)
        ) {
            return $url;
        }
        return null;
    }

    /**
     * Get default profiles (from `default_profile` file), comma-split.
     * @return string[]
     */
    public function getDefaultProfiles(): array
    {
        $raw = $this->readMetadata('default_profile');
        if ($raw === null || $raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Get autostart flag (from `autostart` file).
     * @return bool
     */
    public function getAutostart(): bool
    {
        $val = $this->readMetadata('autostart');
        return ($val !== null && strpos($val, 'true') !== false);
    }

    /**
     * Get the started_at timestamp (from `started_at` file).
     * @return string|null
     */
    public function getStartedAt(): ?string
    {
        $val = $this->readMetadata('started_at');
        return ($val !== null && $val !== '') ? $val : null;
    }

    /**
     * Get available profiles (from `profiles` JSON file).
     * @return array
     */
    public function getProfiles(): array
    {
        $raw = $this->readMetadata('profiles');
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ---------------------------------------------------------------
    // Derived helpers
    // ---------------------------------------------------------------

    /**
     * Get the OverrideInfo for this stack.
     * @return OverrideInfo
     */
    public function getOverrideInfo(): OverrideInfo
    {
        return $this->overrideInfo;
    }

    /**
     * Get the effective override file path (delegates to OverrideInfo).
     * @return string|null
     */
    public function getOverridePath(): ?string
    {
        return $this->overrideInfo->getOverridePath();
    }

    /**
     * Get the list of services defined in the main compose file.
     *
     * Uses `docker compose config --services` to accurately resolve
     * services including extends, anchors, etc.
     *
     * @return string[] List of service names
     */
    public function getDefinedServices(): array
    {
        if ($this->composeFilePath === null || !is_file($this->composeFilePath)) {
            return [];
        }

        $cmd = "docker compose -f " . escapeshellarg($this->composeFilePath);

        // Include override file if available
        $overridePath = $this->getOverridePath();
        if ($overridePath !== null && is_file($overridePath)) {
            $cmd .= " -f " . escapeshellarg($overridePath);
        }

        $envFilePath = $this->getEnvFilePath();
        if ($envFilePath !== null && is_file($envFilePath)) {
            $cmd .= " --env-file " . escapeshellarg($envFilePath);
        }
        $cmd .= " config --services 2>/dev/null";

        $output = shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", trim($output))), function ($service) {
            return $service !== '';
        }));
    }

    /**
     * Prune orphaned services from the override file.
     *
     * Resolves valid services from the main compose file (without override),
     * then delegates to OverrideInfo::pruneOrphanServices().
     *
     * @return array{changed: bool, removed: string[]}
     */
    public function pruneOrphanOverrideServices(): array
    {
        $validServices = $this->getMainFileServices();
        if (empty($validServices)) {
            return ['changed' => false, 'removed' => []];
        }
        return $this->overrideInfo->pruneOrphanServices($validServices);
    }

    /**
     * Get the list of services defined in the main compose file only (without override).
     *
     * Used internally by pruneOrphanOverrideServices() to determine which services
     * are valid. Excludes the override file so orphaned override services are not
     * counted as valid.
     *
     * External callers should typically use getDefinedServices() which includes
     * the override file for a complete picture.
     *
     * @return string[] List of service names
     */
    private function getMainFileServices(): array
    {
        if ($this->composeFilePath === null || !is_file($this->composeFilePath)) {
            return [];
        }

        $cmd = "docker compose -f " . escapeshellarg($this->composeFilePath);

        $envFilePath = $this->getEnvFilePath();
        if ($envFilePath !== null && is_file($envFilePath)) {
            $cmd .= " --env-file " . escapeshellarg($envFilePath);
        }
        $cmd .= " config --services 2>/dev/null";

        $output = shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", trim($output))), function ($service) {
            return $service !== '';
        }));
    }

    /**
     * Build the common compose CLI arguments for this stack.
     *
     * Returns the project name, file flags, and env-file flag suitable
     * for passing to `docker compose`.
     *
     * @return array{projectName: string, files: string, envFile: string}
     */
    public function buildComposeArgs(): array
    {
        $composeFile = $this->composeFilePath ?? ($this->composeSource . '/' . COMPOSE_FILE_NAMES[0]);

        $files = "-f " . escapeshellarg($composeFile);

        $overridePath = $this->getOverridePath();
        if ($overridePath !== null) {
            $files .= " -f " . escapeshellarg($overridePath);
        }

        $envFile = "";
        $envPath = $this->getEnvFilePath();
        if ($envPath !== null && is_file($envPath)) {
            $envFile = "--env-file " . escapeshellarg($envPath);
        }

        return [
            'projectName' => $this->projectName,
            'files' => $files,
            'envFile' => $envFile,
        ];
    }

    /**
     * Check if any service in the stack has a build configuration.
     *
     * Uses `docker compose config` to parse the compose files and checks
     * for services with build contexts, indicating they need to be built
     * at startup rather than pulled from a registry.
     *
     * @return bool True if any service has a build configuration
     */
    public function hasBuildConfig(): bool
    {
        // Check cache first
        if (array_key_exists('has_build', $this->metadataCache)) {
            return (bool) $this->metadataCache['has_build'];
        }

        if ($this->composeFilePath === null || !is_file($this->composeFilePath)) {
            $this->metadataCache['has_build'] = false;
            return false;
        }

        // Use docker compose config to get the resolved configuration
        $cmd = "docker compose -f " . escapeshellarg($this->composeFilePath);

        $overridePath = $this->getOverridePath();
        if ($overridePath !== null && is_file($overridePath)) {
            $cmd .= " -f " . escapeshellarg($overridePath);
        }

        $envFilePath = $this->getEnvFilePath();
        if ($envFilePath !== null && is_file($envFilePath)) {
            $cmd .= " --env-file " . escapeshellarg($envFilePath);
        }
        $cmd .= " config 2>/dev/null";

        $output = shell_exec($cmd);
        if (!is_string($output) || trim($output) === '') {
            $this->metadataCache['has_build'] = false;
            return false;
        }

        // Parse the YAML output and check for build keys
        // Look for "build:" at the start of a line (indented under services)
        // This is a simple heuristic - build: can be a string (context) or object
        $hasBuild = preg_match('/^\s+build:/m', $output) === 1;

        $this->metadataCache['has_build'] = $hasBuild;
        return $hasBuild;
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------
    /**
     * Read a metadata file from the stack directory (lazy, cached).
     *
     * @param string $filename Metadata filename (e.g. 'name', 'envpath')
     * @param bool $forceRefresh If true, bypass cache and re-read from disk
     * @return string|null Trimmed file contents, or null if file doesn't exist
     */
    private function readMetadata(string $filename, bool $forceRefresh = false): ?string
    {
        if (!$forceRefresh && array_key_exists($filename, $this->metadataCache)) {
            return $this->metadataCache[$filename];
        }

        $filePath = $this->path . '/' . $filename;
        if (is_file($filePath)) {
            $content = @file_get_contents($filePath);
            $this->metadataCache[$filename] = ($content !== false) ? trim($content) : null;
        } else {
            $this->metadataCache[$filename] = null;
        }

        return $this->metadataCache[$filename];
    }

    /** Write a metadata file to the stack directory and update cache.
     *
     * @param string $filename Metadata filename (e.g. 'name', 'envpath')
     * @param string $content Content to write to the file
     * @return bool True on success, false on failure
     */
    private function writeMetadata(string $filename, string $content): bool
    {
        $content = trim($content);
        $result = @file_put_contents($this->path . '/' . $filename, $content) !== false;
        if ($result) {
            $this->metadataCache[$filename] = $content;
        }
        return $result;
    }

    /** 
     * Set the path to $composeRoot/$project.
     * 
     * @return void
     */
    private function setPath(): void
    {
        $this->path = $this->composeRoot . '/' . $this->projectFolder;
    }

    // ---------------------------------------------------------------
    // Static factory: create a new stack on disk
    // ---------------------------------------------------------------

    /**
     * Create a new stack directory with all required files.
     *
     * Handles folder naming (via sanitizeFolderName + collision avoidance),
     * indirect wiring, default compose file creation, metadata files (name,
     * description), and override initialization.
     *
     * Input validation (e.g. allowed indirect path roots) is the caller's
     * responsibility — this method only deals with the filesystem structure.
     *
     * @param string $composeRoot  The compose projects root directory
     * @param string $stackName    Human-readable stack name
     * @param string $description  Optional description text
     * @param string $indirectPath Optional indirect compose source directory
     *
     * @return self The newly created (and cached) StackInfo instance
     *
     * @throws \RuntimeException If the stack directory cannot be created
     */
    public static function createNew(
        string $composeRoot,
        string $stackName,
        string $description = '',
        string $indirectPath = ''
    ): self {
        $composeRoot = rtrim($composeRoot, '/');

        // Validate stack name is not empty
        if (trim($stackName) === '') {
            clientDebug("Attempted to create a new stack with an empty name, which is not allowed.", null, 'daemon', 'error');
            throw new \RuntimeException("Stack name cannot be empty");
        }
        $projectName = $stackName;

        // Set the project name to the folder-and-project sanitized version of the display name.
        $project = self::sanitizeProjectString($stackName);
        if ($project === '') {
            clientDebug("Sanitized stack name is empty, cannot create stack directory.", ['stackName' => $stackName], 'daemon', 'error');
            throw new \RuntimeException("Stack name produced an empty folder name after sanitization.");
        }

        // Set the path to composeRoot/project (even if indirect, we want the folder there for metadata and override)
        $path = $composeRoot . '/' . $project;

        // Verify the resolved path stays within composeRoot (defense-in-depth)
        $realComposeRoot = realpath($composeRoot);
        if ($realComposeRoot === false) {
            clientDebug("Failed to resolve real path for compose root.", ['composeRoot' => $composeRoot], 'daemon', 'error');
            throw new \RuntimeException("Invalid compose root directory.");
        }

        // For new folders, check that the parent resolves correctly
        $resolvedParent = realpath(dirname($path));
        if ($resolvedParent === false || strpos($resolvedParent, $realComposeRoot) !== 0) {
            clientDebug("Invalid stack name: path would escape compose root.", ['stackName' => $stackName, 'resolvedParent' => $resolvedParent, 'realComposeRoot' => $realComposeRoot], 'daemon', 'error');
            throw new \RuntimeException("Invalid stack name: path would escape compose root.");
        }

        try {
            // Ensure the project folder is available, handling collisions by appending suffixes if needed (e.g. "my-stack-001", "my-stack-002", etc.)
            $path = self::getAvailablePath($composeRoot, $project);
        } catch (\RuntimeException $e) {
            clientDebug("Failed to get available path for stack.", ['stackName' => $stackName, 'error' => $e->getMessage()], 'daemon', 'error');
            throw new \RuntimeException("Failed to create stack: " . $e->getMessage());
        }

        // Create the directory
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            clientDebug("Failed to create stack directory.", ['path' => $path], 'daemon', 'error');
            throw new \RuntimeException("Failed to create stack directory: $path");
        }

        // Create indirect file to store path to indirect project directory
        if ($indirectPath !== '') {
            file_put_contents("$path/indirect", $indirectPath);
        }

        // Write metadata
        file_put_contents("$path/name", $projectName);
        if ($description !== '') {
            file_put_contents("$path/description", $description);
        }

        // Create default compose file at the appropriate location (indirect target or stack dir)
        $composeTarget = ($indirectPath !== '') ? $indirectPath : $path;
        self::writeDefaultComposeFile($composeTarget);

        // Build + cache the instance (resolves override, etc.)
        return self::fromProject($composeRoot, basename($path));
    }

    /**
     * Write a blank compose file to the given path if one doesn't already exist.
     * 
     * @param string $dir The directory to check for an existing compose file and write to if missing
     * @return string The path to the compose file (existing or newly created)
     * @throws \RuntimeException If the compose file cannot be created
     */
    private static function writeDefaultComposeFile(string $dir): string
    {
        $filePath = "$dir/" . COMPOSE_FILE_NAMES[0];
        if (!file_exists($filePath)) {
            if (file_put_contents($filePath, "services:\n") === false) {
                throw new \RuntimeException("Failed to create default compose file: $filePath");
            }
        }
        return $filePath;
    }

    /**
     * Get an available project folder path under the compose root, handling name collisions by appending numeric suffixes.
     * 
     * @param string $composeRoot
     * @param string $baseName
     * @return string An available project name (folder name) that doesn't collide with existing stacks
     * @throws \RuntimeException If an available name cannot be found after many attempts
     */
    private static function getAvailablePath(string $composeRoot, string $baseName): string
    {
        $candidate = $composeRoot . '/' . $baseName;
        $project = $baseName;

        // Handle name collision by appending a suffix (if the folder already exists)
        if (is_dir($candidate)) {
            $maxAttempts = 100;
            $attempts = 0;
            do {
                if ($attempts < 1) {
                    clientDebug("Name collision detected for preferred folder, '$candidate', attempting to find an available name.", ['candidate' => $candidate], 'daemon', 'info');
                } else {
                    clientDebug("Name collision detected for suffixed name, '$candidate'.", ['candidate' => $candidate], 'daemon', 'info');
                }
                $attempts++;
                $candidate = $composeRoot . '/' . $project . '-' . sprintf('%03d', $attempts);
                clientDebug("Checking candidate stack name: '$candidate'", ['candidate' => $candidate], 'daemon', 'debug');
                if ($attempts > $maxAttempts) {
                    throw new \RuntimeException("Unable to find a unique folder name for stack '$baseName' after $maxAttempts attempts");
                }
            } while (is_dir($candidate));
        }
        return $candidate;
    }

    /**
     * List project folder basenames under a compose root, skipping reserved entries.
     *
     * Excludes the standard '.'/'..', plus the plugin-managed {@see COMPOSE_ROOT_VERSION_FILE}
     * that lives at the compose root level (written by compose.manager.plg on install/upgrade).
     * Only directory entries are returned, so plain files are always ignored.
     *
     * Use this everywhere project folders need to be enumerated to guarantee consistent
     * behaviour across allFromRoot(), backup, and any future callers.
     *
     * @param string $composeRoot Compose projects root directory
     * @return string[] Directory basenames (unsorted, filesystem order)
     */
    public static function listProjectFolders(string $composeRoot): array
    {
        $root = rtrim($composeRoot, '/');
        $result = [];
        foreach (@scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === COMPOSE_ROOT_VERSION_FILE) {
                continue;
            }
            if (is_dir("$root/$entry")) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    /**
     * Return all valid StackInfo instances under a compose root.
     *
     * Scans the root directory and returns one StackInfo per valid stack,
     * silently skipping folders with no compose file or invalid structure.
     *
     * @param string $composeRoot Compose projects root directory
     * @param bool   $skipDocker  If true, skip the batch docker ps preload
     *                            (returns stacks with empty container lists
     *                            for fast skeleton rendering).
     * @return self[]
     */
    public static function allFromRoot(string $composeRoot, bool $skipDocker = false): array
    {
        $stacks = [];
        foreach (self::listProjectFolders($composeRoot) as $project) {
            try {
                $stacks[] = self::fromProject($composeRoot, $project);
            } catch (\Throwable $e) {
                // skip non-stack directories (no compose file, invalid structure, etc.)
                clientDebug("[allFromRoot] Skipped project '$project': " . $e->getMessage(), null, 'daemon', 'debug');
            }
        }

        if ($skipDocker) {
            // Set empty container lists so getContainerList() won't trigger
            // per-stack docker calls.
            foreach ($stacks as $stack) {
                $stack->setContainerList([]);
            }
            return $stacks;
        }

        // Batch-preload container data with a single docker ps call to avoid
        // O(n) docker invocations when callers iterate getContainerList().
        $containersByProject = [];
        $psOutput = shell_exec("docker ps -a --filter label=com.docker.compose.project --format json 2>/dev/null");
        if ($psOutput) {
            foreach (explode("\n", trim($psOutput)) as $line) {
                if ($line === '') {
                    continue;
                }
                $ct = @json_decode($line, true);
                if (!$ct) {
                    continue;
                }
                // Extract project name from Labels string
                $labels = $ct['Labels'] ?? '';
                if (preg_match('/com\.docker\.compose\.project=([^,]+)/', $labels, $m)) {
                    $containersByProject[$m[1]][] = $ct;
                }
            }
        }
        foreach ($stacks as $stack) {
            $key = $stack->projectName;
            $stack->setContainerList($containersByProject[$key] ?? []);
        }

        return $stacks;
    }

    /**
     * Get the raw docker compose ps rows for this stack.
     *
     * Runs `docker compose ps --all --format json` scoped to this stack using
     * the resolved compose file, env file, and project name. Results are cached
     * on the instance so repeated calls are free.
     *
     * @return array[] Raw rows from `docker compose ps --all --format json`
     */
    public function getContainerList(): array
    {
        if ($this->cachedContainerList !== null) {
            return $this->cachedContainerList;
        }
        $this->cachedContainerList = [];
        $args = $this->buildComposeArgs();
        $cmd = "docker compose {$args['files']} {$args['envFile']} -p "
            . escapeshellarg($args['projectName'])
            . " ps --all --format json 2>/dev/null";
        $output = shell_exec($cmd);
        if (!$output) {
            return $this->cachedContainerList;
        }
        foreach (explode("\n", trim($output)) as $line) {
            if ($line === '') {
                continue;
            }
            $container = @json_decode($line, true);
            if ($container) {
                $this->cachedContainerList[] = $container;
            }
        }
        return $this->cachedContainerList;
    }

    /**
     * Get the containers for this stack as ContainerInfo objects.
     *
     * Uses the same cached docker ps data as getContainerList().
     *
     * @return ContainerInfo[]
     */
    public function getContainers(): array
    {
        return array_map(
            fn($raw) => ContainerInfo::fromDockerPs($raw),
            $this->getContainerList()
        );
    }

    /**
     * Find a StackInfo by its compose source path.
     *
     * Searches all projects to find one whose composeSource matches the given path.
     * Useful for auto-update feature where config stores compose source paths.
     *
     * @param string $composeRoot The compose projects root directory
     * @param string $composePath The compose source path to search for
     * @return self|null The matching StackInfo, or null if not found
     */
    public static function fromComposePath(string $composeRoot, string $composePath): ?self
    {
        // Static cache: [composeRoot => [composeSourcePath => StackInfo]]
        static $cacheByRoot = [];

        $composeRoot = rtrim($composeRoot, '/');
        $normalizedPath = rtrim($composePath, '/');

        if (isset($cacheByRoot[$composeRoot])) {
            return $cacheByRoot[$composeRoot][$normalizedPath] ?? null;
        }

        $cacheByRoot[$composeRoot] = [];

        foreach (self::allFromRoot($composeRoot) as $stackInfo) {
            $sourcePath = rtrim($stackInfo->composeSource, '/');
            if ($sourcePath !== '') {
                $cacheByRoot[$composeRoot][$sourcePath] = $stackInfo;
            }
        }

        return $cacheByRoot[$composeRoot][$normalizedPath] ?? null;
    }
}

/**
 * Stack operation locking functions
 * Prevents concurrent operations on the same stack
 */

// Lock directory override for testing - set via $GLOBALS['compose_lock_dir']

/**
 * Get the lock directory path
 * @return string
 */
function getLockDir(): string
{
    return $GLOBALS['compose_lock_dir'] ?? "/var/run/compose.manager";
}

/**
 * Acquire a lock for a stack operation
 * @param string $stackName The stack name/folder
 * @param int $timeout Maximum seconds to wait for lock (default 30)
 * @return resource|false File handle if lock acquired, false otherwise
 */
function acquireStackLock($stackName, $timeout = 30)
{
    $lockDir = getLockDir();
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0755, true);
    }

    $lockFile = "$lockDir/" . StackInfo::sanitizeProjectString($stackName) . ".lock";
    $fp = @fopen($lockFile, 'w');

    if (!$fp) {
        return false;
    }

    $waited = 0;
    while (!flock($fp, LOCK_EX | LOCK_NB)) {
        if ($waited >= $timeout) {
            fclose($fp);
            return false;
        }
        sleep(1);
        $waited++;
    }

    // Write lock info for debugging
    fwrite($fp, json_encode([
        'pid' => getmypid(),
        'time' => date('c'),
        'stack' => $stackName
    ]));
    fflush($fp);

    return $fp;
}

/**
 * Release a stack lock
 * @param resource $fp File handle from acquireStackLock
 */
function releaseStackLock($fp)
{
    if ($fp) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Check if a stack is currently locked
 * @param string $stackName The stack name/folder
 * @return array|false Lock info if locked, false if not locked
 */
function isStackLocked($stackName)
{
    $lockDir = getLockDir();
    $lockFile = "$lockDir/" . StackInfo::sanitizeProjectString($stackName) . ".lock";

    if (!is_file($lockFile)) {
        return false;
    }

    $fp = @fopen($lockFile, 'r');
    if (!$fp) {
        return false;
    }

    // Try to get a non-blocking lock
    if (flock($fp, LOCK_EX | LOCK_NB)) {
        // Got the lock, so it wasn't locked
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    // Couldn't get lock, read the lock info
    $content = file_get_contents($lockFile);
    fclose($fp);

    $info = @json_decode($content, true);
    return $info ?: ['locked' => true];
}

/**
 * Get the last operation result for a stack
 * @param string $stackPath Full path to the stack directory
 * @return array|null Result info or null if not found
 */
function getStackLastResult($stackPath)
{
    $resultFile = "$stackPath/last_result.json";
    if (is_file($resultFile)) {
        $content = @file_get_contents($resultFile);
        if ($content) {
            return @json_decode($content, true);
        }
    }
    return null;
}

/**
 * Determine whether Compose-managed containers should be hidden from the Docker tab
 * Uses parse_plugin_cfg('compose.manager') when available (testable), or falls back to
 * parsing /boot/config/plugins/compose.manager/compose.manager.cfg
 *
 * @return bool
 */
function hide_compose_from_docker(): bool
{
    $cfg = [];
    if (function_exists('parse_plugin_cfg')) {
        $cfg = parse_plugin_cfg('compose.manager');
    } else {
        $cfg = @parse_ini_file('/boot/config/plugins/compose.manager/compose.manager.cfg') ?: [];
    }
    return (isset($cfg['HIDE_COMPOSE_FROM_DOCKER']) && $cfg['HIDE_COMPOSE_FROM_DOCKER'] === 'true');
}
