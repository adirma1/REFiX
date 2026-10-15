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
@section('title', __('messages.payments.open_items'))
@section('content')
    @php($money = fn ($cents) => number_format($cents / 100, 2, ',', '.'))
    <h1>{{ __('messages.payments.open_items') }}</h1>
    @include('payments._tabs')

    <div class="tiles">
        <div class="tile"><div class="value">{{ $money($totals['open']) }}</div><div class="label">{{ __('messages.payments.total_open', ['n' => $totals['count']]) }}</div></div>
        <div class="tile"><div class="value">{{ $money($totals['overdue']) }}</div><div class="label">{{ __('messages.payments.total_overdue', ['n' => $totals['overdue_count']]) }}</div></div>
    </div>

    <div class="toolbar">
        <form method="GET" action="{{ route('payments.index') }}">
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('messages.search') }}">
            <select name="filter" aria-label="{{ __('messages.payments.filter') }}">
                <option value="all" @selected($filter === 'all')>{{ __('messages.payments.filter_all') }}</option>
                <option value="overdue" @selected($filter === 'overdue')>{{ __('messages.payments.filter_overdue') }}</option>
            </select>
            <button class="btn secondary" type="submit">{{ __('messages.search') }}</button>
        </form>
    </div>

    <div class="toolbar" data-pick-actions>
        <button class="btn small secondary" type="button" data-act="open" disabled>{{ __('messages.payments.open_invoice') }}</button>
        <button class="btn small" type="button" data-act="book" disabled>{{ __('messages.payments.book_payment') }}</button>
        <button class="btn small secondary" type="button" data-act="pdf" data-new-tab disabled>PDF</button>
        @can('send-mail')
            <button class="btn small secondary" type="button" data-act="mail" disabled>{{ __('messages.invoices.mail_button') }}</button>
        @endcan
        <span class="muted" id="pick-hint">{{ __('messages.payments.pick_first') }}</span>
    </div>

    <table>
        <thead>
        <tr>
            <th class="pick-col"></th>
            <th>{{ __('messages.invoices.number') }}</th>
            <th>{{ __('messages.invoices.customer') }}</th>
            <th>{{ __('messages.invoices.due') }}</th>
            <th class="num">{{ __('messages.invoices.gross') }}</th>
            <th class="num">{{ __('messages.payments.paid') }}</th>
            <th class="num">{{ __('messages.payments.open') }}</th>
            <th>{{ __('messages.invoices.status') }}</th>
        </tr>
        </thead>
        <tbody>
        @forelse($rows as $row)
            @php($invoice = $row['invoice'])
            @php($s = $row['summary'])
            <tr data-pick>
                <td class="pick-col"><input type="radio" class="pick-one" name="invoice" value="{{ $invoice->id }}" aria-label="{{ __('messages.payments.pick_row') }}"
                    data-open="{{ route('invoices.show', $invoice) }}" data-book="{{ route('invoices.show', $invoice) }}#payments"
                    data-pdf="{{ route('invoices.pdf', $invoice) }}" data-mail="{{ route('invoices.mail', $invoice) }}"></td>
                <td><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }}</a></td>
                <td>{{ $invoice->customer_snapshot['company'] ?? '' }} <span class="muted">{{ $invoice->customer_snapshot['customer_number'] ?? '' }}</span></td>
                <td>{{ $invoice->due_date?->format('d.m.Y') }}
                    @if($s['overdue'])<span class="badge warn">{{ __('messages.payments.days_overdue', ['n' => $s['days_overdue']]) }}</span>@endif
                </td>
                <td class="num">{{ $money($s['gross']) }} {{ $invoice->currency }}</td>
                <td class="num">{{ $money($s['paid'] + $s['discount'] + $s['credited']) }}</td>
                <td class="num"><strong>{{ $money($s['open']) }}</strong></td>
                <td>@include('payments._badge', ['status' => $s['status']])</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">{{ __('messages.payments.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <form id="pick-form" method="POST" action="{{ route('payments.index') }}" hidden>@csrf</form>
    @push('scripts')
        <script src="{{ asset('js/rowpick.js') }}?v={{ filemtime(public_path('js/rowpick.js')) }}"></script>
    @endpush
    @include('pagination.footer', ['paginator' => $rows, 'id' => 'payments'])
@endsection
