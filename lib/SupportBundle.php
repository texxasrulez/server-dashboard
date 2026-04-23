<?php

declare(strict_types=1);

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/AuditLog.php";
require_once __DIR__ . "/BackupVerifier.php";
require_once __DIR__ . "/IncidentManager.php";
require_once __DIR__ . "/Redaction.php";

final class SupportBundle
{
    public static function build(): array
    {
        $dir = STATE_DIR . "/support_bundles";
        ensure_dir($dir);
        $stamp = date("Ymd_His");
        $zipPath = $dir . "/support_bundle_" . $stamp . ".zip";

        try {
            if (!class_exists("ZipArchive")) {
                throw new RuntimeException("ZipArchive extension is required for support bundles.");
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Failed to create support bundle archive.");
            }

            $manifest = [
                "generated_at" => date("c"),
                "redaction" => "Secrets are redacted in persisted bundle artifacts by default.",
                "contents" => [
                    "summary.txt",
                    "config_summary.json",
                    "services.json",
                    "incidents.json",
                    "audit.json",
                    "backup_verification.json",
                    "logs/alerts_events.tail.jsonl",
                ],
            ];

            $zip->addFromString("manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->addFromString("summary.txt", self::summaryText());
            $zip->addFromString("config_summary.json", json_encode(self::configSummary(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->addFromString("services.json", json_encode(self::servicesSummary(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->addFromString("incidents.json", json_encode(IncidentManager::recent(50), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->addFromString("audit.json", json_encode(AuditLog::tail(200), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->addFromString("backup_verification.json", json_encode(BackupVerifier::history(20), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->addFromString("logs/alerts_events.tail.jsonl", self::tailFile(dashboard_state_path("alerts_events.jsonl"), 80));

            $zip->close();

            AuditLog::record(
                "bundle.generate",
                basename($zipPath),
                true,
                ["path" => $zipPath],
                "support bundle generated",
                "bundle",
            );

            return [
                "path" => $zipPath,
                "filename" => basename($zipPath),
                "download" => project_url("/api/support_bundle.php?file=" . rawurlencode(basename($zipPath))),
            ];
        } catch (Throwable $e) {
            @unlink($zipPath);
            AuditLog::record(
                "bundle.generate",
                basename($zipPath),
                false,
                ["error" => $e->getMessage()],
                "support bundle generation failed",
                "bundle",
            );
            throw $e;
        }
    }

    public static function downloadPath(string $file): ?string
    {
        $name = basename($file);
        if ($name === "" || !preg_match('/^support_bundle_\d{8}_\d{6}\.zip$/', $name)) {
            return null;
        }
        $path = STATE_DIR . "/support_bundles/" . $name;
        return is_file($path) ? $path : null;
    }

    private static function summaryText(): string
    {
        return implode("\n", [
            "Server Dashboard Support Bundle",
            "Generated: " . date("c"),
            "Redaction: secrets are redacted in bundle files by default.",
            "Contents: config summary, services, incidents, audit trail, backup verification, and selected log tails.",
        ]) . "\n";
    }

    private static function configSummary(): array
    {
        $path = BASE_DIR . "/config/local.json";
        $cfg = is_file($path) ? json_decode((string) @file_get_contents($path), true) : [];
        return is_array($cfg) ? Redaction::redactTree($cfg) : [];
    }

    private static function servicesSummary(): array
    {
        $path = DATA_DIR . "/services.json";
        $payload = is_file($path) ? json_decode((string) @file_get_contents($path), true) : [];
        return is_array($payload) ? $payload : ["items" => []];
    }

    private static function tailFile(string $path, int $lines): string
    {
        if (!is_file($path)) {
            return "";
        }
        $raw = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($raw)) {
            return "";
        }
        $tail = array_slice($raw, -max(1, $lines));
        $tail = array_map(function ($line): string {
            return Redaction::redactText((string) $line);
        }, $tail);
        return implode("\n", $tail) . "\n";
    }
}
