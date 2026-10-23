<?php
/**
 * App - Main application class
 * Handles application initialization, environment checks, and rendering
 */

class App
{
  private $config;
  private $lister;
  private $data;
  private $breadcrumbs;
  private $error;
  private $sortBy;
  private $sortDir;
  /** HTTP status for render() (e.g. 404 for missing listing path) */
  private $httpStatus = 200;

  public function __construct()
  {
    $this->loadConfiguration();
    $this->checkEnvironment();
    $this->checkSecurity();
    $this->initializeLister();
    $this->processRequest();
  }

  /**
   * Load configuration from file
   */
  private function loadConfiguration()
  {
    require_once __DIR__ . '/ConfigLoader.php';
    $this->config = ConfigLoader::loadDefaultJson();
  }

  /**
   * Check that the environment is suitable for running the application
   */
  private function checkEnvironment()
  {
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.3.0', '<')) {
      throw new Exception('PHP 8.3 or higher is required. Current version: ' . PHP_VERSION . '. Contact your hosting provider to upgrade PHP.');
    }

    // Check required functions
    $requiredFunctions = ['scandir', 'stat', 'is_dir', 'is_file'];
    $missingFunctions = [];
    foreach ($requiredFunctions as $function) {
      if (!function_exists($function)) {
        $missingFunctions[] = $function;
      }
    }
    if (!empty($missingFunctions)) {
      throw new Exception('Required PHP functions are disabled: ' . implode(', ', $missingFunctions) . '. Contact your hosting provider to enable these functions.');
    }

    // Check if we can read the current directory
    if (!is_readable('.')) {
      $perms = is_dir('.') ? substr(sprintf('%o', fileperms('.')), -4) : 'unknown';
      throw new Exception('Cannot read current directory. Current permissions: ' . $perms . '. The web server needs read access to the directory.');
    }
    
