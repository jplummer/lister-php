<?php
/**
 * JSON API: safe HTML for text/markdown file preview in the listing modal.
 * Same path rules as direct file access; does not expose files outside the document root.
 */

require_once __DIR__ . '/includes/ConfigLoader.php';

try {
  $config = ConfigLoader::loadDefaultJson();
} catch (Exception $e) {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
  exit;
}

if ($config['security']['enabled'] ?? false) {
  require_once __DIR__ . '/includes/Security.php';
  $security = new Security($config, true);
  $security->checkRequest();
}

require_once __DIR__ . '/includes/PathSanitizer.php';

header('Content-Type: application/json; charset=utf-8');

$maxBytes = (int) ($config['display']['preview_text_max_bytes'] ?? 524288);
if ($maxBytes < 1) {
  $maxBytes = 524288;
}

try {
  $requested = $_GET['path'] ?? '';
  $requested = PathSanitizer::stripTraversalAfterUrlDecode($requested);
  $requested = ltrim(str_replace('\\', '/', $requested), '/');

  $basePath = $_SERVER['DOCUMENT_ROOT'];
  $normalizedBase = realpath($basePath) ?: $basePath;

  $fullPath = $requested === ''
    ? $normalizedBase
    : $normalizedBase . '/' . $requested;

  $realFull = realpath($fullPath);
  if ($realFull === false || !is_file($realFull) || !is_readable($realFull)) {
    throw new Exception('File not found');
  }

  $baseNorm = rtrim(str_replace('\\', '/', $normalizedBase), '/');
  $fullNorm = str_replace('\\', '/', $realFull);
  if ($fullNorm !== $baseNorm && strpos($fullNorm, $baseNorm . '/') !== 0) {
    throw new Exception('Access denied');
  }

  require_once __DIR__ . '/includes/DirectoryLister.php';
  $lister = new DirectoryLister($config, $normalizedBase);
  // Only preview what the listing would show: never lister/ internals, dotfiles, or hidden patterns
  if (!$lister->isPathVisible($realFull)) {
    throw new Exception('File not found');
  }
  $name = basename($realFull);
  if ($lister->previewKindForFilename($name) !== 'text') {
    throw new Exception('Preview not available for this file type');
  }

  $size = filesize($realFull);
  if ($size === false || $size > $maxBytes) {
    throw new Exception('File too large to preview');
  }

  $raw = file_get_contents($realFull, false, null, 0, $maxBytes + 1);
  if ($raw === false || strlen($raw) > $maxBytes) {
    throw new Exception('File too large to preview');
  }

  if (function_exists('mb_check_encoding')
    && function_exists('mb_convert_encoding')
    && !mb_check_encoding($raw, 'UTF-8')) {
    $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    if ($converted !== false) {
      $raw = $converted;
    }
  }

  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

  if ($ext === 'txt') {
    $html = '<div class="lister-preview-plain readme-plain">'
      . nl2br(htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'))
      . '</div>';
  } elseif (in_array($ext, ['md', 'markdown', 'mkd'], true)) {
    $parsedownPath = __DIR__ . '/includes/Parsedown.php';
    if (!is_readable($parsedownPath)) {
      throw new Exception('Markdown parser unavailable');
    }
    require_once $parsedownPath;
    $pd = new Parsedown();
    $pd->setSafeMode(true);
    $html = '<div class="lister-preview-markdown readme-markdown">' . $pd->text($raw) . '</div>';
  } else {
    $html = '<pre class="lister-preview-code"><code>'
      . htmlspecialchars($raw, ENT_QUOTES, 'UTF-8')
      . '</code></pre>';
  }

  $jsonFlags = JSON_UNESCAPED_UNICODE;
  if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
  }
  echo json_encode([
    'success' => true,
    'title' => $name,
    'html' => $html
  ], $jsonFlags);
} catch (Exception $e) {
  http_response_code(400);
  echo json_encode([
    'success' => false,
    'error' => $e->getMessage()
  ]);
}
