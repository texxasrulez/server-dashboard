<?php

$root = $argv[1] ?? dirname(__DIR__);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$keys = [];
foreach ($it as $f) {
    $p = $f->getPathname();
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    if (!in_array($ext, ['php','js','html'])) {
        continue;
    }
    $s = @file_get_contents($p);
    if ($s === false) {
        continue;
    }
    if (preg_match_all('/__\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $s, $m)) {
        foreach ($m[1] as $k) {
            $keys[$k] = $keys[$k] ?? '';
        }
    }
    if (preg_match_all('/data-i18n\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $s, $m)) {
        foreach ($m[1] as $k) {
            $keys[$k] = $keys[$k] ?? '';
        }
    }
    if (preg_match_all('/I18N\.t\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $s, $m)) {
        foreach ($m[1] as $k) {
            $keys[$k] = $keys[$k] ?? '';
        }
    }
}
ksort($keys, SORT_NATURAL | SORT_FLAG_CASE);
echo json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
