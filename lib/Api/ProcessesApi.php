<?php

declare(strict_types=1);

const PROC_API_DEFAULT_LIMIT = 50;
const PROC_API_MIN_LIMIT = 10;
const PROC_API_MAX_LIMIT = 200;
const PROC_API_FILTER_MAX = 80;
const PROC_API_CMDLINE_MAX = 280;
const PROC_API_CACHE_TTL = 2;

function proc_api_default_limit(): int
{
    $raw = function_exists("cfg_local") ? cfg_local("processes.default_limit", PROC_API_DEFAULT_LIMIT) : PROC_API_DEFAULT_LIMIT;
    if (!is_numeric($raw)) {
        return PROC_API_DEFAULT_LIMIT;
    }
    $n = (int) $raw;
    if ($n < PROC_API_MIN_LIMIT) {
        return PROC_API_MIN_LIMIT;
    }
    if ($n > PROC_API_MAX_LIMIT) {
        return PROC_API_MAX_LIMIT;
    }
    return $n;
}

function proc_api_cache_ttl(): int
{
    $raw = function_exists("cfg_local") ? cfg_local("processes.cache_ttl_sec", PROC_API_CACHE_TTL) : PROC_API_CACHE_TTL;
    if (!is_numeric($raw)) {
        return PROC_API_CACHE_TTL;
    }
    $n = (int) $raw;
    if ($n < 1) {
        return 1;
    }
    if ($n > 10) {
        return 10;
    }
    return $n;
}

function proc_api_now(): float
{
    return microtime(true);
}

function proc_api_sort_key(string $raw): string
{
    $raw = strtolower(trim($raw));
    $allow = ["cpu", "mem", "pid", "user", "cmd"];
    return in_array($raw, $allow, true) ? $raw : "cpu";
}

function proc_api_limit($raw): int
{
    if (!is_numeric($raw)) {
        return proc_api_default_limit();
    }
    $n = (int) $raw;
    if ($n < PROC_API_MIN_LIMIT) {
        return PROC_API_MIN_LIMIT;
    }
    if ($n > PROC_API_MAX_LIMIT) {
        return PROC_API_MAX_LIMIT;
    }
    return $n;
}

function proc_api_clean_substr($raw, int $maxLen): string
{
    $s = trim((string) $raw);
    if ($s === "") {
        return "";
    }
    $s = preg_replace("/[\x00-\x1F\x7F]/", " ", $s) ?? "";
    $s = preg_replace("/\s+/", " ", $s) ?? "";
    if (function_exists("mb_substr")) {
        $s = mb_substr($s, 0, $maxLen, "UTF-8");
    } else {
        $s = substr($s, 0, $maxLen);
    }
    return trim($s);
}

function proc_api_clean_user($raw): string
{
    $s = trim((string) $raw);
    if ($s === "") {
        return "";
    }
    if (!preg_match("/^[a-z_][a-z0-9_.-]{0,31}$/i", $s)) {
        return "";
    }
    return $s;
}

function proc_api_cache_file(): string
{
    $base = defined("STATE_DIR") ? (string) STATE_DIR : (dirname(__DIR__, 2) . "/state");
    $dir = rtrim($base, "/") . "/cache";
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    return $dir . "/processes_api_state.json";
}

