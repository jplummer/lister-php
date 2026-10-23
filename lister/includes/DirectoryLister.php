<?php
/**
 * DirectoryLister - Core directory listing functionality
 * Handles directory scanning, file type detection, and data formatting
 */

require_once __DIR__ . '/PathSanitizer.php';

class DirectoryLister
{
  private $config;
  private $currentPath;
  private $basePath;
  private $files = [];
  private $directories = [];
  private $extensionTypes = null;
  /** @var string|null */
  private $sortOverrideBy = null;
  /** @var string|null */
  private $sortOverrideDir = null;
  /** True when the URL path is not an existing file or directory under the listing root */
  private $requestPathMissing = false;
  /** URL path (no leading slash) after sanitization; for 404 messaging only */
  private $requestPathMissingLabel = '';

  public function __construct($config, $basePath = null)
  {
    $this->config = $config;
    $rawBasePath = $basePath ?: $_SERVER['DOCUMENT_ROOT'];
    // Normalize basePath to ensure consistent path comparison
    $this->basePath = realpath($rawBasePath) ?: $rawBasePath;
    $this->currentPath = $this->getCurrentPath();
  }

  /**
   * Apply sort column and direction for this request (overrides display.* config).
   */
  public function setSortOverride($sortBy, $sortDir)
  {
    $this->sortOverrideBy = $sortBy;
    $this->sortOverrideDir = $sortDir;
  }

  /**
   * Get the current directory path from URL
   */
  private function getCurrentPath()
  {
    $requestUri = $_SERVER['REQUEST_URI'];
    
    // Remove query string
    $path = parse_url($requestUri, PHP_URL_PATH);
    $path = PathSanitizer::stripTraversalAfterUrlDecode($path ?? '');
    
    // Remove leading slash
    $path = ltrim($path, '/');
    
    // If path is empty, use base path
    if (empty($path)) {
      $this->requestPathMissing = false;
      return $this->basePath;
    }

    $fullPath = $this->basePath . '/' . $path;

    // A file path lists its directory; a directory lists itself
    $dir = null;
    if (is_file($fullPath)) {
      // A hidden file (e.g. /.htaccess routed here by a server error page) is a 404,
      // not a listing of its folder. The running entry script (index.php) is exempt.
      $realFile = realpath($fullPath);
      $isEntryScript = $realFile !== false && $realFile === realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
      if ($realFile !== false && ($isEntryScript || $this->isPathVisible($realFile))) {
        $dir = dirname($fullPath);
      }
    } elseif (is_dir($fullPath)) {
      $dir = $fullPath;
    }

    // Hidden folders (lister/, dotfolders, file_hiding patterns) get the same 404 as missing ones
    $realDir = $dir !== null ? realpath($dir) : false;
    if ($realDir !== false && $this->isPathVisible($realDir)) {
      $this->requestPathMissing = false;
      return $realDir;
    }

    // Missing or hidden: 404 (do not silently list site root)
    $this->requestPathMissing = true;
    $this->requestPathMissingLabel = $path;
    return $this->basePath;
  }

  /**
   * Whether a real (symlink-resolved) path is inside the listing root and not hidden
   * by file_hiding rules. Every segment counts, so a hidden folder hides everything
   * beneath it. All entry points (pages, api.php, preview.php) use this one check.
   */
  public function isPathVisible($realPath)
  {
    $base = rtrim(str_replace('\\', '/', $this->basePath), '/');
    $full = rtrim(str_replace('\\', '/', $realPath), '/');
    if ($full === $base) {
      return true;
    }
    if (strpos($full, $base . '/') !== 0) {
      return false;
    }
    foreach (explode('/', substr($full, strlen($base) + 1)) as $segment) {
      if ($segment !== '' && $this->shouldHideFile($segment)) {
        return false;
      }
    }
    return true;
  }

  /**
   * Whether the request URI pointed at a path that does not exist as a file or folder.
   */
  public function isRequestPathMissing()
  {
    return $this->requestPathMissing;
  }

  /**
   * Sanitized request path without leading slash (for display on 404).
   */
  public function getRequestPathMissingLabel()
  {
    return $this->requestPathMissingLabel;
  }

