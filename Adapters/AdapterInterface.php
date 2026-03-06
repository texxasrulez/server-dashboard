<?php

declare(strict_types=1);

interface DashboardAdapterInterface {
  public function getCapabilities(): array;
  public function getServiceStatus(): array;
  public function getWebDomains(): array;
  public function getBackupJobs(): array;
}
