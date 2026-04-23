<?php

use PHPUnit\Framework\TestCase;

final class AdminMaintenanceTest extends TestCase
{
    public function testMaskedTokenPreservesEdges(): void
    {
        $masked = \AdminMaintenance::maskedToken("1234567890abcdef");
        $this->assertSame("1234********cdef", $masked);
    }

    public function testPermissionTooOpenDetectsWorldWritableMode(): void
    {
        $this->assertTrue(\AdminMaintenance::isPermissionTooOpen("0666"));
        $this->assertFalse(\AdminMaintenance::isPermissionTooOpen("0640"));
    }
}
