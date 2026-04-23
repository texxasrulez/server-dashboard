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
                if ($locale === '') {
                    $locale = 'en';
                }
            }
        }
    }
    define('DASH_LOCALE', $locale);
}
$GLOBALS['__I18N_MAP'] = $GLOBALS['__I18N_MAP'] ?? null;
function __i18n_merge($base, $override)
{
    if (!is_array($base)) {
        return is_array($override) ? $override : $base;
    }
    if (!is_array($override)) {
        return $base;
    }
    foreach ($override as $k => $v) {
        if (array_key_exists($k, $base) && is_array($base[$k]) && is_array($v)) {
            $base[$k] = __i18n_merge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}
function __i18n_load($loc)
{
    $map = [];
    $chain = ['en'];
    if (is_string($loc) && $loc !== '' && $loc !== 'en') {
        $chain[] = $loc;
    }
    foreach ($chain as $code) {
        $file = __DIR__ . '/../assets/i18n/' . $code . '.json';
        if (!is_file($file)) {
            continue;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            continue;
        }
        $j = json_decode($raw, true);
        if (is_array($j)) {
            if ($code === 'en') {
                $map = $j;
            } else {
                $map = __i18n_merge($map, $j);
            }
        }
    }
    return $map;
}
function __i18n_format($value, $vars = [])
{
    if (!is_string($value) || !is_array($vars) || !$vars) {
        return $value;
    }
    $repl = [];
    foreach ($vars as $k => $v) {
        if (!is_scalar($v) && $v !== null) {
            continue;
        }
        $repl['{' . $k . '}'] = (string)($v ?? '');
    }
    return strtr($value, $repl);
}
function __i18n_get($key, $fallback = null, $vars = [])
{
    $map = $GLOBALS['__I18N_MAP'];
    if ($map === null) {
        $map = __i18n_load(DASH_LOCALE);
        $GLOBALS['__I18N_MAP'] = $map;
    }
    $cur = $map;
    foreach (explode('.', (string)$key) as $k) {
        if (is_array($cur) && array_key_exists($k, $cur)) {
            $cur = $cur[$k];
        } else {
            $cur = null;
            break;
        }
    }
    if (is_string($cur)) {
        return __i18n_format($cur, $vars);
    }
    if ($fallback !== null) {
        return __i18n_format($fallback, $vars);
    }
    return is_string($key) ? $key : '';
}
function __($key, $fallback = null, $vars = [])
{
    return __i18n_get($key, $fallback, $vars);
}
function _e($key, $fallback = null, $vars = [])
{
    echo htmlspecialchars(__i18n_get($key, $fallback, $vars), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
