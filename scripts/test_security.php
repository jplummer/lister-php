<?php
/**
 * Security tests for Lister. Runs against a temp data dir and temp web root, so it
 * never touches lister/data or real files.
 *
 * Usage: php scripts/test_security.php
 * Exit code 0 when everything passes, 1 otherwise.
 */

require_once __DIR__ . '/../lister/includes/ConfigLoader.php';
require_once __DIR__ . '/../lister/includes/Security.php';
require_once __DIR__ . '/../lister/includes/DirectoryLister.php';

$baseConfig = ConfigLoader::loadDefaultJson();
$failures = 0;

function check($label, $ok)
{
  global $failures;
  echo ($ok ? "  ok    " : "  FAIL  ") . $label . "\n";
  if (!$ok) {
    $failures++;
  }
}

function tempDir($prefix)
{
  $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(4));
  mkdir($dir, 0755, true);
  return realpath($dir);
}

function removeTree($dir)
{
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($it as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
  }
  rmdir($dir);
}

function request($ip, $userAgent, $uri = '/')
{
  $_SERVER['REMOTE_ADDR'] = $ip;
  $_SERVER['HTTP_USER_AGENT'] = $userAgent;
  $_SERVER['REQUEST_URI'] = $uri;
  unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
}

function logTypes($dataDir)
{
  $file = $dataDir . '/security.log';
  if (!file_exists($file)) {
    return [];
  }
  return array_map(function ($line) {
    return json_decode($line, true)['type'] ?? '';
  }, file($file, FILE_IGNORE_NEW_LINES));
}

$browser = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36';

// ---------------------------------------------------------------------------
echo "Request checks\n";
$dataDir = tempDir('lister-sec-');
$config = $baseConfig;
$config['security']['max_requests_per_minute'] = 3;
$config['security']['block_duration_seconds'] = 120;
$security = new Security($config, false, $dataDir);

request('198.51.100.1', $browser);
check('normal browser request is allowed', $security->evaluateRequest() === null);

request('198.51.100.2', 'curl/8.4.0');
check('curl user agent is denied as a bot', $security->evaluateRequest() === 'Bot detected');

request('198.51.100.3', '');
check('empty user agent is denied as a bot', $security->evaluateRequest() === 'Bot detected');

request('198.51.100.4', $browser, '/../../etc/passwd');
check('traversal-looking URL is allowed but logged', $security->evaluateRequest() === null
  && in_array('SUSPICIOUS_REQUEST', logTypes($dataDir), true));

request('198.51.100.5', $browser);
$results = [];
for ($i = 0; $i < 4; $i++) {
  $results[] = $security->evaluateRequest();
}
check('requests under the limit are allowed', $results[0] === null && $results[2] === null);
check('request over the limit is denied', $results[3] === 'Rate limit exceeded');
check('IP stays blocked after exceeding the limit', $security->evaluateRequest() === 'Access denied');
$block = json_decode(file_get_contents($dataDir . '/blocked_' . md5('198.51.100.5') . '.json'), true);
check('block_duration_seconds is honored', $block['blocked_until'] - $block['blocked_at'] === 120);

check('data dir gets a deny-all .htaccess', strpos((string) @file_get_contents($dataDir . '/.htaccess'), 'Require all denied') !== false);

file_put_contents($dataDir . '/rate_' . md5('198.51.100.9') . '.json', 'not json');
request('198.51.100.9', $browser);
check('corrupt rate file does not crash the check', $security->evaluateRequest() === null);
removeTree($dataDir);

// ---------------------------------------------------------------------------
echo "\nLogging and cleanup\n";
$dataDir = tempDir('lister-sec-');
$config = $baseConfig;
$config['security']['max_log_bytes'] = 500;
$security = new Security($config, false, $dataDir);
request('198.51.100.20', 'curl/8.4.0');
for ($i = 0; $i < 10; $i++) {
  $security->evaluateRequest();
}
check('log rotates to security.log.1 at max_log_bytes', file_exists($dataDir . '/security.log.1'));
check('current log stays near the cap', filesize($dataDir . '/security.log') < 1000);

$config['security']['log_suspicious_activity'] = false;
$quiet = new Security($config, false, $dataDir);
$before = filesize($dataDir . '/security.log');
clearstatcache();
request('198.51.100.21', 'curl/8.4.0');
$quiet->evaluateRequest();
clearstatcache();
check('log_suspicious_activity false stops logging', filesize($dataDir . '/security.log') === $before);

$old = time() - 3600;
foreach (['rate_old', 'blocked_old', 'login_fail_old'] as $name) {
  touch($dataDir . '/' . $name . '.json', $old);
}
touch($dataDir . '/rate_fresh.json');
@unlink($dataDir . '/.last_prune');
new Security($baseConfig, false, $dataDir);
check('expired rate/blocked/login_fail files are pruned', !file_exists($dataDir . '/rate_old.json')
  && !file_exists($dataDir . '/blocked_old.json') && !file_exists($dataDir . '/login_fail_old.json'));
check('fresh rate file is kept', file_exists($dataDir . '/rate_fresh.json'));
removeTree($dataDir);

