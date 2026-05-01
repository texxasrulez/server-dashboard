<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/Config.php';

final class RoundDavBookmarks
{
    private const REQUEST_TIMEOUT = 15;
    private const CONNECT_TIMEOUT = 5;

    private static bool $configReady = false;

    private static function configInit(): void
    {
        if (self::$configReady) {
            return;
        }

        \App\Config::init(dirname(__DIR__));
        self::$configReady = true;
    }

    public static function defaultSource(): string
    {
        self::configInit();
        $source = strtolower((string) \App\Config::get('bookmarks.default_source', 'local'));
        return $source === 'rounddav' ? 'rounddav' : 'local';
    }

    public static function settings(): array
    {
        self::configInit();

        $settings = \App\Config::get('bookmarks.rounddav', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $visibility = strtolower((string) ($settings['visibility'] ?? 'private'));

        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'base_url' => trim((string) ($settings['base_url'] ?? '')),
            'username' => trim((string) ($settings['username'] ?? '')),
            'password' => (string) ($settings['password'] ?? ''),
            'visibility' => $visibility === 'shared' ? 'shared' : 'private',
        ];
    }

    public static function isConfigured(): bool
    {
        $settings = self::settings();
        return $settings['enabled'] && $settings['base_url'] !== '' && $settings['username'] !== '' && $settings['password'] !== '';
    }

    public static function summary(): array
    {
        $settings = self::settings();
        return [
            'enabled' => $settings['enabled'],
            'configured' => self::isConfigured(),
            'visibility' => $settings['visibility'],
            'base_url' => $settings['base_url'],
            'username' => $settings['username'],
        ];
    }

    private static function assertConfigured(): array
    {
        $settings = self::settings();
        if (!$settings['enabled']) {
            throw new RuntimeException('RoundDAV bookmarks are not enabled in config.');
        }
        if ($settings['base_url'] === '' || $settings['username'] === '' || $settings['password'] === '') {
            throw new RuntimeException('RoundDAV bookmarks are not fully configured.');
        }
        return $settings;
    }

