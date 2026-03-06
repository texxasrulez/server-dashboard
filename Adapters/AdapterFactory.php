<?php

declare(strict_types=1);

require_once __DIR__ . '/GenericLinuxAdapter.php';
require_once __DIR__ . '/HestiaAdapter.php';

final class DashboardAdapterFactory {
  /** @return array<string,string> */
  public static function capabilityHints(): array {
    $out = [];
    $map = [
      'panel' => ['capabilities.panel', 'integrations.panel'],
      'web' => ['capabilities.web'],
      'mta' => ['capabilities.mta'],
      'db' => ['capabilities.db'],
    ];

    foreach ($map as $key => $paths) {
      foreach ($paths as $path) {
        $value = function_exists('cfg_local') ? cfg_local($path, null) : null;
        if (is_string($value) && trim($value) !== '') {
          $out[$key] = strtolower(trim($value));
          break;
        }
      }
    }

    return $out;
  }

  public static function make(): DashboardAdapterInterface {
    $hints = self::capabilityHints();
    $panelHint = strtolower(trim((string)($hints['panel'] ?? '')));

    if ($panelHint === 'hestia') {
      return new HestiaAdapter($hints);
    }

    if (@is_dir('/usr/local/hestia') || @is_file('/usr/local/hestia/bin/v-list-users')) {
      return new HestiaAdapter($hints);
    }

    return new GenericLinuxAdapter($hints);
  }
}
