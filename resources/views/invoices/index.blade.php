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
@section('title', __('messages.nav.invoices'))
@section('content')
    <h1>{{ __('messages.nav.invoices') }}</h1>
    <div class="toolbar">
        <form method="GET" action="{{ route('invoices.index') }}">
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('messages.search') }}">
            <select name="type" aria-label="{{ __('messages.invoices.type') }}">
                <option value="">{{ __('messages.invoices.all_types') }}</option>
                <option value="invoice" @selected($type === 'invoice')>{{ __('messages.invoices.invoice') }}</option>
                <option value="credit_note" @selected($type === 'credit_note')>{{ __('messages.invoices.credit_note') }}</option>
            </select>
            <select name="status" aria-label="{{ __('messages.invoices.status') }}">
                <option value="">{{ __('messages.invoices.all_status') }}</option>
                <option value="draft" @selected($status === 'draft')>{{ __('messages.invoices.status_draft') }}</option>
                <option value="issued" @selected($status === 'issued')>{{ __('messages.invoices.status_issued') }}</option>
            </select>
            <button class="btn secondary" type="submit">{{ __('messages.search') }}</button>
        </form>
        <span class="spacer"></span>
        @can('edit-invoices')
            <a class="btn" href="{{ route('invoices.create') }}">{{ __('messages.invoices.new_invoice') }}</a>
            <a class="btn secondary" href="{{ route('invoices.create', ['type' => 'credit_note']) }}">{{ __('messages.invoices.new_credit_note') }}</a>
        @endcan
    </div>

    <table>
        <thead>
        <tr>
            <th>{{ __('messages.invoices.number') }}</th>
            <th>{{ __('messages.invoices.type') }}</th>
            <th>{{ __('messages.invoices.customer') }}</th>
            <th>{{ __('messages.invoices.date') }}</th>
            <th class="num">{{ __('messages.invoices.gross') }}</th>
            <th>{{ __('messages.invoices.status') }}</th>
        </tr>
        </thead>
        <tbody>
        @forelse($invoices as $invoice)
            <tr>
                <td><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->displayNumber() }}</a></td>
                <td>{{ $invoice->documentTitle() }}</td>
                <td>{{ $invoice->customer?->company }} <span class="muted">{{ $invoice->customer?->customer_number }}</span></td>
                <td>{{ $invoice->issue_date->format('d.m.Y') }}</td>
                <td class="num">{{ number_format((float) $invoice->gross_total, 2, ',', '.') }} {{ $invoice->currency }}</td>
                <td>
                    <span @class(['badge', 'ok' => ! $invoice->isDraft(), 'warn' => $invoice->isDraft()])>
                        {{ $invoice->isDraft() ? __('messages.invoices.status_draft') : __('messages.invoices.status_issued') }}
                    </span>
                    @if($invoice->is_test)<span class="badge">{{ __('messages.testmode.on') }}</span>@endif
                    @if(isset($paymentStates[$invoice->id]) && ! $invoice->is_test)
                        @include('payments._badge', ['status' => $paymentStates[$invoice->id]['status']])
                        @if($paymentStates[$invoice->id]['overdue'])<span class="badge warn">{{ __('messages.payments.days_overdue', ['n' => $paymentStates[$invoice->id]['days_overdue']]) }}</span>@endif
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('messages.invoices.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    @include('pagination.footer', ['paginator' => $invoices, 'id' => 'invoices'])
@endsection
