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
@section('title', __('messages.quotes.title_single').' '.$quote->displayNumber())
@section('content')
    @php($money = fn ($v) => number_format((float) $v, 2, ',', '.'))
    @php($pending = $quote->items->whereNull('invoice_id'))
    @php($canConvert = $quote->isConvertible() && $pending->isNotEmpty())
    <h1>
        {{ __('messages.quotes.title_single') }} {{ $quote->number }}
        @include('quotes._badge', ['quote' => $quote])
        @if($quote->is_test)<span class="badge">{{ __('messages.testmode.on') }}</span>@endif
    </h1>

    <div class="toolbar">
        <a class="btn secondary" href="{{ route('quotes.pdf', $quote) }}" target="_blank" rel="noopener">{{ __('messages.invoices.open_pdf') }}</a>
        <a class="btn secondary" href="{{ route('quotes.pdf', [$quote, 'download' => 1]) }}">{{ __('messages.invoices.download_pdf') }}</a>
        @if(! $quote->isDraft())
            @can('send-mail')
                <a class="btn secondary" href="{{ route('quotes.mail', $quote) }}">{{ __('messages.invoices.mail_button') }}</a>
            @endcan
        @endif
        <span class="spacer"></span>
        @if($quote->isEditable() && $quote->items->whereNotNull('invoice_id')->isEmpty())
            @can('edit-invoices')
                <a class="btn secondary" href="{{ route('quotes.edit', $quote) }}">{{ __('messages.edit') }}</a>
            @endcan
        @endif
        @if($quote->isDraft())
            @can('edit-invoices')
                <form method="POST" action="{{ route('quotes.destroy', $quote) }}" onsubmit="return confirm('{{ __('messages.confirm_delete') }}')">
                    @csrf @method('DELETE')
                    <button class="btn danger" type="submit">{{ __('messages.delete') }}</button>
                </form>
            @endcan
            @can('issue-invoices')
                <form method="POST" action="{{ route('quotes.issue', $quote) }}" onsubmit="return confirm('{{ __('messages.quotes.confirm_issue') }}')">
                    @csrf
                    <button class="btn" type="submit">{{ __('messages.quotes.issue') }}</button>
                </form>
            @endcan
        @endif
        @can('edit-invoices')
            @if($quote->isOpen())
                <form method="POST" action="{{ route('quotes.status', $quote) }}">
                    @csrf
                    <input type="hidden" name="to" value="declined">
                    <button class="btn secondary" type="submit">{{ __('messages.quotes.decline') }}</button>
                </form>
                <form method="POST" action="{{ route('quotes.status', $quote) }}">
                    @csrf
                    <input type="hidden" name="to" value="accepted">
                    <button class="btn secondary" type="submit">{{ __('messages.quotes.accept') }}</button>
                </form>
            @elseif(($quote->isAccepted() || $quote->isDeclined()) && $quote->items->whereNotNull('invoice_id')->isEmpty())
                <form method="POST" action="{{ route('quotes.status', $quote) }}">
                    @csrf
                    <input type="hidden" name="to" value="open">
                    <button class="btn secondary" type="submit">{{ __('messages.quotes.reopen') }}</button>
                </form>
            @endif
        @endcan
    </div>

    @if($quote->isDraft())
        @cannot('issue-invoices')
            <div class="alert warn">{{ __('messages.quotes.issue_needs_accounting') }}</div>
        @endcannot
    @endif
    @if($quote->isExpired())
        <div class="alert warn">{{ __('messages.quotes.expired_note', ['date' => $quote->valid_until->format('d.m.Y')]) }}</div>
    @endif

    <div class="card">
        <div class="grid">
            <div>
                <strong>{{ $quote->customer?->company }}</strong><br>
                {{ $quote->customer?->street }}<br>
                {{ $quote->customer?->zip }} {{ $quote->customer?->city }}<br>
                <span class="muted">{{ __('messages.customers.number') }}: {{ $quote->customer?->customer_number }}</span>
            </div>
            <div>
                {{ __('messages.invoices.date') }}: {{ $quote->issue_date->format('d.m.Y') }}<br>
                {{ __('messages.quotes.valid_until') }}: {{ $quote->valid_until?->format('d.m.Y') }}<br>
                {{ __('messages.invoices.tax_mode') }}: {{ __('messages.invoices.tax_modes.'.$quote->tax_mode) }}<br>
                {{ __('messages.customers.currency') }}: {{ $quote->currency }}
            </div>
            <div>
                @if($quote->issued_at){{ __('messages.invoices.issued_at') }}: {{ $quote->issued_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}<br>@endif
                @if($quote->status_changed_at && ! $quote->isDraft()){{ __('messages.quotes.status_since') }}: {{ $quote->status_changed_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}@endif
            </div>
        </div>
    </div>

    @if($canConvert)
        @can('edit-invoices')
            <div class="alert warn" style="background:#eef4fb;border-color:#c5d8f0;color:var(--text)">
                {{ __('messages.quotes.convert_hint') }}
            </div>
        @endcan
    @endif

    <form method="POST" action="{{ route('quotes.convert', $quote) }}" id="convert-form">
        @csrf
        <input type="hidden" name="selection" value="1">
        <table>
            <thead>
            <tr>
                @if($canConvert)<th class="pick-col"></th>@endif
                <th>#</th><th>{{ __('messages.invoices.description') }}</th>
                <th class="num">{{ __('messages.invoices.quantity') }}</th>
                <th class="num">{{ __('messages.invoices.unit_price') }}</th>
                <th class="num">{{ __('messages.invoices.discount') }}</th>
                <th class="num">{{ __('messages.invoices.tax') }}</th>
                <th class="num">{{ __('messages.invoices.net') }}</th>
                <th>{{ __('messages.quotes.invoiced_in') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($quote->items as $item)
                <tr @if($item->invoice_id) style="opacity:.7" @endif>
                    @if($canConvert)
                        <td class="pick-col">
                            @if(! $item->invoice_id)
                                <input type="checkbox" name="items[]" value="{{ $item->id }}" checked aria-label="{{ __('messages.bank.select') }}">
                            @endif
                        </td>
                    @endif
                    <td>{{ $item->position }}</td>
                    <td>{!! nl2br(e($item->description)) !!}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 4, ',', '.'), '0'), ',') }} {{ $item->unit }}</td>
                    <td class="num">{{ $money($item->unit_price) }}</td>
                    <td class="num">{{ (float) $item->discount_percent > 0 ? $money($item->discount_percent).' %' : '' }}</td>
                    <td class="num">{{ $quote->tax_mode === 'standard' ? rtrim(rtrim(number_format((float) $item->tax_rate, 2, ',', '.'), '0'), ',').' %' : '–' }}</td>
                    <td class="num">{{ $money($item->net_amount) }}</td>
                    <td>
                        @if($item->invoice)
                            <a href="{{ route('invoices.show', $item->invoice) }}">{{ $item->invoice->displayNumber() }}</a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if($canConvert)
            @can('edit-invoices')
                <p style="margin-top:.75rem"><button class="btn" type="submit">{{ __('messages.quotes.convert') }}</button></p>
            @endcan
        @endif
    </form>

    <div class="totals-box">
        @if((float) $quote->discount_total > 0)
            <div><span>{{ __('messages.invoices.total_discount') }} ({{ $money($quote->discount_percent) }} %)</span><span>– {{ $money($quote->discount_total) }}</span></div>
        @endif
        <div><span>{{ __('messages.invoices.net') }}</span><span>{{ $money($quote->net_total) }}</span></div>
        @if($quote->tax_mode === 'standard')
            @foreach($quote->tax_breakdown ?? [] as $group)
                <div><span>{{ __('messages.invoices.vat_on', ['rate' => rtrim(rtrim(number_format($group['rate'], 2, ',', '.'), '0'), ','), 'net' => $money($group['net'])]) }}</span><span>{{ $money($group['tax']) }}</span></div>
            @endforeach
        @else
            <div class="muted"><span>{{ $quote->taxNoteText() }}</span><span></span></div>
        @endif
        <div class="grand"><span>{{ __('messages.invoices.gross') }}</span><span>{{ $money($quote->gross_total) }} {{ $quote->currency }}</span></div>
    </div>

    @if($invoices->isNotEmpty())
        <div class="card" style="margin-top:1.25rem">
            <strong>{{ __('messages.quotes.invoices_title') }}</strong>
            <table style="margin-top:.5rem">
                <tbody>
                @foreach($invoices as $invoice)
                    <tr>
                        <td><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->displayNumber() }}</a></td>
                        <td>{{ $invoice->issue_date->format('d.m.Y') }}</td>
                        <td class="num">{{ $money($invoice->gross_total) }} {{ $invoice->currency }}</td>
                        <td>{{ $invoice->isDraft() ? __('messages.invoices.status_draft') : __('messages.invoices.status_issued') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($mailLogs->isNotEmpty())
        <div class="card" style="margin-top:1.25rem">
            <strong>{{ __('messages.invoices.mail_history') }}</strong>
            <table class="audit-table" style="margin-top:.5rem">
                <tbody>
                @foreach($mailLogs as $log)
                    <tr>
                        <td>{{ $log->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</td>
                        <td>{{ $log->user?->name }}</td>
                        <td>{{ $log->recipients }}</td>
                        <td>
                            @if($log->wasSent())
                                <span class="badge ok">{{ __('messages.invoices.mail_status_sent') }}</span>
                            @else
                                <span class="badge warn" title="{{ $log->error }}">{{ __('messages.invoices.mail_status_failed') }}</span>
                                <span class="muted">{{ $log->error }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($quote->internal_notes)
        <div class="card" style="margin-top:1.25rem">
            <strong>{{ __('messages.invoices.internal_notes') }}</strong>
            <p style="margin-bottom:0">{!! nl2br(e($quote->internal_notes)) !!}</p>
        </div>
    @endif
@endsection
