<?php

namespace Tests\Unit;

use App\Support\RiderValidation;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class RiderValidationTest extends TestCase
{
    public function test_normalize_email_trims_and_lowercases(): void
    {
        $this->assertSame('a@b.com', RiderValidation::normalizeEmail('  A@B.COM '));
    }

    public function test_normalize_mobile_plus63_and_separators(): void
    {
        $this->assertSame('09171234567', RiderValidation::normalizeMobile('+639171234567'));
        $this->assertSame('09171234567', RiderValidation::normalizeMobile('0917-123-4567'));
    }

    public function test_normalize_plate_and_license_uppercase(): void
    {
        $this->assertSame('ABC123', RiderValidation::normalizePlate(' abc123 '));
        $this->assertSame('N01-23-456789', RiderValidation::normalizeLicense(' n01-23-456789 '));
    }

    public function test_is_adult_boundary_manila(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00', 'Asia/Manila'));
        $today = Carbon::now('Asia/Manila');
        $this->assertFalse(RiderValidation::isAdult($today->copy()->subYears(18)->addDay()->format('Y-m-d')));
        $this->assertTrue(RiderValidation::isAdult($today->copy()->subYears(18)->format('Y-m-d')));
        $this->assertFalse(RiderValidation::isAdult($today->copy()->addDay()->format('Y-m-d')));
        Carbon::setTestNow();
    }

    public function test_is_expiry_valid_boundary_manila(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00', 'Asia/Manila'));
        $today = Carbon::now('Asia/Manila');
        $this->assertFalse(RiderValidation::isExpiryValid($today->copy()->subDay()->format('Y-m-d')));
        $this->assertTrue(RiderValidation::isExpiryValid($today->format('Y-m-d')));
        $this->assertTrue(RiderValidation::isExpiryValid($today->copy()->addDay()->format('Y-m-d')));
        Carbon::setTestNow();
    }

    public function test_license_format_constant(): void
    {
        $this->assertMatchesRegularExpression(RiderValidation::LICENSE_REGEX, 'N01-23-456789');
        $this->assertDoesNotMatchRegularExpression(RiderValidation::LICENSE_REGEX, 'n01-23-456789');
    }
}
