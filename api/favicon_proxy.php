<?php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/_favicon_cache.php';

require_login();
if ((bool)cfg_local('security.favicon_require_admin', false)) {
    require_admin();
}
$allowHttp = (bool)cfg_local('security.favicon_allow_http', false);

$url = $_GET['url'] ?? '';
$host = $_GET['host'] ?? '';

if ($url && !$host) {
    $u = @parse_url($url);
    $scheme = is_array($u) ? strtolower((string)($u['scheme'] ?? '')) : '';
    if ($scheme === 'http' && !$allowHttp) {
        $u = null;
    }
    if ($u && isset($u['host'])) {
        $host = $u['host'];
    }
}
$host = strtolower(trim($host));
$host = rtrim($host, '.');

$cacheFile = server_dashboard_favicon_cache_file($host);
$default = __DIR__ . '/../assets/img/default_favicon.png';

function out_png($file)
{
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=300');
    readfile($file);
    exit;
}
function out_ico($file)
{
    header('Content-Type: image/x-icon');
    header('Cache-Control: public, max-age=86400');
    readfile($file);
    exit;
}

if ($host) {
    $cached = server_dashboard_cache_favicon($host);
    if ($cached && file_exists($cached)) {
        out_ico($cached);
    }
}

out_png($default);
