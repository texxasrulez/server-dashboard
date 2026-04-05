<?php

namespace App;

require_once __DIR__ . '/Config.php';
require_once dirname(__DIR__) . '/api/_state_path.php';

final class Speedtest
{
    private const RANGE_SECONDS = [
        '24h' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
        '90d' => 7776000,
    ];

    public static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function ensureConfig(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }
        Config::init(self::projectRoot());
        $ready = true;
    }

    public static function settings(): array
    {
        self::ensureConfig();
        $cfg = Config::get('speedtest', []);
        $quiet = is_array($cfg['quiet_hours'] ?? null) ? $cfg['quiet_hours'] : [];
        $legacyPreferredServer = self::sanitizePreferredServer($cfg['preferred_server'] ?? '');

        return [
            'enabled' => (bool)($cfg['enabled'] ?? false),
            'interval_minutes' => max(1, min(10080, (int)($cfg['interval_minutes'] ?? 240))),
            'timeout_seconds' => max(5, min(300, (int)($cfg['timeout_seconds'] ?? 90))),
            'preferred_backend' => self::normalizeBackend((string)($cfg['preferred_backend'] ?? 'auto')),
            'preferred_server_ookla' => self::sanitizePreferredServer($cfg['preferred_server_ookla'] ?? $legacyPreferredServer),
            'preferred_server_librespeed' => self::sanitizePreferredServer($cfg['preferred_server_librespeed'] ?? $legacyPreferredServer),
            'retention_days' => max(1, min(3650, (int)($cfg['retention_days'] ?? 90))),
            'max_history_entries' => max(10, min(50000, (int)($cfg['max_history_entries'] ?? 2000))),
            'log_failed_tests' => (bool)($cfg['log_failed_tests'] ?? true),
            'randomize_schedule_window' => (bool)($cfg['randomize_schedule_window'] ?? false),
            'randomize_window_minutes' => max(0, min(240, (int)($cfg['randomize_window_minutes'] ?? 10))),
            'quiet_hours' => [
                'enabled' => (bool)($quiet['enabled'] ?? false),
                'start_hour' => max(0, min(23, (int)($quiet['start_hour'] ?? 0))),
                'end_hour' => max(0, min(23, (int)($quiet['end_hour'] ?? 0))),
            ],
        ];
    }

    public static function validatePatch(array $patch): void
    {
        if (!isset($patch['speedtest']) || !is_array($patch['speedtest'])) {
            return;
        }
        $speedtest = $patch['speedtest'];
        if (array_key_exists('preferred_backend', $speedtest)) {
            $backend = strtolower(trim((string)$speedtest['preferred_backend']));
            if (!in_array($backend, ['auto', 'ookla', 'librespeed', 'speedtest'], true)) {
                throw new \InvalidArgumentException('Preferred backend must be auto, Ookla speedtest, or librespeed-cli');
            }
        }
        foreach (['preferred_server', 'preferred_server_ookla', 'preferred_server_librespeed'] as $key) {
            if (array_key_exists($key, $speedtest)) {
                $rawServer = trim((string)$speedtest[$key]);
                $server = self::sanitizePreferredServer($speedtest[$key]);
                if ($rawServer !== '' && $server !== $rawServer) {
                    throw new \InvalidArgumentException('Preferred server values must use only letters, numbers, dots, colons, dashes, underscores, or slashes');
                }
            }
        }
        if (isset($speedtest['quiet_hours']) && is_array($speedtest['quiet_hours'])) {
            $quiet = $speedtest['quiet_hours'];
            $start = array_key_exists('start_hour', $quiet) ? (int)$quiet['start_hour'] : null;
            $end = array_key_exists('end_hour', $quiet) ? (int)$quiet['end_hour'] : null;
            foreach (['start_hour' => $start, 'end_hour' => $end] as $label => $hour) {
                if ($hour !== null && ($hour < 0 || $hour > 23)) {
                    throw new \InvalidArgumentException('Quiet hours ' . str_replace('_', ' ', $label) . ' must be 0 through 23');
                }
            }
            if (!empty($quiet['enabled']) && $start !== null && $end !== null && $start === $end) {
                throw new \InvalidArgumentException('Quiet hours start and end hour must differ when quiet hours are enabled');
            }
        }
    }

    public static function stateDir(): string
    {
        $baseDir = dirname(dashboard_state_path('speedtest/.keep'));
        $legacyDir = $baseDir . '/speedtest';
        foreach ([$baseDir, $legacyDir] as $candidate) {
            if (is_file($candidate . '/speedtest_history.ndjson') || is_file($candidate . '/speedtest_meta.json')) {
                if (!is_dir($candidate)) {
                    @mkdir($candidate, 0775, true);
                }
                return $candidate;
            }
        }
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0775, true);
        }
        return $baseDir;
    }

    public static function historyPath(): string
    {
        return self::stateDir() . '/speedtest_history.ndjson';
    }

    public static function metaPath(): string
    {
        return self::stateDir() . '/speedtest_meta.json';
    }

    public static function loadMeta(): array
    {
        $defaults = [
            'last_attempted_at' => null,
            'last_success_at' => null,
            'last_backend' => '',
            'last_backend_version' => '',
            'last_error' => '',
            'next_due_at' => null,
            'last_duration_ms' => null,
            'history_invalid_lines' => 0,
        ];
        $path = self::metaPath();
        if (!is_file($path)) {
            return $defaults;
        }
        $raw = @file_get_contents($path);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($json)) {
            return $defaults;
        }
        return array_merge($defaults, $json);
    }

    public static function saveMeta(array $patch): array
    {
        $meta = array_merge(self::loadMeta(), $patch);
        dashboard_atomic_write(self::metaPath(), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        return $meta;
    }

    public static function backendStatus(): array
    {
        $catalog = [
            'ookla' => [
                'label' => 'Ookla speedtest',
                'binary_names' => ['speedtest'],
                'common_paths' => ['/usr/bin/speedtest', '/usr/local/bin/speedtest'],
            ],
            'librespeed' => [
                'label' => 'librespeed-cli',
                'binary_names' => ['librespeed-cli'],
                'common_paths' => ['/usr/bin/librespeed-cli', '/usr/local/bin/librespeed-cli'],
            ],
        ];
        $out = [];
        foreach ($catalog as $key => $def) {
            $binary = self::discoverBinary($def['binary_names'], $def['common_paths']);
            $version = '';
            $mode = $key;
            $label = $def['label'];
            if ($binary !== '') {
                $versionInfo = self::readToolVersion($binary, $key);
                $version = $versionInfo['version'];
                $mode = $versionInfo['mode'];
                if ($key === 'ookla' && $mode === 'speedtest-cli') {
                    $label = 'speedtest-cli';
                }
            }
            $out[$key] = [
                'available' => $binary !== '',
                'label' => $label,
                'binary' => $binary,
                'version' => $version,
                'mode' => $mode,
            ];
        }
        return $out;
    }

    public static function detectBackend(string $preferred = 'auto'): array
    {
        $preferred = self::normalizeBackend($preferred);
        $status = self::backendStatus();
        $order = $preferred === 'auto' ? ['ookla', 'librespeed'] : [$preferred, $preferred === 'ookla' ? 'librespeed' : 'ookla'];
        foreach ($order as $backend) {
            if (!empty($status[$backend]['available'])) {
                return [
                    'backend' => (string)($status[$backend]['mode'] ?? $backend),
                    'binary' => (string)$status[$backend]['binary'],
                    'version' => (string)$status[$backend]['version'],
                    'available' => true,
                    'statuses' => $status,
                ];
            }
        }
        return [
            'backend' => '',
            'binary' => '',
            'version' => '',
            'available' => false,
            'statuses' => $status,
        ];
    }

    public static function parseRange(string $range): int
    {
        return self::RANGE_SECONDS[$range] ?? self::RANGE_SECONDS['24h'];
    }

    public static function filterOptions(array $params): array
    {
        $range = isset($params['range']) ? strtolower(trim((string)$params['range'])) : '24h';
        if (!isset(self::RANGE_SECONDS[$range])) {
            $range = '24h';
        }
        $server = self::sanitizeServerFilter($params['server'] ?? '');
        return [
            'range' => $range,
            'include_failed' => !empty($params['include_failed']),
            'server' => $server,
            'since_ts' => time() - self::parseRange($range),
        ];
    }

    public static function statusPayload(): array
    {
        $settings = self::settings();
        $meta = self::loadMeta();
        $detected = self::detectBackend($settings['preferred_backend']);
        $nextDue = self::estimateNextDue($settings, $meta, time());

        return [
            'settings' => $settings,
            'meta' => $meta,
            'backend' => $detected,
            'detected_backend_status' => $detected['statuses'],
            'next_due_at' => $nextDue,
            'history_exists' => is_file(self::historyPath()),
        ];
    }

    public static function runCollector(array $options = []): array
    {
        $force = !empty($options['force']);
        $settings = self::settings();
        $meta = self::loadMeta();
        $now = time();
        $decision = self::shouldRun($settings, $meta, $now, $force);
        if (!$decision['run']) {
            if (isset($decision['next_due_at'])) {
                self::saveMeta(['next_due_at' => $decision['next_due_at']]);
            }
            return [
                'ok' => true,
                'ran' => false,
                'reason' => $decision['reason'],
                'next_due_at' => $decision['next_due_at'] ?? null,
            ];
        }

        $detected = self::detectBackend($settings['preferred_backend']);

        if (empty($detected['available'])) {
            $failure = self::failureRow($now, '', '', 'No supported speedtest CLI was detected on this host');
            if ($settings['log_failed_tests']) {
                $historyWrite = self::appendHistoryRow($failure, $settings);
                if (!$historyWrite['ok']) {
                    $failure['error_message'] = self::combineErrorMessages($failure['error_message'], $historyWrite['error']);
                }
            }
            $meta = self::saveMeta([
                'last_attempted_at' => $now,
                'last_backend' => '',
                'last_backend_version' => '',
                'last_error' => $failure['error_message'],
                'next_due_at' => self::scheduleNextDue($settings, $now),
            ]);
            return [
                'ok' => false,
                'ran' => true,
                'result' => $failure,
                'meta' => $meta,
            ];
        }

        $attempts = [[
            'backend' => (string)$detected['backend'],
            'binary' => (string)$detected['binary'],
            'version' => (string)$detected['version'],
            'preferred_server' => self::preferredServerForBackend($settings, $detected['backend']),
        ]];

        if (
            $detected['backend'] === 'speedtest-cli' &&
            self::preferredServerForBackend($settings, $detected['backend']) !== ''
        ) {
            $attempts[] = [
                'backend' => (string)$detected['backend'],
                'binary' => (string)$detected['binary'],
                'version' => (string)$detected['version'],
                'preferred_server' => '',
                'note' => 'Retrying speedtest-cli without preferred server',
            ];
        }

        if (
            $settings['preferred_backend'] === 'auto' &&
            $detected['backend'] === 'speedtest-cli' &&
            !empty($detected['statuses']['librespeed']['available'])
        ) {
            $attempts[] = [
                'backend' => 'librespeed',
                'binary' => (string)$detected['statuses']['librespeed']['binary'],
                'version' => (string)$detected['statuses']['librespeed']['version'],
                'preferred_server' => self::preferredServerForBackend($settings, 'librespeed'),
                'note' => 'Falling back to librespeed after speedtest-cli backend failure',
            ];
        }

        $result = null;
        foreach ($attempts as $index => $attempt) {
            $result = self::performCollectionAttempt(
                $attempt['backend'],
                $attempt['binary'],
                $attempt['version'],
                $attempt['preferred_server'],
                $now,
                (int)$settings['timeout_seconds'],
                (string)($attempt['note'] ?? '')
            );
            if (!empty($result['ok'])) {
                break;
            }
            if (empty($result['retryable']) || $index === count($attempts) - 1) {
                break;
            }
        }

        $row = is_array($result['row'] ?? null)
            ? $result['row']
            : self::failureRow($now, $detected['backend'], $detected['version'], 'Failed to collect speedtest result');
        $durationMs = (int)($result['duration_ms'] ?? null);
        $lastBackend = (string)($result['backend'] ?? $detected['backend']);
        $lastVersion = (string)($result['version'] ?? $detected['version']);

        if ($row['status'] === 'success' || $settings['log_failed_tests']) {
            $historyWrite = self::appendHistoryRow($row, $settings);
            if (!$historyWrite['ok']) {
                $row = self::normalizeStoredRow(array_merge($row, [
                    'status' => 'failure',
                    'error_message' => self::combineErrorMessages((string)($row['error_message'] ?? ''), $historyWrite['error']),
                ]));
            }
        }

        $metaPatch = [
            'last_attempted_at' => $now,
            'last_backend' => $lastBackend,
            'last_backend_version' => $lastVersion,
            'last_error' => $row['status'] === 'success' ? '' : (string)$row['error_message'],
            'last_duration_ms' => $durationMs,
            'next_due_at' => self::scheduleNextDue($settings, $now),
        ];
        if ($row['status'] === 'success') {
            $metaPatch['last_success_at'] = $now;
        }
        $meta = self::saveMeta($metaPatch);

        return [
            'ok' => $row['status'] === 'success',
            'ran' => true,
            'result' => $row,
            'meta' => $meta,
        ];
    }

    public static function readHistory(array $filters): array
    {
        $path = self::historyPath();
        $rows = [];
        $servers = [];
        $invalid = 0;
        if (!is_file($path)) {
            return ['rows' => [], 'servers' => [], 'invalid_lines' => 0];
        }
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            throw new \RuntimeException('Speedtest history file is unreadable');
        }
        while (!feof($fh)) {
            $line = fgets($fh);
            if ($line === false) {
                break;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row)) {
                $invalid++;
                continue;
            }
            $row = self::normalizeStoredRow($row);
            if ($row['timestamp_ts'] < $filters['since_ts']) {
                continue;
            }
            if (!$filters['include_failed'] && $row['status'] !== 'success') {
                continue;
            }
            if ($filters['server'] !== '' && self::serverKey($row) !== $filters['server']) {
                continue;
            }
            $rows[] = $row;
            if ($row['server_name'] !== '' || $row['server_location'] !== '' || $row['server_id'] !== '') {
                $key = self::serverKey($row);
                $servers[$key] = [
                    'value' => $key,
                    'label' => self::serverLabel($row),
                    'server_id' => $row['server_id'],
                    'server_name' => $row['server_name'],
                    'server_location' => $row['server_location'],
                ];
            }
        }
        fclose($fh);
        ksort($servers, SORT_NATURAL | SORT_FLAG_CASE);
        return [
            'rows' => $rows,
            'servers' => array_values($servers),
            'invalid_lines' => $invalid,
        ];
    }

    public static function buildPayload(array $filters): array
    {
        $history = self::readHistory($filters);
        $rows = $history['rows'];
        return [
            'filters' => $filters,
            'rows' => $rows,
            'servers' => $history['servers'],
            'summary' => self::buildSummary($rows),
            'charts' => self::buildCharts($rows),
            'invalid_lines' => $history['invalid_lines'],
        ];
    }

    public static function csvRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'timestamp' => $row['timestamp'],
                'status' => $row['status'],
                'backend' => $row['backend'],
                'server_id' => $row['server_id'],
                'server_name' => $row['server_name'],
                'server_location' => $row['server_location'],
                'ping_ms' => $row['ping_ms'],
                'jitter_ms' => $row['jitter_ms'],
                'download_mbps' => $row['download_mbps'],
                'upload_mbps' => $row['upload_mbps'],
                'packet_loss' => $row['packet_loss'],
                'duration_ms' => $row['duration_ms'],
                'error_message' => $row['error_message'],
                'raw_tool_version' => $row['raw_tool_version'],
            ];
        }
        return $out;
    }

    private static function normalizeBackend(string $backend): string
    {
        $backend = strtolower(trim($backend));
        if ($backend === 'speedtest') {
            return 'ookla';
        }
        if (!in_array($backend, ['auto', 'ookla', 'librespeed'], true)) {
            return 'auto';
        }
        return $backend;
    }

    private static function sanitizePreferredServer($value): string
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 200) {
            return '';
        }
        return preg_match('/^[A-Za-z0-9._:\\/-]+$/', $value) ? $value : '';
    }

    private static function sanitizeServerFilter($value): string
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > 240) {
            return '';
        }
        return preg_match('/^[A-Za-z0-9._:\\/-]+$/', $value) ? $value : '';
    }

    private static function discoverBinary(array $names, array $commonPaths): string
    {
        foreach ($commonPaths as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        foreach ($names as $name) {
            $cmd = 'command -v ' . escapeshellarg($name) . ' 2>/dev/null';
            $found = @shell_exec($cmd);
            if (is_string($found)) {
                $found = trim($found);
                if ($found !== '' && is_file($found) && is_executable($found)) {
                    return $found;
                }
            }
        }
        return '';
    }

    private static function readToolVersion(string $binary, string $backend): array
    {
        $res = self::execCommand(escapeshellarg($binary) . ' --version', 4);
        if (!$res['ok']) {
            return [
                'version' => '',
                'mode' => $backend,
            ];
        }
        $versionOutput = trim($res['stdout']) !== '' ? $res['stdout'] : $res['stderr'];
        $line = trim(strtok($versionOutput, "\r\n"));
        $line = mb_substr($line, 0, 200);
        return [
            'version' => $line,
            'mode' => self::detectBinaryMode($backend, $line),
        ];
    }

    private static function detectBinaryMode(string $backend, string $versionLine): string
    {
        if ($backend !== 'ookla') {
            return $backend;
        }
        $version = strtolower($versionLine);
        if ($version !== '' && (str_contains($version, 'speedtest-cli') || str_contains($version, 'python'))) {
            return 'speedtest-cli';
        }
        return 'ookla';
    }

    private static function shouldRun(array $settings, array $meta, int $now, bool $force): array
    {
        if ($force) {
            return ['run' => true, 'reason' => 'forced'];
        }
        if (empty($settings['enabled'])) {
            return ['run' => false, 'reason' => 'disabled'];
        }
        $nextDue = isset($meta['next_due_at']) ? (int)$meta['next_due_at'] : 0;
        if ($nextDue > $now) {
            return ['run' => false, 'reason' => 'interval', 'next_due_at' => $nextDue];
        }
        if (self::isWithinQuietHours($settings, $now)) {
            return ['run' => false, 'reason' => 'quiet_hours', 'next_due_at' => self::quietHoursEnd($settings, $now)];
        }
        $lastAttempt = isset($meta['last_attempted_at']) ? (int)$meta['last_attempted_at'] : 0;
        $fallbackDue = $lastAttempt > 0 ? ($lastAttempt + ((int)$settings['interval_minutes'] * 60)) : 0;
        if ($fallbackDue > $now) {
            return ['run' => false, 'reason' => 'interval', 'next_due_at' => $fallbackDue];
        }
        return ['run' => true, 'reason' => 'due'];
    }

    private static function buildCommand(string $backend, string $binary, string $preferredServer, string $format = 'json'): string
    {
        $parts = [escapeshellarg($binary)];
        if ($backend === 'ookla') {
            $parts[] = '--accept-license';
            $parts[] = '--accept-gdpr';
            $parts[] = '--format=json';
            if ($preferredServer !== '' && ctype_digit($preferredServer)) {
                $parts[] = '--server-id=' . escapeshellarg($preferredServer);
            }
        } elseif ($backend === 'speedtest-cli') {
            $parts[] = '--json';
            if ($preferredServer !== '' && ctype_digit($preferredServer)) {
                $parts[] = '--server';
                $parts[] = escapeshellarg($preferredServer);
            }
        } elseif ($backend === 'librespeed') {
            $parts[] = $format === 'csv' ? '--csv' : '--json';
            if ($preferredServer !== '') {
                $parts[] = '--server';
                $parts[] = escapeshellarg($preferredServer);
            }
        }
        return implode(' ', $parts);
    }

    private static function preferredServerForBackend(array $settings, string $backend): string
    {
        if ($backend === 'librespeed') {
            return self::sanitizePreferredServer($settings['preferred_server_librespeed'] ?? '');
        }
        return self::sanitizePreferredServer(
            $settings['preferred_server_ookla'] ?? ($settings['preferred_server'] ?? '')
        );
    }

    private static function performCollectionAttempt(
        string $backend,
        string $binary,
        string $version,
        string $preferredServer,
        int $now,
        int $timeoutSeconds,
        string $note = ''
    ): array {
        $command = self::buildCommand($backend, $binary, $preferredServer);
        $started = microtime(true);
        $exec = self::execCommand($command, $timeoutSeconds);
        $durationMs = (int)round((microtime(true) - $started) * 1000);

        if (!$exec['ok']) {
            $message = self::prependAttemptNote((string)$exec['error'], $note);
            return [
                'ok' => false,
                'backend' => $backend,
                'version' => $version,
                'duration_ms' => $durationMs,
                'retryable' => self::isRetryableBackendFailure($backend, $message),
                'row' => self::failureRow($now, $backend, $version, $message, $durationMs),
            ];
        }

        $decoded = self::decodeCommandJson($exec['stdout']);
        if (!is_array($decoded)) {
            $message = self::prependAttemptNote(
                self::buildUnreadableJsonMessage($exec['stdout'], $exec['stderr']),
                $note
            );
            return [
                'ok' => false,
                'backend' => $backend,
                'version' => $version,
                'duration_ms' => $durationMs,
                'retryable' => self::isRetryableBackendFailure($backend, $message),
                'row' => self::failureRow($now, $backend, $version, $message, $durationMs),
            ];
        }

        try {
            $row = self::normalizeResult($backend, $decoded, $now, $durationMs, $version);
        } catch (\Throwable $e) {
            $row = self::failureRow(
                $now,
                $backend,
                $version,
                self::prependAttemptNote('Failed to normalize speedtest result', $note),
                $durationMs
            );
        }

        if ($backend === 'librespeed' && self::rowHasNoSpeedMetrics($row)) {
            $csvRow = self::performLibrespeedCsvFallback(
                $binary,
                $version,
                $preferredServer,
                $now,
                $timeoutSeconds,
                $note
            );
            if ($csvRow !== null) {
                $row = $csvRow['row'];
                $durationMs = $csvRow['duration_ms'];
            }
        }

        return [
            'ok' => $row['status'] === 'success',
            'backend' => $backend,
            'version' => $version,
            'duration_ms' => $durationMs,
            'retryable' => $row['status'] !== 'success' && self::isRetryableBackendFailure($backend, (string)$row['error_message']),
            'row' => $row,
        ];
    }

    private static function performLibrespeedCsvFallback(
        string $binary,
        string $version,
        string $preferredServer,
        int $now,
        int $timeoutSeconds,
        string $note
    ): ?array {
        $command = self::buildCommand('librespeed', $binary, $preferredServer, 'csv');
        $started = microtime(true);
        $exec = self::execCommand($command, $timeoutSeconds);
        $durationMs = (int)round((microtime(true) - $started) * 1000);
        if (!$exec['ok']) {
            return null;
        }
        $row = self::decodeLibrespeedCsvRow($exec['stdout'], $now, $durationMs, $version, $note);
        if ($row === null || self::rowHasNoSpeedMetrics($row)) {
            return null;
        }
        return [
            'row' => $row,
            'duration_ms' => $durationMs,
        ];
    }

    private static function decodeLibrespeedCsvRow(
        string $output,
        int $timestamp,
        int $durationMs,
        string $version,
        string $note
    ): ?array {
        $lines = preg_split('/\R+/', trim(self::sanitizeCommandOutput(self::stripUtf8Bom($output)))) ?: [];
        $line = '';
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (trim($lines[$i]) !== '') {
                $line = trim($lines[$i]);
                break;
            }
        }
        if ($line === '') {
            return null;
        }
        $fields = str_getcsv($line);
        if (count($fields) < 7) {
            return null;
        }
        return self::normalizeStoredRow([
            'timestamp' => trim((string)($fields[0] ?? '')) ?: gmdate('c', $timestamp),
            'status' => 'success',
            'backend' => 'librespeed',
            'server_name' => trim((string)($fields[1] ?? '')),
            'server_location' => trim((string)($fields[2] ?? '')),
            'ping_ms' => self::toFloat($fields[3] ?? null),
            'jitter_ms' => self::toFloat($fields[4] ?? null),
            'download_mbps' => self::coerceMbps($fields[5] ?? null),
            'upload_mbps' => self::coerceMbps($fields[6] ?? null),
            'packet_loss' => null,
            'duration_ms' => $durationMs,
            'error_message' => self::prependAttemptNote('', $note),
            'raw_tool_version' => $version,
        ]);
    }

    private static function rowHasNoSpeedMetrics(array $row): bool
    {
        return ($row['ping_ms'] ?? null) === null
            && ($row['jitter_ms'] ?? null) === null
            && ($row['download_mbps'] ?? null) === null
            && ($row['upload_mbps'] ?? null) === null
            && ($row['server_name'] ?? '') === ''
            && ($row['server_location'] ?? '') === '';
    }

    private static function prependAttemptNote(string $message, string $note): string
    {
        $note = trim($note);
        $message = trim($message);
        if ($note === '') {
            return $message;
        }
        if ($message === '') {
            return $note;
        }
        return $note . ': ' . $message;
    }

    private static function combineErrorMessages(string $primary, string $secondary): string
    {
        $primary = trim($primary);
        $secondary = trim($secondary);
        if ($primary === '') {
            return $secondary;
        }
        if ($secondary === '') {
            return $primary;
        }
        return $primary . ' | ' . $secondary;
    }

    private static function isRetryableBackendFailure(string $backend, string $message): bool
    {
        $message = strtolower(trim($message));
        if ($message === '') {
            return false;
        }
        if ($backend === 'speedtest-cli') {
            return str_contains($message, 'no matched servers')
                || str_contains($message, 'cannot retrieve speedtest configuration')
                || str_contains($message, 'http error 403')
                || str_contains($message, 'forbidden');
        }
        return false;
    }

    private static function execCommand(string $command, int $timeoutSeconds): array
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($command, $spec, $pipes, self::projectRoot());
        if (!is_resource($proc)) {
            return ['ok' => false, 'stdout' => '', 'stderr' => '', 'error' => 'Failed to start speedtest process'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        do {
            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }
            if (!$read) {
                break;
            }
            $write = null;
            $except = null;
            $seconds = max(0, (int)floor($deadline - microtime(true)));
            $micro = 200000;
            @stream_select($read, $write, $except, $seconds, $micro);
            foreach ($read as $stream) {
                $chunk = stream_get_contents($stream);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return ['ok' => false, 'stdout' => $stdout, 'stderr' => $stderr, 'error' => 'Speedtest timed out'];
            }
        } while (true);

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            $message = trim($stderr) !== '' ? trim($stderr) : 'Speedtest CLI exited with code ' . $code;
            return ['ok' => false, 'stdout' => $stdout, 'stderr' => $stderr, 'error' => mb_substr($message, 0, 500)];
        }
        return ['ok' => true, 'stdout' => $stdout, 'stderr' => $stderr, 'error' => ''];
    }

    private static function decodeCommandJson(string $output): ?array
    {
        $trimmed = trim(self::sanitizeCommandOutput(self::stripUtf8Bom($output)));
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $lineDecoded = self::decodeLineDelimitedJson($trimmed);
        if (is_array($lineDecoded)) {
            return $lineDecoded;
        }

        $payload = self::extractJsonPayload($trimmed);
        if ($payload === null) {
            return null;
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function stripUtf8Bom(string $value): string
    {
        if (strncmp($value, "\xEF\xBB\xBF", 3) === 0) {
            return substr($value, 3);
        }
        return $value;
    }

    private static function sanitizeCommandOutput(string $value): string
    {
        $value = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $value) ?? $value;
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? $value;
        return $value;
    }

    private static function decodeLineDelimitedJson(string $output): ?array
    {
        $lines = preg_split('/\R+/', $output) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '' || ($line[0] !== '{' && $line[0] !== '[')) {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    private static function buildUnreadableJsonMessage(string $stdout, string $stderr): string
    {
        $message = 'Speedtest CLI returned unreadable JSON';
        $snippet = self::firstDiagnosticSnippet($stdout);
        if ($snippet === '') {
            $snippet = self::firstDiagnosticSnippet($stderr);
        }
        if ($snippet !== '') {
            $message .= ': ' . $snippet;
        }
        return $message;
    }

    private static function firstDiagnosticSnippet(string $value): string
    {
        $value = trim(self::sanitizeCommandOutput(self::stripUtf8Bom($value)));
        if ($value === '') {
            return '';
        }
        $lines = preg_split('/\R+/', $value) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            return mb_substr($line, 0, 180);
        }
        return mb_substr($value, 0, 180);
    }

    private static function extractJsonPayload(string $output): ?string
    {
        $length = strlen($output);
        $start = null;
        $endChar = '';
        for ($i = 0; $i < $length; $i++) {
            $char = $output[$i];
            if ($char === '{' || $char === '[') {
                $start = $i;
                $endChar = $char === '{' ? '}' : ']';
                break;
            }
        }
        if ($start === null) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        for ($i = $start; $i < $length; $i++) {
            $char = $output[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }
            if ($char === '{' || $char === '[') {
                $depth++;
                continue;
            }
            if ($char === '}' || $char === ']') {
                $depth--;
                if ($depth === 0 && $char === $endChar) {
                    return substr($output, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private static function normalizeResult(string $backend, array $json, int $timestamp, int $durationMs, string $version): array
    {
        if ($backend === 'ookla') {
            $downloadMbps = self::toMbps($json['download']['bandwidth'] ?? null);
            $uploadMbps = self::toMbps($json['upload']['bandwidth'] ?? null);
            return self::normalizeStoredRow([
                'timestamp' => gmdate('c', $timestamp),
                'status' => 'success',
                'backend' => 'ookla',
                'server_id' => self::scalarText($json['server']['id'] ?? ''),
                'server_name' => self::scalarText($json['server']['name'] ?? ''),
                'server_location' => self::scalarText($json['server']['location'] ?? ''),
                'ping_ms' => self::toFloat($json['ping']['latency'] ?? null),
                'jitter_ms' => self::toFloat($json['ping']['jitter'] ?? null),
                'download_mbps' => $downloadMbps,
                'upload_mbps' => $uploadMbps,
                'packet_loss' => self::toFloat($json['packetLoss'] ?? null),
                'duration_ms' => $durationMs,
                'error_message' => '',
                'raw_tool_version' => $version,
            ]);
        }

        if ($backend === 'speedtest-cli') {
            $server = self::arrayValue($json, ['server']);
            $locationParts = array_values(array_filter([
                self::scalarText($server['name'] ?? ''),
                self::scalarText($server['country'] ?? ''),
            ], static function ($value) {
                return $value !== '';
            }));
            return self::normalizeStoredRow([
                'timestamp' => self::scalarText($json['timestamp'] ?? gmdate('c', $timestamp)),
                'status' => 'success',
                'backend' => 'speedtest-cli',
                'server_id' => self::scalarText($server['id'] ?? ''),
                'server_name' => self::scalarText(self::pickValue([$server['sponsor'] ?? null, $server['host'] ?? null, $server['name'] ?? null])),
                'server_location' => implode(', ', $locationParts),
                'ping_ms' => self::toFloat($json['ping'] ?? null),
                'jitter_ms' => null,
                'download_mbps' => self::coerceMbps($json['download'] ?? null),
                'upload_mbps' => self::coerceMbps($json['upload'] ?? null),
                'packet_loss' => null,
                'duration_ms' => $durationMs,
                'error_message' => '',
                'raw_tool_version' => $version,
            ]);
        }

        $server = self::arrayValue($json, ['server', 'selectedServer', 'serverInfo']);
        return self::normalizeStoredRow([
            'timestamp' => gmdate('c', $timestamp),
            'status' => 'success',
            'backend' => 'librespeed',
            'server_id' => self::scalarText(self::pickValue([$server['id'] ?? null, $json['server_id'] ?? null, $json['serverId'] ?? null])),
            'server_name' => self::scalarText(self::pickValue([$server['name'] ?? null, $server['server'] ?? null, $server['sponsor'] ?? null, $json['server_name'] ?? null, $json['serverName'] ?? null, $json['server'] ?? null, $json['sponsor'] ?? null])),
            'server_location' => self::scalarText(self::pickValue([$server['location'] ?? null, $server['city'] ?? null, $server['country'] ?? null, $json['server_location'] ?? null, $json['serverLocation'] ?? null, $json['location'] ?? null])),
            'ping_ms' => self::toFloat(self::pickValue([$json['ping'] ?? null, $json['latency'] ?? null, $json['ping_ms'] ?? null])),
            'jitter_ms' => self::toFloat(self::pickValue([$json['jitter'] ?? null, $json['jitterMs'] ?? null, $json['jitter_ms'] ?? null])),
            'download_mbps' => self::coerceMbps(self::pickValue([$json['download'] ?? null, $json['dlSpeed'] ?? null, $json['dl_speed'] ?? null, $json['download_mbps'] ?? null, $json['downloadMbps'] ?? null])),
            'upload_mbps' => self::coerceMbps(self::pickValue([$json['upload'] ?? null, $json['ulSpeed'] ?? null, $json['ul_speed'] ?? null, $json['upload_mbps'] ?? null, $json['uploadMbps'] ?? null])),
            'packet_loss' => self::toFloat(self::pickValue([$json['packetLoss'] ?? null, $json['packet_loss'] ?? null, $json['packetLossPercent'] ?? null])),
            'duration_ms' => $durationMs,
            'error_message' => '',
            'raw_tool_version' => $version,
        ]);
    }

    private static function failureRow(int $timestamp, string $backend, string $version, string $message, ?int $durationMs = null): array
    {
        return self::normalizeStoredRow([
            'timestamp' => gmdate('c', $timestamp),
            'status' => 'failure',
            'backend' => $backend,
            'server_id' => '',
            'server_name' => '',
            'server_location' => '',
            'ping_ms' => null,
            'jitter_ms' => null,
            'download_mbps' => null,
            'upload_mbps' => null,
            'packet_loss' => null,
            'duration_ms' => $durationMs,
            'error_message' => mb_substr(trim($message), 0, 500),
            'raw_tool_version' => $version,
        ]);
    }

    private static function appendHistoryRow(array $row, array $settings): array
    {
        $path = self::historyPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return [
                'ok' => false,
                'error' => 'Speedtest history directory is not writable: ' . $dir,
            ];
        }
        $fh = @fopen($path, 'ab');
        if (!$fh) {
            return [
                'ok' => false,
                'error' => 'Failed to open speedtest history file for append: ' . $path,
            ];
        }
        if (!@flock($fh, LOCK_EX)) {
            fclose($fh);
            return [
                'ok' => false,
                'error' => 'Failed to lock speedtest history file: ' . $path,
            ];
        }
        $bytes = @fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        @fflush($fh);
        @flock($fh, LOCK_UN);
        fclose($fh);
        if ($bytes === false) {
            return [
                'ok' => false,
                'error' => 'Failed to write speedtest history file: ' . $path,
            ];
        }
        $prune = self::pruneHistory($settings);
        if (!$prune['ok']) {
            return $prune;
        }
        return ['ok' => true];
    }

    private static function pruneHistory(array $settings): array
    {
        $path = self::historyPath();
        if (!is_file($path)) {
            return ['ok' => true];
        }
        $cutoff = time() - (((int)$settings['retention_days']) * 86400);
        $maxEntries = (int)$settings['max_history_entries'];
        $rows = [];
        $invalid = 0;
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return [
                'ok' => false,
                'error' => 'Speedtest history file is unreadable: ' . $path,
            ];
        }
        while (!feof($fh)) {
            $line = fgets($fh);
            if ($line === false) {
                break;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row)) {
                $invalid++;
                continue;
            }
            $row = self::normalizeStoredRow($row);
            if ($row['timestamp_ts'] < $cutoff) {
                continue;
            }
            $rows[] = $row;
            if (count($rows) > $maxEntries) {
                array_shift($rows);
            }
        }
        fclose($fh);

        $lines = [];
        foreach ($rows as $row) {
            unset($row['timestamp_ts'], $row['server_label']);
            $lines[] = json_encode($row, JSON_UNESCAPED_SLASHES);
        }
        $write = dashboard_atomic_write($path, $lines ? implode(PHP_EOL, $lines) . PHP_EOL : '');
        if (empty($write['ok'])) {
            return [
                'ok' => false,
                'error' => 'Failed to rewrite speedtest history file: ' . ($write['path'] ?? $path),
            ];
        }
        self::saveMeta(['history_invalid_lines' => $invalid]);
        return ['ok' => true];
    }

    private static function normalizeStoredRow(array $row): array
    {
        $timestamp = self::normalizeTimestamp($row['timestamp'] ?? ($row['ts'] ?? ''));
        $server = self::arrayValue($row, ['server', 'selectedServer', 'serverInfo']);
        $out = [
            'timestamp' => $timestamp['iso'],
            'timestamp_ts' => $timestamp['ts'],
            'status' => strtolower((string)($row['status'] ?? 'failure')) === 'success' ? 'success' : 'failure',
            'backend' => self::scalarText($row['backend'] ?? ''),
            'server_id' => self::scalarText(self::pickValue([$row['server_id'] ?? null, $row['serverId'] ?? null, $server['id'] ?? null])),
            'server_name' => self::scalarText(self::pickValue([$row['server_name'] ?? null, $row['serverName'] ?? null, $server['name'] ?? null, $server['server'] ?? null, $row['server'] ?? null, $row['sponsor'] ?? null])),
            'server_location' => self::scalarText(self::pickValue([$row['server_location'] ?? null, $row['serverLocation'] ?? null, $server['location'] ?? null, $server['city'] ?? null, $row['location'] ?? null])),
            'ping_ms' => self::toFloat(self::pickValue([$row['ping_ms'] ?? null, $row['latency'] ?? null, $row['ping'] ?? null])),
            'jitter_ms' => self::toFloat(self::pickValue([$row['jitter_ms'] ?? null, $row['jitterMs'] ?? null, $row['jitter'] ?? null])),
            'download_mbps' => self::coerceMbps(self::pickValue([$row['download_mbps'] ?? null, $row['downloadMbps'] ?? null, $row['dlSpeed'] ?? null, $row['dl_speed'] ?? null, $row['download'] ?? null])),
            'upload_mbps' => self::coerceMbps(self::pickValue([$row['upload_mbps'] ?? null, $row['uploadMbps'] ?? null, $row['ulSpeed'] ?? null, $row['ul_speed'] ?? null, $row['upload'] ?? null])),
            'packet_loss' => self::toFloat(self::pickValue([$row['packet_loss'] ?? null, $row['packetLoss'] ?? null, $row['packetLossPercent'] ?? null])),
            'duration_ms' => self::toIntOrNull($row['duration_ms'] ?? null),
            'error_message' => self::scalarText($row['error_message'] ?? ''),
            'raw_tool_version' => self::scalarText($row['raw_tool_version'] ?? ''),
        ];
        $out['server_label'] = self::serverLabel($out);
        return $out;
    }

    private static function buildSummary(array $rows): array
    {
        $successes = array_values(array_filter($rows, static function ($row) {
            return ($row['status'] ?? '') === 'success';
        }));
        $latest = $rows ? $rows[count($rows) - 1] : null;
        $latestSuccess = $successes ? $successes[count($successes) - 1] : null;
        $successCount = count($successes);
        $failureCount = count($rows) - $successCount;
        $avgDownload = self::average($successes, 'download_mbps');
        $avgUpload = self::average($successes, 'upload_mbps');
        $best = self::pickBy($successes, 'download_mbps', true);
        $worst = self::pickBy($successes, 'download_mbps', false);

        return [
            'latest_result' => $latest,
            'latest_download_mbps' => $latestSuccess['download_mbps'] ?? null,
            'latest_upload_mbps' => $latestSuccess['upload_mbps'] ?? null,
            'latest_ping_ms' => $latestSuccess['ping_ms'] ?? null,
            'average_download_mbps' => $avgDownload,
            'average_upload_mbps' => $avgUpload,
            'best_result' => $best,
            'worst_result' => $worst,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'last_successful_test' => $latestSuccess['timestamp'] ?? null,
        ];
    }

    private static function buildCharts(array $rows): array
    {
        $out = [
            'download' => [],
            'upload' => [],
            'ping' => [],
            'jitter' => [],
            'packet_loss' => [],
        ];
        foreach ($rows as $row) {
            $base = [
                'ts' => $row['timestamp_ts'],
                'timestamp' => $row['timestamp'],
                'status' => $row['status'],
            ];
            foreach ([
                'download' => 'download_mbps',
                'upload' => 'upload_mbps',
                'ping' => 'ping_ms',
                'jitter' => 'jitter_ms',
                'packet_loss' => 'packet_loss',
            ] as $series => $key) {
                $out[$series][] = $base + ['value' => $row[$key]];
            }
        }
        return $out;
    }

    private static function average(array $rows, string $key): ?float
    {
        $sum = 0.0;
        $count = 0;
        foreach ($rows as $row) {
            if ($row[$key] === null) {
                continue;
            }
            $sum += (float)$row[$key];
            $count++;
        }
        if ($count === 0) {
            return null;
        }
        return round($sum / $count, 2);
    }

    private static function pickBy(array $rows, string $key, bool $highest): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            if ($row[$key] === null) {
                continue;
            }
            if ($best === null) {
                $best = $row;
                continue;
            }
            if ($highest && $row[$key] > $best[$key]) {
                $best = $row;
            }
            if (!$highest && $row[$key] < $best[$key]) {
                $best = $row;
            }
        }
        return $best;
    }

    private static function normalizeTimestamp($value): array
    {
        $ts = is_numeric($value) ? (int)$value : strtotime((string)$value);
        if ($ts <= 0) {
            $ts = time();
        }
        return [
            'ts' => $ts,
            'iso' => gmdate('c', $ts),
        ];
    }

    private static function toMbps($bytesPerSecond): ?float
    {
        if (!is_numeric($bytesPerSecond)) {
            return null;
        }
        return round((((float)$bytesPerSecond) * 8) / 1000000, 2);
    }

    private static function coerceMbps($value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $value = (float)$value;
        if ($value > 100000) {
            return round($value / 1000000, 2);
        }
        return round($value, 2);
    }

    private static function toFloat($value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return round((float)$value, 2);
    }

    private static function toIntOrNull($value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (int)$value;
    }

    private static function scalarText($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return mb_substr(trim((string)$value), 0, 200);
    }

    private static function pickValue(array $values)
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }
            return $value;
        }
        return null;
    }

    private static function arrayValue(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_array($row[$key])) {
                return $row[$key];
            }
        }
        return [];
    }

    private static function serverKey(array $row): string
    {
        $serverId = trim((string)($row['server_id'] ?? ''));
        if ($serverId !== '') {
            return $serverId;
        }
        $name = trim((string)($row['server_name'] ?? ''));
        $location = trim((string)($row['server_location'] ?? ''));
        $combo = trim($name . '|' . $location, '|');
        if ($combo !== '') {
            return preg_replace('/[^A-Za-z0-9._:\\/-]+/', '-', strtolower($combo));
        }
        return '';
    }

    private static function serverLabel(array $row): string
    {
        $parts = [];
        if (($row['server_name'] ?? '') !== '') {
            $parts[] = $row['server_name'];
        }
        if (($row['server_location'] ?? '') !== '') {
            $parts[] = $row['server_location'];
        }
        if (!$parts && ($row['server_id'] ?? '') !== '') {
            $parts[] = 'Server ' . $row['server_id'];
        }
        return implode(' - ', $parts);
    }

    private static function scheduleNextDue(array $settings, int $now): int
    {
        $next = $now + (((int)$settings['interval_minutes']) * 60);
        if (!empty($settings['randomize_schedule_window'])) {
            $window = max(0, (int)$settings['randomize_window_minutes']) * 60;
            if ($window > 0) {
                $next += random_int(0, $window);
            }
        }
        if (self::isWithinQuietHours($settings, $next)) {
            $next = self::quietHoursEnd($settings, $next);
        }
        return $next;
    }

    private static function estimateNextDue(array $settings, array $meta, int $now): ?int
    {
        if (empty($settings['enabled'])) {
            return null;
        }
        $nextDue = isset($meta['next_due_at']) ? (int)$meta['next_due_at'] : 0;
        if ($nextDue > 0) {
            return $nextDue;
        }
        $lastAttempt = isset($meta['last_attempted_at']) ? (int)$meta['last_attempted_at'] : 0;
        if ($lastAttempt > 0) {
            $nextDue = $lastAttempt + (((int)$settings['interval_minutes']) * 60);
            if (self::isWithinQuietHours($settings, $nextDue)) {
                $nextDue = self::quietHoursEnd($settings, $nextDue);
            }
            return $nextDue;
        }
        if (self::isWithinQuietHours($settings, $now)) {
            return self::quietHoursEnd($settings, $now);
        }
        return $now;
    }

    private static function isWithinQuietHours(array $settings, int $timestamp): bool
    {
        $quiet = $settings['quiet_hours'] ?? [];
        if (empty($quiet['enabled'])) {
            return false;
        }
        $start = (int)($quiet['start_hour'] ?? 0);
        $end = (int)($quiet['end_hour'] ?? 0);
        if ($start === $end) {
            return false;
        }
        $tz = self::timezone();
        $dt = new \DateTimeImmutable('@' . $timestamp);
        $hour = (int)$dt->setTimezone($tz)->format('G');
        if ($start < $end) {
            return $hour >= $start && $hour < $end;
        }
        return $hour >= $start || $hour < $end;
    }

    private static function quietHoursEnd(array $settings, int $timestamp): int
    {
        $quiet = $settings['quiet_hours'] ?? [];
        $end = (int)($quiet['end_hour'] ?? 0);
        $tz = self::timezone();
        $dt = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
        $endLocal = $dt->setTime($end, 0, 0);
        if ($endLocal <= $dt) {
            $endLocal = $endLocal->modify('+1 day');
        }
        $utc = $endLocal->setTimezone(new \DateTimeZone('UTC'));
        $next = $utc->getTimestamp();
        if ($next <= 0) {
            return $timestamp + 3600;
        }
        return $next;
    }

    private static function timezone(): \DateTimeZone
    {
        self::ensureConfig();
        $name = (string)Config::get('site.timezone', 'UTC');
        try {
            return new \DateTimeZone($name);
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }
}
