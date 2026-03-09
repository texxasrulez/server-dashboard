#!/usr/bin/env php
<?php

/**
 * bin/config-cli.php — headless helper to inspect/update config/local.json.
 *
 * Usage:
 *   php bin/config-cli.php get site.name
 *   php bin/config-cli.php set mail.smtp_host smtp.example.com
 *   php bin/config-cli.php set-json mail.sec_email '["ops@example.com","oncall@example.com"]'
 *   php bin/config-cli.php dump
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');

$root = realpath(__DIR__ . '/..');
chdir($root);

require_once $root . '/includes/init.php';
require_once $root . '/lib/Config.php';
\App\Config::init($root);

function usage()
{
    $text = <<<TXT
Config CLI
----------
  get <path>              Print the value at dot-path (e.g. mail.smtp_host)
  set <path> <value>      Set a scalar value (bool/int/string). Numbers/bools auto-detected.
  set-json <path> <json>  Set a value using JSON (for lists/objects).
  unset <path>            Remove a value so it falls back to defaults.
  dump                    Print the entire merged configuration as JSON.

Examples:
  php bin/config-cli.php get site.name
  php bin/config-cli.php set mail.smtp_host smtp.example.com
  php bin/config-cli.php set-json mail.sec_email '["ops@example.com"]'
  php bin/config-cli.php unset mail.smtp_host
TXT;
    fwrite(STDERR, $text . PHP_EOL);
    exit(1);
}

if ($argc < 2) {
    usage();
}
$cmd = strtolower($argv[1]);

switch ($cmd) {
    case 'get':
        if ($argc < 3) {
            usage();
        }
        $path = $argv[2];
        $val = \App\Config::get($path, null);
        if (is_array($val) || is_object($val)) {
            echo json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } elseif ($val === null) {
            fwrite(STDERR, "null\n");
        } else {
            echo $val . PHP_EOL;
        }
        exit(0);

    case 'dump':
        echo json_encode(\App\Config::all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);

    case 'unset':
        if ($argc < 3) {
            usage();
        }
        $path = $argv[2];
        if (\App\Config::delete($path)) {
            echo "Removed {$path}\n";
            exit(0);
        }
        fwrite(STDERR, "Nothing to remove for {$path}\n");
        exit(1);

    case 'set':
    case 'set-json':
        if ($argc < 4) {
            usage();
        }
        $path = $argv[2];
        $rawValue = $argv[3];
        if ($cmd === 'set-json') {
            $value = json_decode($rawValue, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                fwrite(STDERR, "Invalid JSON payload\n");
                exit(1);
            }
        } else {
            $value = parseScalar($rawValue);
        }
        $patch = buildPatch($path, $value);
        \App\Config::setMany($patch);
        echo "Updated {$path}\n";
        exit(0);

    default:
        usage();
}

function parseScalar($raw)
{
    $trim = trim($raw);
    if ($trim === '') {
        return '';
    }
    if (preg_match('/^-?[0-9]+$/', $trim)) {
        return (int)$trim;
    }
    if (preg_match('/^-?[0-9]*\.[0-9]+$/', $trim)) {
        return (float)$trim;
    }
    $lower = strtolower($trim);
    if ($lower === 'true') {
        return true;
    }
    if ($lower === 'false') {
        return false;
    }
    if ($lower === 'null') {
        return null;
    }
    return $raw;
}

function buildPatch($path, $value)
{
    $segments = explode('.', $path);
    $out = [];
    $node = & $out;
    foreach ($segments as $index => $segment) {
        if ($segment === '') {
            continue;
        }
        if ($index === count($segments) - 1) {
            $node[$segment] = $value;
        } else {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = & $node[$segment];
        }
    }
    return $out;
}
