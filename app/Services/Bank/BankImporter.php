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

use App\Models\AuditLog;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/** Speichert gelesene Kontobewegungen. Schon bekannte Bewegungen (gleicher Fingerabdruck) werden übersprungen. */
class BankImporter
{
    public function import(ParsedStatement $statement, string $filename, ?User $user): BankImport
    {
        return DB::transaction(function () use ($statement, $filename, $user) {
            $import = BankImport::create([
                'filename' => mb_substr($filename, 0, 255),
                'format' => $statement->format,
                'account_iban' => $statement->accountIban,
                'tx_count' => count($statement->transactions),
                'imported_by' => $user?->id,
            ]);

            // Fingerabdruck pro Bewegung; gleiche Bewegungen in derselben Datei werden durchnummeriert,
            // damit zwei echte Zahlungen gleichen Betrags nicht als Dublette gelten.
            $seen = [];
            $rows = [];
            foreach ($statement->transactions as $tx) {
                $base = implode('|', [
                    Tenancy::id(), $tx->bookingDate, $tx->cents, strtoupper((string) $tx->iban),
                    mb_strtolower((string) $tx->purpose), mb_strtolower((string) $tx->reference),
                ]);
                $seen[$base] = ($seen[$base] ?? -1) + 1;
                $rows[] = [$tx, sha1($base.'|'.$seen[$base])];
            }

            $existing = BankTransaction::whereIn('dedupe_hash', array_column($rows, 1))->pluck('dedupe_hash')->flip()->all();

            $new = 0;
            $duplicates = 0;
            foreach ($rows as [$tx, $hash]) {
                if (isset($existing[$hash])) {
                    $duplicates++;

                    continue;
                }

                BankTransaction::create([
                    'bank_import_id' => $import->id,
                    'booking_date' => $tx->bookingDate,
                    'value_date' => $tx->valueDate,
                    'amount' => number_format($tx->cents / 100, 2, '.', ''),
                    'currency' => $tx->currency,
                    'counterparty_name' => $tx->name !== null ? mb_substr($tx->name, 0, 255) : null,
                    'counterparty_iban' => $tx->iban,
                    'purpose' => $tx->purpose,
                    'reference' => $tx->reference !== null ? mb_substr($tx->reference, 0, 255) : null,
                    'dedupe_hash' => $hash,
                    // Ausgänge (und Nullbeträge) braucht die Zahlungszuordnung nicht.
                    'status' => $tx->cents > 0 ? BankTransaction::NEW : BankTransaction::IGNORED,
                ]);
                $new++;
            }

            $import->forceFill(['new_count' => $new, 'duplicate_count' => $duplicates])->save();

            AuditLog::record('bank.imported', null, [
                'file' => $filename, 'format' => $statement->format, 'total' => count($rows), 'new' => $new, 'duplicates' => $duplicates,
            ]);

            return $import;
        });
    }
}
