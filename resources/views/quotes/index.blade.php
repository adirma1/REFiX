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
@section('title', __('messages.nav.quotes'))
@section('content')
    <div class="page-head">
        <h1>{{ __('messages.nav.quotes') }}</h1>
        @can('edit-invoices')
            <div class="head-actions">
                <a class="btn" href="{{ route('quotes.create') }}">{{ __('messages.quotes.new') }}</a>
            </div>
        @endcan
    </div>
    <div class="toolbar">
        <form method="GET" action="{{ route('quotes.index') }}">
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('messages.search') }}">
            <select name="status" aria-label="{{ __('messages.invoices.status') }}">
                <option value="">{{ __('messages.invoices.all_status') }}</option>
                @foreach(['draft', 'open', 'expired', 'accepted', 'declined'] as $name)
                    <option value="{{ $name }}" @selected($status === $name)>{{ __('messages.quotes.status_'.$name) }}</option>
                @endforeach
            </select>
            <x-range-filter :label="__('messages.invoices.date')" :from="$dateFrom" :to="$dateTo"
                            :presets="\App\Support\DateRangeFilter::documentPresets()" route="quotes.index"/>
            <x-range-filter :label="__('messages.quotes.valid_until')" :from="$validFrom" :to="$validTo"
                            :presets="\App\Support\DateRangeFilter::validityPresets()" from-key="valid_from" to-key="valid_to" route="quotes.index"/>
            <button class="btn secondary" type="submit">{{ __('messages.search') }}</button>
        </form>
    </div>

    <table>
        <thead>
        <tr>
            <x-th-sort :sort="$sort" col="number">{{ __('messages.invoices.number') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="customer">{{ __('messages.invoices.customer') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="date">{{ __('messages.invoices.date') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="valid_until">{{ __('messages.quotes.valid_until') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="gross" class="num">{{ __('messages.invoices.gross') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="status">{{ __('messages.invoices.status') }}</x-th-sort>
        </tr>
        </thead>
        <tbody>
        @forelse($quotes as $quote)
            <tr>
                <td class="nowrap"><a href="{{ route('quotes.show', $quote) }}">{{ $quote->displayNumber() }}</a></td>
                <td>{{ $quote->customer?->company }} <span class="muted">{{ $quote->customer?->customer_number }}</span></td>
                <td class="nowrap">{{ $quote->issue_date->format('d.m.Y') }}</td>
                <td class="nowrap">{{ $quote->valid_until?->format('d.m.Y') }}</td>
                <td class="num">{{ number_format((float) $quote->gross_total, 2, ',', '.') }} {{ $quote->currency }}</td>
                <td>
                    @include('quotes._badge', ['quote' => $quote])
                    @if($quote->is_test)<span class="badge">{{ __('messages.testmode.on') }}</span>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('messages.quotes.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    @include('pagination.footer', ['paginator' => $quotes, 'id' => 'quotes'])
@endsection
