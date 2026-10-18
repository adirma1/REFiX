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
use App\Models\Quote;
use App\Models\Tenant;
use App\Services\InvoiceCalculator;
use App\Services\QuotePdf;
use App\Services\QuoteService;
use App\Support\DateRangeFilter;
use App\Support\ListSort;
use App\Support\PerPage;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** Angebote: Liste, Erfassen, Freigeben, Status, Umwandlung in Rechnungsentwürfe, PDF. */
class QuoteController extends Controller
{
    public function __construct(private QuoteService $service, private QuotePdf $pdf) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $status = (string) $request->query('status');

        [$dateFrom, $dateTo] = DateRangeFilter::fromRequest($request);
        [$validFrom, $validTo] = DateRangeFilter::fromRequest($request, 'valid');

        $sort = ListSort::make($request, 'quotes', [
            'number' => 'number', 'customer' => ListSort::byCustomer('quotes'), 'date' => 'issue_date',
            'valid_until' => 'valid_until', 'gross' => 'gross_total', 'status' => 'status',
        ], ['date', 'desc'], [['id', 'desc']]);

        $quotes = Quote::query()->with('customer')
            ->when($status === 'expired', fn ($q) => $q->where('status', Quote::STATUS_OPEN)->whereDate('valid_until', '<', now()->toDateString()))
            ->when(in_array($status, Quote::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when($dateFrom !== '', fn ($q) => $q->whereDate('issue_date', '>=', $dateFrom))
            ->when($dateTo !== '', fn ($q) => $q->whereDate('issue_date', '<=', $dateTo))
            ->when($validFrom !== '', fn ($q) => $q->whereDate('valid_until', '>=', $validFrom))
            ->when($validTo !== '', fn ($q) => $q->whereDate('valid_until', '<=', $validTo))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($q) use ($like) {
                    $q->where('number', 'like', $like)
                        ->orWhereHas('customer', fn ($c) => $c->where('company', 'like', $like)->orWhere('customer_number', 'like', $like));
                });
            })
            ->tap(fn ($q) => $sort->apply($q))
            ->paginate(PerPage::for($request->user()))->withQueryString();

        return view('quotes.index', ['dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'validFrom' => $validFrom, 'validTo' => $validTo, 'sort' => $sort, 'quotes' => $quotes, 'search' => $search, 'status' => $status]);
    }

    public function create(Request $request): View
    {
        $this->authorize('edit-invoices');

        $tenant = Tenant::findOrFail(Tenancy::id());
        $quote = new Quote([
            'status' => Quote::STATUS_DRAFT,
            'issue_date' => now(),
            'valid_until' => now()->addDays((int) ($tenant->quote_valid_days ?: 30)),
            'currency' => 'EUR',
            'tax_mode' => 'standard',
            'discount_percent' => 0,
        ]);

        if ($request->filled('customer') && ($customer = Customer::find($request->query('customer')))) {
            $quote->customer_id = $customer->id;
            $quote->currency = $customer->currency;
            $quote->buyer_reference = $customer->buyer_reference;
        }

        return view('quotes.form', $this->formData($quote, $tenant));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('edit-invoices');

        [$head, $items] = $this->validated($request);
        $quote = $this->service->save(null, $head, $items, $request->user());

        return redirect()->route('quotes.show', $quote)->with('status', __('messages.quotes.saved'));
    }

    public function show(Quote $quote): View
    {
        $quote->load('items.invoice', 'customer');

        return view('quotes.show', [
            'quote' => $quote,
            'tenant' => Tenant::findOrFail(Tenancy::id()),
            'invoices' => $quote->invoices()->get(),
            'mailLogs' => $quote->isDraft() ? collect() : $quote->mailLogs()->with('user:id,name')->limit(20)->get(),
        ]);
    }

    public function edit(Quote $quote): View|RedirectResponse
    {
        $this->authorize('edit-invoices');

        if (! $this->editable($quote)) {
            return redirect()->route('quotes.show', $quote)->with('warning', __('messages.quotes.not_editable'));
        }

        $quote->load('items');

        return view('quotes.form', $this->formData($quote, Tenant::findOrFail(Tenancy::id())));
    }

    public function update(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorize('edit-invoices');

        if (! $this->editable($quote)) {
            return redirect()->route('quotes.show', $quote)->with('warning', __('messages.quotes.not_editable'));
        }

        [$head, $items] = $this->validated($request);
        $this->service->save($quote, $head, $items, $request->user());

        return redirect()->route('quotes.show', $quote)->with('status', __('messages.quotes.saved'));
    }

    public function destroy(Quote $quote): RedirectResponse
    {
        $this->authorize('edit-invoices');

        if (! $quote->isDraft()) {
            return redirect()->route('quotes.show', $quote)->with('warning', __('messages.quotes.not_deletable'));
        }

        $quote->delete();

        return redirect()->route('quotes.index')->with('status', __('messages.quotes.deleted'));
    }

    public function issue(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorize('issue-invoices');

        try {
            $quote = $this->service->issue($quote, $request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('quotes.show', $quote)->with('warning', $e->getMessage());
        }

        return redirect()->route('quotes.show', $quote)->with('status', __('messages.quotes.issued', ['number' => $quote->number]));
    }

    /** Angenommen, abgelehnt oder wieder offen. */
    public function status(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorize('edit-invoices');

        $data = $request->validate(['to' => ['required', Rule::in([Quote::STATUS_ACCEPTED, Quote::STATUS_DECLINED, Quote::STATUS_OPEN])]]);

        try {
            $this->service->setStatus($quote, $data['to']);
        } catch (RuntimeException $e) {
            return redirect()->route('quotes.show', $quote)->with('warning', $e->getMessage());
        }

        return redirect()->route('quotes.show', $quote)->with('status', __('messages.quotes.status_changed'));
    }

    /** Markierte (oder alle offenen) Positionen in einen Rechnungsentwurf übernehmen. */
    public function convert(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorize('edit-invoices');

        $data = $request->validate([
            'selection' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array'],
            'items.*' => ['integer'],
        ]);

        try {
            // Mit „selection“ zählt nur die Auswahl (auch eine leere); ohne Auswahlformular werden alle offenen Positionen übernommen.
            $invoice = $this->service->convert($quote, $request->boolean('selection') ? ($data['items'] ?? []) : null, $request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('quotes.show', $quote)->with('warning', $e->getMessage());
        }

        return redirect()->route('invoices.edit', $invoice)->with('status', __('messages.quotes.converted', ['number' => $quote->displayNumber()]));
    }

    public function pdf(Request $request, Quote $quote): Response
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $quote->number ?: 'Angebot-Entwurf-'.$quote->id).'.pdf';

        return response($this->pdf->render($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Ändern ist im Entwurf und bei offenen Angeboten möglich, solange noch nichts in eine Rechnung übernommen wurde. */
    private function editable(Quote $quote): bool
    {
        return $quote->isEditable() && ! $quote->items()->whereNotNull('invoice_id')->exists();
    }

    /** @return array<string, mixed> */
    private function formData(Quote $quote, Tenant $tenant): array
    {
        return [
            'quote' => $quote,
            'tenant' => $tenant,
            'customers' => Customer::orderBy('customer_number')->get(),
            'articles' => Article::where('active', true)->orderBy('name')->get(['id', 'article_number', 'name']),
            'taxRates' => config('invoice.tax_rates'),
        ];
    }

    /** @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>} */
    private function validated(Request $request): array
    {
        $input = $request->all();

        $input['discount_percent'] = $this->decimal($input['discount_percent'] ?? null);

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
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at')],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'currency' => ['required', Rule::in(config('invoice.currencies'))],
            'tax_mode' => ['required', Rule::in(InvoiceCalculator::TAX_MODES)],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'buyer_reference' => ['nullable', 'string', 'max:255'],
            'intro_text' => ['nullable', 'string', 'max:5000'],
            'footer_text' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
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
