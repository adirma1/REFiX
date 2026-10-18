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

use App\Models\Dunning;
use App\Models\DunningHold;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\DunningInvalid;
use App\Services\DunningService;
use App\Support\ListSort;
use App\Support\PerPage;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/** Mahnwesen: Vorschlagsliste, erstellte Mahnungen, Mahnsperren. Nichts geschieht ohne Bestätigung. */
class DunningController extends Controller
{
    private const TABS = ['due', 'history', 'holds'];

    public function __construct(private DunningService $dunnings) {}

    public function index(Request $request): View
    {
        $this->authorize('manage-payments');

        $enabled = $this->enabled();
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'due';
        // Abgeschaltet: nur noch lesen (Mahnungen und Sperren), keine Vorschlagsliste.
        if (! $enabled && $tab === 'due') {
            $tab = 'history';
        }
        $perPage = PerPage::for($request->user());
        $data = [];

        // Rechnungsnummer der Mahnung bzw. Sperre per Unterabfrage, damit auch seitenübergreifend sortiert wird.
        $invoiceNumber = fn (string $table) => fn ($q, string $dir) => $q->orderByRaw(
            "(select number from invoices where invoices.id = {$table}.invoice_id) {$dir}"
        );

        if ($tab === 'due') {
            $sort = ListSort::make($request, 'dunning.due', [
                'default' => fn () => 0,
                'number' => fn (array $r) => $r['invoice']->number,
                'customer' => fn (array $r) => $r['invoice']->customer_snapshot['company'] ?? '',
                'due' => fn (array $r) => $r['invoice']->due_date?->timestamp ?? 0,
                'step' => fn (array $r) => $r['level'],
                'open' => fn (array $r) => $r['calc']['open'],
                'fee' => fn (array $r) => $r['calc']['fee'] + $r['calc']['interest'],
                'total' => fn (array $r) => $r['calc']['total'],
            ], ['default', 'asc']);
            $rows = $sort->sortCollection($this->dunnings->suggestions());
            $page = LengthAwarePaginator::resolveCurrentPage();
            $data['items'] = new LengthAwarePaginator(
                $rows->slice(($page - 1) * $perPage, $perPage)->values(), $rows->count(), $perPage, $page,
                ['path' => route('dunning.index'), 'query' => $request->query()]
            );
        } elseif ($tab === 'history') {
            $sort = ListSort::make($request, 'dunning.history', [
                'date' => 'dunned_on', 'number' => $invoiceNumber('dunnings'), 'step' => 'level', 'total' => 'total', 'pay_by' => 'pay_by',
                // Offen (0) vor versendet (1) vor storniert (2)
                'status' => fn ($q, string $dir) => $q->orderByRaw("case when voided_at is not null then 2 when sent_at is not null then 1 else 0 end {$dir}"),
            ], ['date', 'desc'], [['id', 'desc']]);
            $data['items'] = Dunning::with('invoice')->tap(fn ($q) => $sort->apply($q))->paginate($perPage)->withQueryString();
        } else {
            $sort = ListSort::make($request, 'dunning.holds', [
                'default' => fn () => null,
                'number' => $invoiceNumber('dunning_holds'), 'reason' => 'reason',
                // Ohne Datum = bis zur Freigabe: gilt als „unbegrenzt“ und steht aufsteigend zuletzt
                'until' => fn ($q, string $dir) => $q->orderByRaw("hold_until is null {$dir}")->orderBy('hold_until', $dir),
            ], ['default', 'asc'], [['id', 'desc']]);
            $data['items'] = DunningHold::active()->with('invoice')->tap(fn ($q) => $sort->apply($q))->paginate($perPage)->withQueryString();
        }

        return view('dunning.index', $data + [
            'sort' => $sort,
            'tab' => $tab,
            'enabled' => $enabled,
            'counts' => [
                'due' => $tab === 'due' ? $data['items']->total() : ($enabled ? $this->dunnings->suggestions()->count() : 0),
                'history' => Dunning::count(),
                'holds' => DunningHold::active()->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-payments');

        if (! $this->enabled()) {
            return $this->disabled();
        }

        $ids = array_filter(array_map('intval', (array) $request->input('invoices', [])));
        $created = 0;
        $errors = [];

        foreach (Invoice::whereIn('id', $ids)->get() as $invoice) {
            try {
                $this->dunnings->create($invoice, $request->user());
                $created++;
            } catch (DunningInvalid $e) {
                $errors[] = $invoice->number.': '.$e->getMessage();
            }
        }

        $redirect = redirect()->route('dunning.index', ['tab' => $created > 0 ? 'history' : null]);

        if ($created > 0) {
            $redirect->with('status', __('messages.dunning.created_n', ['n' => $created]));
        }
        if ($errors !== []) {
            $redirect->with('warning', implode(' · ', array_slice($errors, 0, 5)));
        } elseif ($created === 0) {
            $redirect->with('warning', __('messages.dunning.nothing_selected'));
        }

        return $redirect;
    }

    private function enabled(): bool
    {
        return (bool) Tenant::findOrFail(Tenancy::id())->dunning_enabled;
    }

    private function disabled(): RedirectResponse
    {
        return redirect()->route('dunning.index', ['tab' => 'history'])->with('warning', __('messages.dunning.disabled_notice'));
    }

    public function pdf(Request $request, Dunning $dunning): Response
    {
        $this->authorize('manage-payments');

        abort_unless($dunning->pdf_path && Storage::disk('local')->exists($dunning->pdf_path), 404);

        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', ($dunning->level === 1 ? 'Zahlungserinnerung-' : 'Mahnung-').$dunning->invoice->number).'.pdf';

        return response(Storage::disk('local')->get($dunning->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function void(Request $request, Dunning $dunning): RedirectResponse
    {
        $this->authorize('manage-payments');

        if (! $this->enabled()) {
            return $this->disabled();
        }

        try {
            $this->dunnings->void($dunning, $request->user());
        } catch (DunningInvalid $e) {
            return redirect()->route('dunning.index', ['tab' => 'history'])->with('warning', $e->getMessage());
        }

        return redirect()->route('dunning.index', ['tab' => 'history'])->with('status', __('messages.dunning.voided'));
    }

    public function sent(Request $request, Dunning $dunning): RedirectResponse
    {
        $this->authorize('manage-payments');

        if (! $this->enabled()) {
            return $this->disabled();
        }

        try {
            $this->dunnings->markSent($dunning, $request->user(), 'post');
        } catch (DunningInvalid $e) {
            return redirect()->route('dunning.index', ['tab' => 'history'])->with('warning', $e->getMessage());
        }

        return redirect()->route('dunning.index', ['tab' => 'history'])->with('status', __('messages.dunning.marked_sent'));
    }

    public function hold(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('manage-payments');

        if (! $this->enabled()) {
            return $this->disabled();
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'hold_until' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $this->dunnings->hold($invoice, $data['reason'], $data['hold_until'] ?? null, $request->user());

        return back()->with('status', __('messages.dunning.hold_set'));
    }

    /** Mahnsperre für mehrere markierte Rechnungen auf einmal (gleicher Grund, gleiches Datum). */
    public function holdMany(Request $request): RedirectResponse
    {
        $this->authorize('manage-payments');

        if (! $this->enabled()) {
            return $this->disabled();
        }

        $data = $request->validate([
            'invoices' => ['required', 'array', 'min:1'],
            'invoices.*' => ['integer'],
            'reason' => ['required', 'string', 'max:255'],
            'hold_until' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $held = 0;
        foreach (Invoice::whereIn('id', array_unique($data['invoices']))->get() as $invoice) {
            $this->dunnings->hold($invoice, $data['reason'], $data['hold_until'] ?? null, $request->user());
            $held++;
        }

        if ($held === 0) {
            return redirect()->route('dunning.index')->with('warning', __('messages.dunning.nothing_selected'));
        }

        return redirect()->route('dunning.index', ['tab' => 'holds'])->with('status', __('messages.dunning.hold_set_n', ['n' => $held]));
    }

    public function release(DunningHold $hold): RedirectResponse
    {
        $this->authorize('manage-payments');

        if (! $this->enabled()) {
            return $this->disabled();
        }

        $this->dunnings->release($hold);

        return back()->with('status', __('messages.dunning.hold_released'));
    }
}