function proc_api_cache_available(): bool
{
    return function_exists("apcu_fetch")
        && function_exists("apcu_store")
        && (bool) filter_var(ini_get("apc.enabled"), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
}

function proc_api_cache_get(string $key): ?array
{
    if (proc_api_cache_available()) {
        $ok = false;
        $value = apcu_fetch($key, $ok);
        if ($ok && is_array($value)) {
            return $value;
        }
    }

    $file = proc_api_cache_file();
    if (!is_readable($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === "") {
        return null;
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return null;
    }
    if (($json["_key"] ?? "") !== $key) {
        return null;
    }
    return $json["payload"] ?? null;
}

function proc_api_cache_set(string $key, array $payload, int $ttl): void
{
    if (proc_api_cache_available()) {
        @apcu_store($key, $payload, $ttl);
    }

    $file = proc_api_cache_file();
    $tmp = $file . ".tmp";
    $wrapper = ["_key" => $key, "payload" => $payload, "stored_at" => time()];
    $json = json_encode($wrapper, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return;
    }
    @chmod($tmp, 0660);
    @rename($tmp, $file);
}

function proc_api_read_line(string $path): ?string
{
    if (!is_readable($path)) {
        return null;
    }
    $line = @file_get_contents($path);
    if (!is_string($line)) {
        return null;
    }
    return trim($line);
}

function proc_api_uid_map(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $map = [];
    $passwd = "/etc/passwd";
    if (!is_readable($passwd)) {
        return $map;
    }
    $lines = @file($passwd, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $map;
    }
    foreach ($lines as $line) {
        $parts = explode(":", $line);
        if (count($parts) < 3) {
            continue;
        }
        $uid = (int) $parts[2];
        $name = trim($parts[0]);
        if ($name === "") {
            continue;
        }
        $map[$uid] = $name;
    }
    return $map;
}

function proc_api_user_from_uid(int $uid): string
{
    static $cache = [];
    if (isset($cache[$uid])) {
        return $cache[$uid];
    }
    if (function_exists("posix_getpwuid")) {
        $pw = @posix_getpwuid($uid);
        if (is_array($pw) && !empty($pw["name"])) {
            $cache[$uid] = (string) $pw["name"];
            return $cache[$uid];
        }
    }
    $map = proc_api_uid_map();
    if (isset($map[$uid])) {
        $cache[$uid] = $map[$uid];
        return $cache[$uid];
    }
    $cache[$uid] = (string) $uid;
    return $cache[$uid];
}

function proc_api_read_total_cpu_ticks(): int
{
    $line = proc_api_read_line("/proc/stat");
    if ($line === null || strpos($line, "cpu ") !== 0) {
        return 0;
    }
    $parts = preg_split("/\s+/", trim($line));
    if (!is_array($parts) || count($parts) < 2) {
        return 0;
    }
    $sum = 0;
    for ($i = 1; $i < count($parts); $i++) {
        if (is_numeric($parts[$i])) {
            $sum += (int) $parts[$i];
        }
    }
    return $sum;
}

function proc_api_uptime_seconds(): ?float
{
    $line = proc_api_read_line("/proc/uptime");
    if ($line === null) {
        return null;
    }
    $parts = preg_split("/\s+/", $line);
    if (!is_array($parts) || empty($parts[0]) || !is_numeric($parts[0])) {
        return null;
    }
    return (float) $parts[0];
}

function proc_api_loadavg(): ?array
{
    $line = proc_api_read_line("/proc/loadavg");
    if ($line === null) {
        return null;
    }
    $parts = preg_split("/\s+/", $line);
    if (!is_array($parts) || count($parts) < 3) {
        return null;
    }
    return ["l1" => (float) $parts[0], "l5" => (float) $parts[1], "l15" => (float) $parts[2]];
}

function proc_api_clean_cmdline(string $raw): string
{
    $raw = str_replace("\0", " ", $raw);
    $raw = preg_replace("/[\x00-\x1F\x7F]/", " ", $raw) ?? "";
    $raw = preg_replace("/\s+/", " ", $raw) ?? "";
    $raw = trim($raw);
    if ($raw === "") {
        return "";
    }
    if (function_exists("mb_substr")) {
        return mb_substr($raw, 0, PROC_API_CMDLINE_MAX, "UTF-8");
    }
    return substr($raw, 0, PROC_API_CMDLINE_MAX);
}

function proc_api_parse_status(string $path): array
{
    $uid = 0;
    $rss = 0;
    $threads = 0;
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return ["uid" => 0, "vmrss_kb" => 0, "threads" => 0];
    }
    foreach ($lines as $line) {
        if (strpos($line, "Uid:") === 0) {
            $parts = preg_split("/\s+/", trim(substr($line, 4)));
            if (is_array($parts) && isset($parts[0]) && is_numeric($parts[0])) {
                $uid = (int) $parts[0];
            }
        } elseif (strpos($line, "VmRSS:") === 0) {
            if (preg_match("/VmRSS:\s+(\d+)/", $line, $m)) {
                $rss = (int) $m[1];
            }
        } elseif (strpos($line, "Threads:") === 0) {
            if (preg_match("/Threads:\s+(\d+)/", $line, $m)) {
                $threads = (int) $m[1];
            }
        }
    }
    return ["uid" => $uid, "vmrss_kb" => $rss, "threads" => $threads];
}

function proc_api_parse_io(string $path): array
{
    if (!is_readable($path)) {
        return ["read_bytes" => null, "write_bytes" => null];
    }
    $read = null;
    $write = null;
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return ["read_bytes" => null, "write_bytes" => null];
    }
    foreach ($lines as $line) {
        if (strpos($line, "read_bytes:") === 0) {
            $v = trim(substr($line, strlen("read_bytes:")));
            if (is_numeric($v)) {
                $read = (int) $v;
            }
        } elseif (strpos($line, "write_bytes:") === 0) {
            $v = trim(substr($line, strlen("write_bytes:")));
            if (is_numeric($v)) {
                $write = (int) $v;
            }
        }
    }
    return ["read_bytes" => $read, "write_bytes" => $write];
}

