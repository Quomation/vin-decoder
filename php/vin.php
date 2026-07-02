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
 * ---------------------------------------------------------------------------
 *
 * Command-line entry point for the offline VIN decoder.
 *
 * Usage:
 *     php vin.php 1HGCM82633A004352
 *     php vin.php --json 1HGCM82633A004352
 *     echo "1HGCM82633A004352" | php vin.php
 */

require __DIR__ . '/VinDecoder.php';

use Quomation\Vin\VinDecoder;

$args = array_slice($argv, 1);
$asJson = false;
$vin = null;

foreach ($args as $arg) {
    if ($arg === '--json') {
        $asJson = true;
    } else {
        $vin = $arg;
    }
}

if ($vin === null) {
    $vin = trim((string) fgets(STDIN));
}

if ($vin === '') {
    fwrite(STDERR, "Usage: php vin.php [--json] <VIN>\n");
    exit(1);
}

$result = VinDecoder::decode($vin);

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    echo VinDecoder::formatReport($result), "\n";
}

exit($result['valid'] ? 0 : 2);
