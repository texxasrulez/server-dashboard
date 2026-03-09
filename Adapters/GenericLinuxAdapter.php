<?php

declare(strict_types=1);

require_once __DIR__ . '/AdapterInterface.php';
require_once __DIR__ . '/SystemctlHelper.php';

class GenericLinuxAdapter implements DashboardAdapterInterface
{
    /** @var array<string,string> */
    protected $hints;

    /** @param array<string,string> $hints */
    public function __construct(array $hints = [])
    {
        $this->hints = $hints;
    }

    protected function hint(string $key, array $allow, string $fallback): string
    {
        $raw = strtolower(trim((string)($this->hints[$key] ?? '')));
        if ($raw !== '' && in_array($raw, $allow, true)) {
            return $raw;
        }
        return $fallback;
    }

    public function getCapabilities(): array
    {
        $panel = $this->hint('panel', ['hestia', 'none'], 'none');
        $web = $this->hint('web', ['nginx', 'apache', 'nginx+apache', 'none'], $this->detectWebStack());
        $mta = $this->hint('mta', ['exim', 'postfix', 'none'], $this->detectMta());
        $db = $this->hint('db', ['mariadb', 'postgres', 'none'], $this->detectDb());

        return [
          'panel' => $panel,
          'web' => $web,
          'mta' => $mta,
          'db' => $db,
          'adapter' => 'generic-linux',
        ];
    }

    public function getServiceStatus(): array
    {
        return [
          'hestia' => SystemctlHelper::anyActive('hestia'),
          'nginx' => SystemctlHelper::anyActive('nginx'),
          'apache' => SystemctlHelper::anyActive('apache'),
          'postfix' => SystemctlHelper::anyActive('postfix'),
          'exim' => SystemctlHelper::anyActive('exim'),
          'mariadb' => SystemctlHelper::anyActive('mariadb'),
          'postgres' => SystemctlHelper::anyActive('postgres'),
        ];
    }

    public function getWebDomains(): array
    {
        return [];
    }

    public function getBackupJobs(): array
    {
        $jobs = [];
        $cfgJobs = function_exists('cfg_local') ? cfg_local('backups.jobs', []) : [];
        if (is_array($cfgJobs)) {
            foreach ($cfgJobs as $row) {
                if (is_string($row) && trim($row) !== '') {
                    $jobs[] = ['name' => trim($row)];
                }
                if (is_array($row) && !empty($row['name'])) {
                    $jobs[] = ['name' => (string)$row['name']];
                }
            }
        }
        return $jobs;
    }

    protected function detectWebStack(): string
    {
        $hasNginx = @is_file('/etc/nginx/nginx.conf') || SystemctlHelper::anyActive('nginx') === true;
        $hasApache = @is_file('/etc/apache2/apache2.conf') || @is_file('/etc/httpd/conf/httpd.conf') || SystemctlHelper::anyActive('apache') === true;
        if ($hasNginx && $hasApache) {
            return 'nginx+apache';
        }
        if ($hasNginx) {
            return 'nginx';
        }
        if ($hasApache) {
            return 'apache';
        }
        return 'none';
    }

    protected function detectMta(): string
    {
        $hasPostfix = @is_file('/etc/postfix/main.cf') || SystemctlHelper::anyActive('postfix') === true;
        $hasExim = @is_file('/etc/exim4/update-exim4.conf.conf') || @is_file('/etc/exim/exim.conf') || SystemctlHelper::anyActive('exim') === true;
        if ($hasExim) {
            return 'exim';
        }
        if ($hasPostfix) {
            return 'postfix';
        }
        return 'none';
    }

    protected function detectDb(): string
    {
        $hasMaria = @is_file('/etc/mysql/my.cnf') || SystemctlHelper::anyActive('mariadb') === true;
        $hasPg = @is_dir('/etc/postgresql') || SystemctlHelper::anyActive('postgres') === true;
        if ($hasMaria) {
            return 'mariadb';
        }
        if ($hasPg) {
            return 'postgres';
        }
        return 'none';
    }
}
