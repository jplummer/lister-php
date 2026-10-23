<?php
/**
 * Load and validate lister/config/default.json for App, API, and the log viewer.
 */

class ConfigLoader
{
  /**
   * Load default.json from lister/config/ (or $configPath when provided).
   *
   * @param string|null $configPath Absolute path to JSON file; null = default beside this include
   * @return array
   * @throws Exception When file is missing, unreadable, or not a JSON object
   */
  public static function loadDefaultJson($configPath = null)
  {
    if ($configPath === null) {
      $configPath = __DIR__ . '/../config/default.json';
    }

    if (!file_exists($configPath)) {
      throw new Exception('Configuration file not found: lister/config/default.json. Make sure you uploaded the entire lister/ folder.');
    }

    if (!is_readable($configPath)) {
      $perms = substr(sprintf('%o', fileperms($configPath)), -4);
      throw new Exception('Cannot read configuration file: lister/config/default.json. Current permissions: ' . $perms . '. Run: chmod 644 lister/config/default.json');
    }

    $configContent = file_get_contents($configPath);
    if ($configContent === false) {
      throw new Exception('Failed to read configuration file: lister/config/default.json. Check file permissions.');
    }

    $decoded = json_decode($configContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new Exception('Invalid configuration file: lister/config/default.json. JSON error: ' . json_last_error_msg() . '. Check the file for syntax errors.');
    }

    if (!is_array($decoded)) {
      throw new Exception('Invalid configuration file: lister/config/default.json must contain a JSON object (e.g. { ... }), not a bare string or number.');
    }

    return $decoded;
  }
}
