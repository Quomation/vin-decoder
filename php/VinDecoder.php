<?php
/**
 * Copyright, Quomation Inc, 2026
 *
 * This checker provides basic functionality only, based on the data encoded in
 * the VIN itself. For more accurate information please get in touch with
 * Quomation using the official website contact details:
 * https://www.quomation.com/contact/
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * ---------------------------------------------------------------------------
 *
 * Offline Vehicle Identification Number (VIN) decoder.
 *
 * Decodes a 17-character VIN purely from the structure that ISO 3779 / ISO 3780
 * embed inside the number itself. No external service or online database is
 * contacted. It reports validity, the North American check digit, region and
 * (where unambiguous) country, the manufacturer derived from the WMI, the model
 * year from position 10, and the raw plant/serial fields.
 *
 * What a VIN can NOT reveal on its own is the precise model and trim. Those live
 * in the Vehicle Descriptor Section (positions 4-8) under a scheme each maker
 * defines privately, so they cannot be resolved without proprietary tables. This
 * decoder reports the raw descriptor section instead of guessing.
 */

namespace Quomation\Vin;

class VinDecoder
{
    /** Letters that are never permitted in a VIN (confused with 1 and 0). */
    private const FORBIDDEN = ['I', 'O', 'Q'];

    private const ALLOWED = 'ABCDEFGHJKLMNPRSTUVWXYZ0123456789';

    /** Transliteration values used by the check-digit calculation. */
    private const TRANSLITERATION = [
        'A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5, 'F' => 6, 'G' => 7, 'H' => 8,
        'J' => 1, 'K' => 2, 'L' => 3, 'M' => 4, 'N' => 5, 'P' => 7, 'R' => 9,
        'S' => 2, 'T' => 3, 'U' => 4, 'V' => 5, 'W' => 6, 'X' => 7, 'Y' => 8, 'Z' => 9,
        '0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4,
        '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9,
    ];

    /** Positional weights, positions 1..17. Position 9 carries weight 0. */
    private const WEIGHTS = [8, 7, 6, 5, 4, 3, 2, 10, 0, 9, 8, 7, 6, 5, 4, 3, 2];

    /** Model-year codes (position 10); each maps to two candidate years. */
    private const YEAR_CODES = [
        'A' => [1980, 2010], 'B' => [1981, 2011], 'C' => [1982, 2012], 'D' => [1983, 2013],
        'E' => [1984, 2014], 'F' => [1985, 2015], 'G' => [1986, 2016], 'H' => [1987, 2017],
        'J' => [1988, 2018], 'K' => [1989, 2019], 'L' => [1990, 2020], 'M' => [1991, 2021],
        'N' => [1992, 2022], 'P' => [1993, 2023], 'R' => [1994, 2024], 'S' => [1995, 2025],
        'T' => [1996, 2026], 'V' => [1997, 2027], 'W' => [1998, 2028], 'X' => [1999, 2029],
        'Y' => [2000, 2030], '1' => [2001, 2031], '2' => [2002, 2032], '3' => [2003, 2033],
        '4' => [2004, 2034], '5' => [2005, 2035], '6' => [2006, 2036], '7' => [2007, 2037],
        '8' => [2008, 2038], '9' => [2009, 2039],
    ];

    /** Coarse region grouping keyed on the first character. */
    private const REGION_BY_FIRST = [
        ['A', 'H', 'Africa'],
        ['J', 'R', 'Asia'],
        ['S', 'Z', 'Europe'],
        ['1', '5', 'North America'],
        ['6', '7', 'Oceania'],
        ['8', '9', 'South America'],
    ];

