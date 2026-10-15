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

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\Import\CustomerImporter;
use App\Services\Import\ImportInvalid;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/** Kunden aus einer CSV-Datei importieren: Hochladen → Spalten zuordnen → Vorschau → Importieren. */
class CustomerImportController extends Controller
{
    private const TOKEN = '/^[a-f0-9]{32}$/';

    public function __construct(private CustomerImporter $importer) {}

    public function form(): View
    {
        $this->authorize('edit-master-data');

        return view('customers.import');
    }

    public function template(): Response
    {
        $this->authorize('edit-master-data');

        $labels = array_map(fn ($f) => $this->importer->label($f), CustomerImporter::FIELDS);
        $example = ['10001', 'Musterfirma GmbH', 'Herr', 'Max', 'Mustermann', 'Hauptstraße 1', '33378', 'Rheda-Wiedenbrück', 'DE',
            'rechnung@musterfirma.example', '05242 12345', 'DE123456789', 'EUR', '14', '', '', '', '', '01.01.2026', '', 'ja', '#m #xyz', '15.03.1980', 'www.musterfirma.example'];

        $out = "\xEF\xBB\xBF".implode(';', $labels)."\r\n".implode(';', $example)."\r\n";

        return response($out, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="kunden-vorlage.csv"',
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $this->authorize('edit-master-data');

        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:csv,txt']]);

        $raw = (string) file_get_contents($request->file('file')->getRealPath());

        try {
            CustomerImporter::parse($raw); // früh prüfen: lesbar, Spalten vorhanden, nicht zu groß
        } catch (ImportInvalid $e) {
            return redirect()->route('customers.import')->with('warning', $e->getMessage());
        }

        $this->prune();
        $token = bin2hex(random_bytes(16));
        Storage::disk('local')->put($this->path($token), $raw);

        return redirect()->route('customers.import.map', $token);
    }

    public function map(string $token): View|RedirectResponse
    {
        $this->authorize('edit-master-data');

        if (($parsed = $this->load($token)) === null) {
            return $this->expired();
        }

        return view('customers.import-map', [
            'token' => $token,
            'parsed' => $parsed,
            'mapping' => old('map', CustomerImporter::guess($parsed['headers'])),
            'fields' => CustomerImporter::FIELDS,
            'importer' => $this->importer,
        ]);
    }

    public function preview(Request $request, string $token): View|RedirectResponse
    {
        $this->authorize('edit-master-data');

        if (($parsed = $this->load($token)) === null) {
            return $this->expired();
        }

        $mapping = $this->mapping($request, count($parsed['headers']));
        $analysis = $this->importer->analyze($parsed['rows'], $mapping);

        return view('customers.import-preview', [
            'token' => $token,
            'mapping' => $mapping,
            'analysis' => $analysis,
            'problems' => array_slice(array_values(array_filter($analysis['rows'], fn ($r) => $r['status'] !== 'ok')), 0, 200),
            'samples' => array_slice(array_values(array_filter($analysis['rows'], fn ($r) => $r['status'] === 'ok')), 0, 10),
            'importer' => $this->importer,
        ]);
    }

    public function run(Request $request, string $token): RedirectResponse
    {
        $this->authorize('edit-master-data');

        if (($parsed = $this->load($token)) === null) {
            return $this->expired();
        }

        $result = $this->importer->run($parsed['rows'], $this->mapping($request, count($parsed['headers'])));

        Storage::disk('local')->delete($this->path($token));
        AuditLog::record('customer.imported', null, $result);

        return redirect()->route('customers.index')->with('status', __('messages.customer_import.done', $result));
    }

    /** @return array<string, int|null> Feld → Spaltenindex, nur gültige Spalten */
    private function mapping(Request $request, int $columns): array
    {
        $input = (array) $request->input('map', []);
        $mapping = [];

        foreach (CustomerImporter::FIELDS as $field) {
            $value = $input[$field] ?? '';
            $mapping[$field] = ($value !== '' && ctype_digit((string) $value) && (int) $value < $columns) ? (int) $value : null;
        }

        return $mapping;
    }

    /** @return array{headers: array<int, string>, rows: array<int, array<int, string>>, delimiter: string}|null */
    private function load(string $token): ?array
    {
        if (! preg_match(self::TOKEN, $token) || ! Storage::disk('local')->exists($this->path($token))) {
            return null;
        }

        try {
            return CustomerImporter::parse(Storage::disk('local')->get($this->path($token)));
        } catch (ImportInvalid) {
            return null;
        }
    }

    private function path(string $token): string
    {
        return 'imports/'.Tenancy::id().'/'.$token.'.csv';
    }

    private function expired(): RedirectResponse
    {
        return redirect()->route('customers.import')->with('warning', __('messages.customer_import.expired'));
    }

    /** Hochgeladene Dateien, die nie importiert wurden, nach einem Tag wieder entfernen. */
    private function prune(): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files('imports/'.Tenancy::id()) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }
}