  /**
   * Scan directory and return file/directory information
   */
  public function scanDirectory($path = null)
  {
    $targetPath = $path ?: $this->currentPath;
    
    if (!is_dir($targetPath)) {
      throw new Exception('Directory not found: ' . $targetPath);
    }
    
    // Normalize the target path (resolve symlinks, etc.)
    $normalizedPath = realpath($targetPath);
    if ($normalizedPath === false) {
      $normalizedPath = $targetPath;
    }
    
    // Update currentPath to the directory being scanned
    // This ensures getItemUrl() generates correct URLs for files in this directory
    $this->currentPath = $normalizedPath;

    $items = scandir($normalizedPath);
    $this->files = [];
    $this->directories = [];

    foreach ($items as $item) {
      if ($item === '.' || $item === '..') {
        continue;
      }

      // Check if file should be hidden
      if ($this->shouldHideFile($item)) {
        continue;
      }

      // Use normalized path for consistency
      $fullPath = $normalizedPath . '/' . $item;
      $itemInfo = $this->getItemInfo($item, $fullPath);

      if (is_dir($fullPath)) {
        // Check if directory is empty
        $dirContents = scandir($fullPath);
        $hasVisibleItems = false;
        
        foreach ($dirContents as $dirItem) {
          if ($dirItem === '.' || $dirItem === '..') {
            continue;
          }
          if (!$this->shouldHideFile($dirItem)) {
            $hasVisibleItems = true;
            break;
          }
        }
        
        // If directory is empty, mark it as such
        if (!$hasVisibleItems) {
          $itemInfo['type'] = 'Empty folder';
          $itemInfo['is_empty'] = true;
        }
        
        $this->directories[] = $itemInfo;
      } else {
        $this->files[] = $itemInfo;
      }
    }

    // Sort items
    $this->sortItems();
    
    return [
      'directories' => $this->directories,
      'files' => $this->files,
      'current_path' => $this->currentPath,
      'parent_path' => $this->getParentPath()
    ];
  }

  /**
   * Check if a file should be hidden based on configuration
   */
  private function shouldHideFile($filename)
  {
    $hidingConfig = $this->config['file_hiding'] ?? [];
    
    // Check dotfiles
    if (($hidingConfig['hide_dotfiles'] ?? true) && strpos($filename, '.') === 0) {
      return true;
    }
    
    if (($hidingConfig['hide_sensitive_files'] ?? true) && $this->filenameMatchesAnyPattern($filename, $hidingConfig['sensitive_patterns'] ?? [])) {
      return true;
    }

    if (($hidingConfig['hide_os_cruft'] ?? true) && $this->filenameMatchesAnyPattern($filename, $hidingConfig['os_cruft_patterns'] ?? [])) {
      return true;
    }

    if (($hidingConfig['hide_app_files'] ?? true) && $this->filenameMatchesAnyPattern($filename, $hidingConfig['app_files_patterns'] ?? [])) {
      return true;
    }

    return false;
  }

