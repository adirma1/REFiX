{{--
    RE-FiX – Rechnungsprogramm
    Copyright (C) 2026 Martin Adirsch

    This program is free software; you can redistribute it and/or modify it under the terms of
    version 2 of the GNU General Public License as published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
    without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
    See the GNU General Public License for more details.

    You should have received a copy of the GNU General Public License along with this program
    (file LICENSE). If not, see <https://www.gnu.org/licenses/old-licenses/gpl-2.0.html>.

    SPDX-License-Identifier: GPL-2.0-only
--}}@extends('layouts.app')
@php
    $editing = $invoice->exists;
    $isCredit = $invoice->isCreditNote();
    $title = $editing
        ? __('messages.invoices.edit_title', ['title' => $invoice->documentTitle()])
        : ($isCredit ? __('messages.invoices.new_credit_note') : __('messages.invoices.new_invoice'));

    $fmt = function ($v, int $min = 0, int $max = 4) {
        if ($v === null || $v === '') { return ''; }
        [$int, $dec] = array_pad(explode(',', number_format((float) $v, $max, ',', '')), 2, '');
        $dec = str_pad(rtrim($dec, '0'), $min, '0');
        return $dec === '' ? $int : $int.','.$dec;
    };

    $rows = old('items');
    if ($rows === null) {
        $rows = $invoice->exists
            ? $invoice->items->map(fn ($item) => [
                'article_id' => $item->article_id, 'description' => $item->description,
                'quantity' => $fmt($item->quantity, 0, 4), 'unit' => $item->unit,
                'unit_price' => $fmt($item->unit_price, 2, 4), 'discount_percent' => (float) $item->discount_percent > 0 ? $fmt($item->discount_percent, 0, 2) : '',
                'tax_rate' => $fmt($item->tax_rate, 0, 2),
            ])->all()
            : [['quantity' => '1', 'unit' => 'Stück', 'tax_rate' => '19']];
    }
    $rows = array_values($rows);
    $val = fn ($key, $default = null) => old($key, $default);