    private static function normalizeApiBase(string $serverUrl): string
    {
        $parts = parse_url($serverUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('Invalid RoundDAV base URL.');
        }

        $path = $parts['path'] ?? '';
        if (preg_match('#/api\.php$#', $path)) {
            $apiPath = $path;
        } elseif (preg_match('#/public/?$#', $path)) {
            $apiPath = rtrim($path, '/') . '/api.php';
        } else {
            $apiPath = rtrim($path, '/') . '/public/api.php';
        }

        $url = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $url .= ':' . (int) $parts['port'];
        }
        $url .= $apiPath;
        return $url;
    }

    private static function routeUrl(array $settings, string $route): string
    {
        return self::normalizeApiBase($settings['base_url']) . '?r=' . rawurlencode($route);
    }

    private static function authHeader(array $settings): string
    {
        return 'Authorization: Basic ' . base64_encode($settings['username'] . ':' . $settings['password']);
    }

    private static function request(string $route, array $payload = []): array
    {
        $settings = self::assertConfigured();
        $url = self::routeUrl($settings, $route);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode RoundDAV request payload.');
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            self::authHeader($settings),
        ];

        $body = false;
        $status = 0;
        $error = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($body === false) {
                $error = (string) curl_error($ch);
            }
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'timeout' => self::REQUEST_TIMEOUT,
                    'ignore_errors' => true,
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'content' => $json,
                ],
            ]);
            $body = @file_get_contents($url, false, $context);
            if ($body === false) {
                $error = 'Network request failed';
            }
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $line) {
                    if (preg_match('#\s(\d{3})\s#', $line, $m)) {
                        $status = (int) $m[1];
                        break;
                    }
                }
            }
        }

        if ($body === false) {
            throw new RuntimeException('RoundDAV request failed: ' . ($error !== '' ? $error : 'unknown network error'));
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new RuntimeException('RoundDAV returned non-JSON data.');
        }

        if ($status >= 400 || ($data['status'] ?? '') !== 'ok') {
            $message = trim((string) ($data['message'] ?? ''));
            if ($message === '') {
                $message = 'RoundDAV request failed for ' . $route . '.';
            }
            throw new RuntimeException($message);
        }

        return $data;
    }

    private static function normalizeTimestamp($value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $ts = strtotime($value);
            if ($ts !== false) {
                return (int) $ts;
            }
        }
        return 0;
    }

    private static function normalizeFolderTree(array $folders): array
    {
        $normalized = [];
        foreach ($folders as $folder) {
            if (!is_array($folder)) {
                continue;
            }
            $id = (string) ($folder['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $normalized[$id] = [
                'id' => $id,
                'name' => trim((string) ($folder['name'] ?? '')),
                'parent_id' => (string) ($folder['parent_id'] ?? ''),
                'updated' => self::normalizeTimestamp($folder['updated_at'] ?? null),
                'sort' => (int) ($folder['sort_order'] ?? 0),
                'full_name' => '',
            ];
        }

        $resolvePath = static function ($id) use (&$resolvePath, &$normalized): string {
            $id = (string) $id;
            if (!isset($normalized[$id])) {
                return '';
            }
            if ($normalized[$id]['full_name'] !== '') {
                return $normalized[$id]['full_name'];
            }
            $name = $normalized[$id]['name'] !== '' ? $normalized[$id]['name'] : 'Folder';
            $parentId = $normalized[$id]['parent_id'];
            if ($parentId === '' || !isset($normalized[$parentId]) || $parentId === $id) {
                $normalized[$id]['full_name'] = $name;
                return $normalized[$id]['full_name'];
            }
            $parentPath = $resolvePath($parentId);
            $normalized[$id]['full_name'] = $parentPath !== '' ? $parentPath . ' / ' . $name : $name;
            return $normalized[$id]['full_name'];
        };

        foreach (array_keys($normalized) as $id) {
            $resolvePath($id);
        }

        return array_values($normalized);
    }

    private static function mapBookmark(array $bookmark, array $foldersById): ?array
    {
        $id = (string) ($bookmark['id'] ?? '');
        $url = trim((string) ($bookmark['url'] ?? ''));
        if ($id === '' || $url === '') {
            return null;
        }

        $folderId = (string) ($bookmark['folder_id'] ?? '');
        $folder = $folderId !== '' && isset($foldersById[$folderId]) ? $foldersById[$folderId] : null;
        $host = (string) parse_url($url, PHP_URL_HOST);
        $tags = $bookmark['tags'] ?? [];
        if (is_string($tags)) {
            $tags = array_values(array_filter(array_map('trim', explode(',', $tags))));
        }
        if (!is_array($tags)) {
            $tags = [];
        }

        return [
            'id' => $id,
            'title' => trim((string) ($bookmark['title'] ?? '')) ?: ($host !== '' ? $host : $url),
            'url' => $url,
            'tags' => $tags,
            'host' => $host,
            'favicon' => '',
            'category_id' => $folderId !== '' ? $folderId : null,
            'folder_id' => $folderId !== '' ? $folderId : null,
            'folder_name' => $folder['name'] ?? '',
            'folder_path' => $folder['full_name'] ?? '',
            'created' => self::normalizeTimestamp($bookmark['created_at'] ?? null),
            'updated' => self::normalizeTimestamp($bookmark['updated_at'] ?? null),
            'description' => trim((string) ($bookmark['description'] ?? '')),
            'source' => 'rounddav',
        ];
    }

    public static function listBookmarks(): array
    {
        $settings = self::assertConfigured();
        $response = self::request('bookmarks/list', ['include_shared' => true]);
        $data = $response['data'] ?? [];
        $visibility = $settings['visibility'];
        $folderItems = self::normalizeFolderTree($data['folders'][$visibility] ?? []);

        $foldersById = [];
        foreach ($folderItems as $folder) {
            $foldersById[(string) $folder['id']] = $folder;
        }

        $items = [];
        foreach (($data['bookmarks'][$visibility] ?? []) as $bookmark) {
            if (!is_array($bookmark)) {
                continue;
            }
            $mapped = self::mapBookmark($bookmark, $foldersById);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }

        return [
            'visibility' => $visibility,
            'folders' => $folderItems,
            'items' => $items,
        ];
    }

    public static function listFolders(): array
    {
        return self::listBookmarks()['folders'];
    }

    public static function upsertBookmark(array $bookmark): array
    {
        $settings = self::assertConfigured();
        $route = !empty($bookmark['id']) ? 'bookmarks/update' : 'bookmarks/create';
        $payload = [
            'title' => trim((string) ($bookmark['title'] ?? '')),
            'url' => trim((string) ($bookmark['url'] ?? '')),
            'tags' => array_values(array_filter(array_map('strval', is_array($bookmark['tags'] ?? null) ? $bookmark['tags'] : []))),
            'visibility' => $settings['visibility'],
        ];

        if (!empty($bookmark['id'])) {
            $payload['id'] = (int) $bookmark['id'];
        }

        $folderId = $bookmark['folder_id'] ?? $bookmark['category_id'] ?? null;
        if ($folderId !== null && $folderId !== '') {
            $payload['folder_id'] = (int) $folderId;
        }

        $response = self::request($route, $payload);
        $saved = $response['bookmark'] ?? null;
        if (!is_array($saved)) {
            throw new RuntimeException('RoundDAV did not return the saved bookmark.');
        }

        $folderLookup = [];
        foreach (self::listFolders() as $folder) {
            $folderLookup[(string) $folder['id']] = $folder;
        }

        $mapped = self::mapBookmark($saved, $folderLookup);
        if ($mapped === null) {
            throw new RuntimeException('Failed to normalize the saved RoundDAV bookmark.');
        }

        return $mapped;
    }

    public static function deleteBookmark(string $id): void
    {
        self::assertConfigured();
        if ($id === '') {
            throw new RuntimeException('RoundDAV bookmark id is required.');
        }
        self::request('bookmarks/delete', ['id' => (int) $id]);
    }
}
