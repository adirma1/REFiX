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
@section('title', __('messages.nav.recurring'))
@section('content')
    <div class="page-head">
        <h1>{{ __('messages.nav.recurring') }}</h1>
        @can('edit-invoices')
            <div class="head-actions">
                <form method="POST" action="{{ route('recurring.run') }}">
                    @csrf
                    <button class="btn secondary" type="submit">{{ __('messages.recurring.run_now') }}</button>
                </form>
                <a class="btn" href="{{ route('recurring.create') }}">{{ __('messages.recurring.new') }}</a>
            </div>
        @endcan
    </div>
    <p class="muted">{{ __('messages.recurring.intro') }}</p>
    <div class="toolbar">
        <form method="GET" action="{{ route('recurring.index') }}">
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('messages.search') }}">
            <select name="state" aria-label="{{ __('messages.invoices.status') }}">
                <option value="">{{ __('messages.filter.status_all') }}</option>
                @foreach(['active', 'paused', 'finished'] as $state)
                    <option value="{{ $state }}" @selected($filters['state'] === $state)>{{ __('messages.recurring.state_'.$state) }}</option>
                @endforeach
            </select>
            <select name="interval" aria-label="{{ __('messages.recurring.interval') }}">
                <option value="">{{ __('messages.filter.interval_all') }}</option>
                @foreach(['monthly', 'quarterly', 'yearly'] as $interval)
                    <option value="{{ $interval }}" @selected($filters['interval'] === $interval)>{{ __('messages.recurring.interval_'.$interval) }}</option>
                @endforeach
            </select>
            <select name="billing" aria-label="{{ __('messages.filter.billing') }}">
                <option value="">{{ __('messages.filter.billing_all') }}</option>
                @foreach(['advance', 'arrears'] as $billing)
                    <option value="{{ $billing }}" @selected($filters['billing'] === $billing)>{{ __('messages.recurring.billing_'.$billing) }}</option>
                @endforeach
            </select>
            <select name="drafts" aria-label="{{ __('messages.recurring.drafts') }}">
                <option value="">{{ __('messages.filter.drafts_all') }}</option>
                <option value="1" @selected($filters['drafts'] === '1')>{{ __('messages.filter.drafts_open') }}</option>
            </select>
            <button class="btn secondary" type="submit">{{ __('messages.search') }}</button>
            @if($filtered)
                <a class="btn secondary" href="{{ route('recurring.index') }}">{{ __('messages.filter.reset') }}</a>
            @endif
        </form>
    </div>

    <table class="recurring-table">
        <thead>
        <tr>
            <x-th-sort :sort="$sort" col="name">{{ __('messages.recurring.name') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="customer">{{ __('messages.invoices.customer') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="amount" class="col-amt num">{{ __('messages.invoices.gross') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="interval" class="col-fit">{{ __('messages.recurring.interval') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="next_date" class="col-date">{{ __('messages.recurring.next_date') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="end_date" class="col-date">{{ __('messages.recurring.end_date') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="drafts" class="col-fit num">{{ __('messages.recurring.drafts') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="status" class="col-fit">{{ __('messages.invoices.status') }}</x-th-sort>
        </tr>
        </thead>
        <tbody>
        @forelse($recurrings as $recurring)
            @php($state = $recurring->state())
            <tr>
                <td><a href="{{ route('recurring.edit', $recurring) }}">{{ $recurring->name }}</a></td>
                <td>{{ $recurring->customer?->company }} <span class="muted nowrap">{{ $recurring->customer?->customer_number }}</span>
                    @if($recurring->customer?->isBlockedFor($recurring->next_date))<br><span class="badge warn">{{ __('messages.customers.blocked_hint') }}</span>@endif</td>
                @php($total = $recurring->totals())
                <td class="num col-amt" title="{{ __('messages.recurring.amount_tip') }}">{{ number_format($total['gross_total'], 2, ',', '.') }}<br><span class="muted">{{ $recurring->currency }}</span></td>
                <td class="col-fit">{{ __('messages.recurring.interval_'.$recurring->interval) }}<br><span class="muted">{{ __('messages.recurring.billing_'.$recurring->billing) }}</span></td>
                <td class="col-date">{{ $recurring->next_date->format('d.m.Y') }}</td>
                <td class="col-date">{{ $recurring->end_date?->format('d.m.Y') }}</td>
                <td class="num col-fit">{{ $recurring->drafts_count }}</td>
                <td class="col-fit nowrap"><span @class(['badge', 'ok' => $state === 'active', 'warn' => $state === 'paused'])>{{ __('messages.recurring.state_'.$state) }}</span></td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">{{ __('messages.recurring.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
