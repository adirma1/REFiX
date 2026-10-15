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

use App\Services\Bank\BankCsvParser;
use App\Services\Bank\BankFileInvalid;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BankCsvParserTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            ['1.234,56', 123456], ['1,234.56', 123456], ['12,34-', -1234], ['(12,34)', -1234], ['-0,5', -50],
            ['100', 10000], ['1.234', 123400], ['12.34', 1234], ['+5,00', 500], ['1.234.567,89', 123456789],
            ['12,5 EUR', 1250], ['', null], ['abc', null],
        ];
    }

    #[DataProvider('amounts')]
    public function test_amounts_in_german_and_english_notation(string $text, ?int $cents): void
    {
        $this->assertSame($cents, BankCsvParser::amount($text));
    }

    public static function dates(): array
    {
        return [
            ['13.10.2026', '2026-10-13'], ['3.1.26', '2026-01-03'], ['2026-10-13', '2026-10-13'], ['2026-10-13T10:00:00', '2026-10-13'],
            ['13/10/2026', '2026-10-13'], ['31.02.2026', null], ['', null], ['gestern', null],
        ];
    }

    #[DataProvider('dates')]
    public function test_dates(string $text, ?string $expected): void
    {
        $this->assertSame($expected, BankCsvParser::date($text));
    }

    public function test_preamble_lines_before_the_header_are_skipped_and_columns_guessed(): void
    {
        $csv = "Umsatzanzeige;Datum:;15.10.2026\r\nIBAN:;DE02\r\n\r\n"
            ."Buchungstag;Valuta;Auftraggeber/Empfänger;Verwendungszweck;Betrag;Währung\r\n"
            ."13.10.2026;13.10.2026;Muster GmbH;RE-2026-0001;1.470,30;EUR\r\n"
            ."14.10.2026;14.10.2026;Lieferant;Miete;-50,00;EUR\r\n"
            .";;;;Kontostand;1.000,00\r\n";

        $parsed = BankCsvParser::parse($csv);
        $this->assertSame(';', $parsed['delimiter']);
        $this->assertSame(['Buchungstag', 'Valuta', 'Auftraggeber/Empfänger', 'Verwendungszweck', 'Betrag', 'Währung'], $parsed['headers']);

        $mapping = BankCsvParser::guess($parsed['headers']);
        $this->assertSame(['date' => 0, 'value_date' => 1, 'amount' => 4, 'currency' => 5, 'name' => 2, 'purpose' => 3],
            array_filter($mapping, fn ($v) => $v !== null));

        $result = BankCsvParser::convert($parsed['rows'], $mapping);
        $this->assertSame(1, $result['skipped']); // die Kontostand-Zeile
        $this->assertCount(2, $result['transactions']);
        $this->assertSame(147030, $result['transactions'][0]->cents);
        $this->assertSame('Muster GmbH', $result['transactions'][0]->name);
        $this->assertSame('RE-2026-0001', $result['transactions'][0]->purpose);
        $this->assertSame(-5000, $result['transactions'][1]->cents);
    }

    public function test_separate_credit_and_debit_columns(): void
    {
        $csv = "Datum,Haben,Soll,Name\n13.10.2026,\"1.000,00\",,Kunde\n14.10.2026,,\"25,00\",Lieferant\n";

        $parsed = BankCsvParser::parse($csv);
        $mapping = BankCsvParser::guess($parsed['headers']);
        $result = BankCsvParser::convert($parsed['rows'], $mapping);

        $this->assertSame([100000, -2500], array_map(fn ($t) => $t->cents, $result['transactions']));
    }

    public function test_windows_1252_file_is_converted(): void
    {
        $csv = mb_convert_encoding("Datum;Betrag;Verwendungszweck\n13.10.2026;10,00;Müller Rechnung\n", 'Windows-1252', 'UTF-8');

        $parsed = BankCsvParser::parse($csv);

        $this->assertSame('Müller Rechnung', $parsed['rows'][0][2]);
    }

    public function test_conversion_needs_a_date_and_an_amount_column(): void
    {
        $this->expectException(BankFileInvalid::class);

        BankCsvParser::convert([['13.10.2026', '1,00']], ['date' => null, 'amount' => 1]);
    }

    public function test_file_without_rows_is_rejected(): void
    {
        $this->expectException(BankFileInvalid::class);

        BankCsvParser::parse("Datum;Betrag;Zweck\n");
    }
}
