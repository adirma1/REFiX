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

use App\Services\Bank\BankFileInvalid;
use App\Services\Bank\BankFileReader;
use App\Services\Bank\CamtParser;
use Tests\TestCase;

class CamtParserTest extends TestCase
{
    private function sample(): string
    {
        return file_get_contents(__DIR__.'/../Support/bank/sample.camt052.xml');
    }

    public function test_format_is_detected_from_the_content(): void
    {
        $this->assertSame('camt', BankFileReader::detect($this->sample()));
    }

    public function test_booked_entries_are_read_and_pending_ones_skipped(): void
    {
        $statement = CamtParser::parse($this->sample());

        $this->assertSame('DE02120300000000202051', $statement->accountIban);
        // gebuchte Eingänge/Ausgänge + Sammelbuchung in zwei Teile; die vorgemerkte Buchung (PDNG) fehlt
        $this->assertCount(4, $statement->transactions);

        [$credit, $debit, $splitA, $splitB] = $statement->transactions;

        $this->assertSame('2026-10-13', $credit->bookingDate);
        $this->assertSame(147030, $credit->cents);
        $this->assertSame('Musterfirma GmbH', $credit->name);
        $this->assertSame('DE89370400440532013000', $credit->iban);
        $this->assertSame('Rechnung RE-2026-0001 vom 11.10.2026', $credit->purpose);
        $this->assertSame('RE2026-0001', $credit->reference);

        $this->assertSame(-5000, $debit->cents);
        $this->assertSame('Lieferant', $debit->name);

        $this->assertSame([10000, 20000], [$splitA->cents, $splitB->cents]);
        $this->assertSame(['A', 'B'], [$splitA->name, $splitB->name]);
        $this->assertSame(['RE-1', 'RE-2'], [$splitA->purpose, $splitB->purpose]);
    }

    public function test_reversal_flips_the_sign(): void
    {
        $xml = '<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>'
            .'<Acct><Id><IBAN>DE02120300000000202051</IBAN></Id></Acct>'
            .'<Ntry><Amt Ccy="EUR">12.50</Amt><CdtDbtInd>CRDT</CdtDbtInd><RvslInd>true</RvslInd><Sts>BOOK</Sts>'
            .'<BookgDt><Dt>2026-10-13</Dt></BookgDt><AddtlNtryInf>Rueckbuchung</AddtlNtryInf></Ntry>'
            .'</Stmt></BkToCstmrStmt></Document>';

        $tx = CamtParser::parse($xml)->transactions[0];

        $this->assertSame(-1250, $tx->cents);
        $this->assertSame('Rueckbuchung', $tx->purpose);
    }

    public function test_doctype_and_entities_are_rejected(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE Document [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
            .'<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.02"><Ntry>&x;</Ntry></Document>';

        $this->expectException(BankFileInvalid::class);

        CamtParser::parse($xml);
    }

    public function test_broken_xml_is_rejected(): void
    {
        $this->expectException(BankFileInvalid::class);

        CamtParser::parse('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.02"><Ntry>');
    }

    public function test_utf8_content_declared_as_latin1_keeps_its_umlauts(): void
    {
        $xml = '<?xml version="1.0" encoding="ISO-8859-1" ?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.08"><BkToCstmrAcctRpt><Rpt>'
            .'<Ntry><Amt Ccy="EUR">1.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts><Cd>BOOK</Cd></Sts><BookgDt><Dt>2026-10-13</Dt></BookgDt>'
            .'<NtryDtls><TxDtls><RltdPties><Dbtr><Pty><Nm>Müller</Nm></Pty></Dbtr></RltdPties><RmtInf><Ustrd>Büro</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>'
            .'</Rpt></BkToCstmrAcctRpt></Document>';

        $tx = CamtParser::parse($xml)->transactions[0];

        $this->assertSame('Müller', $tx->name);
        $this->assertSame('Büro', $tx->purpose);
    }
}
