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

namespace App\Services\Bank;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/** Liest CAMT.052 / CAMT.053 / CAMT.054 (ISO 20022) – alle drei haben dieselbe Eintragsstruktur. */
class CamtParser
{
    public static function looks(string $raw): bool
    {
        return preg_match('/<(?:\w+:)?Document[\s>]/', substr($raw, 0, 4000)) === 1
            && preg_match('/camt\.0(?:52|53|54)/', substr($raw, 0, 4000)) === 1;
    }

    /** @throws BankFileInvalid */
    public static function parse(string $raw): ParsedStatement
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        // Manche Banken deklarieren ISO-8859-1, liefern aber UTF-8 (Umlaute wären sonst kaputt).
        if (preg_match('/^<\?xml[^>]*encoding=["\'](?!utf-8)[^"\']+["\']/i', $raw) && mb_check_encoding($raw, 'UTF-8') && preg_match('/[\x80-\xFF]/', $raw)) {
            $raw = preg_replace('/(^<\?xml[^>]*encoding=)["\'][^"\']+["\']/i', '$1"UTF-8"', $raw, 1);
        }

        // Keine DTD/Entitäten zulassen (XXE, "Billion Laughs").
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $raw)) {
            throw new BankFileInvalid(__('messages.bank.err_camt_invalid'));
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $ok) {
            throw new BankFileInvalid(__('messages.bank.err_camt_invalid'));
        }

        $xp = new DOMXPath($dom);
        $iban = null;
        $transactions = [];

        foreach ($xp->query('//*[local-name()="Ntry"]') as $entry) {
            /** @var DOMElement $entry */
            if ($iban === null) {
                $iban = self::text($xp, './ancestor::*[local-name()="Rpt" or local-name()="Stmt" or local-name()="Ntfctn"][1]/*[local-name()="Acct"]/*[local-name()="Id"]/*[local-name()="IBAN"]', $entry);
            }

            $status = self::text($xp, './*[local-name()="Sts"]', $entry)
                ?? self::text($xp, './*[local-name()="Sts"]/*[local-name()="Cd"]', $entry);
            if ($status !== null && strtoupper($status) !== 'BOOK') {
                continue; // vorgemerkte/offene Umsätze noch nicht übernehmen
            }

            $sign = strtoupper((string) self::text($xp, './*[local-name()="CdtDbtInd"]', $entry)) === 'DBIT' ? -1 : 1;
            if (strtolower((string) self::text($xp, './*[local-name()="RvslInd"]', $entry)) === 'true') {
                $sign = -$sign;
            }

            $amountNode = $xp->query('./*[local-name()="Amt"]', $entry)->item(0);
            if (! $amountNode instanceof DOMElement) {
                continue;
            }
            $currency = $amountNode->getAttribute('Ccy') ?: 'EUR';
            $entryCents = self::cents($amountNode->textContent);

            $booking = self::date(self::text($xp, './*[local-name()="BookgDt"]/*[local-name()="Dt"]', $entry)
                ?? self::text($xp, './*[local-name()="BookgDt"]/*[local-name()="DtTm"]', $entry));
            $value = self::date(self::text($xp, './*[local-name()="ValDt"]/*[local-name()="Dt"]', $entry)
                ?? self::text($xp, './*[local-name()="ValDt"]/*[local-name()="DtTm"]', $entry));
            $booking ??= $value;
            if ($booking === null) {
                throw new BankFileInvalid(__('messages.bank.err_camt_nodate'));
            }

            $entryRef = self::text($xp, './*[local-name()="AcctSvcrRef"]', $entry);
            $entryInfo = self::text($xp, './*[local-name()="AddtlNtryInf"]', $entry);

            $details = $xp->query('./*[local-name()="NtryDtls"]/*[local-name()="TxDtls"]', $entry);

            if ($details->length === 0) {
                $transactions[] = new ParsedTransaction($booking, $value, $sign * $entryCents, $currency, null, null,
                    self::clean($entryInfo), self::clean($entryRef));

                continue;
            }

            foreach ($details as $detail) {
                $cents = $entryCents;
                $amt = $xp->query('./*[local-name()="AmtDtls"]/*[local-name()="TxAmt"]/*[local-name()="Amt"] | ./*[local-name()="Amt"]', $detail)->item(0);
                if ($details->length > 1 && $amt instanceof DOMElement) {
                    $cents = self::cents($amt->textContent);
                } elseif ($details->length > 1) {
                    $cents = 0; // Sammelposten ohne Einzelbetrag: nicht aufteilbar
                }
                if ($cents === 0 && $details->length > 1) {
                    $transactions[] = new ParsedTransaction($booking, $value, $sign * $entryCents, $currency, null, null,
                        self::clean($entryInfo), self::clean($entryRef));
                    break;
                }

                // Bei Eingang ist der Zahler der Gegenpart (Dbtr), bei Ausgang der Empfänger (Cdtr).
                $party = $sign > 0 ? 'Dbtr' : 'Cdtr';
                $name = self::text($xp, './*[local-name()="RltdPties"]/*[local-name()="'.$party.'"]/*[local-name()="Nm"]', $detail)
                    ?? self::text($xp, './*[local-name()="RltdPties"]/*[local-name()="'.$party.'"]/*[local-name()="Pty"]/*[local-name()="Nm"]', $detail);
                $partyIban = self::text($xp, './*[local-name()="RltdPties"]/*[local-name()="'.$party.'Acct"]/*[local-name()="Id"]/*[local-name()="IBAN"]', $detail);

                $purpose = [];
                foreach ($xp->query('./*[local-name()="RmtInf"]/*[local-name()="Ustrd"]', $detail) as $u) {
                    $purpose[] = trim($u->textContent);
                }
                foreach ($xp->query('./*[local-name()="RmtInf"]/*[local-name()="Strd"]/*[local-name()="CdtrRefInf"]/*[local-name()="Ref"]', $detail) as $u) {
                    $purpose[] = trim($u->textContent);
                }
                $purposeText = self::clean(implode(' ', array_filter($purpose))) ?? self::clean($entryInfo);

                $reference = self::text($xp, './*[local-name()="Refs"]/*[local-name()="EndToEndId"]', $detail);
                if ($reference === null || strtoupper($reference) === 'NOTPROVIDED') {
                    $reference = self::text($xp, './*[local-name()="Refs"]/*[local-name()="AcctSvcrRef"]', $detail) ?? $entryRef;
                }

                $partyIban = $partyIban !== null ? strtoupper(str_replace(' ', '', $partyIban)) : null;

                $transactions[] = new ParsedTransaction($booking, $value, $sign * $cents, $currency, self::clean($name),
                    $partyIban, $purposeText, self::clean($reference));
            }
        }

        if ($transactions === []) {
            throw new BankFileInvalid(__('messages.bank.err_no_transactions'));
        }

        return new ParsedStatement('camt', $iban !== null ? strtoupper(str_replace(' ', '', $iban)) : null, $transactions);
    }

    private static function text(DOMXPath $xp, string $expr, DOMNode $context): ?string
    {
        $node = $xp->query($expr, $context)->item(0);
        $text = $node ? trim($node->textContent) : '';

        return $text === '' ? null : $text;
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim(preg_replace('/\s+/', ' ', $value));

        return $value === null || $value === '' ? null : mb_substr($value, 0, 2000);
    }

    private static function cents(string $amount): int
    {
        return (int) round(abs((float) trim($amount)) * 100);
    }

    private static function date(?string $value): ?string
    {
        return ($value !== null && preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m)) ? $m[1] : null;
    }
}
