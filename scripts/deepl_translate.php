<?php
/**
 * Translate i18n JSON using DeepL with multi-target support.
 * Adds: --to=all, comma lists, {lang} in --out, optional --from=EN, --append.
 *
 * Env:
 *   DEEPL_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxx
 *   DEEPL_API_PLAN=free|pro   (default: free)
 */
function argval($name, $default=null){
  foreach ($GLOBALS['argv'] as $a){
    if (preg_match('/^--'.preg_quote($name,'/').'=(.*)$/', $a, $m)) return $m[1];
    if ($a === '--'.$name) return true;
  }
  return $default;
}

$src   = argval('src');
$out   = argval('out');
$toArg = argval('to');
$from  = argval('from', null);
$append = (bool) argval('append', false);

if (!$src || !$out || !$toArg){
  fwrite(STDERR, "Usage: php scripts/deepl_translate.php --src=assets/i18n/en.json --out=assets/i18n/{lang}.json --to=de|de,fr|all [--from=en] [--append]\n");
  exit(2);
}

$key  = getenv('DEEPL_API_KEY');
$plan = getenv('DEEPL_API_PLAN') ?: 'free';
if (!$key){ fwrite(STDERR, "DEEPL_API_KEY not set\n"); exit(3); }

$base = ($plan==='pro') ? 'https://api.deepl.com' : 'https://api-free.deepl.com';
$translateEndpoint = $base . '/v2/translate';
$languagesEndpoint = $base . '/v2/languages?type=target';

$srcMap = json_decode(@file_get_contents($src), true);
if (!is_array($srcMap)) { fwrite(STDERR, "Bad src JSON: $src\n"); exit(4); }

function flatten($arr, $prefix=''){
  $out = [];
  foreach ($arr as $k=>$v){
    $key = $prefix ? ($prefix.'.'.$k) : $k;
    if (is_array($v)) { $out += flatten($v, $key); }
    elseif (is_string($v)) { $out[$key] = $v; }
  }
  return $out;
}
function unflatten($flat){
  $out = [];
  foreach ($flat as $k=>$v){
    $parts = explode('.', $k);
    $cur =& $out;
    foreach ($parts as $i=>$p){
      if ($i === count($parts)-1) { $cur[$p] = $v; }
      else { if (!isset($cur[$p]) || !is_array($cur[$p])) $cur[$p] = []; $cur=&$cur[$p]; }
    }
  }
  return $out;
}

function build_form_body($scalars, $texts){
  $pairs = [];
  foreach ($scalars as $k=>$v){
    if ($v === null) continue;
    $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
  }
  foreach ($texts as $t){
    $pairs[] = 'text=' . rawurlencode($t);
  }
  return implode('&', $pairs);
}
function http_post_urlencoded($url, $body, $authKey){
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'Authorization: DeepL-Auth-Key ' . $authKey
  ]);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_TIMEOUT, 60);
  $res = curl_exec($ch);
  if ($res === false){ $err = curl_error($ch); curl_close($ch); throw new Exception($err); }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) throw new Exception("HTTP $code: $res");
  return $res;
}
function http_get_json($url, $authKey){
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: DeepL-Auth-Key ' . $authKey
  ]);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
  curl_setopt($ch, CURLOPT_TIMEOUT, 60);
  $res = curl_exec($ch);
  if ($res === false){ $err = curl_error($ch); curl_close($ch); throw new Exception($err); }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) throw new Exception("HTTP $code: $res");
  $j = json_decode($res, true);
  if (!is_array($j)) throw new Exception("Bad JSON from $url");
  return $j;
}

function get_target_languages($languagesEndpoint, $authKey){
  $arr = http_get_json($languagesEndpoint, $authKey);
  $codes = [];
  foreach ($arr as $item){
    if (isset($item['language'])) $codes[] = strtolower($item['language']);
  }
  $codes = array_values(array_unique($codes));
  sort($codes, SORT_NATURAL|SORT_FLAG_CASE);
  return $codes;
}

function translate_batch($endpoint, $authKey, $target_lang, $texts, $source_lang=null){
  $scalars = ['target_lang'=>strtoupper($target_lang)];
  if ($source_lang) $scalars['source_lang'] = strtoupper($source_lang);
  // You can send auth_key in body OR via Authorization header; we use header (cleaner),
  // but adding in body is harmless for old proxies:
  $scalars['auth_key'] = $authKey;
  $body = build_form_body($scalars, $texts);
  $res  = http_post_urlencoded($endpoint, $body, $authKey);
  $j = json_decode($res, true);
  if (!isset($j['translations']) || !is_array($j['translations']))
    throw new Exception("Bad DeepL response: ".$res);
  return array_map(function($x){ return $x['text'] ?? ''; }, $j['translations']);
}

$srcFlat = flatten($srcMap);

function compute_todo($srcFlat, $dstFlat, $append){
  $todo = [];
  foreach ($srcFlat as $k=>$v){
    if ($append){
      if (!array_key_exists($k, $dstFlat) || $dstFlat[$k]==='')
        $todo[$k] = $v;
    } else {
      $todo[$k] = $v;
    }
  }
  return $todo;
}

$targets = [];
if (strtolower($toArg) === 'all'){
  $targets = get_target_languages($languagesEndpoint, $key);
} elseif (strpos($toArg, ',') !== false){
  $targets = array_map('trim', explode(',', $toArg));
  $targets = array_values(array_filter($targets, fn($x)=>$x!==''));
} else {
  $targets = [ $toArg ];
}

if (!$targets){ fwrite(STDERR, "No target languages resolved.\n"); exit(5); }

$chunkSize = 40;

foreach ($targets as $tgt){
  $tgtLower = strtolower($tgt);

  // Determine output filename
  if (strpos($out, '{lang}') !== false){
    $outPath = str_replace('{lang}', $tgtLower, $out);
  } else {
    $dot = strrpos($out, '.');
    if ($dot !== false){
      $outPath = substr($out, 0, $dot) . '.' . $tgtLower . substr($out, $dot);
    } else {
      $outPath = $out . '.' . $tgtLower . '.json';
    }
  }

  $dstMapThis = (file_exists($outPath) ? (json_decode(@file_get_contents($outPath), true) ?: []) : []);
  $dstFlatThis = flatten($dstMapThis);
  $todo = compute_todo($srcFlat, $dstFlatThis, $append);

  if (!$todo){
    fwrite(STDOUT, "Nothing to translate for $tgtLower → $outPath\n");
    continue;
  }

  $keys = array_keys($todo);
  $vals = array_values($todo);
  $translated = [];

  for ($i=0; $i<count($vals); $i+=$chunkSize){
    $chunk = array_slice($vals, $i, $chunkSize);
    $outTexts = translate_batch($translateEndpoint, $key, $tgtLower, $chunk, $from);
    for ($j=0; $j<count($chunk); $j++){
      $translated[$keys[$i+$j]] = $outTexts[$j] ?? $chunk[$j];
    }
  }

  foreach ($translated as $k=>$v){
    $dstFlatThis[$k] = $v;
  }
  $merged = unflatten($dstFlatThis);
  @mkdir(dirname($outPath), 0775, true);
  file_put_contents($outPath, json_encode($merged, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
  fwrite(STDOUT, "Wrote: $outPath\n");
}
