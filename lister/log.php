<?php
/**
 * Security Log - read-only viewer for lister/data/security.log (no settings here)
 * Access: yourdomain.com/lister/log.php
 *
 * Off by default (returns 404). To turn it on:
 *   1. Set "log_viewer_enabled": true in the "security" block of lister/config/default.json
 *   2. Create lister/config/log_viewer.json containing {"password_hash": "..."}, where the hash is
 *      the output of: php -r 'echo password_hash("your password", PASSWORD_DEFAULT), "\n";'
 * log_viewer.json is gitignored and blocked from the web. Failed logins are limited per IP.
 */

header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

/** Sessions idle longer than this are logged out */
const SESSION_IDLE_TIMEOUT = 1800;

function plainResponse($status, $message)
{
  http_response_code($status);
  header('Content-Type: text/plain; charset=utf-8');
  echo $message;
  exit;
}

require_once __DIR__ . '/includes/ConfigLoader.php';
try {
  $config = ConfigLoader::loadDefaultJson();
} catch (Exception $e) {
  plainResponse(500, 'Configuration error.');
}

// Disabled viewer looks like no viewer
if (!($config['security']['log_viewer_enabled'] ?? false)) {
  plainResponse(404, 'Not found');
}

$passwordFile = __DIR__ . '/config/log_viewer.json';
$passwordConfig = is_readable($passwordFile) ? json_decode((string) file_get_contents($passwordFile), true) : null;
$passwordHash = is_array($passwordConfig) ? (string) ($passwordConfig['password_hash'] ?? '') : '';
if ($passwordHash === '') {
  plainResponse(500, 'The log viewer is enabled but lister/config/log_viewer.json has no password_hash. See the comment at the top of lister/log.php.');
}

require_once __DIR__ . '/includes/Security.php';
$security = new Security($config);

$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
  || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('lister_log');
session_set_cookie_params([
  'lifetime' => 0,
  'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/',
  'secure' => $secureCookie,
  'httponly' => true,
  'samesite' => 'Strict',
]);
session_start();

$selfUrl = $_SERVER['SCRIPT_NAME'];
$loggedIn = !empty($_SESSION['logged_in']);

if ($loggedIn && time() - ($_SESSION['last_seen'] ?? 0) > SESSION_IDLE_TIMEOUT) {
  $_SESSION = [];
  session_destroy();
  $loggedIn = false;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'logout' && $loggedIn) {
    if (hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
      $_SESSION = [];
      session_destroy();
    }
    header('Location: ' . $selfUrl);
    exit;
  }

  if ($action === 'login') {
    if ($security->isLoginLockedOut()) {
      http_response_code(429);
      $loginError = 'Too many failed attempts. Try again later.';
    } elseif (password_verify((string) ($_POST['password'] ?? ''), $passwordHash)) {
      session_regenerate_id(true);
      $_SESSION['logged_in'] = true;
      $_SESSION['last_seen'] = time();
      $_SESSION['csrf'] = bin2hex(random_bytes(16));
      $security->clearLoginFailures();
      header('Location: ' . $selfUrl);
      exit;
    } else {
      $security->recordLoginFailure();
      $loginError = 'Incorrect password.';
    }
  }
}

if (!$loggedIn) {
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Security Log - Log in</title>
    <style>
      body { font-family: Arial, sans-serif; margin: 40px; max-width: 400px; }
      .form { background: #f8f9fa; padding: 20px; border-radius: 4px; }
      .error { color: #721c24; }
      input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; box-sizing: border-box; }
      button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
    </style>
  </head>
  <body>
    <div class="form">
      <h2>Security Log</h2>
      <?php if ($loginError !== ''): ?>
        <p class="error" role="alert"><?= htmlspecialchars($loginError) ?></p>
      <?php endif; ?>
      <form method="post" action="<?= htmlspecialchars($selfUrl) ?>">
        <input type="hidden" name="action" value="login">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <button type="submit">Log in</button>
      </form>
    </div>
  </body>
  </html>
  <?php
  exit;
}

$_SESSION['last_seen'] = time();
$stats = $security->getStats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Security Log - Lister</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .stats { background: #e9ecef; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
    .incident { background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 5px 0; border-radius: 4px; }
    .blocked { background: #f8d7da; border: 1px solid #f5c6cb; }
    .bot { background: #d1ecf1; border: 1px solid #bee5eb; }
    .rate-limit { background: #fff3cd; border: 1px solid #ffeaa7; }
    .suspicious { background: #f8d7da; border: 1px solid #f5c6cb; }
    pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
    .toolbar { float: right; display: flex; gap: 12px; align-items: center; }
    .toolbar form { margin: 0; }
  </style>
</head>
<body>
  <div class="toolbar">
    <a href="<?= htmlspecialchars($selfUrl) ?>">Refresh</a>
    <form method="post" action="<?= htmlspecialchars($selfUrl) ?>">
      <input type="hidden" name="action" value="logout">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
      <button type="submit">Log out</button>
    </form>
  </div>
  <h1>Security Log</h1>

  <div class="stats">
    <h3>Statistics</h3>
    <p><strong>Total Security Incidents:</strong> <?= (int) $stats['total_incidents'] ?></p>
    <p><strong>Recent Incidents:</strong> <?= count($stats['recent_incidents']) ?></p>
  </div>

  <h3>Recent Security Incidents</h3>
  <?php if (empty($stats['recent_incidents'])): ?>
    <p>No recent security incidents.</p>
  <?php else: ?>
    <?php foreach (array_reverse($stats['recent_incidents']) as $incident): ?>
      <div class="incident <?= htmlspecialchars(strtolower(str_replace('_', '-', $incident->type ?? ''))) ?>">
        <strong><?= htmlspecialchars($incident->type ?? '') ?></strong><br>
        <strong>IP:</strong> <?= htmlspecialchars($incident->ip ?? '') ?><br>
        <strong>Time:</strong> <?= htmlspecialchars($incident->timestamp ?? '') ?><br>
        <strong>User Agent:</strong> <?= htmlspecialchars($incident->user_agent ?? '') ?><br>
        <strong>Request:</strong> <?= htmlspecialchars($incident->request_uri ?? '') ?><br>
        <?php if (!empty($incident->referer)): ?>
          <strong>Referer:</strong> <?= htmlspecialchars($incident->referer) ?><br>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <h3>Current security settings (edit in lister/config/default.json)</h3>
  <pre><?= htmlspecialchars(json_encode($config['security'], JSON_PRETTY_PRINT)) ?></pre>

  <p><a href="..">← Back to Directory Listing</a></p>
</body>
</html>
