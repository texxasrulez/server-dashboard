<?php

declare(strict_types=1);

final class SystemctlHelper {
  /**
   * Strictly allowlisted service units. No user-controlled values are accepted.
   */
  private const ALLOWED_UNITS = [
    'nginx' => ['nginx.service', 'nginx'],
    'apache' => ['apache2.service', 'httpd.service', 'apache2', 'httpd'],
    'postfix' => ['postfix.service', 'postfix'],
    'exim' => ['exim4.service', 'exim.service', 'exim4', 'exim'],
    'mariadb' => ['mariadb.service', 'mysql.service', 'mariadb', 'mysql'],
    'postgres' => ['postgresql.service', 'postgresql'],
    'hestia' => ['hestia.service', 'hestia'],
  ];

  private static function systemctlBinary(): ?string {
    static $bin = null;
    if ($bin !== null) return $bin;
    foreach (['/bin/systemctl', '/usr/bin/systemctl'] as $candidate) {
      if (@is_executable($candidate)) {
        $bin = $candidate;
        return $bin;
      }
    }
    $bin = '';
    return null;
  }

  private static function canExec(): bool {
    if (!function_exists('exec')) return false;
    $disabled = (string)@ini_get('disable_functions');
    if ($disabled === '') return true;
    $list = array_map('trim', explode(',', $disabled));
    return !in_array('exec', $list, true);
  }

  /**
   * Returns true/false when determinable, null when systemctl is unavailable.
   */
  public static function isActive(string $unit): ?bool {
    $unit = trim($unit);
    if ($unit === '') return null;

    $allowed = false;
    foreach (self::ALLOWED_UNITS as $units) {
      if (in_array($unit, $units, true)) {
        $allowed = true;
        break;
      }
    }
    if (!$allowed) return null;

    $bin = self::systemctlBinary();
    if (!$bin) return null;
    if (!self::canExec()) return null;

    $cmd = $bin . ' is-active --quiet ' . escapeshellarg($unit) . ' 2>/dev/null';
    $code = 1;
    @exec($cmd, $out, $code);
    return $code === 0;
  }

  public static function anyActive(string $serviceKey): ?bool {
    if (!isset(self::ALLOWED_UNITS[$serviceKey])) return null;
    $sawDeterminate = false;
    foreach (self::ALLOWED_UNITS[$serviceKey] as $unit) {
      $active = self::isActive($unit);
      if ($active === true) return true;
      if ($active === false) $sawDeterminate = true;
    }
    // Return false only when at least one check ran deterministically.
    return $sawDeterminate ? false : null;
  }
}