  /**
   * @param string[] $patterns
   */
  private function filenameMatchesAnyPattern($filename, $patterns)
  {
    foreach ($patterns as $pattern) {
      if ($this->matchesPattern($filename, $pattern)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Check if filename matches a pattern (supports wildcards)
   */
  private function matchesPattern($filename, $pattern)
  {
    // Handle directory patterns (ending with /)
    if (substr($pattern, -1) === '/') {
      $dirPattern = substr($pattern, 0, -1);
      return $filename === $dirPattern;
    }
    
    // Convert wildcard pattern to regex
    $regex = str_replace(['*', '.'], ['.*', '\.'], $pattern);
    $regex = '/^' . $regex . '$/i';
    
    return preg_match($regex, $filename);
  }

  /**
   * Directory path relative to base (no leading/trailing slashes), unencoded segment text.
   */
  private function getCurrentDirectoryRelativeToBase()
  {
    $normalizedBase = rtrim($this->basePath, '/');
    $normalizedCurrent = rtrim($this->currentPath, '/');
    
    if (empty($normalizedCurrent) || !is_dir($normalizedCurrent)) {
      $normalizedCurrent = $normalizedBase;
    }
    
    $relativePath = '';
    if ($normalizedCurrent !== $normalizedBase) {
      $realBase = realpath($normalizedBase);
      $realCurrent = realpath($normalizedCurrent);
      
      if ($realBase && $realCurrent && strpos($realCurrent, $realBase) === 0) {
        $relativePath = substr($realCurrent, strlen($realBase));
        $relativePath = trim($relativePath, '/');
      }
      
      if (empty($relativePath)) {
        $relativePath = str_replace($normalizedBase, '', $normalizedCurrent);
        $relativePath = trim($relativePath, '/');
      }
    }
    
    return $relativePath;
  }

  /**
   * Path segments from document root to this item (unencoded). Used for web_path and URLs.
   */
  private function getItemUrlPathSegmentsUnencoded($name)
  {
    $relativePath = $this->getCurrentDirectoryRelativeToBase();
    $segments = [];
    if (!empty($relativePath)) {
      foreach (explode('/', $relativePath) as $segment) {
        if ($segment !== '') {
          $segments[] = $segment;
        }
      }
    }
    $segments[] = $name;
    return $segments;
  }

  /**
   * Path under document root (no leading slash), unencoded — for API, data-path, and filesystem join.
   */
  private function getItemWebPath($name)
  {
    return implode('/', $this->getItemUrlPathSegmentsUnencoded($name));
  }

  /**
   * Get detailed information about a file or directory
   */
  private function getItemInfo($name, $fullPath)
  {
    $stat = stat($fullPath);
    $isDir = is_dir($fullPath);
    
    return [
      'name' => $name,
      'path' => $fullPath,
      'web_path' => $this->getItemWebPath($name),
      'url' => $this->getItemUrl($name),
      'is_directory' => $isDir,
      'size' => $isDir ? null : $stat['size'],
      'size_formatted' => $isDir ? '-' : $this->formatFileSize($stat['size']),
      'modified' => $stat['mtime'],
      'modified_formatted' => $this->formatDate($stat['mtime']),
      'permissions' => $this->formatPermissions($stat['mode']),
      'extension' => $isDir ? null : $this->getFileExtension($name),
      'mime_type' => $isDir ? null : $this->getMimeType($fullPath),
      'type' => $isDir ? 'Folder' : $this->getFileType($name),
      'icon' => $this->getFileIcon($name, $isDir),
      'preview_kind' => $isDir ? null : $this->getPreviewKindForFile($name)
    ];
  }

  /**
   * Whether the file can open in the listing modal: image, pdf, iframe (URL in modal), or text.
   *
   * @return string|null 'image', 'pdf', 'iframe', 'text', or null
   */
  public function previewKindForFilename($name)
  {
    return $this->getPreviewKindForFile($name);
  }

  /**
   * @return string|null 'image', 'pdf', 'iframe', 'text', or null
   */
  private function getPreviewKindForFile($name)
  {
    $ext = $this->getFileExtension($name);
    if ($ext === 'pdf') {
      return 'pdf';
    }

    // Rendered in <iframe> at file URL (HTML output, server-side execution), not as source text.
    $iframeExts = [
      'html', 'htm', 'xhtml', 'shtml',
      'php', 'phtml',
      'asp', 'aspx',
      'jsp', 'jspx',
      'cfm', 'cfml',
      'cgi',
    ];
    if (in_array($ext, $iframeExts, true)) {
      return 'iframe';
    }

    $this->loadExtensionTypes();
    if (isset($this->extensionTypes[$ext])) {
      $cat = $this->extensionTypes[$ext][0];
      if ($cat === 'image') {
        return 'image';
      }
      if (in_array($cat, ['text', 'code', 'web'], true)) {
        return 'text';
      }
      if ($cat === 'data' && in_array($ext, ['json', 'xml', 'yaml', 'yml', 'toml', 'csv', 'tsv'], true)) {
        return 'text';
      }
    }

    $fallbackText = [
      'md', 'markdown', 'log', 'gitignore', 'editorconfig',
      'json', 'yaml', 'yml', 'toml', 'csv', 'tsv', 'sh', 'bash', 'zsh',
      'htaccess', 'gitattributes'
    ];
    if (in_array($ext, $fallbackText, true)) {
      return 'text';
    }

    return null;
  }

  /**
   * Get URL for file or directory
   */
  private function getItemUrl($name)
  {
    // For expandable directories, we'll use data attributes instead of URLs
    // Files will still have direct URLs
    $segments = $this->getItemUrlPathSegmentsUnencoded($name);
    $urlParts = array_map('rawurlencode', $segments);
    
    // Always return an absolute URL starting with /
    $url = '/' . implode('/', $urlParts);
    
    // Safety check: ensure URL doesn't contain API endpoint patterns
    if (strpos($url, 'api.php') !== false || strpos($url, 'lister/api') !== false) {
      error_log('Warning: getItemUrl() generated invalid URL: ' . $url . ' for file: ' . $name . ' in directory: ' . $this->currentPath);
      // Fallback: return just the filename if URL construction failed
      return '/' . rawurlencode($name);
    }
    
    return $url;
  }

  /**
   * Format file size in human-readable format
   */
  private function formatFileSize($bytes)
  {
    if ($bytes == 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $factor = floor(log($bytes, 1024));
    
    return round($bytes / pow(1024, $factor), 1) . ' ' . $units[$factor];
  }

  /**
   * Format date in readable format
   */
  private function formatDate($timestamp)
  {
    return date('Y-m-d H:i', $timestamp);
  }

  /**
   * Format file permissions
   */
  private function formatPermissions($mode)
  {
    $info = '';
    
    $info .= (($mode & 0x0100) ? 'r' : '-');
    $info .= (($mode & 0x0080) ? 'w' : '-');
    $info .= (($mode & 0x0040) ? 'x' : '-');
    $info .= (($mode & 0x0020) ? 'r' : '-');
    $info .= (($mode & 0x0010) ? 'w' : '-');
    $info .= (($mode & 0x0008) ? 'x' : '-');
    $info .= (($mode & 0x0004) ? 'r' : '-');
    $info .= (($mode & 0x0002) ? 'w' : '-');
    $info .= (($mode & 0x0001) ? 'x' : '-');
    
    return $info;
  }

  /**
   * Get file extension
   */
  private function getFileExtension($filename)
  {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
  }

  /**
   * Get MIME type for file
   */
  private function getMimeType($filepath)
  {
    if (function_exists('mime_content_type')) {
      return mime_content_type($filepath);
    }
    
    if (function_exists('finfo_file')) {
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      return finfo_file($finfo, $filepath);
    }
    
    return 'application/octet-stream';
  }

  /**
   * Load extension types from JSON file
   * 
   * Uses dyne/file-extension-list (https://github.com/dyne/file-extension-list)
   * for comprehensive file extension to type mapping
   */
  private function loadExtensionTypes()
  {
    if ($this->extensionTypes !== null) {
      return;
    }
    
    $extensionsFile = __DIR__ . '/../config/extensions.json';
    if (file_exists($extensionsFile)) {
      $jsonData = file_get_contents($extensionsFile);
      $this->extensionTypes = json_decode($jsonData, true) ?: [];
    } else {
      $this->extensionTypes = [];
    }
  }

  /**
   * Get descriptive file type from extension
   */
  private function getFileType($filename)
  {
    $this->loadExtensionTypes();
    
    $extension = $this->getFileExtension($filename);
    if (empty($extension)) {
      return 'File';
    }
    
    // Check if we have type information for this extension
    if (isset($this->extensionTypes[$extension])) {
      $categories = $this->extensionTypes[$extension];
      // Return the first category, or a more descriptive name
      $category = $categories[0];
      
      // Convert category to more descriptive names
      $typeMap = [
        'code' => 'Code file',
        'image' => 'Image',
        'video' => 'Video',
        'audio' => 'Audio',
        'archive' => 'Archive',
        'document' => 'Document',
        'text' => 'Text file',
        'data' => 'Data file',
        'font' => 'Font',
        'executable' => 'Executable',
        'system' => 'System file',
        'web' => 'Web file',
        'presentation' => 'Presentation',
        'spreadsheet' => 'Spreadsheet',
        'database' => 'Database',
        'cad' => 'CAD file',
        'ebook' => 'E-book',
        'game' => 'Game file',
        'config' => 'Configuration',
        'backup' => 'Backup'
      ];
      
      // Special handling for specific extensions that need better categorization
      $specialTypes = [
        'pdf' => 'Document',
        'doc' => 'Document',
        'docx' => 'Document',
        'odt' => 'Document',
        'rtf' => 'Document',
        'txt' => 'Text file',
        'md' => 'Text file',
        'log' => 'Text file'
      ];
      
      if (isset($specialTypes[$extension])) {
        return $specialTypes[$extension];
      }
      
      return $typeMap[$category] ?? ucfirst($category);
    }
    
    // Fallback to extension-based naming
    return ucfirst($extension) . ' file';
  }

  /**
   * Get appropriate icon for file type
   */
  private function getFileIcon($name, $isDir)
  {
    if ($isDir) {
      return 'folder';
    }
    
    $this->loadExtensionTypes();
    $extension = $this->getFileExtension($name);
    
    if (empty($extension)) {
      return 'file';
    }
    
    // Check if we have type information for this extension
    if (isset($this->extensionTypes[$extension])) {
      $categories = $this->extensionTypes[$extension];
      $category = $categories[0]; // Use the first category
      
      // Map categories to icon types
      $categoryToIconMap = [
        'code' => 'code',
        'image' => 'image',
        'video' => 'video',
        'audio' => 'audio',
        'archive' => 'archive',
        'book' => 'book',
        'exec' => 'exec',
        'web' => 'web',
        'sheet' => 'sheet',
        'text' => 'text',
        'font' => 'font',
        'slide' => 'slide'
      ];
      
      return $categoryToIconMap[$category] ?? 'file';
    }
    
    // Fallback for unknown extensions
    return 'file';
  }

  /**
   * Sort items based on configuration
   */
  private function sortItems()
  {
    $sortBy = $this->sortOverrideBy ?? ($this->config['display']['default_sort'] ?? 'name');
    $sortDir = $this->sortOverrideDir ?? ($this->config['display']['sort_direction'] ?? 'asc');
    
    $sortFunction = function($a, $b) use ($sortBy, $sortDir) {
      if ($sortBy === 'size') {
        $valueA = (int) ($a['size'] ?? 0);
        $valueB = (int) ($b['size'] ?? 0);
        $result = $valueA <=> $valueB;
      } elseif ($sortBy === 'modified') {
        $valueA = (int) ($a['modified'] ?? 0);
        $valueB = (int) ($b['modified'] ?? 0);
        $result = $valueA <=> $valueB;
      } else {
        $valueA = (string) ($a[$sortBy] ?? '');
        $valueB = (string) ($b[$sortBy] ?? '');
        $result = strcasecmp($valueA, $valueB);
      }
      return $sortDir === 'desc' ? -$result : $result;
    };
    
    usort($this->directories, $sortFunction);
    usort($this->files, $sortFunction);
  }

  /**
   * Get parent directory path
   */
  private function getParentPath()
  {
    $parent = dirname($this->currentPath);
    if ($parent === $this->basePath) {
      return null; // Already at root
    }
    return $parent;
  }

  /**
   * Get breadcrumb navigation
   */
  public function getBreadcrumbs()
  {
    $breadcrumbs = [];
    $path = $this->currentPath;
    $basePath = $this->basePath;
    
    while ($path !== $basePath && $path !== dirname($basePath)) {
      $name = basename($path);
      $breadcrumbs[] = [
        'name' => $name,
        'path' => $path,
        'url' => $this->getItemUrl($name)
      ];
      $path = dirname($path);
    }
    
    $breadcrumbs[] = [
      'name' => 'Home',
      'path' => $basePath,
      'url' => '/'
    ];
    
    return array_reverse($breadcrumbs);
  }
}

