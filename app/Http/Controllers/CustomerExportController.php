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
use App\Models\Customer;
use App\Services\CustomerExporter;
use App\Support\CustomerFilter;
use App\Support\ListFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Kunden als CSV exportieren: Auswahl (aktuelle Liste oder alle), Felder und Format wählbar. */
class CustomerExportController extends Controller
{
    public function __construct(private CustomerExporter $exporter) {}

    public function form(Request $request): View
    {
        $this->authorize('edit-master-data');

        $options = CustomerFilter::options();
        ['search' => $search, 'filters' => $filters] = CustomerFilter::fromRequest($request, $options);
        $filtered = ListFilter::active($filters + ['q' => $search]);

        return view('customers.export', [
            'groups' => CustomerExporter::GROUPS,
            'prefs' => CustomerExporter::prefs($request->user()->customer_export_prefs),
            'filtered' => $filtered,
            'selectionCount' => CustomerFilter::query($search, $filters)->count(),
            'totalCount' => Customer::count(),
            'summary' => $this->summary($search, $filters),
        ]);
    }

    public function download(Request $request): StreamedResponse
    {
        $this->authorize('edit-master-data');

        $data = $request->validate([
            'scope' => ['required', Rule::in(['selection', 'all'])],
            'with_deleted' => ['nullable', 'boolean'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string', Rule::in(CustomerExporter::fields())],
            'delimiter' => ['required', Rule::in(array_keys(CustomerExporter::DELIMITERS))],
            'encoding' => ['required', Rule::in(CustomerExporter::ENCODINGS)],
            'date_format' => ['required', Rule::in(CustomerExporter::DATE_FORMATS)],
        ]);

        // Spalten immer in der festen Reihenfolge der Importvorlage, egal wie sie ankamen.
        $fields = array_values(array_intersect(CustomerExporter::fields(), $data['fields']));
        $options = [
            'delimiter' => $data['delimiter'], 'encoding' => $data['encoding'], 'date_format' => $data['date_format'],
        ];

        $catalog = CustomerFilter::options();
        ['search' => $search, 'filters' => $filters] = CustomerFilter::fromRequest($request, $catalog);
        if ($data['scope'] === 'all') {
            $search = '';
            $filters = [];
        }

        $query = CustomerFilter::query($search, $filters, $request->boolean('with_deleted'));
        $count = (clone $query)->count();

        // Einstellungen für das nächste Mal merken (ohne Protokolleintrag am Benutzer).
        DB::table('users')->where('id', $request->user()->id)->update([
            'customer_export_prefs' => json_encode(['fields' => $fields] + $options),
        ]);

        AuditLog::record('customers.exported', null, [
            'count' => $count, 'scope' => $data['scope'], 'fields' => $fields,
        ]);

        $charset = $options['encoding'] === 'cp1252' ? 'windows-1252' : 'UTF-8';

        return response()->streamDownload(function () use ($query, $fields, $options) {
            $out = fopen('php://output', 'w');
            $this->exporter->write($query, $fields, $options, $out);
            fclose($out);
        }, 'kunden-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset='.$charset]);
    }

    /** Die aktive Auswahl als lesbarer Text, z. B. „Status: Aktiv · Tag: #m“. */
    private function summary(string $search, array $filters): string
    {
        $parts = [];
        if ($search !== '') {
            $parts[] = __('messages.search').': '.$search;
        }
        if (($filters['kind'] ?? '') !== '') {
            $parts[] = __('messages.filter.kind').': '.__('messages.filter.kind_'.$filters['kind']);
        }
        if (($filters['country'] ?? '') !== '') {
            $parts[] = __('messages.customers.country').': '.$filters['country'];
        }
        if (($filters['currency'] ?? '') !== '') {
            $parts[] = __('messages.filter.currency').': '.$filters['currency'];
        }
        if (($filters['ref'] ?? '') === '1') {
            $parts[] = __('messages.filter.reference_set');
        }
        if (($filters['status'] ?? '') !== '') {
            $parts[] = __('messages.customers.status').': '.__('messages.filter.'.$filters['status']);
        }
        if (($filters['tag'] ?? '') !== '') {
            $parts[] = __('messages.customers.tags').': '.$filters['tag'];
        }

        return implode(' · ', $parts);
    }
}
