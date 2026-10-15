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
use App\Models\Tenant;
use App\Services\InvoiceCalculator;
use App\Services\InvoicePdf;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\Tenancy;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $service, private InvoicePdf $pdf) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $type = $request->query('type');
        $status = $request->query('status');

        $invoices = Invoice::query()->with('customer')
            ->when(in_array($type, [Invoice::TYPE_INVOICE, Invoice::TYPE_CREDIT_NOTE], true), fn ($q) => $q->where('type', $type))
            ->when(in_array($status, [Invoice::STATUS_DRAFT, Invoice::STATUS_ISSUED], true), fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($q) use ($like) {
                    $q->where('number', 'like', $like)
                        ->orWhereHas('customer', fn ($c) => $c->where('company', 'like', $like)->orWhere('customer_number', 'like', $like));
                });
            })
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->paginate(PerPage::for($request->user()))->withQueryString();

        $paymentStates = app(PaymentService::class)->summaries(
            $invoices->getCollection()->filter(fn (Invoice $i) => $i->status === Invoice::STATUS_ISSUED && ! $i->isCreditNote())
        );

        return view('invoices.index', [
            'paymentStates' => $paymentStates,
            'invoices' => $invoices, 'search' => $search, 'type' => $type, 'status' => $status,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('edit-invoices');

        $tenant = Tenant::findOrFail(Tenancy::id());
        $type = $request->query('type') === Invoice::TYPE_CREDIT_NOTE ? Invoice::TYPE_CREDIT_NOTE : Invoice::TYPE_INVOICE;

        $invoice = new Invoice([
            'type' => $type,
            'status' => Invoice::STATUS_DRAFT,
            'issue_date' => now(),
            'currency' => 'EUR',
            'tax_mode' => 'standard',
            'payment_term_days' => $tenant->default_payment_days,
            'discount_percent' => 0,
        ]);

        // Folgerechnung zu einer Serie: Kunde, Währung und Konditionen der Teilrechnung übernehmen
        $series = $type === Invoice::TYPE_INVOICE && $request->filled('series')
            ? Invoice::query()->where('status', Invoice::STATUS_ISSUED)->find($request->query('series'))
            : null;

        if ($series && $series->isPartial()) {
            $invoice->fill([
                'billing_kind' => $request->query('billing') === Invoice::BILLING_FINAL ? Invoice::BILLING_FINAL : Invoice::BILLING_PARTIAL,
                'series_invoice_id' => $series->seriesRootId(),
                'customer_id' => $series->customer_id,
                'currency' => $series->currency,
                'tax_mode' => $series->tax_mode,
                'payment_term_days' => $series->payment_term_days,
                'skonto1_percent' => $series->skonto1_percent, 'skonto1_days' => $series->skonto1_days,
                'skonto2_percent' => $series->skonto2_percent, 'skonto2_days' => $series->skonto2_days,
                'buyer_reference' => $series->buyer_reference,
                'customer_order_ref' => $series->customer_order_ref,
            ]);
        } elseif ($request->filled('customer') && ($customer = Customer::selectable()->find($request->query('customer')))) {
            $invoice->customer_id = $customer->id;
            $invoice->currency = $customer->currency;
            $invoice->payment_term_days = $customer->payment_term_days ?? $tenant->default_payment_days;
            $invoice->skonto1_percent = $customer->discount_percent;
            $invoice->skonto1_days = $customer->discount_days;
            $invoice->buyer_reference = $customer->buyer_reference;
        }

        return view('invoices.form', $this->formData($invoice, $tenant));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('edit-invoices');

        [$head, $items] = $this->validated($request);
        $type = $request->input('type') === Invoice::TYPE_CREDIT_NOTE ? Invoice::TYPE_CREDIT_NOTE : Invoice::TYPE_INVOICE;

        try {
            $invoice = $this->service->saveDraft(null, $head, $items, $request->user(), $type);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('warning', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', __('messages.invoices.saved'));
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load('items', 'customer', 'originalInvoice');

        $payments = app(PaymentService::class);
        $showPayments = ! $invoice->isDraft() && ! $invoice->isCreditNote();

        return view('invoices.show', [
            'showPayments' => $showPayments,
            'paymentList' => $showPayments ? $invoice->payments()->get() : collect(),
            'paymentSummary' => $showPayments ? $payments->summary($invoice) : null,
            'bookable' => $payments->bookable($invoice),
            'invoice' => $invoice,
            'tenant' => Tenant::findOrFail(Tenancy::id()),
            'mailLogs' => $invoice->isDraft() ? collect() : $invoice->mailLogs()->with('user:id,name')->limit(20)->get(),
            'credits' => $invoice->isCreditNote() ? collect() : Invoice::where('original_invoice_id', $invoice->id)->get(),
            'seriesList' => $this->seriesList($invoice),
            'seriesClosed' => $invoice->inSeries() && $invoice->seriesClosed(),
        ]);
    }

    public function edit(Invoice $invoice): View|RedirectResponse
    {
        if (! $invoice->isDraft()) {
            return redirect()->route('invoices.show', $invoice)->with('warning', __('messages.invoices.not_editable'));
        }

        $this->authorize('edit-invoices');
        $invoice->load('items');

        return view('invoices.form', $this->formData($invoice, Tenant::findOrFail(Tenancy::id())));
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('edit-invoices');

        if (! $invoice->isDraft()) {
            return redirect()->route('invoices.show', $invoice)->with('warning', __('messages.invoices.not_editable'));
        }

        [$head, $items] = $this->validated($request);
        try {
            $this->service->saveDraft($invoice, $head, $items, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('warning', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', __('messages.invoices.saved'));
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorize('edit-invoices');

        if (! $invoice->isDraft()) {
            return redirect()->route('invoices.show', $invoice)->with('warning', __('messages.invoices.not_deletable'));
        }

        $invoice->delete();

        return redirect()->route('invoices.index')->with('status', __('messages.invoices.deleted'));
    }

    public function issue(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('issue-invoices');

        try {
            $invoice = $this->service->issue($invoice, $request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('invoices.show', $invoice)->with('warning', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('status', __('messages.invoices.issued', ['number' => $invoice->number]));
    }

    public function creditNote(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('edit-invoices');

        try {
            $credit = $this->service->createCreditNote($invoice, $request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('invoices.show', $invoice)->with('warning', __('messages.invoices.credit_not_possible'));
        }

        return redirect()->route('invoices.edit', $credit)->with('status', __('messages.invoices.credit_created'));
    }

    /** Archiv-PDF eines freigegebenen Belegs neu erzeugen (Fassung bleibt als Kopie erhalten, protokolliert). */
    public function rebuildPdf(Invoice $invoice): RedirectResponse
    {
        $this->authorize('issue-invoices');

        try {
            $this->pdf->rebuildArchive($invoice);
        } catch (RuntimeException $e) {
            return redirect()->route('invoices.show', $invoice)->with('warning', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', __('messages.invoices.pdf_rebuilt'));
    }

    public function pdf(Request $request, Invoice $invoice): Response
    {
        $bytes = $this->pdf->archivedOrRender($invoice);

        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoice->number ?: 'Entwurf-'.$invoice->id).'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Preis, Einheit und Steuersatz eines Artikels für die Positionszeile. */
    public function articlePrice(Request $request, Article $article): JsonResponse
    {
        $this->authorize('edit-invoices');

        $currency = strtoupper((string) $request->query('currency', 'EUR'));
        $customerId = $request->filled('customer') ? (int) $request->query('customer') : null;
        $date = $request->query('date') ?: now()->toDateString();

        $price = $article->priceFor($date, $currency, $customerId);

        return response()->json([
            'description' => trim($article->name.($article->description ? "\n".$article->description : '')),
            'unit' => $article->unit,
            'tax_rate' => (float) $article->tax_rate,
            'price' => $price ? (float) $price->amount : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(Invoice $invoice, Tenant $tenant): array
    {
        return [
            'invoice' => $invoice,
            'tenant' => $tenant,
            'customers' => Customer::selectable($invoice->customer_id)->orderBy('customer_number')->get(),
            'articles' => Article::where('active', true)->orderBy('name')->get(['id', 'article_number', 'name']),
            'taxRates' => config('invoice.tax_rates'),
            'seriesOptions' => $this->seriesOptions(),
        ];
    }

    /**
     * Freigegebene Teilrechnungen, an die sich eine weitere Teil- oder die Schlussrechnung anhängen lässt
     * (Serien ohne freigegebene Schlussrechnung).
     *
     * @return \Illuminate\Support\Collection<int, Invoice>
     */
    private function seriesOptions(): \Illuminate\Support\Collection
    {
        $closed = Invoice::query()
            ->where('type', Invoice::TYPE_INVOICE)->where('status', Invoice::STATUS_ISSUED)
            ->where('billing_kind', Invoice::BILLING_FINAL)->whereNotNull('series_invoice_id')
            ->pluck('series_invoice_id')->all();

        return Invoice::query()
            ->where('type', Invoice::TYPE_INVOICE)->where('status', Invoice::STATUS_ISSUED)
            ->where('billing_kind', Invoice::BILLING_PARTIAL)
            ->orderByDesc('id')->limit(300)->get()
            ->reject(fn (Invoice $i) => in_array($i->seriesRootId(), $closed, true))
            ->values();
    }

    /** Alle Belege der Serie (auch Entwürfe) für die Übersicht in der Detailansicht. */
    private function seriesList(Invoice $invoice): \Illuminate\Support\Collection
    {
        $root = $invoice->seriesRootId();

        if ($root === null) {
            return collect();
        }

        return Invoice::query()
            ->where('type', Invoice::TYPE_INVOICE)
            ->where(fn ($q) => $q->where('id', $root)->orWhere('series_invoice_id', $root))
            ->orderByRaw("CASE WHEN status = 'issued' THEN 0 ELSE 1 END")->orderBy('series_no')->orderBy('id')
            ->get();
    }

    /** @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>} */
    private function validated(Request $request): array
    {
        $input = $request->all();

        foreach (['discount_percent', 'skonto1_percent', 'skonto2_percent'] as $key) {
            $input[$key] = $this->decimal($input[$key] ?? null);
        }

        // Leere Positionszeilen ignorieren, Zahlen normalisieren.
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
            'service_date_from' => ['nullable', 'date'],
            'service_date_to' => ['nullable', 'date', 'after_or_equal:service_date_from'],
            'currency' => ['required', Rule::in(config('invoice.currencies'))],
            'tax_mode' => ['required', Rule::in(InvoiceCalculator::TAX_MODES)],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'skonto1_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'skonto1_days' => ['nullable', 'integer', 'min:0', 'max:365', 'required_with:skonto1_percent'],
            'skonto2_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'skonto2_days' => ['nullable', 'integer', 'min:0', 'max:365', 'required_with:skonto2_percent'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'billing_kind' => ['nullable', Rule::in(Invoice::BILLING_KINDS)],
            'series_invoice_id' => ['nullable', Rule::exists('invoices', 'id')->where('tenant_id', $tenantId)],
            'buyer_reference' => ['nullable', 'string', 'max:255'],
            'customer_order_ref' => ['nullable', 'string', 'max:255'],
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

        $lines = array_map(fn ($line) => $this->emptyToNull($line), $lines);

        return [$this->emptyToNull($data), $lines];
    }
}
