<?php

declare(strict_types=1);

require_once __DIR__ . '/GenericLinuxAdapter.php';

class HestiaAdapter extends GenericLinuxAdapter
{
    public function getCapabilities(): array
    {
        $caps = parent::getCapabilities();
        $caps['panel'] = 'hestia';
        $caps['adapter'] = 'hestia';
        if ($caps['mta'] === 'none') {
            $caps['mta'] = 'exim';
        }
        if ($caps['web'] === 'none') {
            $caps['web'] = 'nginx';
        }
        return $caps;
    }

    public function getWebDomains(): array
    {
        $domains = [];
        $usersRoot = '/usr/local/hestia/data/users';
        if (!is_dir($usersRoot)) {
            return [];
        }

        $users = @scandir($usersRoot);
        if (!is_array($users)) {
            return [];
        }

        foreach ($users as $user) {
            if ($user === '.' || $user === '..') {
                continue;
            }
            $conf = $usersRoot . '/' . $user . '/web.conf';
            if (!is_readable($conf)) {
                continue;
            }
            $lines = @file($conf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $parts = preg_split('/\s+/', $line);
                if (!is_array($parts) || empty($parts[0])) {
                    continue;
                }
                $candidate = strtolower(trim((string)$parts[0]));
                if (!preg_match('/^[a-z0-9][a-z0-9.\-]{0,251}[a-z0-9]$/', $candidate)) {
                    continue;
                }
                $domains[$candidate] = true;
            }
        }

        return array_keys($domains);
    }

    public function getBackupJobs(): array
    {
        $jobs = parent::getBackupJobs();
        $cmd = trim((string)(function_exists('cfg_local') ? cfg_local('backups.hestia_cmd', '/usr/local/hestia/bin/v-backup-user') : '/usr/local/hestia/bin/v-backup-user'));
        $user = trim((string)(function_exists('cfg_local') ? cfg_local('backups.hestia_user', '') : ''));

        if ($cmd !== '' && $user !== '') {
            $jobs[] = [
              'name' => 'hestia-user-backup',
              'command' => $cmd . ' ' . $user,
            ];
        }

        return $jobs;
    }
}