    // Check if data directory is writable (if security is enabled)
    if ($this->config['security']['enabled'] ?? false) {
      $dataDir = __DIR__ . '/../data';
      // If directory doesn't exist, check if parent is writable
      if (!is_dir($dataDir)) {
        $parentDir = dirname($dataDir);
        if (!is_writable($parentDir)) {
          $perms = is_dir($parentDir) ? substr(sprintf('%o', fileperms($parentDir)), -4) : 'unknown';
          throw new Exception('Cannot create data directory: lister/data/. Parent directory permissions: ' . $perms . '. Run: chmod 755 lister/ (or create lister/data/ manually with chmod 755)');
        }
      } elseif (!is_writable($dataDir)) {
        $perms = substr(sprintf('%o', fileperms($dataDir)), -4);
        throw new Exception('Data directory is not writable: lister/data/. Current permissions: ' . $perms . '. Run: chmod 755 lister/data/');
      }
    }
  }

  /**
   * Check security (rate limiting, bot detection)
   */
  private function checkSecurity()
  {
    if ($this->config['security']['enabled'] ?? false) {
      $securityPath = __DIR__ . '/Security.php';
      if (!file_exists($securityPath)) {
        throw new Exception('Security class file not found: lister/includes/Security.php. Make sure you uploaded the entire lister/ folder.');
      }
      
      require_once $securityPath;
      $security = new Security($this->config);
      $security->checkRequest();
    }
  }

  /**
   * Initialize the DirectoryLister
   */
  private function initializeLister()
  {
    $listerPath = __DIR__ . '/DirectoryLister.php';
    if (!file_exists($listerPath)) {
      throw new Exception('DirectoryLister class file not found: lister/includes/DirectoryLister.php. Make sure you uploaded the entire lister/ folder.');
    }
    
    require_once $listerPath;
    $this->lister = new DirectoryLister($this->config);
  }

  /**
   * Process the current request
   */
  private function processRequest()
  {
    require_once __DIR__ . '/SortPreference.php';
    $prefs = SortPreference::resolveForHtmlPage($this->config);
    $this->sortBy = $prefs['sort_by'];
    $this->sortDir = $prefs['sort_dir'];
    $this->lister->setSortOverride($this->sortBy, $this->sortDir);

    if ($this->lister->isRequestPathMissing()) {
      $this->httpStatus = 404;
      $this->error = 'The requested path does not exist.';
      $this->data = null;
      $this->breadcrumbs = [];
      return;
    }

    try {
      $this->data = $this->lister->scanDirectory();
      $this->breadcrumbs = $this->lister->getBreadcrumbs();
      require_once __DIR__ . '/ReadmePreview.php';
      $this->data['readme_preview'] = ReadmePreview::load(
        $this->data['current_path'],
        $this->config
      );
    } catch (Exception $e) {
      $this->error = $e->getMessage();
      $this->data = null;
      $this->breadcrumbs = [];
    }
  }

  /**
   * Material Symbols Outlined ligature name for a file-type icon key
   */
  public function getIconSymbol($icon)
  {
    require_once __DIR__ . '/IconSymbols.php';
    return IconSymbols::symbolFor($icon);
  }

  /**
   * Send a 404 and render Lister's not-found page. Static so other entry points
   * (e.g. a disabled log.php) can return the same page as a missing listing path.
   */
  public static function renderNotFound($requestPathLabel)
  {
    http_response_code(404);
    $label = ltrim((string) $requestPathLabel, '/');
    $listerRequestPathDisplay = '/' . $label;
    $listerPageTitle = 'Page not found — Miscellaneous';
    $listerMainHeading = 'Page not found';
    $deploymentTimestamp = self::getDeploymentTimestamp();
    include __DIR__ . '/../templates/http_error_404.php';
  }

  /**
   * Get deployment timestamp for verification
   */
  private static function getDeploymentTimestamp()
  {
    // Read from .deploy-timestamp file (created during deployment)
    $timestampFile = __DIR__ . '/../.deploy-timestamp';
    if (file_exists($timestampFile)) {
      $timestamp = trim(file_get_contents($timestampFile));
      if (!empty($timestamp)) {
        return $timestamp;
      }
    }
    
    return null;
  }

  /**
   * Render the application
   */
  public function render()
  {
    if ($this->httpStatus !== 200) {
      http_response_code($this->httpStatus);
    }

    if ($this->error !== null && $this->httpStatus === 404 && $this->lister->isRequestPathMissing()) {
      self::renderNotFound($this->lister->getRequestPathMissingLabel());
      return;
    }

    $templatePath = __DIR__ . '/../templates/index.php';
    if (!file_exists($templatePath)) {
      throw new Exception('Template file not found: lister/templates/index.php. Make sure you uploaded the entire lister/ folder.');
    }
    
    if (!is_readable($templatePath)) {
      $perms = substr(sprintf('%o', fileperms($templatePath)), -4);
      throw new Exception('Cannot read template file: lister/templates/index.php. Current permissions: ' . $perms . '. Run: chmod 644 lister/templates/index.php');
    }

    // Extract variables for template
    $config = $this->config;
    $data = $this->data;
    $breadcrumbs = $this->breadcrumbs;
    $error = $this->error;
    $sortBy = $this->sortBy;
    $sortDir = $this->sortDir;
    $deploymentTimestamp = self::getDeploymentTimestamp();
    
    // Make getIconSymbol function available to template
    $getIconSymbol = [$this, 'getIconSymbol'];

    require_once __DIR__ . '/IconSymbols.php';
    $listerIconSymbolsJson = json_encode(IconSymbols::getMap(), JSON_UNESCAPED_UNICODE);
    if ($listerIconSymbolsJson === false) {
      throw new Exception('Failed to encode icon symbol map for template.');
    }

    // Include the template
    include $templatePath;
  }

  /**
   * Get configuration
   */
  public function getConfig()
  {
    return $this->config;
  }

  /**
   * Get data
   */
  public function getData()
  {
    return $this->data;
  }

  /**
   * Get breadcrumbs
   */
  public function getBreadcrumbs()
  {
    return $this->breadcrumbs;
  }

  /**
   * Get error
   */
  public function getError()
  {
    return $this->error;
  }
}