    /** Country ranges keyed on the first two characters of the WMI. */
    private const COUNTRY_RANGES = [
        ['AA', 'AH', 'South Africa'], ['AJ', 'AN', 'Ivory Coast'],
        ['BA', 'BE', 'Angola'], ['BF', 'BK', 'Kenya'], ['BL', 'BR', 'Tanzania'],
        ['CA', 'CE', 'Benin'], ['CF', 'CK', 'Madagascar'], ['CL', 'CR', 'Tunisia'],
        ['DA', 'DE', 'Egypt'], ['DF', 'DK', 'Morocco'], ['DL', 'DR', 'Zambia'],
        ['EA', 'EE', 'Ethiopia'], ['EF', 'EK', 'Mozambique'],
        ['FA', 'FE', 'Ghana'], ['FF', 'FK', 'Nigeria'],
        ['JA', 'J0', 'Japan'],
        ['KA', 'KE', 'Sri Lanka'], ['KF', 'KK', 'Israel'], ['KL', 'KR', 'South Korea'],
        ['KS', 'K0', 'Kazakhstan'],
        ['LA', 'L0', 'China'],
        ['MA', 'ME', 'India'], ['MF', 'MK', 'Indonesia'], ['ML', 'MR', 'Thailand'],
        ['MS', 'M0', 'Myanmar'],
        ['NA', 'NE', 'Iran'], ['NF', 'NK', 'Pakistan'], ['NL', 'NR', 'Turkey'],
        ['PA', 'PE', 'Philippines'], ['PF', 'PK', 'Singapore'], ['PL', 'PR', 'Malaysia'],
        ['RA', 'RE', 'United Arab Emirates'], ['RF', 'RK', 'Taiwan'],
        ['RL', 'RR', 'Vietnam'], ['RS', 'R0', 'Saudi Arabia'],
        ['SA', 'SM', 'United Kingdom'], ['SN', 'ST', 'Germany'], ['SU', 'SZ', 'Poland'],
        ['S1', 'S4', 'Latvia'],
        ['TA', 'TH', 'Switzerland'], ['TJ', 'TP', 'Czech Republic'],
        ['TR', 'TV', 'Hungary'], ['TW', 'T1', 'Portugal'],
        ['UH', 'UM', 'Denmark'], ['UN', 'UT', 'Ireland'], ['UU', 'UZ', 'Romania'],
        ['U5', 'U7', 'Slovakia'],
        ['VA', 'VE', 'Austria'], ['VF', 'VR', 'France'], ['VS', 'VW', 'Spain'],
        ['VX', 'V2', 'Serbia'], ['V3', 'V5', 'Croatia'], ['V6', 'V0', 'Estonia'],
        ['WA', 'W0', 'Germany'],
        ['XA', 'XE', 'Bulgaria'], ['XF', 'XK', 'Greece'], ['XL', 'XR', 'Netherlands'],
        ['XS', 'XW', 'Russia'], ['XX', 'X2', 'Luxembourg'], ['X3', 'X0', 'Russia'],
        ['YA', 'YE', 'Belgium'], ['YF', 'YK', 'Finland'], ['YL', 'YR', 'Malta'],
        ['YS', 'YW', 'Sweden'], ['YX', 'Y2', 'Norway'], ['Y3', 'Y5', 'Belarus'],
        ['Y6', 'Y0', 'Ukraine'],
        ['ZA', 'ZR', 'Italy'], ['ZX', 'Z2', 'Slovenia'], ['Z3', 'Z5', 'Lithuania'],
        ['1A', '10', 'United States'], ['2A', '20', 'Canada'],
        ['3A', '30', 'Mexico'],
        ['4A', '40', 'United States'], ['5A', '50', 'United States'],
        ['6A', '6W', 'Australia'], ['7A', '7E', 'New Zealand'],
        ['8A', '8E', 'Argentina'], ['8F', '8K', 'Chile'], ['8L', '8R', 'Ecuador'],
        ['8S', '8W', 'Peru'], ['8X', '82', 'Venezuela'],
        ['9A', '9E', 'Brazil'], ['9F', '9K', 'Colombia'], ['9L', '9R', 'Paraguay'],
        ['9S', '9W', 'Uruguay'], ['9X', '92', 'Trinidad and Tobago'], ['93', '99', 'Brazil'],
    ];