// ---------------------------------------------------------------------------
echo "\nLog viewer login lockout\n";
$dataDir = tempDir('lister-sec-');
$security = new Security($baseConfig, false, $dataDir);
request('198.51.100.30', $browser);
for ($i = 0; $i < Security::LOGIN_MAX_FAILURES - 1; $i++) {
  $security->recordLoginFailure();
}
check('not locked out below the failure limit', !$security->isLoginLockedOut());
$security->recordLoginFailure();
check('locked out at the failure limit', $security->isLoginLockedOut());
request('198.51.100.31', $browser);
check('lockout is per IP', !$security->isLoginLockedOut());
request('198.51.100.30', $browser);
$security->clearLoginFailures();
check('successful login clears failures', !$security->isLoginLockedOut());
check('failed logins are logged', in_array('LOG_VIEWER_LOGIN_FAILED', logTypes($dataDir), true));
removeTree($dataDir);

// ---------------------------------------------------------------------------
echo "\nClient IP and trusted proxies\n";
$dataDir = tempDir('lister-sec-');
$security = new Security($baseConfig, false, $dataDir);
request('198.51.100.40', $browser);
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.7';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.8';
check('forwarded headers from a direct visitor are ignored', $security->getClientIP() === '198.51.100.40');

request('162.158.10.20', $browser);
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.7';
check('CF-Connecting-IP is used when the request comes from Cloudflare', $security->getClientIP() === '203.0.113.7');

request('2606:4700::1', $browser);
$_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8::5';
check('Cloudflare IPv6 edge is trusted too', $security->getClientIP() === '2001:db8::5');

$config = $baseConfig;
$config['security']['trusted_proxies'] = ['10.0.0.0/8'];
$proxied = new Security($config, false, $dataDir);
request('10.1.2.3', $browser);
$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 203.0.113.9, 10.0.0.5';
check('X-Forwarded-For uses the rightmost hop outside the trusted proxies', $proxied->getClientIP() === '203.0.113.9');
request('162.158.10.20', $browser);
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.7';
check('Cloudflare is not trusted once trusted_proxies leaves it out', $proxied->getClientIP() === '162.158.10.20');

$config['security']['trusted_proxies'] = [];
$noProxy = new Security($config, false, $dataDir);
request('162.158.10.20', $browser);
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.7';
check('empty trusted_proxies always uses REMOTE_ADDR', $noProxy->getClientIP() === '162.158.10.20');
removeTree($dataDir);

// ---------------------------------------------------------------------------
echo "\nPath visibility\n";
$root = tempDir('lister-root-');
foreach (['docs', 'lister/config', 'lister/data', '.git', 'docs/.private'] as $d) {
  mkdir($root . '/' . $d, 0755, true);
}
foreach (['docs/notes.md', 'lister/config/default.json', 'lister/data/security.log', '.htaccess', '.env', 'docs/.private/x.txt', 'docs/id_rsa'] as $f) {
  file_put_contents($root . '/' . $f, 'x');
}
$_SERVER['REQUEST_URI'] = '/';
$lister = new DirectoryLister($baseConfig, $root);
$visible = function ($rel) use ($lister, $root) {
  return $lister->isPathVisible(realpath($root . '/' . $rel));
};
check('web root is visible', $lister->isPathVisible($root));
check('ordinary folder and file are visible', $visible('docs') && $visible('docs/notes.md'));
check('lister/ is hidden', !$visible('lister'));
check('files under lister/ are hidden', !$visible('lister/config/default.json') && !$visible('lister/data/security.log'));
check('dotfiles are hidden', !$visible('.htaccess') && !$visible('.env'));
check('files under a dotfolder are hidden', !$visible('docs/.private/x.txt'));
check('sensitive patterns are hidden', !$visible('docs/id_rsa'));
check('paths outside the root are hidden', !$lister->isPathVisible(dirname($root)));

$_SERVER['REQUEST_URI'] = '/lister/config/';
$byUrl = new DirectoryLister($baseConfig, $root);
check('page URL for a hidden folder is a 404', $byUrl->isRequestPathMissing());
$_SERVER['REQUEST_URI'] = '/docs/';
$byUrl = new DirectoryLister($baseConfig, $root);
check('page URL for a visible folder is not a 404', !$byUrl->isRequestPathMissing());
$_SERVER['REQUEST_URI'] = '/.htaccess';
$byUrl = new DirectoryLister($baseConfig, $root);
check('page URL for a hidden file is a 404, not its folder', $byUrl->isRequestPathMissing());
$_SERVER['REQUEST_URI'] = '/docs/notes.md';
$byUrl = new DirectoryLister($baseConfig, $root);
check('page URL for a visible file lists its folder', !$byUrl->isRequestPathMissing());
file_put_contents($root . '/index.php', '<?php');
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$byUrl = new DirectoryLister($baseConfig, $root);
check('page URL for the entry script itself is not a 404', !$byUrl->isRequestPathMissing());
removeTree($root);

// ---------------------------------------------------------------------------
echo "\n" . ($failures === 0 ? "All security tests passed.\n" : "$failures security test(s) failed.\n");
exit($failures === 0 ? 0 : 1);
