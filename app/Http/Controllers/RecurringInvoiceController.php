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

use App\Models\Article;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\Tenant;
use App\Services\InvoiceCalculator;
use App\Services\RecurringService;
use App\Support\ListFilter;
use App\Support\ListSort;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Wiederkehrende Rechnungen: Vorlagen verwalten; die Entwürfe entstehen automatisch zum Stichtag. */
class RecurringInvoiceController extends Controller
{
    public function __construct(private RecurringService $service) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $filters = [
            'state' => ListFilter::pick($request, 'state', ['active', 'paused', 'finished']),
            'interval' => ListFilter::pick($request, 'interval', array_keys(RecurringInvoice::INTERVALS)),
            'billing' => ListFilter::pick($request, 'billing', RecurringInvoice::BILLINGS),
            'drafts' => ListFilter::pick($request, 'drafts', ['1']),
        ];

        // „Beendet“ = Enddatum gesetzt und der nächste Stichtag liegt dahinter (siehe RecurringInvoice::isFinished()).
        $notFinished = fn ($q) => $q->where(fn ($w) => $w->whereNull('end_date')->orWhereColumn('next_date', '<=', 'end_date'));

        // Ohne Klick: aktive Vorlagen zuerst, dann nach nächstem Stichtag.
        $sort = ListSort::make($request, 'recurring', [
            'default' => fn ($q) => $q->orderByDesc('active')->orderBy('next_date'),
            'name' => 'name', 'customer' => ListSort::byCustomer('recurring_invoices'),
            'amount' => fn ($r) => $r->totals()['gross_total'], 'interval' => 'interval',
            'next_date' => 'next_date', 'end_date' => 'end_date', 'drafts' => 'drafts_count', 'status' => 'active',
        ], ['default', 'asc'], [['id', 'asc']]);