    /**
     * Representative World Manufacturer Identifier table. Not exhaustive, but it
     * covers the marques most people will test against. Defined as a method so
     * it can be overridden or extended by subclasses if required.
     *
     * @return array<string,string>
     */
    protected static function wmiTable(): array
    {
        return [
            '1G1' => 'Chevrolet (USA)', '1GC' => 'Chevrolet Truck (USA)',
            '1GM' => 'Pontiac (USA)', '1G6' => 'Cadillac (USA)', '1GY' => 'Cadillac (USA)',
            '1FA' => 'Ford (USA)', '1FB' => 'Ford (USA)', '1FC' => 'Ford (USA)',
            '1FD' => 'Ford Truck (USA)', '1FM' => 'Ford (USA)', '1FT' => 'Ford Truck (USA)',
            '1HG' => 'Honda (USA)', '1HD' => 'Harley-Davidson (USA)',
            '1J4' => 'Jeep (USA)', '1C3' => 'Chrysler (USA)', '1C4' => 'Chrysler (USA)',
            '1C6' => 'Chrysler (USA)', '1B3' => 'Dodge (USA)', '1D7' => 'Dodge (USA)',
            '1L1' => 'Lincoln (USA)', '1ME' => 'Mercury (USA)', '1N4' => 'Nissan (USA)',
            '1N6' => 'Nissan (USA)', '1VW' => 'Volkswagen (USA)', '1YV' => 'Mazda (USA)',
            '2G1' => 'Chevrolet (Canada)', '2HG' => 'Honda (Canada)',
            '2HK' => 'Honda (Canada)', '2T1' => 'Toyota (Canada)', '2T2' => 'Lexus (Canada)',
            '2FA' => 'Ford (Canada)', '2C3' => 'Chrysler (Canada)',
            '3FA' => 'Ford (Mexico)', '3G1' => 'Chevrolet (Mexico)',
            '3N1' => 'Nissan (Mexico)', '3VW' => 'Volkswagen (Mexico)', '3FE' => 'Ford (Mexico)',
            '4F2' => 'Mazda (USA)', '4S3' => 'Subaru (USA)', '4S4' => 'Subaru (USA)',
            '4T1' => 'Toyota (USA)', '4T3' => 'Toyota (USA)', '4US' => 'BMW (USA)',
            '5FN' => 'Honda (USA)', '5J6' => 'Honda (USA)', '5N1' => 'Nissan (USA)',
            '5TD' => 'Toyota (USA)', '5TF' => 'Toyota (USA)', '5YJ' => 'Tesla (USA)',
            '5UX' => 'BMW (USA)', '5NP' => 'Hyundai (USA)',
            '7SA' => 'Tesla (USA)',
            '6F4' => 'Nissan (Australia)', '6G1' => 'Holden (Australia)',
            '6G2' => 'Pontiac (Australia)', '6H8' => 'Holden (Australia)',
            '6MM' => 'Mitsubishi (Australia)',
            '9BW' => 'Volkswagen (Brazil)', '93H' => 'Honda (Brazil)',
            '93Y' => 'Renault (Brazil)', '9BD' => 'Fiat (Brazil)',
            'JHM' => 'Honda (Japan)', 'JH4' => 'Acura (Japan)', 'JF1' => 'Subaru (Japan)',
            'JF2' => 'Subaru (Japan)', 'JM1' => 'Mazda (Japan)', 'JM3' => 'Mazda (Japan)',
            'JN1' => 'Nissan (Japan)', 'JN8' => 'Nissan (Japan)', 'JT2' => 'Toyota (Japan)',
            'JT3' => 'Toyota (Japan)', 'JT4' => 'Toyota (Japan)', 'JTD' => 'Toyota (Japan)',
            'JTH' => 'Lexus (Japan)', 'JTM' => 'Toyota (Japan)', 'JS1' => 'Suzuki (Japan)',
            'JS2' => 'Suzuki (Japan)', 'JS3' => 'Suzuki (Japan)',
            'JK' => 'Kawasaki (Japan)', 'JY' => 'Yamaha (Japan)', 'JD' => 'Daihatsu (Japan)',
            'KMH' => 'Hyundai (South Korea)', 'KM8' => 'Hyundai (South Korea)',
            'KNA' => 'Kia (South Korea)', 'KND' => 'Kia (South Korea)',
            'KNM' => 'Renault Samsung (South Korea)', 'KL1' => 'Daewoo/GM (South Korea)',
            'KL5' => 'GM Korea (South Korea)', 'KPT' => 'SsangYong (South Korea)',
            'LGB' => 'Dongfeng (China)', 'LRW' => 'Tesla (China)',
            'LSV' => 'SAIC Volkswagen (China)', 'LVS' => 'Ford (China)',
            'LJD' => 'BYD (China)', 'LB3' => 'Geely (China)',
            'LZW' => 'SAIC-GM-Wuling (China)',
            'MAJ' => 'Ford (India)', 'MAK' => 'Honda (India)', 'MAT' => 'Tata (India)',
            'MA1' => 'Mahindra (India)', 'MA3' => 'Suzuki/Maruti (India)',
            'MBH' => 'Suzuki/Maruti (India)', 'MB1' => 'Ashok Leyland (India)',
            'MMB' => 'Mitsubishi (Thailand)', 'MMT' => 'Mitsubishi (Thailand)',
            'MR0' => 'Toyota (Thailand)', 'MNT' => 'Nissan (Thailand)',
            'MPA' => 'Isuzu (Thailand)', 'MHF' => 'Toyota (Indonesia)',
            'NLE' => 'Mercedes-Benz (Turkey)', 'NMT' => 'Toyota (Turkey)',
            'NM0' => 'Ford (Turkey)', 'NM4' => 'Fiat (Turkey)',
            'PE1' => 'Ford (Malaysia)', 'PE3' => 'Mazda (Malaysia)',
            'PL1' => 'Proton (Malaysia)', 'PNA' => 'Perodua (Malaysia)',
            'SAJ' => 'Jaguar (UK)', 'SAL' => 'Land Rover (UK)', 'SAR' => 'Rover (UK)',
            'SCA' => 'Rolls-Royce (UK)', 'SCB' => 'Bentley (UK)', 'SCC' => 'Lotus (UK)',
            'SCE' => 'DeLorean (UK)', 'SCF' => 'Aston Martin (UK)', 'SDB' => 'Peugeot (UK)',
            'SFD' => 'Alexander Dennis (UK)',
            'SHH' => 'Honda (UK)', 'SHS' => 'Honda (UK)', 'SJN' => 'Nissan (UK)',
            'SMT' => 'Triumph Motorcycles (UK)',
            'TMA' => 'Hyundai (Czech Republic)', 'TMB' => 'Skoda (Czech Republic)',
            'TMP' => 'Praga (Czech Republic)',
            'TRU' => 'Audi (Hungary)',
            'UU1' => 'Dacia (Romania)', 'UU3' => 'ARO (Romania)',
            'U5Y' => 'Kia (Slovakia)', 'U6Y' => 'Kia (Slovakia)',
            'VF1' => 'Renault (France)', 'VF3' => 'Peugeot (France)',
            'VF7' => 'Citroen (France)', 'VF6' => 'Renault Trucks (France)',
            'VF8' => 'Matra (France)', 'VFE' => 'IvecoBus (France)', 'VNK' => 'Toyota (France)',
            'VSS' => 'SEAT (Spain)', 'VSE' => 'Suzuki (Spain)', 'VSX' => 'Opel (Spain)',
            'VWV' => 'Volkswagen (Spain)',
            'WAU' => 'Audi (Germany)', 'WA1' => 'Audi SUV (Germany)',
            'WBA' => 'BMW (Germany)', 'WBS' => 'BMW M (Germany)', 'WBY' => 'BMW i (Germany)',
            'WBX' => 'BMW (Germany)', 'WDB' => 'Mercedes-Benz (Germany)',
            'WDC' => 'Mercedes-Benz SUV (Germany)', 'WDD' => 'Mercedes-Benz (Germany)',
            'WDF' => 'Mercedes-Benz Van (Germany)', 'WEB' => 'Setra/EvoBus (Germany)',
            'WF0' => 'Ford (Germany)',
            'WMA' => 'MAN (Germany)', 'WME' => 'smart (Germany)', 'WMW' => 'MINI (Germany)',
            'WP0' => 'Porsche (Germany)', 'WP1' => 'Porsche SUV (Germany)',
            'WUA' => 'Audi quattro (Germany)', 'WVW' => 'Volkswagen (Germany)',
            'WV1' => 'Volkswagen Commercial (Germany)', 'WV2' => 'Volkswagen Bus/Van (Germany)',
            'WVG' => 'Volkswagen SUV (Germany)',
            'W0L' => 'Opel (Germany)', 'W0V' => 'Opel (Germany)',
            'XLR' => 'DAF (Netherlands)', 'XL9' => 'Spyker (Netherlands)',
            'XTA' => 'Lada/AvtoVAZ (Russia)', 'XTT' => 'UAZ (Russia)',
            'XW8' => 'Volkswagen Group Rus (Russia)', 'XW7' => 'Toyota (Russia)',
            'X4X' => 'BMW (Russia)', 'XWB' => 'Daewoo/UZ (Uzbekistan)',
            'YK1' => 'Saab (Sweden)', 'YS3' => 'Saab (Sweden)', 'YS2' => 'Scania (Sweden)',
            'YS4' => 'Scania (Sweden)', 'YTN' => 'Saab (Sweden)',
            'YV1' => 'Volvo (Sweden)', 'YV2' => 'Volvo Trucks (Sweden)', 'YV4' => 'Volvo (Sweden)',
            'ZAR' => 'Alfa Romeo (Italy)', 'ZAM' => 'Maserati (Italy)',
            'ZFA' => 'Fiat (Italy)', 'ZFF' => 'Ferrari (Italy)', 'ZHW' => 'Lamborghini (Italy)',
            'ZLA' => 'Lancia (Italy)', 'ZAP' => 'Piaggio (Italy)', 'ZD0' => 'Ducati (Italy)',
            'ZDM' => 'Ducati (Italy)', 'ZD4' => 'Aprilia (Italy)',
            // --- additional commonly seen WMIs ---
            '1G2' => 'Pontiac (USA)', '1G3' => 'Oldsmobile (USA)', '1G4' => 'Buick (USA)',
            '1G8' => 'Saturn (USA)', '1GT' => 'GMC Truck (USA)', '1GK' => 'GMC SUV (USA)',
            '1GB' => 'Chevrolet/GMC Truck (USA)', '1GD' => 'GMC Truck (USA)',
            '1FU' => 'Freightliner (USA)', '1FV' => 'Freightliner (USA)',
            '1XK' => 'Kenworth (USA)', '1XP' => 'Peterbilt (USA)',
            '1ZV' => 'Ford Mustang (AutoAlliance, USA)', '1NX' => 'Toyota (NUMMI, USA)',
            '2G2' => 'Pontiac (Canada)', '2G3' => 'Oldsmobile (Canada)', '2G4' => 'Buick (Canada)',
            '2T3' => 'Toyota (Canada)', '2C4' => 'Chrysler (Canada)', '2D4' => 'Dodge (Canada)',
            '2LM' => 'Lincoln (Canada)', '2ME' => 'Mercury (Canada)', '2V4' => 'Volkswagen (Canada)',
            '2FM' => 'Ford SUV (Canada)', '2FT' => 'Ford Truck (Canada)',
            '3HG' => 'Honda (Mexico)', '3MD' => 'Mazda (Mexico)', '3MZ' => 'Mazda (Mexico)',
            '3GN' => 'Chevrolet SUV (Mexico)', '3C4' => 'Chrysler (Mexico)', '3D7' => 'Dodge Truck (Mexico)',
            '4JG' => 'Mercedes-Benz SUV (USA)', '4M2' => 'Mercury (USA)', '4T4' => 'Toyota (USA)',
            '5LM' => 'Lincoln SUV (USA)', '5L1' => 'Lincoln (USA)', '5GA' => 'Buick (USA)',
            '5XY' => 'Hyundai SUV (USA)', '5XX' => 'Kia (USA)', '5NM' => 'Hyundai SUV (USA)',
            '5TB' => 'Toyota (USA)',
            'JA3' => 'Mitsubishi (Japan)', 'JA4' => 'Mitsubishi (Japan)', 'JHL' => 'Honda SUV (Japan)',
            'JKA' => 'Kawasaki (Japan)', 'JYA' => 'Yamaha (Japan)', 'JTN' => 'Toyota (Japan)',
            'JTE' => 'Toyota SUV (Japan)', 'JTK' => 'Toyota (Japan)', 'JTJ' => 'Lexus SUV (Japan)',
            'JNK' => 'Infiniti (Japan)', 'JN3' => 'Infiniti (Japan)', 'JMB' => 'Mitsubishi (Japan)',
            'JMZ' => 'Mazda (Japan)', 'JDA' => 'Daihatsu (Japan)',
            'KNB' => 'Kia (South Korea)', 'KNC' => 'Kia (South Korea)', 'KNE' => 'Kia (South Korea)',
            'KMC' => 'Hyundai (South Korea)', 'KMF' => 'Hyundai Van (South Korea)',
            'LFV' => 'FAW-Volkswagen (China)', 'LSG' => 'SAIC-GM (China)', 'LVV' => 'Chery (China)',
            'LBV' => 'BMW Brilliance (China)', 'LGX' => 'BYD (China)', 'L6T' => 'Geely (China)',
            'WVG' => 'Volkswagen SUV (Germany)',
        ];
    }

