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
use App\Services\DunningInvalid;
use App\Services\DunningService;
use App\Support\PerPage;
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

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'due';
        $perPage = PerPage::for($request->user());
        $data = [];

        if ($tab === 'due') {
            $rows = $this->dunnings->suggestions();
            $page = LengthAwarePaginator::resolveCurrentPage();
            $data['items'] = new LengthAwarePaginator(
                $rows->slice(($page - 1) * $perPage, $perPage)->values(), $rows->count(), $perPage, $page,
                ['path' => route('dunning.index'), 'query' => $request->query()]
            );
        } elseif ($tab === 'history') {
            $data['items'] = Dunning::with('invoice')->orderByDesc('id')->paginate($perPage)->withQueryString();
        } else {
            $data['items'] = DunningHold::active()->with('invoice')->orderByDesc('id')->paginate($perPage)->withQueryString();
        }

        return view('dunning.index', $data + [
            'tab' => $tab,
            'counts' => [
                'due' => $tab === 'due' ? $data['items']->total() : $this->dunnings->suggestions()->count(),
                'history' => Dunning::count(),
                'holds' => DunningHold::active()->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-payments');

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

        $this->dunnings->release($hold);

        return back()->with('status', __('messages.dunning.hold_released'));
    }
}