function proc_api_sample_processes(): array
{
    $entries = @scandir("/proc");
    if (!is_array($entries)) {
        return [];
    }
    $rows = [];
    foreach ($entries as $entry) {
        if ($entry === "." || $entry === ".." || !ctype_digit($entry)) {
            continue;
        }
        $pid = (int) $entry;
        if ($pid <= 0) {
            continue;
        }
        $base = "/proc/" . $entry;
        $statRaw = @file_get_contents($base . "/stat");
        if (!is_string($statRaw) || $statRaw === "") {
            continue;
        }
        $l = strrpos($statRaw, ")");
        if ($l === false) {
            continue;
        }
        $commStart = strpos($statRaw, "(");
        $comm = trim(substr($statRaw, $commStart + 1, $l - $commStart - 1));
        $rest = trim(substr($statRaw, $l + 1));
        $parts = preg_split("/\s+/", $rest);
        if (!is_array($parts) || count($parts) < 22) {
            continue;
        }
        $state = (string) ($parts[0] ?? "?");
        $ppid = is_numeric($parts[1] ?? null) ? (int) $parts[1] : 0;
        $utime = is_numeric($parts[11] ?? null) ? (int) $parts[11] : 0;
        $stime = is_numeric($parts[12] ?? null) ? (int) $parts[12] : 0;
        $threadsFromStat = is_numeric($parts[17] ?? null) ? (int) $parts[17] : 0;
        $status = proc_api_parse_status($base . "/status");
        $uid = (int) $status["uid"];
        $user = proc_api_user_from_uid($uid);
        $cmd = trim((string) @file_get_contents($base . "/comm"));
        if ($cmd === "") {
            $cmd = $comm;
        }
        $cmdlineRaw = @file_get_contents($base . "/cmdline");
        $cmdline = is_string($cmdlineRaw) ? proc_api_clean_cmdline($cmdlineRaw) : "";
        $rssKb = (int) $status["vmrss_kb"];
        $threads = (int) $status["threads"];
        if ($threads <= 0) {
            $threads = $threadsFromStat;
        }
        $row = [
            "pid" => $pid,
            "ppid" => $ppid,
            "uid" => $uid,
            "user" => $user,
            "state" => $state,
            "cmd" => $cmd,
            "cmdline" => $cmdline,
            "rss_kb" => max(0, $rssKb),
            "threads" => max(0, $threads),
            "proc_ticks" => max(0, $utime + $stime),
            "cpu_pct" => 0.0,
        ];
        $io = proc_api_parse_io($base . "/io");
        if ($io["read_bytes"] !== null) {
            $row["io_read_bytes"] = (int) $io["read_bytes"];
        }
        if ($io["write_bytes"] !== null) {
            $row["io_write_bytes"] = (int) $io["write_bytes"];
        }
        $rows[$pid] = $row;
    }
    return $rows;
}

function proc_api_apply_cpu_delta(array $current, array $previous, int $totalDelta): array
{
    if ($totalDelta <= 0) {
        return $current;
    }
    foreach ($current as $pid => &$row) {
        $prev = $previous[$pid] ?? null;
        if (!is_array($prev)) {
            $row["cpu_pct"] = 0.0;
            continue;
        }
        $currTicks = (int) ($row["proc_ticks"] ?? 0);
        $prevTicks = (int) ($prev["proc_ticks"] ?? 0);
        $procDelta = $currTicks - $prevTicks;
        if ($procDelta <= 0) {
            $row["cpu_pct"] = 0.0;
            continue;
        }
        $pct = ($procDelta / $totalDelta) * 100.0;
        if ($pct < 0) {
            $pct = 0.0;
        }
        if ($pct > 999.9) {
            $pct = 999.9;
        }
        $row["cpu_pct"] = round($pct, 1);
    }
    unset($row);
    return $current;
}

