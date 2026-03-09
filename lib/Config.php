<?php

// lib/Config.php — FULL implementation with dynamic theme discovery

namespace App;

final class Config
{
    private static $cache;
    private static $path;    // <project-root>/config
    private static $schema;

    public static function init(string $rootDir): void
    {
        self::$path = rtrim($rootDir, '/').'/config';
        if (!is_dir(self::$path)) {
            throw new \RuntimeException("Missing config directory at: " . self::$path);
        }
        // Load schema AFTER class is loaded so schema can call \App\Config::listThemes()
        self::$schema = require self::$path . '/schema.php';
    }

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $defaults = require self::$path . '/defaults.php';
        $local = self::readLocal();
        $merged = self::merge($defaults, $local);
        $merged = self::applyEnvOverrides($merged);
        // ensure secrets
        if (empty($merged['security']['csrf_secret'])) {
            $merged['security']['csrf_secret'] = self::ensureSecret('csrf_secret');
        }
        return self::$cache = $merged;
    }

    public static function get(string $path, $default = null)
    {
        $cfg = self::all();
        foreach (explode('.', $path) as $seg) {
            if (!is_array($cfg) || !array_key_exists($seg, $cfg)) {
                return $default;
            }
            $cfg = $cfg[$seg];
        }
        return $cfg;
    }

    public static function setMany(array $patch): array
    {
        $current = self::all();
        $schema = self::$schema;
        $normalized = self::validateTree($schema, $patch, $current);
        $next = self::merge($current, $normalized);
        $next = self::pruneUnchangedEmpty($next, $normalized);
        $defaults = require self::$path . '/defaults.php';
        $overrides = self::diff($next, $defaults);
        self::writeLocal($overrides);
        self::syncSecurityConfig($next);
        self::$cache = $next;
        return $next;
    }

    public static function delete(string $path): bool
    {
        $current = self::all();
        $segments = array_values(array_filter(explode('.', trim($path))));
        if (!$segments) {
            return false;
        }
        if (!self::removePath($current, $segments)) {
            return false;
        }
        $defaults = require self::$path . '/defaults.php';
        $overrides = self::diff($current, $defaults);
        self::writeLocal($overrides);
        self::syncSecurityConfig($current);
        self::$cache = $current;
        return true;
    }

    private static function readLocal(): array
    {
        $f = self::$path . '/local.json';
        if (!file_exists($f)) {
            $local = [];
        } else {
            $json = file_get_contents($f);
            $data = json_decode($json, true);
            $local = is_array($data) ? $data : [];
        }
        return self::mergeLegacySecurity($local);
    }

    private static function writeLocal(array $data): void
    {
        $f = self::$path . '/local.json';
        $tmp = $f . '.tmp';
        if (!is_dir(dirname($f))) {
            mkdir(dirname($f), 0775, true);
        }
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($tmp, $f);
        // backup disabled: backups are created only via api/config_backup.php button
    }

    private static function ensureSecret(string $key): string
    {
        $curr = self::readLocal();
        if (!isset($curr['security'])) {
            $curr['security'] = [];
        }
        if (empty($curr['security'][$key])) {
            $curr['security'][$key] = bin2hex(random_bytes(32));
            self::writeLocal($curr);
        }
        return $curr['security'][$key];
    }

    private static function merge(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            if (is_array($v) && isset($a[$k]) && is_array($a[$k])) {
                $a[$k] = self::merge($a[$k], $v);
            } else {
                $a[$k] = $v;
            }
        }
        return $a;
    }

    // Remove empty arrays that were NOT modified in the incoming patch.
    private static function pruneUnchangedEmpty(array $node, array $patch): array
    {
        foreach ($node as $k => $v) {
            $p = isset($patch[$k]) ? $patch[$k] : null;
            if (is_array($v)) {
                if ($v === array() && ($p === null)) {
                    unset($node[$k]);
                    continue;
                }
                if (is_array($p)) {
                    $node[$k] = self::pruneUnchangedEmpty($v, $p);
                }
            }
        }
        return $node;
    }

    private static function diff(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $bv = $b[$k] ?? null;
            if (is_array($v) && is_array($bv)) {
                $d = self::diff($v, $bv);
                if ($d !== []) {
                    $out[$k] = $d;
                }
            } elseif ($v !== $bv) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function applyEnvOverrides(array $cfg): array
    {
        foreach ($_ENV as $k => $v) {
            if (strpos($k, 'APP__') !== 0) {
                continue;
            }
            $path = strtolower(str_replace('__', '.', substr($k, 5)));
            $segments = explode('.', $path);
            $node = & $cfg;
            foreach ($segments as $i => $seg) {
                if ($i === count($segments) - 1) {
                    $node[$seg] = self::castEnv($v);
                } else {
                    if (!isset($node[$seg]) || !is_array($node[$seg])) {
                        $node[$seg] = [];
                    }
                    $node = & $node[$seg];
                }
            }
        }
        return $cfg;
    }

    private static function castEnv($v)
    {
        $s = trim((string)$v);
        if ($s === 'true') {
            return true;
        }
        if ($s === 'false') {
            return false;
        }
        if (is_numeric($s)) {
            return $s + 0;
        }
        return $s;
    }

    private static function validateTree(array $schema, array $patch, array $current)
    {
        $out = array();
        foreach ($schema as $key => $rule) {
            if ($key === '_label') {
                continue;
            }

            // Leaf node
            if (is_array($rule) && isset($rule['type'])) {
                if (!array_key_exists($key, $patch)) {
                    continue;
                } // unchanged; omit
                $old = isset($current[$key]) ? $current[$key] : null;
                $out[$key] = self::validateLeaf($rule, $patch[$key], $old);
                continue;
            }

            // Branch object: only include if present in PATCH and not empty
            if (is_array($rule)) {
                if (!isset($patch[$key]) || !is_array($patch[$key]) || $patch[$key] === array()) {
                    continue; // do not create empty child branches
                }
                $childPatch = $patch[$key];
                $curChild = isset($current[$key]) && is_array($current[$key]) ? $current[$key] : array();
                $child = self::validateTree($rule, $childPatch, $curChild);
                if ($child !== array()) {
                    $out[$key] = $child;
                }
            }
        }
        return $out;
    }

    private static function validateLeaf(array $rule, $val, $old)
    {
        $type = $rule['type'];
        $label = $rule['label'] ?? 'Value';
        $req = $rule['required'] ?? false;

        // Guardrail: for hidden fields, prevent accidental blanking.
        // If UI sends empty string/null but an old value exists, keep the old value.
        if (!empty($rule['hidden']) && ($val === '' || $val === null) && $old !== null) {
            return $old;
        }
        if ($val === '' || $val === null) {
            if ($req) {
                throw new \InvalidArgumentException("$label is required");
            }
            return ($type === 'int') ? 0 : (($type === 'bool') ? false : ($type === 'list' ? [] : $val));
        }

        switch ($type) {
            case 'text':
            case 'string':
                $s = (string)$val;
                $min = $rule['min'] ?? 0;
                $max = $rule['max'] ?? 1000;
                if (mb_strlen($s) < $min || mb_strlen($s) > $max) {
                    throw new \InvalidArgumentException("$label length must be $min..$max");
                }
                return $s;
            case 'secret':
                return (string)$val;
            case 'int':
                $n = (int)$val;
                if (isset($rule['min']) && $n < $rule['min']) {
                    throw new \InvalidArgumentException("$label must be >= {$rule['min']}");
                }
                if (isset($rule['max']) && $n > $rule['max']) {
                    throw new \InvalidArgumentException("$label must be <= {$rule['max']}");
                }
                return $n;
            case 'bool':
                return (bool)$val;
            case 'url':
                if (!filter_var($val, FILTER_VALIDATE_URL)) {
                    throw new \InvalidArgumentException("$label must be a valid URL");
                }
                return (string)$val;
            case 'email':
                if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException("$label must be a valid email");
                }
                return (string)$val;
            case 'list':
                $item = $rule['item'] ?? 'string';
                $arr = is_array($val) ? $val : preg_split('/\s*,\s*/', (string)$val, -1, PREG_SPLIT_NO_EMPTY);
                $out = [];
                foreach ($arr as $x) {
                    $out[] = self::validateLeaf(['type' => $item,'label' => $label.' item'], $x, null);
                }
                return $out;
            case 'timezone':
                try {
                    new \DateTimeZone((string)$val);
                } catch (\Exception $e) {
                    throw new \InvalidArgumentException("$label must be a valid timezone");
                }
                return (string)$val;
            case 'enum':
                $vals = $rule['values'] ?? [];
                if (!in_array($val, $vals, true)) {
                    throw new \InvalidArgumentException("$label must be one of: ".implode(', ', $vals));
                }
                return $val;
            default:
                throw new \InvalidArgumentException("Unsupported type $type for $label");
        }
    }

    private static function mergeLegacySecurity(array $local): array
    {
        $secFile = dirname(self::$path) . '/data/security_config.json';
        if (!is_file($secFile)) {
            return $local;
        }
        $raw = @file_get_contents($secFile);
        if ($raw === false) {
            return $local;
        }
        $legacy = json_decode($raw, true);
        if (!is_array($legacy) || !$legacy) {
            return $local;
        }

        $map = [
          'mail_transport' => ['mail','mail_transport'],
          'mail_from'      => ['mail','mail_from'],
          'mail_replyto'   => ['mail','mail_replyto'],
          'sendmail_path'  => ['mail','sendmail_path'],
          'smtp_host'      => ['mail','smtp_host'],
          'smtp_port'      => ['mail','smtp_port'],
          'smtp_secure'    => ['mail','smtp_secure'],
          'smtp_user'      => ['mail','smtp_user'],
          'smtp_pass'      => ['mail','smtp_pass'],
          'alert_emails'   => ['mail','sec_email'],
          'sec_email'      => ['mail','sec_email'],
          'admin_emails'   => ['security','admin_emails'],
          'csrf_secret'    => ['security','csrf_secret'],
          'cron_token'     => ['alerts','cron_token'],
        ];

        foreach ($map as $legacyKey => $path) {
            if (!array_key_exists($legacyKey, $legacy)) {
                continue;
            }
            if (self::pathHasValue($local, $path)) {
                continue;
            }
            $value = $legacy[$legacyKey];
            $setPath = $path;

            if (in_array($legacyKey, ['alert_emails','sec_email'], true)) {
                $value = self::splitEmails($value);
            }
            if ($legacyKey === 'admin_emails' && !is_array($value)) {
                $value = is_string($value) ? self::splitEmails($value) : [];
            }
            if ($legacyKey === 'smtp_port') {
                $value = is_numeric($value) ? (int)$value : null;
            }
            if ($legacyKey === 'cron_token' && !is_string($value)) {
                $value = null;
            }
            if ($value === null) {
                continue;
            }
            self::setPath($local, $setPath, $value);
        }
        return $local;
    }

    private static function syncSecurityConfig(array $cfg): void
    {
        try {
            $root = dirname(self::$path);
            $secFile = $root . '/data/security_config.json';
            if (!is_dir(dirname($secFile))) {
                @mkdir(dirname($secFile), 0775, true);
            }
            $current = [];
            if (is_file($secFile)) {
                $raw = @file_get_contents($secFile);
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $current = $decoded;
                }
            }
            $apply = $current;

            $alertString = self::emailsToString(self::getValue($cfg, ['mail','sec_email']));
            $fallbackAlert = self::getValue($cfg, ['alerts','email']);
            $cronToken = self::pickFirst([
              self::getValue($cfg, ['alerts','cron_token']),
              self::getValue($cfg, ['security','cron_token']),
              self::getValue($cfg, ['site','cron_token']),
              self::getValue($cfg, ['cron','token']),
              self::getValue($cfg, ['api','cron_token']),
              self::getValue($cfg, ['history','token']),
            ]);
            $map = [
              'mail_transport' => self::getValue($cfg, ['mail','mail_transport']),
              'mail_from'      => self::getValue($cfg, ['mail','mail_from']),
              'mail_replyto'   => self::getValue($cfg, ['mail','mail_replyto']),
              'sendmail_path'  => self::getValue($cfg, ['mail','sendmail_path']),
              'smtp_host'      => self::getValue($cfg, ['mail','smtp_host']),
              'smtp_port'      => self::getValue($cfg, ['mail','smtp_port']),
              'smtp_secure'    => self::getValue($cfg, ['mail','smtp_secure']),
              'smtp_user'      => self::getValue($cfg, ['mail','smtp_user']),
              'smtp_pass'      => self::getValue($cfg, ['mail','smtp_pass']),
              'alert_emails'   => $alertString ?: (is_string($fallbackAlert) ? $fallbackAlert : ''),
              'sec_email'      => $alertString ?: (is_string($fallbackAlert) ? $fallbackAlert : ''),
              'admin_emails'   => self::normalizeArray(self::getValue($cfg, ['security','admin_emails'])),
              'csrf_secret'    => self::getValue($cfg, ['security','csrf_secret']),
              'cron_token'     => $cronToken,
            ];

            foreach ($map as $key => $value) {
                if ($key === 'smtp_pass' && ($value === null || $value === '')) {
                    if (isset($apply[$key]) && $apply[$key] !== '') {
                        continue;
                    }
                }
                if ($value === null) {
                    unset($apply[$key]);
                    continue;
                }
                if ($key === 'smtp_port') {
                    $value = (int)$value;
                }
                if ($key === 'mail_transport' && $value === '') {
                    $value = 'phpmail';
                }
                $apply[$key] = $value;
            }

            $tmp = $secFile . '.tmp';
            if (@file_put_contents($tmp, json_encode($apply, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
                return;
            }
            @chmod($tmp, 0640);
            @rename($tmp, $secFile);
        } catch (\Throwable $e) {
            // Non-fatal: legacy security config is best-effort
        }
    }

    private static function pathHasValue(array $tree, array $segments): bool
    {
        return self::getValue($tree, $segments) !== null;
    }

    private static function getValue($tree, array $segments)
    {
        $node = $tree;
        foreach ($segments as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                return null;
            }
            $node = $node[$seg];
        }
        return $node;
    }

    private static function setPath(array &$tree, array $segments, $value): void
    {
        $node = & $tree;
        foreach ($segments as $i => $seg) {
            if ($i === count($segments) - 1) {
                $node[$seg] = $value;
            } else {
                if (!isset($node[$seg]) || !is_array($node[$seg])) {
                    $node[$seg] = [];
                }
                $node = & $node[$seg];
            }
        }
    }

    private static function splitEmails($value): array
    {
        if (is_array($value)) {
            $list = $value;
        } elseif (is_string($value)) {
            $parts = explode(',', $value);
            $list = [];
            foreach ($parts as $part) {
                $t = trim($part);
                if ($t !== '') {
                    $list[] = $t;
                }
            }
        } else {
            $list = [];
        }
        return $list;
    }

    private static function emailsToString($value): string
    {
        if (!is_array($value)) {
            return is_string($value) ? trim($value) : '';
        }
        $clean = [];
        foreach ($value as $item) {
            $s = trim((string)$item);
            if ($s !== '') {
                $clean[] = $s;
            }
        }
        return implode(', ', $clean);
    }

    private static function normalizeArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            return self::splitEmails($value);
        }
        return [];
    }

    private static function pickFirst(array $candidates)
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }
        return null;
    }

    private static function removePath(array &$tree, array $segments): bool
    {
        $key = array_shift($segments);
        if ($key === null || !array_key_exists($key, $tree)) {
            return false;
        }
        if ($segments === []) {
            unset($tree[$key]);
            return true;
        }
        if (!is_array($tree[$key])) {
            return false;
        }
        $changed = self::removePath($tree[$key], $segments);
        if ($changed && $tree[$key] === []) {
            unset($tree[$key]);
        }
        return $changed;
    }

    /**
     * Discover available themes by scanning /assets/css/themes.
     * Supports both file themes (themes/foo.css) and folder themes (themes/foo/theme.css).
     * Filters out non-themes like core.css and *.mobile.css.
     */
    public static function listThemes(): array
    {
        $root = dirname(self::$path); // project root
        $dir = $root . '/assets/css/themes';
        $out = [];

        if (is_dir($dir)) {
            foreach ((glob($dir.'/*.css') ?: []) as $file) {
                $base = basename($file);
                if ($base === 'core.css') {
                    continue;
                }
                if (substr($base, -11) === '.mobile.css') {
                    continue;
                }
                $name = preg_replace('/\.css$/', '', $base);
                $out[$name] = true;
            }
            foreach ((glob($dir.'/*/theme.css') ?: []) as $file) {
                $name = basename(dirname($file));
                $out[$name] = true;
            }
        }

        $names = array_keys($out);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        if (!in_array('default', $names, true) && is_file($dir.'/default.css')) {
            array_unshift($names, 'default');
        }
        return $names;
    }
}
