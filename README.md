# Quomation VIN Decoder

An **offline Vehicle Identification Number (VIN) decoder** provided in two
independent implementations — one in **PHP** and one in **Python**. Both read a
17-character VIN and report everything that the VIN standard encodes inside the
number itself: whether the VIN is structurally valid, whether its check digit is
correct, where the vehicle was built, who built it, and which model year it
belongs to.

There are no API keys, no online lookups and no third-party services. The
decoder works entirely from the public structure of the VIN as defined by the
ISO 3779 and ISO 3780 standards, so it runs anywhere, offline, with no
dependencies beyond a standard PHP or Python installation.

> **Disclaimer.** This checker provides basic functionality only, based on the
> data encoded in the VIN itself. For more accurate information please get in
> touch with Quomation using the official website contact details:
> <https://www.quomation.com/contact/>. You can also see other work we do on our
> [IT consulting projects](https://www.quomation.com/it-consulting-projects/)
> page.

---

## Table of contents

1. [What a VIN is](#what-a-vin-is)
2. [The structure of a VIN](#the-structure-of-a-vin)
3. [How the decoder reads each field](#how-the-decoder-reads-each-field)
4. [The check digit explained](#the-check-digit-explained)
5. [Model-year decoding and its ambiguity](#model-year-decoding-and-its-ambiguity)
6. [What this tool can and cannot tell you](#what-this-tool-can-and-cannot-tell-you)
7. [Installation](#installation)
8. [Using the Python version](#using-the-python-version)
9. [Using the PHP version](#using-the-php-version)
10. [Output fields reference](#output-fields-reference)
11. [Worked example](#worked-example)
12. [Extending the manufacturer table](#extending-the-manufacturer-table)
13. [Accuracy, limitations and edge cases](#accuracy-limitations-and-edge-cases)
14. [Licence and copyright](#licence-and-copyright)

---

## What a VIN is

A Vehicle Identification Number is the unique fingerprint stamped onto every
road vehicle manufactured since the standard was harmonised in 1981. Since 1981,
a VIN is always **17 characters long** and is made up of capital letters and
digits. Three letters are deliberately never used — **I, O and Q** — because
they are too easily confused with the digits 1 and 0. Every other letter (A–H,
J–N, P, R–Z) and every digit (0–9) is allowed.

The 17 characters are not random. They are divided into three sections, each
governed by an international standard, and each section carries a specific piece
of information about the vehicle. Because the layout is fixed and public, a VIN
can be partially decoded by anyone, without contacting the manufacturer — which
is exactly what this tool does.

## The structure of a VIN

A VIN is split into three blocks:

```
 Position:  1  2  3   4  5  6  7  8   9   10  11   12 13 14 15 16 17
            └────────┘ └──────────┘  └┘  └┘  └┘   └──────────────┘
              WMI          VDS       CK   YR  PL        VIS serial
            (1-3)        (4-8)      (9) (10) (11)     (12-17)
```

| Block | Positions | Name | What it tells you |
|-------|-----------|------|-------------------|
| **WMI** | 1–3 | World Manufacturer Identifier | The country/region (char 1, and 1–2 together) and the manufacturer (chars 1–3). |
| **VDS** | 4–8 | Vehicle Descriptor Section | Manufacturer-specific attributes such as model, body, engine and restraint system. The exact meaning is defined privately by each maker. |
| **Check** | 9 | Check digit | A single calculated character used to detect typing errors. Mandatory in North America. |
| **VIS** | 10–17 | Vehicle Identifier Section | Position 10 is the model year, position 11 is the assembly plant, and positions 12–17 are the production serial number. |

* **Position 1** identifies the broad geographic region (for example, a leading
  `1`, `4` or `5` means North America / United States, `J` means Japan, `W`
  means Germany).
* **Positions 1–2** together narrow the origin down to a specific country.
* **Positions 1–3** form the WMI, which is assigned to an individual
  manufacturer by the relevant national authority.

## How the decoder reads each field

For every VIN you pass in, the decoder performs the following steps in order:

1. **Normalise** the input — trim whitespace, remove spaces and dashes, and
   convert to upper case.
2. **Validate the structure** — confirm there are exactly 17 characters and that
   none of them are the forbidden letters I, O or Q (or any other illegal
   symbol). If this fails, decoding stops and the reason is reported.
3. **Split the VIN** into WMI (1–3), VDS (4–8), check digit (9), year (10),
   plant (11) and serial (12–17).
4. **Look up the region** from the first character.
5. **Look up the country** from the first two characters, using the official
   range tables.
6. **Look up the manufacturer** from the three-character WMI (falling back to a
   two-character prefix where appropriate).
7. **Recalculate the check digit** and compare it with position 9.
8. **Decode the model year** from position 10.

The result is returned as a structured object (a `dataclass` in Python, an
associative array in PHP) and can also be printed as a readable report or as
JSON.

## The check digit explained

Position 9 of a VIN is a **check digit**. It is not part of the vehicle's
description; it exists purely so that a mistyped or misread VIN can be detected
automatically. It is mandatory for vehicles sold in North America and is widely
used elsewhere.

The check digit is calculated with a **modulo-11 weighted sum**, not the Luhn
algorithm used by credit cards. (The original task brief mentioned "Luhn" as a
shorthand for "checksum digit", but the VIN standard defines its own scheme, and
that is the one implemented here, because Luhn would reject perfectly valid
VINs.) The calculation works like this:

1. **Transliterate** every character to a number. Digits keep their face value.
   Letters map to a value from 1 to 9 according to a fixed table:

   | A B C D E F G H | J K L M N&nbsp;&nbsp;P&nbsp;&nbsp;R | S T U V W X Y Z |
   |-----------------|-------------------------------------|-----------------|
   | 1 2 3 4 5 6 7 8 | 1 2 3 4 5&nbsp;&nbsp;7&nbsp;&nbsp;9 | 2 3 4 5 6 7 8 9 |

2. **Multiply** each transliterated value by the weight for its position. The
   weights, for positions 1 through 17, are:

   ```
   8  7  6  5  4  3  2  10  0  9  8  7  6  5  4  3  2
   ```

   Note that position 9 — the check digit itself — has a weight of 0, so it does
   not influence its own calculation.

3. **Sum** all the products and take the remainder when divided by 11.

4. The remainder (0–10) is the check digit. A remainder of **10 is written as
   the letter `X`**.

If the calculated value matches the character actually in position 9, the VIN
passes the check. If it does not, the VIN almost certainly contains a
transcription error. The decoder reports both the expected and the actual value
so you can see exactly where the mismatch is.

## Model-year decoding and its ambiguity

Position 10 encodes the **model year** using a single character. The code table
runs through the alphabet and digits and then **repeats every 30 years**, which
means each code maps to two possible years:

| Code | Years | Code | Years | Code | Years |
|------|-------|------|-------|------|-------|
| A | 1980 / 2010 | L | 1990 / 2020 | Y | 2000 / 2030 |
| B | 1981 / 2011 | M | 1991 / 2021 | 1 | 2001 / 2031 |
| C | 1982 / 2012 | N | 1992 / 2022 | 2 | 2002 / 2032 |
| D | 1983 / 2013 | P | 1993 / 2023 | 3 | 2003 / 2033 |
| E | 1984 / 2014 | R | 1994 / 2024 | 4 | 2004 / 2034 |
| F | 1985 / 2015 | S | 1995 / 2025 | 5 | 2005 / 2035 |
| G | 1986 / 2016 | T | 1996 / 2026 | 6 | 2006 / 2036 |
| H | 1987 / 2017 | V | 1997 / 2027 | 7 | 2007 / 2037 |
| J | 1988 / 2018 | W | 1998 / 2028 | 8 | 2008 / 2038 |
| K | 1989 / 2019 | X | 1999 / 2029 | 9 | 2009 / 2039 |

Because the code is ambiguous on its own, the decoder uses a common heuristic to
choose between the two candidates: on most vehicles built in the 2010-and-later
cycle, **position 7 is a letter**, whereas on older vehicles it is a digit. The
decoder reports its best single guess *and* always lists both candidate years,
so you can apply your own judgement. This heuristic is reliable for the majority
of passenger vehicles but is not guaranteed by the standard for every
manufacturer.

## What this tool can and cannot tell you

It is important to be honest about the limits of pure VIN decoding.

**Reliably decodable from the VIN alone:**

* whether the VIN is structurally valid;
* whether the check digit is correct;
* the region and country of the manufacturing plant;
* the manufacturer / marque (from the WMI table);
* the model year (with the 30-year ambiguity noted above);
* the assembly-plant code and the production serial number.

**Partially decodable — only for documented marques:**

* the **model.** The model lives in the Vehicle Descriptor Section (positions
  4–8), but each manufacturer assigns those characters using its own private
  scheme. There is no public, universal table that maps VDS characters to a
  model name. Rather than guess, this decoder ships an **extensible per-brand
  rule set** and only reports a model for marques whose scheme is publicly
  documented. Included out of the box:

  * **Tesla** — position 4 encodes the model line (Model S / 3 / X / Y /
    Cybertruck).
  * **Volkswagen Group — Volkswagen, Audi, SEAT and Škoda (European-format
    VINs)** — these marques share the same platform "type number" in positions
    7–8, which maps to a model line (e.g. `1K` → VW Golf Mk5/Mk6, `3C` → VW
    Passat, `8K` → Audi A4 B8, `5F` → SEAT Leon Mk3, `5E` → Škoda Octavia Mk3).
    This rule only fires when positions 4–6 are the `ZZZ` filler used on
    European VINs, so it never misfires on a North-American VIN that reuses
    those positions.

  Model labels are intentionally broad (model line / generation) so they stay
  robust; this is best-effort decoding from open VIN guides. More brands can be
  added easily — see
  [Adding model decoding for a brand](#adding-model-decoding-for-a-brand).

**NOT reliably decodable from the VIN alone:**

* the exact **trim, equipment, body style or engine** for an arbitrary vehicle.
  Any tool claiming to give you a precise build sheet for every make without a
  licensed manufacturer database is guessing.

If you need full model, equipment and build-sheet detail across all makes, that
requires manufacturer-licensed data —
[contact Quomation](https://www.quomation.com/contact/) and we can help.

## Installation

Clone or download the repository — there is nothing to compile and there are no
runtime dependencies.

```
vin-decoder/
├── README.md
├── LICENSE
├── php/
│   ├── VinDecoder.php           # the library (class Quomation\Vin\VinDecoder)
│   ├── vin.php                  # command-line wrapper
│   └── tests/VinDecoderTest.php # unit tests (PHPUnit)
└── python/
    ├── vin_decoder.py           # the library + CLI
    └── test_vin_decoder.py      # unit tests
```

* **Python:** version 3.7 or newer. No runtime packages to install.
* **PHP:** version 7.4 or newer. No extensions beyond the standard library.

## Using the Python version

**From the command line:**

```bash
python3 python/vin_decoder.py 1HGCM82633A004352
```

Add `--json` for machine-readable output:

```bash
python3 python/vin_decoder.py --json 1HGCM82633A004352
```

If you run it with no argument it will prompt you to type a VIN.

**As a library:**

```python
from vin_decoder import decode

result = decode("1HGCM82633A004352")

print(result.valid)          # True
print(result.country)        # United States
print(result.manufacturer)   # Honda (USA)
print(result.model_year)     # 2003
print(result.to_dict())      # full result as a dict
```

The `decode()` function never raises on bad input; instead it returns a result
whose `valid` flag is `False` and whose `errors` list explains what went wrong.

## Using the PHP version

**From the command line:**

```bash
php php/vin.php 1HGCM82633A004352
php php/vin.php --json 1HGCM82633A004352
```

**As a library:**

```php
require __DIR__ . '/php/VinDecoder.php';

use Quomation\Vin\VinDecoder;

$result = VinDecoder::decode("1HGCM82633A004352");

echo $result['valid'] ? "valid\n" : "invalid\n";
echo $result['country'] . "\n";        // United States
echo $result['manufacturer'] . "\n";   // Honda (USA)
echo $result['model_year'] . "\n";     // 2003

echo VinDecoder::formatReport($result); // human-readable report
```

Both implementations expose the same fields and produce the same results, so you
can use whichever language fits your stack.

## Running the tests

Both versions ship with a unit-test suite covering the check digit, country and
manufacturer detection, model-year resolution, model decoding and invalid
input. The same suites run automatically in CI on every push.

```bash
# Python — standalone, no dependencies (or run with pytest if installed)
python3 python/test_vin_decoder.py

# PHP — with PHPUnit (phpunit.phar or a system install)
phpunit php/tests/
```

## Output fields reference

| Field | Meaning |
|-------|---------|
| `vin` | The normalised 17-character VIN. |
| `valid` | `true` when the VIN is structurally sound **and** the check digit verifies. |
| `errors` | A list of human-readable problems (empty when everything is fine). |
| `region` | Broad geographic region from character 1. |
| `country` | Country of the manufacturing plant from characters 1–2. |
| `wmi` | The three-character World Manufacturer Identifier. |
| `manufacturer` | The marque resolved from the WMI table (or `null`/Unknown if not listed). |
| `model` | The model, for marques with a documented VDS scheme (or `null`). |
| `vds` | Raw positions 4–8, the manufacturer-specific descriptor. |
| `check_digit` | The character actually in position 9. |
| `check_digit_valid` | Whether that character matches the calculation. |
| `expected_check_digit` | What the calculation says position 9 should be. |
| `model_year` | Best single estimate of the model year. |
| `model_year_candidates` | Both possible years (the code repeats every 30 years). |
| `plant_code` | Position 11, the assembly-plant code (manufacturer-specific). |
| `serial_number` | Positions 12–17, the production serial number. |

## Worked example

Decoding the VIN `1HGCM82633A004352`:

```
VIN ........... 1HGCM82633A004352
Status ........ VALID
Region ........ North America
Country ....... United States
WMI ........... 1HG
Manufacturer .. Honda (USA)
Descriptor .... CM826  (positions 4-8, manufacturer-specific)
Model year .... 2003  (or 2033 - the year code repeats every 30 years)
Check digit ... 3 (OK)
Plant code .... A
Serial ........ 004352
```

Reading this back: the leading `1` and the `1HG` WMI tell us this is a Honda
built in the United States; position 10 (`3`) gives a 2003 model year; and the
recalculated check digit (`3`) matches position 9, so the VIN is internally
consistent and very likely genuine.

## Extending the manufacturer table

The bundled WMI table is representative, not exhaustive — there are several
thousand registered WMIs worldwide and new ones are issued regularly. If a VIN
returns `Unknown (WMI not in local table)`, the first three characters simply
are not in the local list yet; the rest of the decoding (country, year, check
digit) still works.

To add a manufacturer:

* **Python** — add an entry to the `_WMI` dictionary in `vin_decoder.py`, e.g.
  `"WP0": "Porsche (Germany)"`.
* **PHP** — add an entry to the array returned by `wmiTable()` in
  `VinDecoder.php`, e.g. `'WP0' => 'Porsche (Germany)'`.

## Adding model decoding for a brand

Model decoding is data-driven and brand-by-brand. Each rule says: "for this
WMI, look at one character of the VIN and map it to a model name." To add a
brand whose scheme you can document from a public source:

* **Python** — add an entry to `_MODEL_RULES` in `vin_decoder.py`:
  ```python
  "5YJ": {"at": 3, "models": {"S": "Model S", "3": "Model 3", "X": "Model X", "Y": "Model Y"}},
  ```
* **PHP** — add the matching entry to `modelRules()` in `VinDecoder.php`:
  ```php
  '5YJ' => ['at' => 3, 'models' => ['S' => 'Model S', '3' => 'Model 3', 'X' => 'Model X', 'Y' => 'Model Y']],
  ```

`at` is the zero-based character index in the VIN (so `3` is the 4th character).

For schemes that span several characters (like the VW/Audi type number), use a
slice instead of a single index, and optionally restrict the rule to
European-format VINs with the `ZZZ` filler:

```python
# Python
"WVW": {"from": 6, "to": 8, "filler_zzz": True, "models": {"1K": "Golf Mk5/Mk6 / Jetta"}},
```
```php
// PHP
'WVW' => ['from' => 6, 'len' => 2, 'filler_zzz' => true, 'models' => ['1K' => 'Golf Mk5/Mk6 / Jetta']],
```

Prefer broad model-line labels over exact trim — they are far less likely to be
wrong across generations and markets.

## Accuracy, limitations and edge cases

* **Check digit and non-North-American VINs.** The check digit is only
  guaranteed to be calculated for vehicles built for the North American market.
  A European or Asian VIN may legitimately have a check digit that does not
  satisfy the formula. For that reason the decoder reports `check_digit_valid`
  separately, and you can choose to treat a structurally sound VIN as acceptable
  even when the check digit does not verify.
* **Country ranges.** Country detection uses the published two-character ranges.
  A small number of low-volume countries are grouped at the regional level for
  simplicity; the major automotive nations are all covered.
* **Pre-1981 VINs.** Vehicles built before the 1981 harmonisation may use
  shorter or differently structured VINs and are out of scope for this tool.
* **The model is intentionally not guessed.** As explained above, the model and
  trim cannot be derived from the VIN without licensed manufacturer data, so the
  decoder reports the raw descriptor rather than a possibly-wrong model name.

This tool is designed to be transparent and conservative: it tells you what the
VIN genuinely encodes and is honest about what it cannot know.

## Licence and copyright

Copyright, Quomation Inc, 2026.

Released under the **Apache License, Version 2.0** — see the [LICENSE](LICENSE)
file. You are free to use, modify and redistribute this software, including
commercially, provided you retain the copyright and licence notices.

This checker provides basic functionality only, based on the data encoded in the
VIN itself. For more accurate information please get in touch with Quomation
using the official website contact details: <https://www.quomation.com/contact/>.
More about our work: <https://www.quomation.com/it-consulting-projects/>.

Reference for the VIN structure: the publicly documented ISO 3779 / ISO 3780
standards, as summarised on
[Wikipedia](https://en.wikipedia.org/wiki/Vehicle_identification_number).