        $recurrings = RecurringInvoice::query()->with(['customer', 'items'])
            ->withCount(['invoices as drafts_count' => fn ($q) => $q->where('status', Invoice::STATUS_DRAFT)])
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)->orWhereHas('customer', fn ($c) => $c
                        ->where('company', 'like', $like)->orWhere('customer_number', 'like', $like));
                });
            })
            ->when($filters['state'] === 'finished', fn ($q) => $q->whereNotNull('end_date')->whereColumn('next_date', '>', 'end_date'))
            ->when($filters['state'] === 'active', fn ($q) => $notFinished($q->where('active', true)))
            ->when($filters['state'] === 'paused', fn ($q) => $notFinished($q->where('active', false)))
            ->when($filters['interval'] !== '', fn ($q) => $q->where('interval', $filters['interval']))
            ->when($filters['billing'] !== '', fn ($q) => $q->where('billing', $filters['billing']))
            ->when($filters['drafts'] === '1', fn ($q) => $q->whereHas('invoices', fn ($i) => $i->where('status', Invoice::STATUS_DRAFT)))
            ->when($sort->key !== 'amount', fn ($q) => $sort->apply($q), fn ($q) => $q->orderBy('id'))
            ->get();

        // Der Betrag wird aus den Positionen berechnet und lässt sich nicht in der Abfrage sortieren.
        if ($sort->key === 'amount') {
            $recurrings = $sort->sortCollection($recurrings);
        }

        return view('recurring.index', [
            'sort' => $sort, 'recurrings' => $recurrings, 'search' => $search, 'filters' => $filters,
            'filtered' => ListFilter::active($filters + ['q' => $search]),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('edit-invoices');

        $tenant = Tenant::findOrFail(Tenancy::id());
        $recurring = new RecurringInvoice([
            'active' => true, 'interval' => 'monthly', 'billing' => RecurringInvoice::ADVANCE,
            'next_date' => now()->addMonthNoOverflow()->startOfMonth(),
            'currency' => 'EUR', 'tax_mode' => 'standard', 'discount_percent' => 0,
        ]);

        return view('recurring.form', $this->formData($recurring, $tenant));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('edit-invoices');

        [$head, $items] = $this->validated($request);
        $recurring = $this->service->save(null, $head, $items, $request->user());

        return redirect()->route('recurring.edit', $recurring)->with('status', __('messages.recurring.saved'));
    }

    public function edit(RecurringInvoice $recurring): View
    {
        $recurring->load('items');

        return view('recurring.form', $this->formData($recurring, Tenant::findOrFail(Tenancy::id())) + [
            'generated' => $recurring->invoices()->orderByDesc('issue_date')->orderByDesc('id')->limit(24)->get(),
        ]);
    }

    public function update(Request $request, RecurringInvoice $recurring): RedirectResponse
    {
        $this->authorize('edit-invoices');

        [$head, $items] = $this->validated($request);
        $this->service->save($recurring, $head, $items, $request->user());

        return redirect()->route('recurring.edit', $recurring)->with('status', __('messages.recurring.saved'));
    }

    public function destroy(RecurringInvoice $recurring): RedirectResponse
    {
        $this->authorize('edit-invoices');

        $recurring->delete();

        return redirect()->route('recurring.index')->with('status', __('messages.recurring.deleted'));
    }

    /** Fällige Entwürfe sofort erzeugen (sonst macht es der tägliche Lauf). */
    public function run(Request $request): RedirectResponse
    {
        $this->authorize('edit-invoices');

        $result = $this->service->runForTenant(Tenant::findOrFail(Tenancy::id()), null, ['created' => 0, 'failed' => 0], $request->user());

        $flash = $result['failed'] > 0 ? 'warning' : 'status';

        return redirect()->route('recurring.index')->with($flash, __('messages.recurring.run_result', $result));
    }

    /** Aus einer vorhandenen Rechnung eine (pausierte) Vorlage anlegen und zum Bearbeiten öffnen. */
    public function fromInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('edit-invoices');

        if ($invoice->isCreditNote()) {
            return redirect()->route('invoices.show', $invoice)->with('warning', __('messages.recurring.no_credit_note'));
        }

        $recurring = $this->service->fromInvoice($invoice, $request->user());

        return redirect()->route('recurring.edit', $recurring)->with('status', __('messages.recurring.created_from_invoice'));
    }

    /** @return array<string, mixed> */
    private function formData(RecurringInvoice $recurring, Tenant $tenant): array
    {
        return [
            'recurring' => $recurring,
            'tenant' => $tenant,
            'customers' => Customer::selectable($recurring->customer_id)->orderBy('customer_number')->get(),
            'articles' => Article::where('active', true)->orderBy('name')->get(['id', 'article_number', 'name']),
            'taxRates' => config('invoice.tax_rates'),
        ];
    }

    /** @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>} */
    private function validated(Request $request): array
    {
        $input = $request->all();
        $input['discount_percent'] = $this->decimal($input['discount_percent'] ?? null);
        $input['active'] = $request->boolean('active');

        $items = [];
        foreach ((array) ($input['items'] ?? []) as $row) {
            $row = (array) $row;

            if (trim((string) ($row['description'] ?? '')) === '' && trim((string) ($row['unit_price'] ?? '')) === '') {
                continue;
            }

            foreach (['quantity', 'unit_price', 'discount_percent', 'tax_rate'] as $key) {
                $row[$key] = $this->decimal($row[$key] ?? null);
            }

            $row['discount_percent'] ??= 0;
            $items[] = $row;
        }
        $input['items'] = $items;
        $request->merge($input);

        $tenantId = Tenancy::id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'interval' => ['required', Rule::in(array_keys(RecurringInvoice::INTERVALS))],
            'billing' => ['required', Rule::in(RecurringInvoice::BILLINGS)],
            'next_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'currency' => ['required', Rule::in(config('invoice.currencies'))],
            'tax_mode' => ['required', Rule::in(InvoiceCalculator::TAX_MODES)],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'buyer_reference' => ['nullable', 'string', 'max:255'],
            'intro_text' => ['nullable', 'string', 'max:5000'],
            'footer_text' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'active' => ['boolean'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.article_id' => ['nullable', Rule::exists('articles', 'id')->where('tenant_id', $tenantId)],
            'items.*.description' => ['required', 'string', 'max:5000'],
            'items.*.quantity' => ['required', 'numeric', 'not_in:0', 'min:-999999', 'max:999999'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'numeric', 'min:-9999999', 'max:9999999'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $lines = $data['items'];
        unset($data['items']);

        return [$this->emptyToNull($data), array_map(fn ($line) => $this->emptyToNull($line), $lines)];
    }
}