@endphp
@section('title', $title)
@section('content')
    <h1>{{ $title }}</h1>

    @if($errors->any())
        <div class="alert error">
            <ul style="margin:0;padding-left:1.2rem">
                @foreach(array_unique($errors->all()) as $message)<li>{{ $message }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" id="invoice-form"
          action="{{ $editing ? route('invoices.update', $invoice) : route('invoices.store') }}"
          data-price-url="{{ route('invoices.article-price', '__ID__') }}">
        @csrf
        @if($editing) @method('PUT') @endif
        <input type="hidden" name="type" value="{{ $invoice->type }}">

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.invoices.head') }}</h2>
            @unless($isCredit)
                <div class="grid">
                    <x-select name="billing_kind" :label="__('messages.invoices.billing_kind')"
                              :options="['normal' => __('messages.invoices.billing_normal'), 'partial' => __('messages.invoices.billing_partial'), 'final' => __('messages.invoices.billing_final')]"
                              :value="$invoice->billing_kind ?? 'normal'" required />
                    <div class="field" id="series-field">
                        <label for="series_invoice_id">{{ __('messages.invoices.series_select') }}</label>
                        <select id="series_invoice_id" name="series_invoice_id">
                            <option value="">{{ __('messages.invoices.series_none') }}</option>
                            @foreach($seriesOptions as $option)
                                <option value="{{ $option->id }}" @selected((string) old('series_invoice_id', $invoice->series_invoice_id) === (string) $option->seriesRootId() || (string) old('series_invoice_id', $invoice->series_invoice_id) === (string) $option->id)>
                                    {{ $option->number }} – {{ $option->customer_snapshot['company'] ?? '' }} ({{ $option->issue_date->format('d.m.Y') }}, {{ number_format((float) $option->gross_total, 2, ',', '.') }} {{ $option->currency }})
                                </option>
                            @endforeach
                        </select>
                        @error('series_invoice_id')<div class="error">{{ $message }}</div>@enderror
                        <div class="hint">{{ __('messages.invoices.series_hint') }}</div>
                    </div>
                </div>
            @endunless
            <div class="grid">
                <div class="field">
                    <label for="customer_id">{{ __('messages.invoices.customer') }} *</label>
                    <select id="customer_id" name="customer_id" required>
                        <option value="">–</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}"
                                    data-currency="{{ $customer->currency }}"
                                    data-term="{{ $customer->payment_term_days }}"
                                    data-s1p="{{ $customer->discount_percent !== null ? $fmt($customer->discount_percent, 0, 2) : '' }}"
                                    data-s1d="{{ $customer->discount_days }}"
                                    data-buyer="{{ $customer->buyer_reference }}"
                                    @selected((string) $val('customer_id', $invoice->customer_id) === (string) $customer->id)>
                                {{ $customer->customer_number }} – {{ $customer->company }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <x-field name="issue_date" type="date" :label="__('messages.invoices.date')" :value="$invoice->issue_date?->format('Y-m-d')" required />
                <x-field name="service_date_from" type="date" :label="__('messages.invoices.service_from')" :value="$invoice->service_date_from?->format('Y-m-d')" />
                <x-field name="service_date_to" type="date" :label="__('messages.invoices.service_to')" :value="$invoice->service_date_to?->format('Y-m-d')" />
            </div>
            <div class="grid">
                <x-select name="currency" :label="__('messages.customers.currency')"
                          :options="array_combine(config('invoice.currencies'), config('invoice.currencies'))" :value="$invoice->currency" required />
                <x-select name="tax_mode" :label="__('messages.invoices.tax_mode')"
                          :options="collect(config('invoice.tax_modes'))->mapWithKeys(fn ($m) => [$m => __('messages.invoices.tax_modes.'.$m)])->all()"
                          :value="$invoice->tax_mode" required />
                <x-field name="buyer_reference" :label="__('messages.customers.buyer_reference')" :value="$invoice->buyer_reference" />
                <x-field name="customer_order_ref" :label="__('messages.invoices.order_ref')" :value="$invoice->customer_order_ref" />
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.invoices.positions') }}</h2>
            <table class="items-edit">
                <thead>
                <tr>
                    <th>#</th>
                    <th>{{ __('messages.invoices.description') }}</th>
                    <th>{{ __('messages.invoices.quantity') }} / {{ __('messages.invoices.unit') }}</th>
                    <th>{{ __('messages.invoices.unit_price') }}</th>
                    <th>{{ __('messages.invoices.discount') }} %</th>
                    <th>{{ __('messages.invoices.tax') }}</th>
                    <th class="num">{{ __('messages.invoices.net') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody id="items-body">
                @foreach($rows as $i => $row)
                    @include('invoices._row', ['i' => $i, 'row' => $row, 'articles' => $articles, 'taxRates' => $taxRates])
                @endforeach
                </tbody>
            </table>
            <p><button type="button" class="btn secondary small" id="add-row">+ {{ __('messages.invoices.add_position') }}</button></p>
            <datalist id="unit-list">
                @foreach(config('invoice.units') as $unit)<option value="{{ $unit }}">@endforeach
            </datalist>

            <div class="grid">
                <x-field name="discount_percent" :label="__('messages.invoices.total_discount')"
                         :value="(float) $invoice->discount_percent > 0 ? $fmt($invoice->discount_percent, 0, 2) : ''" />
                <div></div>
            </div>

            <div id="totals" class="totals-box"
                 data-l-net="{{ __('messages.invoices.net') }}" data-l-discount="{{ __('messages.invoices.total_discount') }}"
                 data-l-vat="{{ __('messages.invoices.vat_on') }}" data-l-gross="{{ __('messages.invoices.gross') }}"
                 data-l-preview="{{ __('messages.invoices.preview_note') }}"></div>
        </div>

        @unless($isCredit)
            <div class="card">
                <h2 style="margin-top:0">{{ __('messages.invoices.payment_terms') }}</h2>
                <div class="grid">
                    <x-field name="payment_term_days" type="number" :label="__('messages.customers.payment_term_days')" :value="$invoice->payment_term_days" />
                    <x-field name="skonto1_percent" :label="__('messages.invoices.skonto1_percent')" :value="$invoice->skonto1_percent !== null ? $fmt($invoice->skonto1_percent, 0, 2) : ''" />
                    <x-field name="skonto1_days" type="number" :label="__('messages.invoices.skonto1_days')" :value="$invoice->skonto1_days" />
                    <x-field name="skonto2_percent" :label="__('messages.invoices.skonto2_percent')" :value="$invoice->skonto2_percent !== null ? $fmt($invoice->skonto2_percent, 0, 2) : ''" />
                    <x-field name="skonto2_days" type="number" :label="__('messages.invoices.skonto2_days')" :value="$invoice->skonto2_days" />
                </div>
            </div>
        @endunless

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.invoices.texts') }}</h2>
            <div class="field">
                <label for="intro_text">{{ __('messages.invoices.intro_text') }}
                    <span class="tip" data-tip="{{ __('messages.invoices.text_default_hint') }}" tabindex="0" aria-label="{{ __('messages.invoices.text_default_hint') }}">ⓘ</span>
                </label>
                <textarea id="intro_text" name="intro_text" placeholder="{{ $tenant->invoice_intro }}">{{ $val('intro_text', $invoice->intro_text) }}</textarea>
            </div>
            <div class="field">
                <label for="footer_text">{{ __('messages.invoices.footer_text') }}
                    <span class="tip" data-tip="{{ __('messages.invoices.text_default_hint') }}" tabindex="0" aria-label="{{ __('messages.invoices.text_default_hint') }}">ⓘ</span>
                </label>
                <textarea id="footer_text" name="footer_text" placeholder="{{ $tenant->invoice_footer }}">{{ $val('footer_text', $invoice->footer_text) }}</textarea>
            </div>
            <div class="field">
                <label for="internal_notes">{{ __('messages.invoices.internal_notes') }}
                    <span class="tip" data-tip="{{ __('messages.invoices.internal_hint') }}" tabindex="0" aria-label="{{ __('messages.invoices.internal_hint') }}">ⓘ</span>
                </label>
                <textarea id="internal_notes" name="internal_notes">{{ $val('internal_notes', $invoice->internal_notes) }}</textarea>
            </div>
        </div>

        <button class="btn" type="submit">{{ __('messages.invoices.save_draft') }}</button>
        <a class="btn secondary" href="{{ $editing ? route('invoices.show', $invoice) : route('invoices.index') }}">{{ __('messages.cancel') }}</a>

        <template id="row-template">
            @include('invoices._row', ['i' => '__INDEX__', 'row' => ['quantity' => '1', 'unit' => 'Stück', 'tax_rate' => '19'], 'articles' => $articles, 'taxRates' => $taxRates])
        </template>
    </form>
@endsection
@push('scripts')
    <script>
        // Serienauswahl nur bei Teil- und Schlussrechnungen zeigen
        (function () {
            var kind = document.getElementById('billing_kind');
            var field = document.getElementById('series-field');
            if (!kind || !field) { return; }
            var sync = function () { field.style.display = kind.value === 'normal' ? 'none' : ''; };
            kind.addEventListener('change', sync);
            sync();
        })();
    </script>
    <script src="{{ asset('js/invoice-form.js') }}?v={{ filemtime(public_path('js/invoice-form.js')) }}"></script>
@endpush
