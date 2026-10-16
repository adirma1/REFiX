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
    $editing = $quote->exists;
    $title = $editing
        ? __('messages.quotes.edit_title', ['number' => $quote->displayNumber()])
        : __('messages.quotes.new');

    $fmt = function ($v, int $min = 0, int $max = 4) {
        if ($v === null || $v === '') { return ''; }
        [$int, $dec] = array_pad(explode(',', number_format((float) $v, $max, ',', '')), 2, '');
        $dec = str_pad(rtrim($dec, '0'), $min, '0');
        return $dec === '' ? $int : $int.','.$dec;
    };

    $rows = old('items');
    if ($rows === null) {
        $rows = $quote->exists
            ? $quote->items->map(fn ($item) => [
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
          action="{{ $editing ? route('quotes.update', $quote) : route('quotes.store') }}"
          data-price-url="{{ route('invoices.article-price', '__ID__') }}">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.invoices.head') }}</h2>
            <div class="grid">
                <div class="field">
                    <label for="customer_id">{{ __('messages.invoices.customer') }} *</label>
                    <select id="customer_id" name="customer_id" required>
                        <option value="">–</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}"
                                    data-currency="{{ $customer->currency }}"
                                    data-buyer="{{ $customer->buyer_reference }}"
                                    @selected((string) $val('customer_id', $quote->customer_id) === (string) $customer->id)>
                                {{ $customer->customer_number }} – {{ $customer->company }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <x-field name="issue_date" type="date" :label="__('messages.invoices.date')" :value="$quote->issue_date?->format('Y-m-d')" required />
                <x-field name="valid_until" type="date" :label="__('messages.quotes.valid_until')" :value="$quote->valid_until?->format('Y-m-d')"
                         :tip="__('messages.quotes.valid_until_tip', ['days' => $tenant->quote_valid_days ?: 30])" />
            </div>
            <div class="grid">
                <x-select name="currency" :label="__('messages.customers.currency')"
                          :options="array_combine(config('invoice.currencies'), config('invoice.currencies'))" :value="$quote->currency" required />
                <x-tax-mode :value="$quote->tax_mode" />
                <x-field name="buyer_reference" :label="__('messages.customers.buyer_reference')" :value="$quote->buyer_reference" />
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
                         :value="(float) $quote->discount_percent > 0 ? $fmt($quote->discount_percent, 0, 2) : ''" />
                <div></div>
            </div>

            <div id="totals" class="totals-box"
                 data-l-net="{{ __('messages.invoices.net') }}" data-l-discount="{{ __('messages.invoices.total_discount') }}"
                 data-l-vat="{{ __('messages.invoices.vat_on') }}" data-l-gross="{{ __('messages.invoices.gross') }}"
                 data-l-preview="{{ __('messages.invoices.preview_note') }}"></div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.invoices.texts') }}</h2>
            <div class="field">
                <label for="intro_text">{{ __('messages.invoices.intro_text') }}
                    <span class="tip" data-tip="{{ __('messages.quotes.text_default_hint') }}" tabindex="0" aria-label="{{ __('messages.quotes.text_default_hint') }}">ⓘ</span>
                </label>
                <textarea id="intro_text" name="intro_text" placeholder="{{ __('messages.quotes.default_intro') }}">{{ $val('intro_text', $quote->intro_text) }}</textarea>
            </div>
            <div class="field">
                <label for="footer_text">{{ __('messages.invoices.footer_text') }}
                    <span class="tip" data-tip="{{ __('messages.quotes.text_default_hint') }}" tabindex="0" aria-label="{{ __('messages.quotes.text_default_hint') }}">ⓘ</span>
                </label>
                <textarea id="footer_text" name="footer_text" placeholder="{{ __('messages.quotes.default_footer') }}">{{ $val('footer_text', $quote->footer_text) }}</textarea>
            </div>
            <div class="field">
                <label for="internal_notes">{{ __('messages.invoices.internal_notes') }}
                    <span class="tip" data-tip="{{ __('messages.invoices.internal_hint') }}" tabindex="0" aria-label="{{ __('messages.invoices.internal_hint') }}">ⓘ</span>
                </label>
                <textarea id="internal_notes" name="internal_notes">{{ $val('internal_notes', $quote->internal_notes) }}</textarea>
            </div>
        </div>

        <button class="btn" type="submit">{{ $editing && ! $quote->isDraft() ? __('messages.save') : __('messages.invoices.save_draft') }}</button>
        <a class="btn secondary" href="{{ $editing ? route('quotes.show', $quote) : route('quotes.index') }}">{{ __('messages.cancel') }}</a>

        <template id="row-template">
            @include('invoices._row', ['i' => '__INDEX__', 'row' => ['quantity' => '1', 'unit' => 'Stück', 'tax_rate' => '19'], 'articles' => $articles, 'taxRates' => $taxRates])
        </template>
    </form>
@endsection
@push('scripts')
    <script src="{{ asset('js/invoice-form.js') }}?v={{ filemtime(public_path('js/invoice-form.js')) }}"></script>
@endpush
