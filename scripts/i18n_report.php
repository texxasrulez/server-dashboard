<?php

$root = dirname(__DIR__);
$scanFile = $root . '/scripts/i18n_scan.php';
$enFile = $root . '/assets/i18n/en.json';
$localeDir = $root . '/assets/i18n';

if (!is_file($scanFile) || !is_file($enFile) || !is_dir($localeDir)) {
    fwrite(STDERR, "Required i18n files are missing.\n");
    exit(1);
}

function flatten_map($arr, $prefix = '')
{
    $out = [];
    if (!is_array($arr)) {
        return $out;
    }
    foreach ($arr as $k => $v) {
        $key = ($prefix === '') ? (string)$k : ($prefix . '.' . $k);
        if (is_array($v)) {
            $out += flatten_map($v, $key);
        } elseif (is_string($v)) {
            $out[$key] = $v;
        }
    }
    return $out;
}

function get_value_by_path($arr, $path)
{
    $cur = $arr;
    foreach (explode('.', (string)$path) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return null;
        }
        $cur = $cur[$part];
    }
    return is_string($cur) ? $cur : null;
}

$en = json_decode(file_get_contents($enFile), true);
if (!is_array($en)) {
    fwrite(STDERR, "Failed to parse English i18n file.\n");
    exit(1);
}

$enFlat = flatten_map($en);
$scanOutput = shell_exec('php ' . escapeshellarg($scanFile) . ' ' . escapeshellarg($root));
$refKeys = [];
$decoded = json_decode($scanOutput, true);
if (is_array($decoded)) {
    $refKeys = array_keys($decoded);
}
sort($refKeys, SORT_NATURAL | SORT_FLAG_CASE);

$missingInEnglish = [];
foreach ($refKeys as $key) {
    if (!array_key_exists($key, $enFlat)) {
        $missingInEnglish[] = $key;
    }
}

$report = [
    'summary' => [
        'referenced_keys' => count($refKeys),
        'english_keys' => count($enFlat),
        'referenced_missing_in_english' => count($missingInEnglish),
    ],
    'missing_in_english' => $missingInEnglish,
    'locales' => [],
];

foreach (glob($localeDir . '/*.json') as $file) {
    $code = basename($file, '.json');
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        $report['locales'][$code] = ['error' => 'invalid_json'];
        continue;
    }
    $flat = flatten_map($data);
    $missingReferenced = [];
    $sameAsEnglish = 0;
    foreach ($refKeys as $key) {
        if (!array_key_exists($key, $flat)) {
            $missingReferenced[] = $key;
            continue;
        }
        if ($code !== 'en' && (($flat[$key] ?? null) === ($enFlat[$key] ?? null))) {
            $sameAsEnglish++;
        }
    }
    $report['locales'][$code] = [
        'defined_keys' => count($flat),
        'missing_referenced_count' => count($missingReferenced),
        'same_as_english_count' => $sameAsEnglish,
        'missing_referenced_sample' => array_slice($missingReferenced, 0, 25),
    ];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
