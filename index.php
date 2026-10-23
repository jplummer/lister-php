<?php
/**
 * Lister - Directory Listing Application
 * Main entry point for the application
 */

// Pre-flight checks: verify critical files exist before loading App
$requiredFiles = [
  'lister/includes/App.php' => 'Main application class',
  'lister/includes/ConfigLoader.php' => 'Configuration loader',
  'lister/includes/PathSanitizer.php' => 'Path sanitization helper',
  'lister/includes/IconSymbols.php' => 'File icon Material symbol names',
  'lister/config/default.json' => 'Configuration file',
  'lister/templates/index.php' => 'Main template',
  'lister/includes/DirectoryLister.php' => 'Directory listing class',
  'lister/api.php' => 'API endpoint',
  'lister/preview.php' => 'Text preview API for modal'
];

$missingFiles = [];
$permissionIssues = [];

foreach ($requiredFiles as $file => $description) {
  $fullPath = __DIR__ . '/' . $file;
  if (!file_exists($fullPath)) {
    $missingFiles[] = ['file' => $file, 'path' => $fullPath, 'description' => $description];
  } elseif (!is_readable($fullPath)) {
    $permissionIssues[] = ['file' => $file, 'path' => $fullPath, 'description' => $description];
  }
}

// If critical files are missing, show installation error
// Also show conflict warning if there are other index files (but only if we're missing files)
if (!empty($missingFiles) || !empty($permissionIssues)) {
  http_response_code(500);
  header('Content-Type: text/html; charset=UTF-8');
  lister_render_preflight_install_error($missingFiles, $permissionIssues);
  exit;
}

/**
 * Read `app.debug` from default.json without loading App (for catch block).
 * Invalid or missing JSON → false (production-safe).
 */
function lister_read_app_debug_flag(): bool {
  $path = __DIR__ . '/lister/config/default.json';
  if (!is_readable($path)) {
    return false;
  }
  $raw = file_get_contents($path);
  if ($raw === false) {
    return false;
  }
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    return false;
  }
  return !empty($data['app']['debug']);
}

// Load the main App class
require_once __DIR__ . '/lister/includes/App.php';

try {
  // Initialize and run the application
  $app = new App();
  $app->render();
} catch (Exception $e) {
  // Handle critical errors
  $errorMessage = $e->getMessage();
  $errorType = 'Runtime Error';
  $suggestions = [];
  
  // Categorize errors and provide suggestions
  if (strpos($errorMessage, 'Configuration file not found') !== false) {
    $errorType = 'Installation Error';
    $suggestions = [
      'Make sure you uploaded the entire lister/ folder',
      'Verify lister/config/default.json exists',
      'Check file permissions: chmod 644 lister/config/default.json'
    ];
  } elseif (strpos($errorMessage, 'Invalid configuration file') !== false) {
    $errorType = 'Configuration Error';
    $suggestions = [
      'Check lister/config/default.json for JSON syntax errors',
      'Validate JSON using an online JSON validator',
      'Re-upload default.json from the repository if corrupted'
    ];
  } elseif (strpos($errorMessage, 'Cannot read') !== false) {
    $errorType = 'Permission Error';
    $suggestions = [
      'Check file permissions on the mentioned file or directory',
      'Run: chmod 644 for files, chmod 755 for directories',
      'Verify the web server user has read access'
    ];
  } elseif (strpos($errorMessage, 'Template file not found') !== false) {
    $errorType = 'Installation Error';
    $suggestions = [
      'Make sure lister/templates/index.php exists',
      'Verify the entire lister/ folder was uploaded',
      'Check for nested lister/lister/ folder structure'
    ];
  } elseif (strpos($errorMessage, 'PHP') !== false && strpos($errorMessage, 'required') !== false) {
    $errorType = 'Environment Error';
    $suggestions = [
      'Contact your hosting provider to upgrade PHP version',
      'PHP 8.3 or higher is required',
      'Check your current PHP version in your hosting control panel'
    ];
  } elseif (strpos($errorMessage, 'function not available') !== false) {
    $errorType = 'Environment Error';
    $suggestions = [
      'Contact your hosting provider - required PHP functions are disabled',
      'The function may be disabled in php.ini',
      'Some hosting providers restrict certain functions for security'
    ];
  }

  $listerRuntimeDebug = lister_read_app_debug_flag();
  if (!$listerRuntimeDebug) {
    error_log(sprintf(
      'Lister [%s] %s | %s:%d',
      $errorType,
      $errorMessage,
      $e->getFile(),
      $e->getLine()
    ));
    $errorMessage = 'Something went wrong while loading this page. Please try again later.';
    $suggestions = [];
    $errorType = 'Error';
  }

  http_response_code(500);
  $runtimeErrorPath = __DIR__ . '/lister/templates/runtime_error.php';
  if (is_readable($runtimeErrorPath)) {
    define('LISTER_RUNTIME_ERROR_RENDER', true);
    include $runtimeErrorPath;
  } else {
    header('Content-Type: text/plain; charset=UTF-8');
    if (!$listerRuntimeDebug) {
      echo "An error occurred.\n";
    } else {
      echo $errorType . ': ' . $errorMessage . "\n";
    }
  }
}

