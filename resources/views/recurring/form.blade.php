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
    $editing = $recurring->exists;
    $title = $editing ? __('messages.recurring.edit_title', ['name' => $recurring->name]) : __('messages.recurring.new');

    $fmt = function ($v, int $min = 0, int $max = 4) {
        if ($v === null || $v === '') { return ''; }
        [$int, $dec] = array_pad(explode(',', number_format((float) $v, $max, ',', '')), 2, '');
        $dec = str_pad(rtrim($dec, '0'), $min, '0');
        return $dec === '' ? $int : $int.','.$dec;
    };

    $rows = old('items');
    if ($rows === null) {
        $rows = $recurring->exists
            ? $recurring->items->map(fn ($item) => [
                'article_id' => $item->article_id, 'description' => $item->description,
                'quantity' => $fmt($item->quantity, 0, 4), 'unit' => $item->unit,
                'unit_price' => $fmt($item->unit_price, 2, 4), 'discount_percent' => (float) $item->discount_percent > 0 ? $fmt($item->discount_percent, 0, 2) : '',
                'tax_rate' => $fmt($item->tax_rate, 0, 2),
            ])->all()
            : [['quantity' => '1', 'unit' => 'Stück', 'tax_rate' => '19']];
    }
    $rows = array_values($rows);
    $val = fn ($key, $default = null) => old($key, $default);
    $activeValue = session()->hasOldInput() ? (bool) old('active') : $recurring->active;
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

    @if($editing && $recurring->state() === 'finished')
        <div class="alert warn">{{ __('messages.recurring.finished_note') }}</div>
    @endif

    <form method="POST" id="invoice-form"
          action="{{ $editing ? route('recurring.update', $recurring) : route('recurring.store') }}"
          data-price-url="{{ route('invoices.article-price', '__ID__') }}">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.recurring.head') }}</h2>
            <div class="grid">
                <x-field name="name" :label="__('messages.recurring.name')" :value="$recurring->name" required />
                <div class="field">
                    <label for="customer_id">{{ __('messages.invoices.customer') }} *</label>
                    <select id="customer_id" name="customer_id" required data-fill-name="name"
                            data-placeholder="{{ __('messages.invoices.customer_search') }}" data-pick-msg="{{ __('messages.invoices.customer_pick') }}" data-none-msg="{{ __('messages.invoices.customer_none') }}">
                        <option value="">–</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}"
                                    data-name="{{ $customer->company }}"
                                    data-currency="{{ $customer->currency }}"
                                    data-buyer="{{ $customer->buyer_reference }}"
                                    @selected((string) $val('customer_id', $recurring->customer_id) === (string) $customer->id)>
                                {{ $customer->customer_number }} – {{ $customer->company }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="active">{{ __('messages.recurring.state') }}</label>
                    <label style="font-weight:normal"><input type="checkbox" id="active" name="active" value="1" @checked($activeValue)> {{ __('messages.recurring.active') }}</label>
                </div>
            </div>
            <div class="grid">
                <x-select name="interval" :label="__('messages.recurring.interval')"
                          :options="collect(array_keys(\App\Models\RecurringInvoice::INTERVALS))->mapWithKeys(fn ($k) => [$k => __('messages.recurring.interval_'.$k)])->all()"
                          :value="$recurring->interval" required />
                <x-select name="billing" :label="__('messages.recurring.billing')"
                          :options="collect(\App\Models\RecurringInvoice::BILLINGS)->mapWithKeys(fn ($k) => [$k => __('messages.recurring.billing_'.$k)])->all()"
                          :value="$recurring->billing" required />
                <x-field name="next_date" type="date" :label="__('messages.recurring.next_date')" :value="$recurring->next_date?->format('Y-m-d')" required
                         :tip="__('messages.recurring.next_date_tip')" />
                <x-field name="end_date" type="date" :label="__('messages.recurring.end_date')" :value="$recurring->end_date?->format('Y-m-d')"
                         :tip="__('messages.recurring.end_date_tip')" />
            </div>
            <div class="grid">
                <x-select name="currency" :label="__('messages.customers.currency')"
                          :options="array_combine(config('invoice.currencies'), config('invoice.currencies'))" :value="$recurring->currency" required />
                <x-tax-mode :value="$recurring->tax_mode" />
                <x-field name="payment_term_days" type="number" :label="__('messages.customers.payment_term_days')" :value="$recurring->payment_term_days"
                         :tip="__('messages.recurring.term_tip')" />
                <x-field name="buyer_reference" :label="__('messages.customers.buyer_reference')" :value="$recurring->buyer_reference" />
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.invoices.positions') }}</h2>
            <p class="muted">{{ __('messages.recurring.placeholder_help') }}</p>
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
                         :value="(float) $recurring->discount_percent > 0 ? $fmt($recurring->discount_percent, 0, 2) : ''" />
                <div></div>
            </div>
            <p class="hint">{{ __('messages.recurring.price_note') }}</p>

            <div id="totals" class="totals-box"
                 data-l-net="{{ __('messages.invoices.net') }}" data-l-discount="{{ __('messages.invoices.total_discount') }}"
                 data-l-vat="{{ __('messages.invoices.vat_on') }}" data-l-gross="{{ __('messages.invoices.gross') }}"
                 data-l-preview="{{ __('messages.invoices.preview_note') }}"></div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.invoices.texts') }}</h2>
            <div class="field">
                <label for="intro_text">{{ __('messages.invoices.intro_text') }}</label>
                <textarea id="intro_text" name="intro_text">{{ $val('intro_text', $recurring->intro_text) }}</textarea>
            </div>
            <div class="field">
                <label for="footer_text">{{ __('messages.invoices.footer_text') }}</label>
                <textarea id="footer_text" name="footer_text">{{ $val('footer_text', $recurring->footer_text) }}</textarea>
            </div>
            <div class="field">
                <label for="internal_notes">{{ __('messages.invoices.internal_notes') }}</label>
                <textarea id="internal_notes" name="internal_notes">{{ $val('internal_notes', $recurring->internal_notes) }}</textarea>
            </div>
        </div>

        <button class="btn" type="submit">{{ __('messages.save') }}</button>
        <a class="btn secondary" href="{{ route('recurring.index') }}">{{ __('messages.cancel') }}</a>

        <template id="row-template">
            @include('invoices._row', ['i' => '__INDEX__', 'row' => ['quantity' => '1', 'unit' => 'Stück', 'tax_rate' => '19'], 'articles' => $articles, 'taxRates' => $taxRates])
        </template>
    </form>

    @if($editing)
        <form method="POST" action="{{ route('recurring.destroy', $recurring) }}" style="margin-top:1rem" onsubmit="return confirm('{{ __('messages.recurring.confirm_delete') }}')">
            @csrf @method('DELETE')
            <button class="btn danger" type="submit">{{ __('messages.delete') }}</button>
        </form>

        <div class="card" style="margin-top:1.5rem">
            <h2 style="margin-top:0">{{ __('messages.recurring.generated') }}</h2>
            <table>
                <thead>
                <tr>
                    <th>{{ __('messages.invoices.number') }}</th>
                    <th>{{ __('messages.invoices.date') }}</th>
                    <th>{{ __('messages.recurring.period') }}</th>
                    <th class="num">{{ __('messages.invoices.gross') }}</th>
                    <th>{{ __('messages.invoices.status') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($generated as $invoice)
                    <tr>
                        <td class="nowrap"><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->displayNumber() }}</a></td>
                        <td class="nowrap">{{ $invoice->issue_date->format('d.m.Y') }}</td>
                        <td class="nowrap">{{ $invoice->service_date_from?->format('d.m.Y') }} – {{ $invoice->service_date_to?->format('d.m.Y') }}</td>
                        <td class="num">{{ number_format((float) $invoice->gross_total, 2, ',', '.') }} {{ $invoice->currency }}</td>
                        <td>{{ $invoice->isDraft() ? __('messages.invoices.status_draft') : __('messages.invoices.status_issued') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">{{ __('messages.recurring.none_generated') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
@endsection
@push('scripts')
    <script src="{{ asset('js/customer-combo.js') }}?v={{ filemtime(public_path('js/customer-combo.js')) }}"></script>
    <script src="{{ asset('js/invoice-form.js') }}?v={{ filemtime(public_path('js/invoice-form.js')) }}"></script>
@endpush