function proc_api_sort_rows(array $rows, string $sort): array
{
    $desc = in_array($sort, ["cpu", "mem"], true);
    uasort($rows, static function (array $a, array $b) use ($sort, $desc): int {
        switch ($sort) {
            case "pid":
                $cmp = ((int) $a["pid"]) <=> ((int) $b["pid"]);
                break;
            case "user":
                $cmp = strcasecmp((string) $a["user"], (string) $b["user"]);
                if ($cmp === 0) {
                    $cmp = ((int) $a["pid"]) <=> ((int) $b["pid"]);
                }
                break;
            case "cmd":
                $cmdA = (string) ($a["cmdline"] !== "" ? $a["cmdline"] : $a["cmd"]);
                $cmdB = (string) ($b["cmdline"] !== "" ? $b["cmdline"] : $b["cmd"]);
                $cmp = strcasecmp($cmdA, $cmdB);
                if ($cmp === 0) {
                    $cmp = ((int) $a["pid"]) <=> ((int) $b["pid"]);
                }
                break;
            case "mem":
                $cmp = ((int) $a["rss_kb"]) <=> ((int) $b["rss_kb"]);
                if ($cmp === 0) {
                    $cmp = ((float) $a["cpu_pct"]) <=> ((float) $b["cpu_pct"]);
                }
                break;
            case "cpu":
            default:
                $cmp = ((float) $a["cpu_pct"]) <=> ((float) $b["cpu_pct"]);
                if ($cmp === 0) {
                    $cmp = ((int) $a["rss_kb"]) <=> ((int) $b["rss_kb"]);
                }
                break;
        }
        return $desc ? -$cmp : $cmp;
    });
    return array_values($rows);
}

function proc_api_filter_rows(array $rows, string $filter, string $user): array
{
    $needle = strtolower($filter);
    $userNeedle = strtolower($user);
    if ($needle === "" && $userNeedle === "") {
        return $rows;
    }
    $out = [];
    foreach ($rows as $row) {
        $rowUser = strtolower((string) ($row["user"] ?? ""));
        if ($userNeedle !== "" && $rowUser !== $userNeedle) {
            continue;
        }
        if ($needle !== "") {
            $hay = strtolower((string) ($row["cmd"] ?? "") . " " . (string) ($row["cmdline"] ?? "") . " " . (string) ($row["user"] ?? ""));
            if (strpos($hay, $needle) === false) {
                continue;
            }
        }
        $out[] = $row;
    }
    return $out;
}

function proc_api_response(array $query): array
{
    if (!is_dir("/proc") || !is_readable("/proc")) {
        return [
            "status" => 503,
            "payload" => [
                "ok" => false,
                "error" => "/proc is unavailable on this host",
                "ts" => date("c"),
                "host" => gethostname() ?: "localhost",
            ],
        ];
    }

    $sort = proc_api_sort_key((string) ($query["sort"] ?? "cpu"));
    $limit = proc_api_limit($query["limit"] ?? proc_api_default_limit());
    $filter = proc_api_clean_substr($query["filter"] ?? "", PROC_API_FILTER_MAX);
    $userFilter = proc_api_clean_user($query["user"] ?? "");
    $cacheKey = "processes:raw:v1";
    $now = proc_api_now();
    $state = proc_api_cache_get($cacheKey);

    $reuse = false;
    if (is_array($state)) {
        $generated = (float) ($state["generated_at"] ?? 0);
        if (($now - $generated) <= proc_api_cache_ttl() && is_array($state["rows"] ?? null)) {
            $reuse = true;
        }
    }

    if (!$reuse) {
        $totalTicks = proc_api_read_total_cpu_ticks();
        $currentRows = proc_api_sample_processes();
        $prevRows = [];
        $prevTicks = 0;
        if (is_array($state)) {
            $prevRows = is_array($state["raw_rows"] ?? null) ? $state["raw_rows"] : [];
            $prevTicks = (int) ($state["total_ticks"] ?? 0);
        }
        $deltaTicks = $totalTicks - $prevTicks;
        if ($deltaTicks < 0) {
            $deltaTicks = 0;
        }
        $rowsWithCpu = proc_api_apply_cpu_delta($currentRows, $prevRows, $deltaTicks);
        $state = [
            "generated_at" => $now,
            "host" => gethostname() ?: "localhost",
            "loadavg" => proc_api_loadavg(),
            "uptime" => proc_api_uptime_seconds(),
            "rows" => array_values($rowsWithCpu),
            "raw_rows" => $currentRows,
            "total_ticks" => $totalTicks,
        ];
        proc_api_cache_set($cacheKey, $state, proc_api_cache_ttl());
    }

    $rows = is_array($state["rows"] ?? null) ? $state["rows"] : [];
    $rows = proc_api_filter_rows($rows, $filter, $userFilter);
    $rows = proc_api_sort_rows($rows, $sort);
    if (count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }
    foreach ($rows as &$row) {
        unset($row["uid"], $row["proc_ticks"]);
    }
    unset($row);

    return [
        "status" => 200,
        "payload" => [
            "ok" => true,
            "ts" => date("c"),
            "host" => $state["host"] ?? (gethostname() ?: "localhost"),
            "loadavg" => $state["loadavg"] ?? null,
            "uptime" => $state["uptime"] ?? null,
            "sort" => $sort,
            "limit" => $limit,
            "filter" => $filter,
            "user" => $userFilter,
            "processes" => $rows,
        ],
    ];
}
