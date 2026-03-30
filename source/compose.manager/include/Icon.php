<?php
/**
 * Serve project icons for Compose Manager
 */

$docroot = $_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp';
require_once "$docroot/plugins/compose.manager/php/defines.php";

$project = $_GET['project'] ?? '';

if (empty($project)) {
    http_response_code(404);
    exit;
}

// Sanitize project name
$project = basename($project);
$compose_root = locate_compose_root('compose.manager');
$projectPath = "$compose_root/$project";

if (!is_dir($projectPath)) {
    http_response_code(404);
    exit;
}

// Look for icon file
$iconFiles = ['icon.png', 'icon.jpg', 'icon.gif', 'icon.svg', 'icon'];
$iconPath = null;
$mimeType = 'image/png';

foreach ($iconFiles as $iconFile) {
    $testPath = "$projectPath/$iconFile";
    if (is_file($testPath)) {
        $iconPath = $testPath;
        
        // Determine mime type
        $ext = strtolower(pathinfo($iconFile, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $mimeType = 'image/jpeg';
                break;
            case 'gif':
                $mimeType = 'image/gif';
                break;
            case 'svg':
                $mimeType = 'image/svg+xml';
                break;
            case 'png':
            default:
                $mimeType = 'image/png';
                break;
        }
        break;
    }
}

if (!$iconPath) {
    http_response_code(404);
    exit;
}

// Serve the icon
header("Content-Type: $mimeType");
header("Cache-Control: public, max-age=3600");
header("Content-Length: " . filesize($iconPath));
readfile($iconPath);
