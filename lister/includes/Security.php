<?php
/**
 * Security - Simple bot detection and rate limiting
 * Designed for low-traffic sites to catch unusual activity
 */

class Security
{
  /** Seconds between sweeps of expired per-IP files in lister/data */
  const PRUNE_INTERVAL = 300;
  /** Failed admin logins allowed per IP within ADMIN_LOCKOUT_WINDOW */
  const ADMIN_MAX_FAILURES = 5;
  const ADMIN_LOCKOUT_WINDOW = 900;

  private $dataDir;
  private $config;
  /** When true, denyAccess() returns JSON (for lister/api.php) instead of HTML */
  private $denyAsJson;

  public function __construct($config, $denyAsJson = false)
  {
    $this->config = $config;
    $this->denyAsJson = $denyAsJson;
    $this->dataDir = __DIR__ . '/../data';
    
    // Create data directory if it doesn't exist
    if (!is_dir($this->dataDir)) {
      mkdir($this->dataDir, 0755, true);
    }
    $this->ensureDataDirDenied();
    $this->pruneDataFiles();
  }

  /**
   * Backstop for the root .htaccess: deny web access to lister/data even if the
   * root rules are missing or edited. Apache-only; other servers need their own rule.
   */
  private function ensureDataDirDenied()
  {
    $file = $this->dataDir . '/.htaccess';
    if (file_exists($file)) {
      return;
    }
    $rules = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
      . "<IfModule !mod_authz_core.c>\n  Order Deny,Allow\n  Deny from all\n</IfModule>\n";
    @file_put_contents($file, $rules);
  }