/**
 * HTML response when required files are missing or unreadable (before lister/ is usable).
 */
function lister_render_preflight_install_error(array $missingFiles, array $permissionIssues): void {
  ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Installation Error - Directory Listing</title>
  <style>
    body { 
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
      margin: 0; 
      padding: 40px; 
      background: #f5f5f5;
      line-height: 1.6;
    }
    .container {
      max-width: 800px;
      margin: 0 auto;
      background: white;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      overflow: hidden;
    }
    .error-header {
      background: #dc3545;
      color: white;
      padding: 24px 32px;
    }
    .error-header h1 {
      margin: 0;
      font-size: 24px;
      font-weight: 600;
    }
    .error-content {
      padding: 32px;
    }
    .error-section {
      margin-bottom: 24px;
    }
    .error-section h2 {
      margin: 0 0 12px 0;
      font-size: 18px;
      color: #dc3545;
    }
    .error-section p {
      margin: 8px 0;
      color: #333;
    }
    .file-list {
      background: #f8f9fa;
      border-left: 4px solid #dc3545;
      padding: 16px;
      margin: 16px 0;
    }
    .file-list code {
      background: #e9ecef;
      padding: 2px 6px;
      border-radius: 3px;
      font-family: "Courier New", monospace;
      font-size: 14px;
    }
    .solution {
      background: #d1ecf1;
      border-left: 4px solid #0c5460;
      padding: 16px;
      margin: 16px 0;
    }
    .solution h3 {
      margin: 0 0 8px 0;
      color: #0c5460;
      font-size: 16px;
    }
    .solution ol {
      margin: 8px 0;
      padding-left: 24px;
    }
    .solution li {
      margin: 4px 0;
    }
    .command {
      background: #2d2d2d;
      color: #f8f8f2;
      padding: 12px;
      border-radius: 4px;
      font-family: "Courier New", monospace;
      font-size: 14px;
      margin: 8px 0;
      overflow-x: auto;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="error-header">
      <h1>Installation Error</h1>
    </div>
    <div class="error-content">
      <?php if (!empty($missingFiles)): ?>
      <div class="error-section">
        <h2>Missing Required Files</h2>
        <p>The following required files are missing from your installation:</p>
        <div class="file-list">
          <?php foreach ($missingFiles as $item): ?>
          <p><strong><?= htmlspecialchars($item['description']) ?>:</strong><br>
          <code><?= htmlspecialchars($item['file']) ?></code></p>
          <?php endforeach; ?>
        </div>
        <div class="solution">
          <h3>Solution</h3>
          <ol>
            <li>Make sure you uploaded the entire <code>lister/</code> folder to your web server</li>
            <li>Verify the file structure matches the installation instructions</li>
            <li>Re-upload any missing files from the repository</li>
            <li>Check that file paths are correct (no nested <code>lister/lister/</code> folders)</li>
          </ol>
          <p><strong>Expected structure:</strong></p>
          <div class="command">
your-domain.com/<br>
├── index.php<br>
├── .htaccess<br>
└── lister/<br>
&nbsp;&nbsp;&nbsp;&nbsp;├── api.php<br>
&nbsp;&nbsp;&nbsp;&nbsp;├── config/<br>
&nbsp;&nbsp;&nbsp;&nbsp;│&nbsp;&nbsp;&nbsp;&nbsp;└── default.json<br>
&nbsp;&nbsp;&nbsp;&nbsp;├── includes/<br>
&nbsp;&nbsp;&nbsp;&nbsp;│&nbsp;&nbsp;&nbsp;&nbsp;├── App.php, DirectoryLister.php, …<br>
&nbsp;&nbsp;&nbsp;&nbsp;└── templates/<br>
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;└── index.php
          </div>
        </div>
      </div>
      <?php endif; ?>
      
      <?php if (!empty($permissionIssues)): ?>
      <div class="error-section">
        <h2>Permission Issues</h2>
        <p>The following files cannot be read due to incorrect permissions:</p>
        <div class="file-list">
          <?php foreach ($permissionIssues as $item): ?>
          <p><strong><?= htmlspecialchars($item['description']) ?>:</strong><br>
          <code><?= htmlspecialchars($item['file']) ?></code></p>
          <?php endforeach; ?>
        </div>
        <div class="solution">
          <h3>Solution</h3>
          <p>Set correct file permissions using these commands:</p>
          <?php foreach ($permissionIssues as $item): ?>
          <div class="command">chmod 644 <?= htmlspecialchars($item['file']) ?></div>
          <?php endforeach; ?>
          <p>Or set permissions for all config files at once:</p>
          <div class="command">chmod 644 lister/config/*.json</div>
        </div>
      </div>
      <?php endif; ?>
      
      
      <div class="error-section">
        <p>For complete installation instructions, see <code>INSTALL.md</code> in the repository.</p>
      </div>
    </div>
  </div>
</body>
</html>
  <?php
}
