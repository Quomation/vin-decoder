#!/usr/bin/env python3
# -*- coding: utf-8 -*-
#
# Copyright, Quomation Inc, 2026
#
# This checker provides basic functionality only, based on the data encoded in
# the VIN itself. For more accurate information please get in touch with
# Quomation using the official website contact details:
# https://www.quomation.com/contact/
#
# Licensed under the Apache License, Version 2.0. See the LICENSE file.

"""Unit tests for the offline VIN decoder.

Run with:  python3 -m pytest python/
Or simply: python3 python/test_vin_decoder.py
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from vin_decoder import compute_check_digit, decode  # noqa: E402


# --------------------------------------------------------------------------- #
# Check digit
# --------------------------------------------------------------------------- #

def test_check_digit_numeric():
    # Canonical valid VIN; the check digit (position 9) is 3.
    assert compute_check_digit("1HGCM82633A004352") == "3"


def test_check_digit_letter_x():
    # Wikipedia worked example; the remainder is 10, written as X.
    assert compute_check_digit("1M8GDM9AXKP042788") == "X"


# --------------------------------------------------------------------------- #
# Full decode of known-valid VINs
# --------------------------------------------------------------------------- #

def test_honda_accord_usa():
    r = decode("1HGCM82633A004352")
    assert r.valid is True
    assert r.region == "North America"
    assert r.country == "United States"
    assert r.manufacturer == "Honda (USA)"
    assert r.model_year == 2003
    assert r.check_digit_valid is True


def test_wikipedia_example_check_x():
    r = decode("1M8GDM9AXKP042788")
    assert r.valid is True
    assert r.check_digit == "X"
    assert r.country == "United States"


def test_tesla_model_3_usa():
    r = decode("5YJ3E1EA7HF000337")  # valid check digit
    assert r.valid is True
    assert r.manufacturer == "Tesla (USA)"
    assert r.model == "Model 3"


def test_tesla_model_s_usa():
    r = decode("5YJSA1E27HF000337")  # valid check digit
    assert r.valid is True
    assert r.manufacturer == "Tesla (USA)"
    assert r.model == "Model S"


def test_vw_golf_european():
    r = decode("WVWZZZ1K68W000000")  # EU format, type number 1K
    assert r.manufacturer == "Volkswagen (Germany)"
    assert r.model == "Golf Mk5/Mk6 / Jetta"


def test_audi_a4_european():
    r = decode("WAUZZZ8K49A000000")  # EU format, type number 8K
    assert r.manufacturer == "Audi (Germany)"
    assert r.model == "A4 (B8)"


def test_skoda_octavia_european():
    r = decode("TMBZZZ5E9G0000000")  # EU format, type number 5E
    assert r.manufacturer == "Skoda (Czech Republic)"
    assert r.model == "Octavia Mk3"


def test_seat_leon_european():
    r = decode("VSSZZZ5F1F0000000")  # EU format, type number 5F
    assert r.manufacturer == "SEAT (Spain)"
    assert r.model == "Leon Mk3"


def test_vw_us_format_has_no_model():
    # North-American VW VIN: positions 4-6 are not the ZZZ filler, so the
    # European type-number scheme must NOT misfire.
    r = decode("3VWLL7AJ3DM000000")
    assert r.manufacturer == "Volkswagen (Mexico)"
    assert r.model is None


# --------------------------------------------------------------------------- #
# Country / manufacturer detection (model-independent)
# --------------------------------------------------------------------------- #

def test_country_detection():
    assert decode("WBA3A5C50CF256916").country == "Germany"
    assert decode("JH4KA8260MC000000").country == "Japan"
    assert decode("ZFF67NFA7E0195864").country == "Italy"
    assert decode("KMH1234567A000000").country == "South Korea"


def test_manufacturer_detection():
    assert decode("WBA3A5C50CF256916").manufacturer == "BMW (Germany)"
    assert decode("ZFF67NFA7E0195864").manufacturer == "Ferrari (Italy)"
    assert decode("1GTV2TEC0FZ000000").manufacturer == "GMC Truck (USA)"


# --------------------------------------------------------------------------- #
# Model year resolution
# --------------------------------------------------------------------------- #

def test_model_year_candidates():
    r = decode("1HGCM82633A004352")
    assert set(r.model_year_candidates) == {2003, 2033}


# --------------------------------------------------------------------------- #
# Invalid input handling
# --------------------------------------------------------------------------- #

def test_wrong_length():
    r = decode("ABC123")
    assert r.valid is False
    assert any("17 characters" in e for e in r.errors)


def test_forbidden_letters():
    # Contains an O and a Q, which are never allowed.
    r = decode("1HGCM8263OAQ04352")
    assert r.valid is False
    assert any("forbidden" in e.lower() for e in r.errors)


def test_check_digit_mismatch_is_reported():
    # Same as the Honda VIN but with a deliberately wrong check digit.
    r = decode("1HGCM82693A004352")
    assert r.valid is False
    assert r.check_digit_valid is False
    assert r.expected_check_digit == "3"


def test_normalisation():
    # Lower case, spaces and dashes should be cleaned up.
    r = decode(" 1hg-cm826 33a0043 52 ")
    assert r.vin == "1HGCM82633A004352"
    assert r.valid is True


if __name__ == "__main__":
    # Allow running the file directly without pytest installed.
    failures = 0
    for name, fn in sorted(globals().items()):
        if name.startswith("test_") and callable(fn):
            try:
                fn()
                print(f"PASS  {name}")
            except AssertionError as exc:
                failures += 1
                print(f"FAIL  {name}: {exc}")
    print(f"\n{'OK' if failures == 0 else str(failures) + ' FAILURES'}")
    sys.exit(1 if failures else 0)