    /**
     * Model (VDS) decoding rules. The model lives in positions 4-8, but each
     * manufacturer defines that section privately, so we only decode marques
     * whose scheme is publicly documented. Each rule maps a WMI to a character
     * index (`at`, 0-based into the full VIN) and a value->model table.
     *
     * To add a brand, append an entry here and the matching one in the Python
     * version, and cite the public source of the scheme.
     *
     * Each rule may locate the code with `at` (a single 0-based index) or with
     * `from`/`len` (a substring). An optional `filler_zzz` flag restricts the
     * rule to European-format VINs (positions 4-6 are the "ZZZ" filler), which
     * is how VW/Audi place a platform "type number" in positions 7-8.
     *
     * Model labels are deliberately broad (model line / generation). This is
     * best-effort decoding from open VIN guides; see the project disclaimer.
     *
     * @return array<string,array<string,mixed>>
     */
    protected static function modelRules(): array
    {
        return [
            // --- Tesla: position 4 encodes the model line ---
            '5YJ' => ['at' => 3, 'models' => [
                'S' => 'Model S', '3' => 'Model 3', 'X' => 'Model X',
                'Y' => 'Model Y', 'C' => 'Cybertruck', 'R' => 'Roadster',
            ]],
            '7SA' => ['at' => 3, 'models' => [
                'Y' => 'Model Y', 'S' => 'Model S', 'X' => 'Model X', 'C' => 'Cybertruck',
            ]],
            'LRW' => ['at' => 3, 'models' => [
                '3' => 'Model 3', 'Y' => 'Model Y',
            ]],

            // --- Volkswagen (European format): type number at positions 7-8 ---
            'WVW' => ['from' => 6, 'len' => 2, 'filler_zzz' => true, 'models' => [
                '1H' => 'Golf Mk3 / Vento', '1J' => 'Golf Mk4 / Bora',
                '1K' => 'Golf Mk5/Mk6 / Jetta', '5K' => 'Golf Mk6', '5G' => 'Golf Mk7',
                'AU' => 'Golf Mk7', 'CD' => 'Golf Mk8',
                '3B' => 'Passat B5', '3C' => 'Passat B6/B7 / CC', '3G' => 'Passat B8',
                '6R' => 'Polo Mk5', '6C' => 'Polo Mk6', 'AW' => 'Polo Mk6',
                '5N' => 'Tiguan Mk1', 'AD' => 'Tiguan Mk2', 'BW' => 'Tiguan Mk2',
                '1T' => 'Touran', '7L' => 'Touareg Mk1', '7P' => 'Touareg Mk2',
                'AA' => 'up!', 'NF' => 'T-Roc',
            ]],

            // --- Audi (European format): type number at positions 7-8 ---
            'WAU' => ['from' => 6, 'len' => 2, 'filler_zzz' => true, 'models' => [
                '8E' => 'A4 (B6/B7)', '8K' => 'A4 (B8)', '8W' => 'A4 (B9)',
                '8P' => 'A3 (8P)', '8V' => 'A3 (8V)', '8Y' => 'A3 (8Y)',
                '4B' => 'A6 (C5)', '4F' => 'A6 (C6)', '4G' => 'A6 (C7)', '4A' => 'A6 (C8)',
                '4E' => 'A8 (D3)', '4H' => 'A8 (D4)',
                '8U' => 'Q3 (8U)', '8R' => 'Q5 (8R)', '80' => 'Q5 (FY)',
                '4L' => 'Q7 (4L)', '4M' => 'Q7 (4M)',
            ]],
            'WUA' => ['from' => 6, 'len' => 2, 'filler_zzz' => true, 'models' => [
                '8K' => 'RS4/S4 (B8)', '4G' => 'RS6/S6 (C7)', '8V' => 'RS3/S3 (8V)',
            ]],
            'WA1' => ['from' => 6, 'len' => 2, 'filler_zzz' => true, 'models' => [
                '8U' => 'Q3 (8U)', '8R' => 'Q5 (8R)', '80' => 'Q5 (FY)',
                '4L' => 'Q7 (4L)', '4M' => 'Q7 (4M)', 'GA' => 'Q8',
            ]],

            // --- SEAT (European format): same VW-Group type number at positions 7-8 ---
            'VSS' => ['from' => 6, 'len' => 2, 'filler_zzz' => true, 'models' => [
                '1L' => 'Toledo Mk1', '1M' => 'Leon Mk1 / Toledo Mk2', '1P' => 'Leon Mk2',
                '5F' => 'Leon Mk3', 'KL' => 'Leon Mk4',
                '6K' => 'Ibiza Mk2 / Cordoba', '6L' => 'Ibiza Mk3', '6J' => 'Ibiza Mk4',
                '6F' => 'Ibiza Mk5', '5P' => 'Altea / Toledo Mk3', '3R' => 'Exeo',
            ]],

            // --- Skoda (European format): same VW-Group type number at positions 7-8 ---
            'TMB' => ['from' => 6, 'len' => 2, 'filler_zzz' => true, 'models' => [
                '1U' => 'Octavia Mk1', '1Z' => 'Octavia Mk2', '5E' => 'Octavia Mk3',
                'NX' => 'Octavia Mk4', '6Y' => 'Fabia Mk1', '5J' => 'Fabia Mk2 / Roomster',
                'NJ' => 'Fabia Mk3', '3U' => 'Superb Mk1', '3T' => 'Superb Mk2',
                '3V' => 'Superb Mk3', '5L' => 'Yeti', 'NS' => 'Kodiaq', 'NU' => 'Karoq',
            ]],
        ];
    }

