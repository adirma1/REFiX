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
@section('title', $invoice->documentTitle().' '.$invoice->displayNumber())
@section('content')
    @php($money = fn ($v) => number_format((float) $v, 2, ',', '.'))
    <h1>
        {{ $invoice->documentTitle() }} {{ $invoice->number }}
        <span @class(['badge', 'ok' => ! $invoice->isDraft(), 'warn' => $invoice->isDraft()])>
            {{ $invoice->isDraft() ? __('messages.invoices.status_draft') : __('messages.invoices.status_issued') }}
        </span>
        @if($invoice->is_test)<span class="badge">{{ __('messages.testmode.on') }}</span>@endif
        @if(! $invoice->isDraft() && ! $invoice->is_test)<span class="badge ok" title="{{ __('messages.invoices.einvoice_tip') }}">{{ __('messages.invoices.einvoice') }}</span>@endif
    </h1>

    <div class="toolbar">
        <a class="btn secondary" href="{{ route('invoices.pdf', $invoice) }}" target="_blank" rel="noopener">{{ __('messages.invoices.open_pdf') }}</a>
        <a class="btn secondary" href="{{ route('invoices.pdf', [$invoice, 'download' => 1]) }}">{{ __('messages.invoices.download_pdf') }}</a>
        @if(! $invoice->isDraft())
            @can('send-mail')
                <a class="btn secondary" href="{{ route('invoices.mail', $invoice) }}">{{ __('messages.invoices.mail_button') }}</a>
            @endcan
            @can('issue-invoices')
                <form method="POST" action="{{ route('invoices.rebuild-pdf', $invoice) }}" onsubmit="return confirm('{{ __('messages.invoices.confirm_rebuild_pdf') }}')">
                    @csrf
                    <button class="btn secondary" type="submit">{{ __('messages.invoices.rebuild_pdf') }}</button>
                </form>
            @endcan
        @endif
        <span class="spacer"></span>
        @if($invoice->isDraft())
            @can('edit-invoices')
                <a class="btn secondary" href="{{ route('invoices.edit', $invoice) }}">{{ __('messages.edit') }}</a>
                <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" onsubmit="return confirm('{{ __('messages.confirm_delete') }}')">
                    @csrf @method('DELETE')
                    <button class="btn danger" type="submit">{{ __('messages.delete') }}</button>
                </form>
            @endcan
            @can('issue-invoices')
                <form method="POST" action="{{ route('invoices.issue', $invoice) }}" onsubmit="return confirm('{{ __('messages.invoices.confirm_issue') }}')">
                    @csrf
                    <button class="btn" type="submit">{{ __('messages.invoices.issue') }}</button>
                </form>
            @endcan
        @elseif(! $invoice->isCreditNote())
            @if($invoice->isPartial() && ! $seriesClosed)
                @can('edit-invoices')
                    <a class="btn secondary" href="{{ route('invoices.create', ['series' => $invoice->id, 'billing' => 'partial']) }}">{{ __('messages.invoices.next_partial') }}</a>
                    <a class="btn secondary" href="{{ route('invoices.create', ['series' => $invoice->id, 'billing' => 'final']) }}">{{ __('messages.invoices.create_final') }}</a>
                @endcan
            @endif
            @can('edit-invoices')
                <form method="POST" action="{{ route('invoices.credit-note', $invoice) }}" onsubmit="return confirm('{{ __('messages.invoices.confirm_credit') }}')">
                    @csrf
                    <button class="btn secondary" type="submit">{{ __('messages.invoices.create_credit_note') }}</button>
                </form>
            @endcan
        @endif
        @if(! $invoice->isCreditNote())
            @can('edit-invoices')
                <form method="POST" action="{{ route('invoices.recurring', $invoice) }}">
                    @csrf
                    <button class="btn secondary" type="submit">{{ __('messages.recurring.make_recurring') }}</button>
                </form>
            @endcan
        @endif
    </div>

    @if($invoice->isDraft())
        @cannot('issue-invoices')
            <div class="alert warn">{{ __('messages.invoices.issue_needs_accounting') }}</div>
        @endcannot
    @endif

    <div class="card">
        <div class="grid">
            <div>
                <strong>{{ $invoice->customer?->company }}</strong><br>
                {{ $invoice->customer?->street }}<br>
                {{ $invoice->customer?->zip }} {{ $invoice->customer?->city }}<br>
                <span class="muted">{{ __('messages.customers.number') }}: {{ $invoice->customer?->customer_number }}</span>
            </div>
            <div>
                {{ __('messages.invoices.date') }}: {{ $invoice->issue_date->format('d.m.Y') }}<br>
                @if($invoice->due_date){{ __('messages.invoices.due') }}: {{ $invoice->due_date->format('d.m.Y') }}<br>@endif
                {{ __('messages.invoices.tax_mode') }}: {{ __('messages.invoices.tax_modes.'.$invoice->tax_mode) }}<br>
                {{ __('messages.customers.currency') }}: {{ $invoice->currency }}
            </div>
            <div>
                @if($invoice->recurring)
                    {{ __('messages.recurring.from') }}: <a href="{{ route('recurring.edit', $invoice->recurring) }}">{{ $invoice->recurring->name }}</a><br>
                @endif
                @if($invoice->recurring_invoice_id && $invoice->service_date_from && $invoice->service_date_to)
                    {{ __('messages.recurring.period') }}: {{ $invoice->service_date_from->format('d.m.Y') }} – {{ $invoice->service_date_to->format('d.m.Y') }}<br>
                @endif
                @if($invoice->originalInvoice)
                    {{ __('messages.pdf.refers_to') }}: <a href="{{ route('invoices.show', $invoice->originalInvoice) }}">{{ $invoice->originalInvoice->number }}</a><br>
                @endif
                @foreach($credits as $credit)
                    {{ __('messages.invoices.credit_note') }}: <a href="{{ route('invoices.show', $credit) }}">{{ $credit->displayNumber() }}</a><br>
                @endforeach
                @if($invoice->issued_at){{ __('messages.invoices.issued_at') }}: {{ $invoice->issued_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}@endif
            </div>
        </div>
    </div>

    @if($seriesList->count() > 1 || ($seriesList->isNotEmpty() && ! $invoice->isDraft()))
        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.invoices.series_title') }}</h2>
            <p class="muted">{{ $seriesClosed ? __('messages.invoices.series_closed') : __('messages.invoices.series_open') }}</p>
            <table>
                <thead>
                <tr>
                    <th>{{ __('messages.invoices.number') }}</th><th>{{ __('messages.invoices.series_kind') }}</th>
                    <th>{{ __('messages.invoices.date') }}</th><th class="num">{{ __('messages.invoices.gross') }}</th><th>{{ __('messages.invoices.status') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($seriesList as $member)
                    <tr>
                        <td>
                            @if($member->id === $invoice->id)<strong>{{ $member->displayNumber() }}</strong>
                            @else<a href="{{ route('invoices.show', $member) }}">{{ $member->displayNumber() }}</a>@endif
                        </td>
                        <td>{{ $member->documentTitle() }}</td>
                        <td>{{ $member->issue_date->format('d.m.Y') }}</td>
                        <td class="num">{{ $money($member->gross_total) }} {{ $member->currency }}</td>
                        <td>{{ $member->isDraft() ? __('messages.invoices.status_draft') : __('messages.invoices.status_issued') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <table>
        <thead>
        <tr>
            <th>#</th><th>{{ __('messages.invoices.description') }}</th>
            <th class="num">{{ __('messages.invoices.quantity') }}</th>
            <th class="num">{{ __('messages.invoices.unit_price') }}</th>
            <th class="num">{{ __('messages.invoices.discount') }}</th>
            <th class="num">{{ __('messages.invoices.tax') }}</th>
            <th class="num">{{ __('messages.invoices.net') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->position }}</td>
                <td>{!! nl2br(e($item->description)) !!}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 4, ',', '.'), '0'), ',') }} {{ $item->unit }}</td>
                <td class="num">{{ $money($item->unit_price) }}</td>
                <td class="num">{{ (float) $item->discount_percent > 0 ? $money($item->discount_percent).' %' : '' }}</td>
                <td class="num">{{ $invoice->tax_mode === 'standard' ? rtrim(rtrim(number_format((float) $item->tax_rate, 2, ',', '.'), '0'), ',').' %' : '–' }}</td>
                <td class="num">{{ $money($item->net_amount) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="totals-box">
        @if((float) $invoice->discount_total > 0)
            <div><span>{{ __('messages.invoices.total_discount') }} ({{ $money($invoice->discount_percent) }} %)</span><span>– {{ $money($invoice->discount_total) }}</span></div>
        @endif
        <div><span>{{ __('messages.invoices.net') }}</span><span>{{ $money($invoice->net_total) }}</span></div>
        @if($invoice->tax_mode === 'standard')
            @foreach($invoice->tax_breakdown ?? [] as $group)
                <div><span>{{ __('messages.invoices.vat_on', ['rate' => rtrim(rtrim(number_format($group['rate'], 2, ',', '.'), '0'), ','), 'net' => $money($group['net'])]) }}</span><span>{{ $money($group['tax']) }}</span></div>
            @endforeach
        @else
            <div class="muted"><span>{{ $invoice->taxNoteText() }}</span><span></span></div>
        @endif
        <div class="grand"><span>{{ __('messages.invoices.gross') }}</span><span>{{ $money($invoice->gross_total) }} {{ $invoice->currency }}</span></div>
    </div>

    @if($showPayments)
        @include('payments._card', ['invoice' => $invoice, 'payments' => $paymentList, 'summary' => $paymentSummary, 'bookable' => $bookable])
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

    @if($invoice->internal_notes)
        <div class="card" style="margin-top:1.25rem">
            <strong>{{ __('messages.invoices.internal_notes') }}</strong>
            <p style="margin-bottom:0">{!! nl2br(e($invoice->internal_notes)) !!}</p>
        </div>
    @endif
@endsection
