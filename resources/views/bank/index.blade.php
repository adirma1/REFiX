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
@section('title', __('messages.bank.title'))
@section('content')
    @php($money = fn ($v) => number_format((float) $v, 2, ',', '.'))
    <div class="page-head">
        <h1>{{ __('messages.bank.title') }}</h1>
        @include('payments._tabs')
    </div>

    <div class="card">
        <form method="POST" action="{{ route('bank.upload') }}" enctype="multipart/form-data" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
            @csrf
            <label for="file"><strong>{{ __('messages.bank.upload') }}</strong>
                <span class="tip" data-tip="{{ __('messages.bank.upload_tip') }}" tabindex="0" aria-label="{{ __('messages.bank.upload_tip') }}">ⓘ</span>
            </label>
            <input type="file" id="file" name="file" required>
            <button class="btn" type="submit">{{ __('messages.bank.read') }}</button>
        </form>
        @error('file')<div class="error">{{ $message }}</div>@enderror
        @if($imports->isNotEmpty())
            <p class="muted" style="margin-bottom:0">
                {{ __('messages.bank.last_imports') }}:
                @foreach($imports as $import)
                    {{ $import->filename }} ({{ strtoupper($import->format) }}, {{ $import->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}, {{ __('messages.bank.import_counts', ['new' => $import->new_count, 'dup' => $import->duplicate_count]) }}){{ ! $loop->last ? ' · ' : '' }}
                @endforeach
            </p>
        @endif
    </div>

    <div class="toolbar" style="margin-top:1rem">
        @foreach(['new', 'booked', 'ignored'] as $name)
            <a @class(['btn', 'small', 'secondary' => $tab !== $name]) href="{{ route('bank.index', ['tab' => $name]) }}">
                {{ __('messages.bank.tab_'.$name) }} ({{ $counts[$name] ?? 0 }})
            </a>
        @endforeach
    </div>

    @if($tab === 'new')
        <form method="POST" action="{{ route('bank.book') }}" data-multi>
            @csrf
            <input type="hidden" name="tab" value="new">
            @if($transactions->count() > 0)
                <div class="toolbar">
                    <button class="btn small" type="submit" data-needs disabled>{{ __('messages.bank.book_selected') }} (<span data-count>0</span>)</button>
                    <button class="btn small secondary" type="submit" formnovalidate formaction="{{ route('bank.ignore-many') }}" data-needs disabled>{{ __('messages.bank.ignore_selected') }}</button>
                    <span class="muted">{{ __('messages.bank.book_hint') }}</span>
                </div>
            @endif
            <table class="bank-new">
                <thead>
                <tr>
                    <th class="pick-col"><input type="checkbox" class="pick-all" aria-label="{{ __('messages.bank.select_all') }}"></th>
                    <x-th-sort :sort="$sort" col="date">{{ __('messages.bank.date') }}</x-th-sort>
                    <x-th-sort :sort="$sort" col="counterparty">{{ __('messages.bank.counterparty') }}</x-th-sort>
                    <x-th-sort :sort="$sort" col="purpose">{{ __('messages.bank.purpose') }}</x-th-sort>
                    <x-th-sort :sort="$sort" col="amount" class="num">{{ __('messages.payments.amount') }}</x-th-sort>
                    <th>{{ __('messages.bank.assign') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($transactions as $tx)
                    @php($sug = $suggestions[$tx->id] ?? null)
                    @php($preselect = $sug !== null && $sug['confidence'] !== 'low')
                    <tr data-pick>
                        <td class="pick-col"><input type="checkbox" class="pick" name="book[{{ $tx->id }}]" value="1" aria-label="{{ __('messages.bank.select') }}" @checked($preselect && $sug['confidence'] !== 'partial')></td>
                        <td class="nowrap">{{ $tx->booking_date->format('d.m.Y') }}</td>
                        <td>{{ $tx->counterparty_name }}<br><span class="muted mono">{{ $tx->counterparty_iban }}</span></td>
                        <td style="max-width:22rem;word-break:break-word">{{ $tx->purpose }}@if($tx->reference)<br><span class="muted">{{ $tx->reference }}</span>@endif</td>
                        <td class="num">{{ $money($tx->amount) }} {{ $tx->currency }}</td>
                        <td class="assign-cell">
                            <select name="invoice[{{ $tx->id }}]" aria-label="{{ __('messages.bank.assign') }}">
                                <option value="">–</option>
                                @foreach($options as $option)
                                    <option value="{{ $option['id'] }}" @selected($sug !== null && $sug['invoice']->id === $option['id'])>{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                            @if($sug !== null)
                                <span @class(['badge', 'ok' => in_array($sug['confidence'], ['high', 'skonto'], true), 'warn' => in_array($sug['confidence'], ['partial', 'low'], true)])>{{ __('messages.bank.match_'.$sug['confidence']) }}</span>
                            @endif
                            @if($sug !== null && $sug['confidence'] === 'skonto')
                                <label class="muted"><input type="checkbox" name="skonto[{{ $tx->id }}]" value="1" checked> {{ __('messages.bank.with_skonto') }}</label>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">{{ __('messages.bank.empty_new') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </form>
    @else
        <div class="toolbar" data-pick-actions>
            @if($tab === 'booked')
                <button class="btn small secondary" type="button" data-act="open" disabled>{{ __('messages.bank.to_invoice') }}</button>
            @else
                <button class="btn small" type="button" data-act="restore" data-method="post" disabled>{{ __('messages.bank.restore') }}</button>
            @endif
            <span class="muted" id="pick-hint">{{ __('messages.bank.pick_first') }}</span>
        </div>
        <form id="pick-form" method="POST" action="{{ route('bank.index') }}" hidden>@csrf</form>
        <table>
            <thead>
            <tr>
                <th class="pick-col"></th>
                <x-th-sort :sort="$sort" col="date">{{ __('messages.bank.date') }}</x-th-sort>
                <x-th-sort :sort="$sort" col="counterparty">{{ __('messages.bank.counterparty') }}</x-th-sort>
                <x-th-sort :sort="$sort" col="purpose">{{ __('messages.bank.purpose') }}</x-th-sort>
                <x-th-sort :sort="$sort" col="amount" class="num">{{ __('messages.payments.amount') }}</x-th-sort>
                @if($tab === 'booked')<x-th-sort :sort="$sort" col="number">{{ __('messages.invoices.number') }}</x-th-sort>@endif
            </tr>
            </thead>
            <tbody>
            @forelse($transactions as $tx)
                <tr data-pick>
                    <td class="pick-col">
                        <input type="radio" class="pick-one" name="tx" value="{{ $tx->id }}" aria-label="{{ __('messages.bank.pick_row') }}"
                            @if($tab === 'booked' && $tx->payment?->invoice) data-open="{{ route('invoices.show', $tx->payment->invoice) }}#payments" @endif
                            @if($tab === 'ignored') data-restore="{{ route('bank.restore', $tx) }}" @endif>
                    </td>
                    <td class="nowrap">{{ $tx->booking_date->format('d.m.Y') }}</td>
                    <td>{{ $tx->counterparty_name }}</td>
                    <td style="max-width:22rem;word-break:break-word">{{ $tx->purpose }}</td>
                    <td class="num">{{ $money($tx->amount) }} {{ $tx->currency }}</td>
                    @if($tab === 'booked')
                        <td>@if($tx->payment?->invoice)<a href="{{ route('invoices.show', $tx->payment->invoice) }}#payments">{{ $tx->payment->invoice->number }}</a>@endif</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $tab === 'booked' ? 6 : 5 }}" class="muted">{{ __('messages.bank.empty_'.$tab) }}</td></tr>
            @endforelse
            </tbody>
        </table>
    @endif
    @push('scripts')
        <script src="{{ asset('js/rowpick.js') }}?v={{ filemtime(public_path('js/rowpick.js')) }}"></script>
    @endpush
    @include('pagination.footer', ['paginator' => $transactions, 'id' => 'bank'])
@endsection
