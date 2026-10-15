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
--}}@php
    $money = fn ($v) => number_format((float) $v, 2, ',', '.');
    $cur = $dunning->currency;
    $sender = trim($tenant->name.' · '.$tenant->street.' · '.trim($tenant->zip.' '.$tenant->city), ' ·');
@endphp
<style>
    body { font-family: dejavusans; font-size: 9pt; color: #1b1b1b; }
    table { border-collapse: collapse; }
    .items th { border-bottom: 0.4mm solid #333; padding: 1.5mm 1mm; font-size: 8pt; text-align: left; }
    .items th.r { text-align: right; }
    .items td { padding: 1.6mm 1mm; border-bottom: 0.2mm solid #ccc; vertical-align: top; }
    .r { text-align: right; }
    .small { font-size: 7.5pt; }
    .muted { color: #555; }
    .grand td { border-top: 0.4mm solid #333; font-weight: bold; font-size: 10.5pt; padding-top: 1.5mm; }
</style>

<div style="position: absolute; left: 115mm; top: 12mm; width: 75mm; text-align: right;">
    @if($logo)
        <img src="{{ $logo }}" style="max-height: 22mm; max-width: 70mm;">
    @else
        <div style="font-size: 13pt; font-weight: bold;">{{ $tenant->name }}</div>
    @endif
</div>

<div style="position: absolute; left: 20mm; top: 45mm; width: 85mm; font-size: 6pt; color: #555; white-space: nowrap; border-bottom: 0.2mm solid #999;">{{ $sender }}</div>

<div style="position: absolute; left: 20mm; top: 51mm; width: 85mm; height: 36mm; font-size: 10pt;">
    <b>{{ $customer['company'] ?? '' }}</b><br>
    @if(!empty($customer['contact'])){{ $customer['contact'] }}<br>@endif
    {{ $customer['street'] ?? '' }}<br>
    {{ trim(($customer['zip'] ?? '').' '.($customer['city'] ?? '')) }}
    @if(($customer['country'] ?? 'DE') !== ($tenant->country ?: 'DE'))<br>{{ $customer['country'] ?? '' }}@endif
</div>

<div style="position: absolute; left: 118mm; top: 51mm; width: 72mm;">
    <table width="100%" class="small">
        <tr><td class="muted">{{ __('messages.dunning.pdf_date') }}</td><td class="r">{{ $dunning->dunned_on->format('d.m.Y') }}</td></tr>
        @if(!empty($customer['customer_number']))
            <tr><td class="muted">{{ __('messages.pdf.customer_no') }}</td><td class="r">{{ $customer['customer_number'] }}</td></tr>
        @endif
        <tr><td class="muted">{{ __('messages.pdf.invoice_no') }}</td><td class="r"><b>{{ $invoice->number }}</b></td></tr>
    </table>
</div>

<div style="height: 74mm;"></div>

<h2 style="font-size: 14pt; margin: 0 0 4mm 0;">{{ $dunning->label() }}</h2>

<p style="margin: 0 0 5mm 0;">{!! nl2br(e($text)) !!}</p>

<table class="items" width="100%">
    <thead>
    <tr>
        <th width="40%">{{ __('messages.dunning.pdf_position') }}</th>
        <th width="20%">{{ __('messages.dunning.pdf_invoice_date') }}</th>
        <th width="20%">{{ __('messages.dunning.pdf_due') }}</th>
        <th width="20%" class="r" align="right" style="text-align: right;">{{ __('messages.dunning.pdf_amount') }}</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td>{{ __('messages.dunning.pdf_open_invoice', ['number' => $invoice->number]) }}</td>
        <td>{{ $invoice->issue_date->format('d.m.Y') }}</td>
        <td>{{ $invoice->due_date?->format('d.m.Y') }}</td>
        <td class="r">{{ $money($dunning->open_amount) }}</td>
    </tr>
    @if((float) $dunning->fee > 0)
        <tr><td colspan="3">{{ __('messages.dunning.pdf_fee') }}</td><td class="r">{{ $money($dunning->fee) }}</td></tr>
    @endif
    @if((float) $dunning->interest > 0)
        <tr><td colspan="3">{{ __('messages.dunning.pdf_interest', ['rate' => $money($dunning->interest_rate), 'days' => $dunning->interest_days]) }}</td><td class="r">{{ $money($dunning->interest) }}</td></tr>
    @endif
    </tbody>
</table>

<table width="100%" style="margin-top: 3mm;" nobr="true">
    <tr class="grand">
        <td width="60%">{{ __('messages.dunning.pdf_total') }}</td>
        <td width="40%" class="r">{{ $money($dunning->total) }} {{ $cur }}</td>
    </tr>
</table>

<table width="100%" style="margin-top: 8mm;" nobr="true">
    <tr>
        <td width="72%" style="vertical-align: top;">
            <b>{{ __('messages.dunning.pdf_pay_by', ['date' => $dunning->pay_by->format('d.m.Y')]) }}</b><br>
            @if($tenant->iban)
                <span class="small muted">
                    {{ __('messages.pdf.bank_transfer') }}: {{ $tenant->bank_name }}<br>
                    IBAN {{ trim(chunk_split(strtoupper(str_replace(' ', '', $tenant->iban)), 4, ' ')) }}@if($tenant->bic) · BIC {{ $tenant->bic }}@endif<br>
                    {{ __('messages.pdf.reference') }}: {{ $invoice->number }}
                </span>
            @endif
        </td>
        <td width="28%" class="r" style="vertical-align: top;">
            @if($qr)
                <img src="{{ $qr }}" style="width: 28mm; height: 28mm;"><br>
                <span class="small muted">{{ __('messages.pdf.girocode') }}</span>
            @endif
        </td>
    </tr>
</table>

<p class="small muted" style="margin-top: 6mm;">{{ __('messages.dunning.pdf_crossed') }}</p>
