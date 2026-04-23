<?php

namespace App\Nvme;

final class NvmeCommandRunner
{
    public static function runSmartctl(string $device): array
    {
        $binary = self::resolveSmartctlBinary();
        $command = escapeshellcmd($binary) . " -a " . escapeshellarg($device) . " 2>&1";
        $lines = [];
        $exitCode = 0;
        if (!self::isProcessFunctionAvailable("exec")) {
            return [
                "output" => "",
                "exit_code" => null,
                "error" => "PHP exec() is unavailable or disabled",
            ];
        }
        @\exec($command, $lines, $exitCode);
        $output = implode("\n", $lines);
        if ($output === "" && $exitCode === 127) {
            return [
                "output" => "",
                "exit_code" => $exitCode,
                "error" => "smartctl binary was not found",
            ];
        }
        return [
            "output" => $output,
            "exit_code" => $exitCode,
            "error" => "",
        ];
    }

    private static function isProcessFunctionAvailable(string $name): bool
    {
        if (!\function_exists($name)) {
            return false;
        }
        $disabled = (string) \ini_get("disable_functions");
        if ($disabled === "") {
            return true;
        }
        $disabledList = array_map("trim", explode(",", $disabled));
        return !in_array($name, $disabledList, true);
    }

    private static function resolveSmartctlBinary(): string
    {
        $configured = trim((string) getenv("SMARTCTL_BIN"));
        if ($configured !== "") {
            return $configured;
        }
        foreach (
            ["/usr/sbin/smartctl", "/usr/bin/smartctl", "/sbin/smartctl", "/bin/smartctl"]
            as $candidate
        ) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return "smartctl";
    }
}