    private static function lookupModel(string $vin): ?string
    {
        $rules = static::modelRules();
        $wmi3 = substr($vin, 0, 3);
        if (!isset($rules[$wmi3])) {
            return null;
        }
        $rule = $rules[$wmi3];
        if (!empty($rule['filler_zzz']) && substr($vin, 3, 3) !== 'ZZZ') {
            return null;
        }
        if (isset($rule['at'])) {
            $key = $vin[$rule['at']];
        } else {
            $key = substr($vin, $rule['from'], $rule['len']);
        }
        return $rule['models'][$key] ?? null;
    }

    /** Upper-case the VIN and strip whitespace and separators. */
    public static function normalize(string $vin): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim($vin)));
    }

    /**
     * Return the check digit ('0'-'9' or 'X') that position 9 should contain.
     */
    public static function computeCheckDigit(string $vin): string
    {
        $total = 0;
        $length = strlen($vin);
        for ($i = 0; $i < $length && $i < 17; $i++) {
            $char = $vin[$i];
            $value = self::TRANSLITERATION[$char] ?? 0;
            $total += $value * self::WEIGHTS[$i];
        }
        $remainder = $total % 11;
        return $remainder === 10 ? 'X' : (string) $remainder;
    }

    private static function lookupRegion(string $firstChar): ?string
    {
        foreach (self::REGION_BY_FIRST as [$low, $high, $region]) {
            if ($firstChar >= $low && $firstChar <= $high) {
                return $region;
            }
        }
        return null;
    }

    /**
     * Collation used by the country tables: letters A-Z first (I, O, Q never
     * occur), then the digits 1-9 and finally 0. Plain byte ordering would put
     * digits before letters, which is wrong for these ranges.
     */
    private const COLLATE = 'ABCDEFGHJKLMNPRSTUVWXYZ1234567890';

    private static function collateKey(string $prefix2): int
    {
        return strpos(self::COLLATE, $prefix2[0]) * 100 + strpos(self::COLLATE, $prefix2[1]);
    }

    private static function lookupCountry(string $prefix2): ?string
    {
        $key = self::collateKey($prefix2);
        foreach (self::COUNTRY_RANGES as [$low, $high, $country]) {
            if ($key >= self::collateKey($low) && $key <= self::collateKey($high)) {
                return $country;
            }
        }
        return null;
    }

    private static function lookupManufacturer(string $vin): ?string
    {
        $table = static::wmiTable();
        $wmi3 = substr($vin, 0, 3);
        if (isset($table[$wmi3])) {
            return $table[$wmi3];
        }
        $wmi2 = substr($vin, 0, 2);
        return $table[$wmi2] ?? null;
    }

    /**
     * Decode a VIN. Returns an associative array describing the vehicle. The
     * method never throws on malformed input; problems are listed under the
     * 'errors' key and 'valid' is set to false.
     *
     * @return array<string,mixed>
     */
    public static function decode(string $vin): array
    {
        $raw = self::normalize($vin);
        $result = [
            'vin' => $raw,
            'valid' => false,
            'errors' => [],
            'region' => null,
            'country' => null,
            'wmi' => null,
            'manufacturer' => null,
            'model' => null,
            'vds' => null,
            'check_digit' => null,
            'check_digit_valid' => null,
            'expected_check_digit' => null,
            'model_year' => null,
            'model_year_candidates' => [],
            'plant_code' => null,
            'serial_number' => null,
        ];

        // Structural validation.
        if (strlen($raw) !== 17) {
            $result['errors'][] = 'A VIN must be exactly 17 characters; got ' . strlen($raw) . '.';
            return $result;
        }

        $bad = [];
        for ($i = 0; $i < 17; $i++) {
            if (strpos(self::ALLOWED, $raw[$i]) === false) {
                $bad[$raw[$i]] = true;
            }
        }
        if (!empty($bad)) {
            $badChars = array_keys($bad);
            $forbidden = array_intersect($badChars, self::FORBIDDEN);
            if (!empty($forbidden)) {
                $result['errors'][] = 'VIN contains the forbidden letters '
                    . implode(', ', $forbidden) . ' (I, O and Q are never used).';
            }
            $other = array_diff($badChars, self::FORBIDDEN);
            if (!empty($other)) {
                $result['errors'][] = 'VIN contains invalid characters: ' . implode(', ', $other) . '.';
            }
            return $result;
        }

        // Field extraction.
        $result['wmi'] = substr($raw, 0, 3);
        $result['vds'] = substr($raw, 3, 5);
        $result['check_digit'] = $raw[8];
        $result['plant_code'] = $raw[10];
        $result['serial_number'] = substr($raw, 11);

        $result['region'] = self::lookupRegion($raw[0]);
        $result['country'] = self::lookupCountry(substr($raw, 0, 2));
        $result['manufacturer'] = self::lookupManufacturer($raw);
        $result['model'] = self::lookupModel($raw);

        // Check digit.
        $expected = self::computeCheckDigit($raw);
        $result['expected_check_digit'] = $expected;
        $result['check_digit_valid'] = ($expected === $raw[8]);
        if (!$result['check_digit_valid']) {
            $result['errors'][] = "Check digit mismatch: position 9 is '" . $raw[8]
                . "' but the calculation expects '" . $expected . "'.";
        }

        // Model year.
        $yearChar = $raw[9];
        if (isset(self::YEAR_CODES[$yearChar])) {
            $candidates = self::YEAR_CODES[$yearChar];
            $result['model_year_candidates'] = $candidates;
            // Position 7 alphabetic => 2010+ cycle (common heuristic).
            $position7IsLetter = ctype_alpha($raw[6]);
            $result['model_year'] = $position7IsLetter ? $candidates[1] : $candidates[0];
        } else {
            $result['errors'][] = "Position 10 ('" . $yearChar . "') is not a valid model-year code.";
        }

        $result['valid'] = ($result['check_digit_valid'] === true);
        return $result;
    }

    /** Render a human-readable text report from a decode() result. */
    public static function formatReport(array $r): string
    {
        $lines = [];
        $lines[] = 'VIN ........... ' . $r['vin'];
        $lines[] = 'Status ........ ' . ($r['valid'] ? 'VALID' : 'NOT VALID');
        if ($r['region']) {
            $lines[] = 'Region ........ ' . $r['region'];
        }
        if ($r['country']) {
            $lines[] = 'Country ....... ' . $r['country'];
        }
        $lines[] = 'WMI ........... ' . ($r['wmi'] ?? '-');
        $lines[] = 'Manufacturer .. ' . ($r['manufacturer'] ?? 'Unknown (WMI not in local table)');
        if (!empty($r['model'])) {
            $lines[] = 'Model ......... ' . $r['model'];
        }
        $lines[] = 'Descriptor .... ' . ($r['vds'] ?? '-') . '  (positions 4-8, manufacturer-specific)';
        if ($r['model_year']) {
            $extra = '';
            if (count($r['model_year_candidates']) === 2) {
                $other = array_values(array_diff($r['model_year_candidates'], [$r['model_year']]));
                if (!empty($other)) {
                    $extra = '  (or ' . $other[0] . ' - the year code repeats every 30 years)';
                }
            }
            $lines[] = 'Model year .... ' . $r['model_year'] . $extra;
        }
        $cd = $r['check_digit'] ?? '-';
        $cd .= $r['check_digit_valid'] ? ' (OK)' : ' (expected ' . $r['expected_check_digit'] . ')';
        $lines[] = 'Check digit ... ' . $cd;
        $lines[] = 'Plant code .... ' . ($r['plant_code'] ?? '-');
        $lines[] = 'Serial ........ ' . ($r['serial_number'] ?? '-');
        if (!empty($r['errors'])) {
            $lines[] = '';
            $lines[] = 'Notes:';
            foreach ($r['errors'] as $err) {
                $lines[] = '  - ' . $err;
            }
        }
        return implode("\n", $lines);
    }
}
