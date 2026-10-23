<?php
/**
 * API endpoint for directory listing
 * Handles AJAX requests for expandable directory navigation
 */

require_once __DIR__ . '/includes/ConfigLoader.php';

try {
    $config = ConfigLoader::loadDefaultJson();
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    $jsonFlags = 0;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()], $jsonFlags);
    exit;
}

if ($config['security']['enabled'] ?? false) {
    require_once __DIR__ . '/includes/Security.php';
    $security = new Security($config, true);
    $security->checkRequest();
}

require_once __DIR__ . '/includes/PathSanitizer.php';
require_once __DIR__ . '/includes/DirectoryLister.php';
require_once __DIR__ . '/includes/SortPreference.php';

header('Content-Type: application/json');

try {
    // Path from POST body avoids servers that mishandle slashes in query strings (nested folders).
    // GET still supported for single-segment paths and manual requests.
    $requestedPath = $_POST['path'] ?? $_GET['path'] ?? '';
    $requestedPath = PathSanitizer::stripTraversalAfterUrlDecode($requestedPath);
    
    // Build full path - handle both relative and absolute paths
    $basePath = $_SERVER['DOCUMENT_ROOT'];
    
    // Normalize basePath for consistent path handling
    $normalizedBasePath = realpath($basePath) ?: $basePath;

    // True if string looks like an absolute filesystem path (legacy clients)
    $isAbsoluteFs = static function ($p) {
        if ($p === '' || $p === null) {
            return false;
        }
        if ($p[0] === '/') {
            return true;
        }
        return (bool) preg_match('#^[A-Za-z]:[/\\\\]#', $p);
    };

    if (!$isAbsoluteFs($requestedPath)) {
        // Preferred: web-root-relative path (e.g. tpotter/subfolder)
        $relative = str_replace('\\', '/', $requestedPath);
        $relative = ltrim($relative, '/');
        $fullPath = $relative === ''
            ? $normalizedBasePath
            : $normalizedBasePath . '/' . $relative;
    } elseif (strpos($requestedPath, $normalizedBasePath) === 0 || strpos($requestedPath, $basePath) === 0) {
        $fullPath = $requestedPath;
    } else {
        $fullPath = $normalizedBasePath . '/' . ltrim($requestedPath, '/');
    }
    
    $realFullPath = realpath($fullPath);
    if ($realFullPath === false) {
        throw new Exception('Directory not found: ' . $requestedPath);
    }
    
    // Prefix check with path boundary (avoids /var/www/html matching /var/www/html2)
    $realBasePath = realpath($normalizedBasePath) ?: $normalizedBasePath;
    $baseNorm = rtrim(str_replace('\\', '/', $realBasePath), '/');
    $fullNorm = str_replace('\\', '/', $realFullPath);
    $insideBase = ($fullNorm === $baseNorm || strpos($fullNorm, $baseNorm . '/') === 0);
    
    if (!$insideBase) {
        throw new Exception('Access denied: Path outside base directory');
    }
    
    if (!is_dir($realFullPath)) {
        throw new Exception('Directory not found: ' . $requestedPath);
    }

    // Create DirectoryLister instance with normalized basePath
    $lister = new DirectoryLister($config, $normalizedBasePath);

    // Hidden folders (lister/, dotfolders, file_hiding patterns) look the same as missing ones
    if (!$lister->isPathVisible($realFullPath)) {
        throw new Exception('Directory not found: ' . $requestedPath);
    }
    $sortPrefs = SortPreference::resolveForApi($config);
    $lister->setSortOverride($sortPrefs['sort_by'], $sortPrefs['sort_dir']);

    // Scan the requested directory (use normalized path)
    $result = $lister->scanDirectory($realFullPath);

    // Don't reveal server filesystem paths; the client uses web_path and url
    unset($result['current_path'], $result['parent_path']);
    foreach (['directories', 'files'] as $group) {
        foreach ($result[$group] as $i => $item) {
            unset($result[$group][$i]['path']);
        }
    }

    // Return JSON response (tolerate odd filenames that are not valid UTF-8)
    $jsonFlags = JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode([
        'success' => true,
        'data' => $result
    ], $jsonFlags);
    
} catch (Exception $e) {
    // Return error response
    http_response_code(400);
    $jsonFlags = 0;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], $jsonFlags);
}
