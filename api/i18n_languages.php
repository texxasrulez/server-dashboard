<?php

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
$root = dirname(__DIR__);
$dir = $root . '/assets/i18n';
$names = [
    'ar' => 'العربية',
    'bg' => 'Български',
    'cs' => 'Čeština',
    'da' => 'Dansk',
    'de' => 'Deutsch',
    'el' => 'Ελληνικά',
    'en' => 'English',
    'en-gb' => 'English (UK)',
    'en-us' => 'English (US)',
    'es' => 'Español',
    'es-419' => 'Español (Latinoamérica)',
    'et' => 'Eesti',
    'fi' => 'Suomi',
    'fr' => 'Français',
    'hu' => 'Magyar',
    'id' => 'Bahasa Indonesia',
    'it' => 'Italiano',
    'ja' => '日本語',
    'ko' => '한국어',
    'lt' => 'Lietuvių',
    'lv' => 'Latviešu',
    'nb' => 'Norsk Bokmål',
    'nl' => 'Nederlands',
    'pl' => 'Polski',
    'pt-br' => 'Português (Brasil)',
    'pt-pt' => 'Português (Portugal)',
    'ro' => 'Română',
    'ru' => 'Русский',
    'sk' => 'Slovenčina',
    'sl' => 'Slovenščina',
    'sv' => 'Svenska',
    'tr' => 'Türkçe',
    'uk' => 'Українська',
    'zh' => '中文',
    'zh-hans' => '简体中文',
    'zh-hant' => '繁體中文',
];
$out = [];
if (is_dir($dir)) {
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        if (substr($f, -5) === '.json') {
            $code = substr($f, 0, -5);
            if ($code !== '') {
                $out[] = [
                    'code' => $code,
                    'name' => $names[$code] ?? strtoupper($code),
                ];
            }
        }
    }
}
usort($out, function ($a, $b) {
    return strcmp($a['code'], $b['code']);
});
echo json_encode(['ok' => true, 'languages' => $out]);
