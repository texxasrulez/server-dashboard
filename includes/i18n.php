<?php
if (!defined('DASH_LOCALE')) {
  $cfgPath = __DIR__ . '/../config/local.json';
  $locale = 'en';
  if (is_file($cfgPath)) {
    $raw = @file_get_contents($cfgPath);
    if ($raw !== false) {
      $j = json_decode($raw, true);
      if (is_array($j)) {
        $locale = $j['i18n']['locale'] ?? ($j['locale'] ?? $locale);
        $locale = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$locale);
        if ($locale === '') $locale = 'en';
      }
    }
  }
  define('DASH_LOCALE', $locale);
}
$GLOBALS['__I18N_MAP'] = $GLOBALS['__I18N_MAP'] ?? null;
function __i18n_load($loc){
  $file = __DIR__ . '/../assets/i18n/' . $loc . '.json';
  $map = [];
  if (is_file($file)) {
    $raw = @file_get_contents($file);
    if ($raw !== false) {
      $j = json_decode($raw, true);
      if (is_array($j)) $map = $j;
    }
  }
  return $map;
}
function __i18n_get($key, $fallback=null){
  $map = $GLOBALS['__I18N_MAP'];
  if ($map === null) { $map = __i18n_load(DASH_LOCALE); $GLOBALS['__I18N_MAP'] = $map; }
  $cur = $map;
  foreach (explode('.', (string)$key) as $k) {
    if (is_array($cur) && array_key_exists($k, $cur)) { $cur = $cur[$k]; } else { $cur = null; break; }
  }
  if (is_string($cur)) return $cur;
  if ($fallback !== null) return $fallback;
  return is_string($key) ? $key : '';
}
function __($key, $fallback=null){ return __i18n_get($key, $fallback); }
function _e($key, $fallback=null){
  echo htmlspecialchars(__i18n_get($key, $fallback), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
