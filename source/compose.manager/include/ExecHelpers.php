<?php
/**
 * Exec Functions for Compose Manager
 * 
 * Contains utility functions used by exec.php for AJAX action handling.
 * Separated from exec.php to allow unit testing without triggering the switch statement.
 */

/**
 * Convert an element name to a safe HTML ID.
 * Replaces dots with dashes and removes spaces.
 *
 * @param string $element The element name to convert
 * @return string The sanitized ID
 */
function getElement($element) {
    $return = str_replace(".","-",$element);
    $return = str_replace(" ","",$return);
    return $return;
}

/**
 * Normalize Docker image name for update checking.
 * Strips the docker.io/ prefix (docker compose adds this for Docker Hub images)
 * and @sha256: digest suffix, then uses Unraid's DockerUtil::ensureImageTag
 * for consistent normalization matching how Unraid stores update status.
 *
 * @param string $image The image name to normalize
 * @return string The normalized image name
 */
function normalizeImageForUpdateCheck($image) {
    // Strip docker.io/ prefix (docker compose adds this for Docker Hub images)
    if (strpos($image, 'docker.io/') === 0) {
        $image = substr($image, 10); // Remove 'docker.io/'
    }
    // Strip @sha256: digest suffix if present (image pinning)
    if (($digestPos = strpos($image, '@sha256:')) !== false) {
        $image = substr($image, 0, $digestPos);
    }
    // Use Unraid's normalization for consistent key format (adds library/ prefix for official images, ensures tag)
    return DockerUtil::ensureImageTag($image);
}

/**
 * Sanitize a stack name to create a safe folder name.
 * Removes special characters that could cause issues in paths.
 *
 * @deprecated Moved to util.php. This stub remains for backward compatibility.
 *
 * @param string $stackName The stack name to sanitize
 * @return string The sanitized folder name
 */
if (!function_exists('sanitizeFolderName')) {
    function sanitizeFolderName($stackName) {
        $folderName = str_replace('"', "", $stackName);
        $folderName = str_replace("'", "", $folderName);
        $folderName = str_replace("&", "", $folderName);
        $folderName = str_replace("(", "", $folderName);
        $folderName = str_replace(")", "", $folderName);
        $folderName = preg_replace("/ {2,}/", " ", $folderName);
        $folderName = preg_replace("/\s/", "_", $folderName);
        return $folderName;
    }
}

/**
 * Build the common compose CLI arguments for a stack.
 *
 * @deprecated Use StackInfo::fromProject($composeRoot, $stack)->buildComposeArgs() instead.
 *
 * Resolves the project name, compose/override files, and env-file flag
 * from the stack directory.  Thin wrapper around StackInfo for backward compatibility.
 *
 * @param string $stack  Stack directory name (basename under $compose_root)
 * @return array{projectName: string, files: string, envFile: string}
 */
function buildComposeArgs(string $stack): array {
    global $compose_root;

    require_once("/usr/local/emhttp/plugins/compose.manager/php/util.php");
    return StackInfo::fromProject($compose_root, $stack)->buildComposeArgs();
}