  /**
   * Delete expired per-IP files so lister/data doesn't grow without bound.
   * Runs at most once per PRUNE_INTERVAL seconds, tracked by a marker file's mtime.
   */
  private function pruneDataFiles()
  {
    $marker = $this->dataDir . '/.last_prune';
    $now = time();
    if (file_exists($marker) && $now - filemtime($marker) < self::PRUNE_INTERVAL) {
      return;
    }
    @touch($marker);

    // Rate files only matter for the last minute; block files until they expire;
    // admin failure files for the lockout window
    $maxAge = [
      'rate_' => 60,
      'blocked_' => $this->blockDuration(),
      'admin_fail_' => self::ADMIN_LOCKOUT_WINDOW,
    ];
    foreach ($maxAge as $prefix => $age) {
      foreach (glob($this->dataDir . '/' . $prefix . '*.json') ?: [] as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && $now - $mtime > $age) {
          @unlink($file);
        }
      }
    }
  }

  private function blockDuration()
  {
    return (int) ($this->config['security']['block_duration_seconds'] ?? 300);
  }
  
  /**
   * Check if request should be allowed
   */
  public function checkRequest()
  {
    $ip = $this->getClientIP();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Check if IP is blocked
    if ($this->isBlocked($ip)) {
      $this->logSuspiciousActivity($ip, 'BLOCKED_IP_ACCESS_ATTEMPT', $userAgent);
      $this->denyAccess('Access denied');
    }
    
    // Check for bot behavior
    if ($this->isBot($userAgent)) {
      $this->logSuspiciousActivity($ip, 'BOT_DETECTED', $userAgent);
      $this->denyAccess('Bot detected');
    }
    
    // Check rate limiting
    if (!$this->checkRateLimit($ip)) {
      $this->logSuspiciousActivity($ip, 'RATE_LIMIT_EXCEEDED', $userAgent);
      $this->blockIP($ip, $this->blockDuration());
      $this->denyAccess('Rate limit exceeded');
    }
    
    // Check for suspicious patterns
    if ($this->isSuspiciousRequest()) {
      $this->logSuspiciousActivity($ip, 'SUSPICIOUS_REQUEST', $userAgent);
      // Don't block immediately, just log
    }
  }
  
  /**
   * Get client IP address
   */
  public function getClientIP()
  {
    $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
      if (!empty($_SERVER[$key])) {
        $ip = $_SERVER[$key];
        // Handle comma-separated IPs (from proxies)
        if (strpos($ip, ',') !== false) {
          $ip = trim(explode(',', $ip)[0]);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
          return $ip;
        }
      }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  }
  
  /**
   * Check if user agent looks like a bot
   */
  private function isBot($userAgent)
  {
    if (empty($userAgent)) {
      return true; // No user agent is suspicious
    }
    
    $botPatterns = [
      '/bot/i', '/crawler/i', '/spider/i', '/scraper/i',
      '/curl/i', '/wget/i', '/python/i', '/java/i',
      '/go-http/i', '/okhttp/i', '/libwww/i', '/httpie/i',
      '/postman/i', '/insomnia/i', '/scrapy/i', '/requests/i',
      '/petalbot/i'
    ];
    
    foreach ($botPatterns as $pattern) {
      if (preg_match($pattern, $userAgent)) {
        return true;
      }
    }
    
    // Check for suspiciously short user agents
    if (strlen($userAgent) < 10) {
      return true;
    }
    
    return false;
  }
  
  /**
   * Check rate limiting
   */
  private function checkRateLimit($ip)
  {
    $maxRequests = $this->config['security']['max_requests_per_minute'] ?? 30;
    $file = $this->dataDir . '/rate_' . md5($ip) . '.json';
    $now = time();
    $windowStart = $now - 60; // 1 minute window
    
    if (file_exists($file)) {
      $data = json_decode((string) file_get_contents($file), true);
      if (!is_array($data)) {
        $data = [];
      }
      // Clean old entries
      $data = array_filter($data, function($timestamp) use ($windowStart) {
        return $timestamp > $windowStart;
      });
    } else {
      $data = [];
    }
    
    if (count($data) >= $maxRequests) {
      return false;
    }
    
    $data[] = $now;
    file_put_contents($file, json_encode($data));
    return true;
  }
  
  /**
   * Check for suspicious request patterns
   */
  private function isSuspiciousRequest()
  {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    
    // Check for directory traversal attempts
    if (strpos($requestUri, '..') !== false || strpos($requestUri, '//') !== false) {
      return true;
    }
    
    // Check for suspicious query parameters
    $suspiciousParams = ['cmd', 'exec', 'system', 'eval', 'shell', 'passwd', 'shadow'];
    foreach ($suspiciousParams as $param) {
      if (isset($_GET[$param]) || isset($_POST[$param])) {
        return true;
      }
    }
    
    // Check for suspicious file extensions in URL
    $suspiciousExtensions = ['.php', '.asp', '.jsp', '.py', '.sh', '.exe'];
    foreach ($suspiciousExtensions as $ext) {
      if (strpos($requestUri, $ext) !== false) {
        return true;
      }
    }
    
    return false;
  }
  
  /**
   * Check if IP is currently blocked
   */
  private function isBlocked($ip)
  {
    $file = $this->dataDir . '/blocked_' . md5($ip) . '.json';
    
    if (!file_exists($file)) {
      return false;
    }
    
    $data = json_decode(file_get_contents($file), true);
    $blockedUntil = $data['blocked_until'] ?? 0;
    
    if (time() < $blockedUntil) {
      return true;
    } else {
      // Block expired, remove file
      unlink($file);
      return false;
    }
  }
  
  /**
   * Block an IP address
   */
  private function blockIP($ip, $seconds = 300)
  {
    $file = $this->dataDir . '/blocked_' . md5($ip) . '.json';
    $data = [
      'ip' => $ip,
      'blocked_at' => time(),
      'blocked_until' => time() + $seconds,
      'reason' => 'Rate limit exceeded'
    ];
    
    file_put_contents($file, json_encode($data));
  }
  
  /**
   * Log suspicious activity
   */
  private function logSuspiciousActivity($ip, $type, $userAgent)
  {
    if (!($this->config['security']['log_suspicious_activity'] ?? true)) {
      return;
    }

    $logEntry = [
      'timestamp' => date('Y-m-d H:i:s'),
      'ip' => $ip,
      'type' => $type,
      'user_agent' => $userAgent,
      'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
      'referer' => $_SERVER['HTTP_REFERER'] ?? ''
    ];
    
    $logFile = $this->dataDir . '/security.log';

    // Rotate at max_log_bytes: keep one previous file, so disk use stays under 2x the cap
    $maxBytes = (int) ($this->config['security']['max_log_bytes'] ?? 1048576);
    clearstatcache(true, $logFile);
    if ($maxBytes > 0 && file_exists($logFile) && filesize($logFile) >= $maxBytes) {
      @rename($logFile, $logFile . '.1');
    }

    file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
  }

  /**
   * Whether this IP has hit the admin login failure limit.
   */
  public function isAdminLockedOut()
  {
    return count($this->recentAdminFailures()) >= self::ADMIN_MAX_FAILURES;
  }

  /**
   * Record a failed admin login for this IP and log it.
   */
  public function recordAdminFailure()
  {
    $ip = $this->getClientIP();
    $failures = $this->recentAdminFailures();
    $failures[] = time();
    file_put_contents($this->adminFailureFile($ip), json_encode(array_values($failures)), LOCK_EX);
    $this->logSuspiciousActivity($ip, 'ADMIN_LOGIN_FAILED', $_SERVER['HTTP_USER_AGENT'] ?? '');
  }

  public function clearAdminFailures()
  {
    @unlink($this->adminFailureFile($this->getClientIP()));
  }

  private function recentAdminFailures()
  {
    $file = $this->adminFailureFile($this->getClientIP());
    if (!file_exists($file)) {
      return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    $cutoff = time() - self::ADMIN_LOCKOUT_WINDOW;
    return array_filter(is_array($data) ? $data : [], function ($t) use ($cutoff) {
      return $t > $cutoff;
    });
  }

  private function adminFailureFile($ip)
  {
    return $this->dataDir . '/admin_fail_' . md5($ip) . '.json';
  }
  
  /**
   * Deny access and exit
   */
  private function denyAccess($message)
  {
    http_response_code(403);
    if ($this->denyAsJson) {
      if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
      }
      $jsonFlags = JSON_UNESCAPED_SLASHES;
      if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
      }
      echo json_encode([
        'success' => false,
        'error' => $message
      ], $jsonFlags);
      exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Access Denied</title>
      <style>
        body { font-family: Arial, sans-serif; margin: 40px; text-align: center; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; border-radius: 4px; max-width: 500px; margin: 0 auto; }
        h1 { color: #721c24; }
      </style>
    </head>
    <body>
      <div class="error">
        <h1>Access Denied</h1>
        <p><?= htmlspecialchars($message) ?></p>
        <p>If you believe this is an error, please contact the administrator.</p>
      </div>
    </body>
    </html>
    <?php
    exit;
  }
  
  /**
   * Get security statistics
   */
  public function getStats()
  {
    $logFile = $this->dataDir . '/security.log';
    if (!file_exists($logFile)) {
      return ['total_incidents' => 0, 'recent_incidents' => []];
    }
    
    $lines = file($logFile, FILE_IGNORE_NEW_LINES);
    $incidents = array_map('json_decode', $lines);
    $incidents = array_filter($incidents); // Remove empty lines
    
    $recent = array_slice($incidents, -10); // Last 10 incidents
    
    return [
      'total_incidents' => count($incidents),
      'recent_incidents' => $recent
    ];
  }
}
