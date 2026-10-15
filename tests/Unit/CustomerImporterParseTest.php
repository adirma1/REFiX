<?php

/*
 * RE-FiX – Rechnungsprogramm
 * Copyright (C) 2026 Martin Adirsch
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of
 * version 2 of the GNU General Public License as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program
 * (file LICENSE). If not, see <https://www.gnu.org/licenses/old-licenses/gpl-2.0.html>.
 *
 * SPDX-License-Identifier: GPL-2.0-only
 */

namespace Tests\Unit;

use App\Services\Import\CustomerImporter;
use App\Services\Import\ImportInvalid;
use Tests\TestCase;

class CustomerImporterParseTest extends TestCase
{
    public function test_semicolon_comma_and_tab_are_detected(): void
    {
        foreach ([';' => ';', ',' => ',', "\t" => "\t"] as $delimiter) {
            $parsed = CustomerImporter::parse("Firma{$delimiter}Ort\r\nMüller GmbH{$delimiter}Bielefeld\r\n");

            $this->assertSame($delimiter, $parsed['delimiter']);
            $this->assertSame(['Firma', 'Ort'], $parsed['headers']);
            $this->assertSame([['Müller GmbH', 'Bielefeld']], $parsed['rows']);
        }
    }

    public function test_bom_quotes_and_blank_lines_are_handled(): void
    {
        $parsed = CustomerImporter::parse("\xEF\xBB\xBF\"Firma\";\"Ort\"\n\n\"Meier; Söhne\";\"Gütersloh\"\n;\n");

        $this->assertSame(['Firma', 'Ort'], $parsed['headers']);
        $this->assertSame([['Meier; Söhne', 'Gütersloh']], $parsed['rows']);
    }

    public function test_windows_1252_is_converted_to_utf8(): void
    {
        $csv = mb_convert_encoding("Firma;Ort\nMüller & Söhne;Düsseldorf\n", 'Windows-1252', 'UTF-8');

        $this->assertSame([['Müller & Söhne', 'Düsseldorf']], CustomerImporter::parse($csv)['rows']);
    }

    public function test_unusable_files_are_rejected(): void
    {
        foreach (["nur eine Spalte\nohne Trenner\n", "Firma;Ort\n", ''] as $csv) {
            try {
                CustomerImporter::parse($csv);
                $this->fail('Hätte abgelehnt werden müssen: '.$csv);
            } catch (ImportInvalid) {
                $this->addToAssertionCount(1);
            }
        }

        $big = "Firma;Ort\n".str_repeat("A;B\n", CustomerImporter::MAX_ROWS + 1);
        $this->expectException(ImportInvalid::class);
        CustomerImporter::parse($big);
    }

    public function test_headers_of_the_old_program_are_guessed(): void
    {
        $headers = ['Kd-Nr', 'Name1', 'Straße', 'PLZ', 'Ort', 'Land', 'E-Mail', 'Tel.', 'USt-IdNr.', 'Zahlungsziel', 'Bemerkung', 'Unbekannt'];
        $map = CustomerImporter::guess($headers);

        $this->assertSame(0, $map['customer_number']);
        $this->assertSame(1, $map['company']);
        $this->assertSame(2, $map['street']);
        $this->assertSame(3, $map['zip']);
        $this->assertSame(4, $map['city']);
        $this->assertSame(5, $map['country']);
        $this->assertSame(6, $map['email']);
        $this->assertSame(7, $map['phone']);
        $this->assertSame(8, $map['vat_id']);
        $this->assertSame(9, $map['payment_term_days']);
        $this->assertSame(10, $map['notes']);
        $this->assertNull($map['first_name']);
    }

    public function test_a_column_is_never_assigned_twice(): void
    {
        $map = CustomerImporter::guess(['Name', 'Straße']);

        $this->assertSame(0, $map['company']);
        $this->assertSame(1, $map['street']);
        $this->assertCount(2, array_filter($map, fn ($i) => $i !== null));
    }
}
