<?php
/**
 * Copyright, Quomation Inc, 2026
 *
 * This checker provides basic functionality only, based on the data encoded in
 * the VIN itself. For more accurate information please get in touch with
 * Quomation using the official website contact details:
 * https://www.quomation.com/contact/
 *
 * Licensed under the Apache License, Version 2.0. See the LICENSE file.
 *
 * Run with:  ./vendor/bin/phpunit
 */

declare(strict_types=1);

namespace Quomation\Vin\Tests;

use PHPUnit\Framework\TestCase;
use Quomation\Vin\VinDecoder;

require_once __DIR__ . '/../VinDecoder.php';

final class VinDecoderTest extends TestCase
{
    // --- Check digit ----------------------------------------------------- //

    public function testCheckDigitNumeric(): void
    {
        $this->assertSame('3', VinDecoder::computeCheckDigit('1HGCM82633A004352'));
    }

    public function testCheckDigitLetterX(): void
    {
        $this->assertSame('X', VinDecoder::computeCheckDigit('1M8GDM9AXKP042788'));
    }

    // --- Full decode of known-valid VINs --------------------------------- //

    public function testHondaAccordUsa(): void
    {
        $r = VinDecoder::decode('1HGCM82633A004352');
        $this->assertTrue($r['valid']);
        $this->assertSame('North America', $r['region']);
        $this->assertSame('United States', $r['country']);
        $this->assertSame('Honda (USA)', $r['manufacturer']);
        $this->assertSame(2003, $r['model_year']);
        $this->assertTrue($r['check_digit_valid']);
    }

    public function testWikipediaExampleCheckX(): void
    {
        $r = VinDecoder::decode('1M8GDM9AXKP042788');
        $this->assertTrue($r['valid']);
        $this->assertSame('X', $r['check_digit']);
        $this->assertSame('United States', $r['country']);
    }

    public function testTeslaModel3Usa(): void
    {
        $r = VinDecoder::decode('5YJ3E1EA7HF000337');
        $this->assertTrue($r['valid']);
        $this->assertSame('Tesla (USA)', $r['manufacturer']);
        $this->assertSame('Model 3', $r['model']);
    }

    public function testTeslaModelSUsa(): void
    {
        $r = VinDecoder::decode('5YJSA1E27HF000337');
        $this->assertTrue($r['valid']);
        $this->assertSame('Tesla (USA)', $r['manufacturer']);
        $this->assertSame('Model S', $r['model']);
    }

    public function testVwGolfEuropean(): void
    {
        $r = VinDecoder::decode('WVWZZZ1K68W000000');
        $this->assertSame('Volkswagen (Germany)', $r['manufacturer']);
        $this->assertSame('Golf Mk5/Mk6 / Jetta', $r['model']);
    }

    public function testAudiA4European(): void
    {
        $r = VinDecoder::decode('WAUZZZ8K49A000000');
        $this->assertSame('Audi (Germany)', $r['manufacturer']);
        $this->assertSame('A4 (B8)', $r['model']);
    }

    public function testSkodaOctaviaEuropean(): void
    {
        $r = VinDecoder::decode('TMBZZZ5E9G0000000');
        $this->assertSame('Skoda (Czech Republic)', $r['manufacturer']);
        $this->assertSame('Octavia Mk3', $r['model']);
    }

    public function testSeatLeonEuropean(): void
    {
        $r = VinDecoder::decode('VSSZZZ5F1F0000000');
        $this->assertSame('SEAT (Spain)', $r['manufacturer']);
        $this->assertSame('Leon Mk3', $r['model']);
    }

    public function testVwUsFormatHasNoModel(): void
    {
        // Positions 4-6 are not the ZZZ filler, so the European type-number
        // scheme must not misfire on a North-American VW VIN.
        $r = VinDecoder::decode('3VWLL7AJ3DM000000');
        $this->assertSame('Volkswagen (Mexico)', $r['manufacturer']);
        $this->assertNull($r['model']);
    }

    // --- Country / manufacturer detection -------------------------------- //

    public function testCountryDetection(): void
    {
        $this->assertSame('Germany', VinDecoder::decode('WBA3A5C50CF256916')['country']);
        $this->assertSame('Japan', VinDecoder::decode('JH4KA8260MC000000')['country']);
        $this->assertSame('Italy', VinDecoder::decode('ZFF67NFA7E0195864')['country']);
        $this->assertSame('South Korea', VinDecoder::decode('KMH1234567A000000')['country']);
    }

    public function testManufacturerDetection(): void
    {
        $this->assertSame('BMW (Germany)', VinDecoder::decode('WBA3A5C50CF256916')['manufacturer']);
        $this->assertSame('Ferrari (Italy)', VinDecoder::decode('ZFF67NFA7E0195864')['manufacturer']);
        $this->assertSame('GMC Truck (USA)', VinDecoder::decode('1GTV2TEC0FZ000000')['manufacturer']);
    }

    // --- Model year ------------------------------------------------------ //

    public function testModelYearCandidates(): void
    {
        $r = VinDecoder::decode('1HGCM82633A004352');
        $this->assertEqualsCanonicalizing([2003, 2033], $r['model_year_candidates']);
    }

    // --- Invalid input --------------------------------------------------- //

    public function testWrongLength(): void
    {
        $r = VinDecoder::decode('ABC123');
        $this->assertFalse($r['valid']);
        $this->assertNotEmpty($r['errors']);
    }

    public function testForbiddenLetters(): void
    {
        $r = VinDecoder::decode('1HGCM8263OAQ04352');
        $this->assertFalse($r['valid']);
        $found = false;
        foreach ($r['errors'] as $e) {
            if (stripos($e, 'forbidden') !== false) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function testCheckDigitMismatchReported(): void
    {
        $r = VinDecoder::decode('1HGCM82693A004352');
        $this->assertFalse($r['valid']);
        $this->assertFalse($r['check_digit_valid']);
        $this->assertSame('3', $r['expected_check_digit']);
    }

    public function testNormalisation(): void
    {
        $r = VinDecoder::decode(' 1hg-cm826 33a0043 52 ');
        $this->assertSame('1HGCM82633A004352', $r['vin']);
        $this->assertTrue($r['valid']);
    }
}
