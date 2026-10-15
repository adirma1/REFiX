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
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, ',', '.'), '0'), ',');
    $cur = $invoice->currency;
    $fromDate = $invoice->service_date_from;
    $toDate = $invoice->service_date_to;
    $intro = $invoice->intro_text ?: $tenant->invoice_intro;
    $footerText = $invoice->footer_text ?: $tenant->invoice_footer;
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
    .totals td { padding: 0.8mm 1mm; }
    .grand td { border-top: 0.4mm solid #333; font-weight: bold; font-size: 10.5pt; padding-top: 1.5mm; }
</style>

{{-- Absolut positionierte Blöcke nach DIN 5008 (Form B) --}}
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
        <tr><td class="muted">{{ $invoice->isCreditNote() ? __('messages.pdf.credit_note_no') : __('messages.pdf.invoice_no') }}</td><td class="r"><b>{{ $invoice->displayNumber() }}</b></td></tr>
        <tr><td class="muted">{{ __('messages.pdf.date') }}</td><td class="r">{{ $invoice->issue_date->format('d.m.Y') }}</td></tr>
        @if(!empty($customer['customer_number']))
            <tr><td class="muted">{{ __('messages.pdf.customer_no') }}</td><td class="r">{{ $customer['customer_number'] }}</td></tr>
        @endif
        <tr><td class="muted">{{ __('messages.pdf.service_date') }}</td><td class="r">
            @if($fromDate && $toDate && ! $fromDate->isSameDay($toDate)){{ $fromDate->format('d.m.Y') }} – {{ $toDate->format('d.m.Y') }}
            @elseif($fromDate){{ $fromDate->format('d.m.Y') }}
            @else{{ __('messages.pdf.service_date_equals') }}@endif
        </td></tr>
        @if($invoice->customer_order_ref)
            <tr><td class="muted">{{ __('messages.pdf.order_ref') }}</td><td class="r">{{ $invoice->customer_order_ref }}</td></tr>
        @endif
        @if($invoice->buyer_reference)
            <tr><td class="muted">{{ __('messages.pdf.buyer_reference') }}</td><td class="r">{{ $invoice->buyer_reference }}</td></tr>
        @endif
        @if($invoice->originalInvoice)
            <tr><td class="muted">{{ __('messages.pdf.refers_to') }}</td><td class="r">{{ $invoice->originalInvoice->number }}</td></tr>
        @endif
        @if(!empty($customer['vat_id']))
            <tr><td class="muted">{{ __('messages.pdf.customer_vat_id') }}</td><td class="r">{{ $customer['vat_id'] }}</td></tr>
        @endif
    </table>
</div>

{{-- Platz für den Anschriftenbereich auf Seite 1 --}}
<div style="height: 74mm;"></div>

<h2 style="font-size: 14pt; margin: 0 0 4mm 0;">{{ $invoice->documentTitle() }} {{ $invoice->number }}</h2>

@if($intro)
    <p style="margin: 0 0 5mm 0;">{!! nl2br(e($intro)) !!}</p>
@endif

<table class="items" width="100%">
    <thead>
    <tr>
        <th width="6%">{{ __('messages.pdf.pos') }}</th>
        <th width="36%">{{ __('messages.pdf.description') }}</th>
        <th width="14%" class="r" align="right" style="text-align: right;">{{ __('messages.pdf.quantity') }}</th>
        <th width="12%" class="r" align="right" style="text-align: right;">{{ __('messages.pdf.unit_price') }}</th>
        <th width="10%" class="r" align="right" style="text-align: right;">{{ __('messages.pdf.discount') }}</th>
        <th width="8%" class="r" align="right" style="text-align: right;">{{ __('messages.pdf.vat') }}</th>
        <th width="14%" class="r" align="right" style="text-align: right;">{{ __('messages.pdf.amount') }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach($items as $item)
        <tr>
            <td>{{ $item->position }}</td>
            <td>{!! nl2br(e($item->description)) !!}</td>
            <td class="r">{{ $qty($item->quantity) }} {{ $item->unit }}</td>
            <td class="r">{{ $money($item->unit_price) }}</td>
            <td class="r">@if((float) $item->discount_percent > 0){{ $money($item->discount_percent) }} %@endif</td>
            <td class="r">{{ $invoice->tax_mode === 'standard' ? rtrim(rtrim(number_format((float) $item->tax_rate, 2, ',', '.'), '0'), ',').' %' : '–' }}</td>
            <td class="r">{{ $money($item->net_amount) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals" width="100%" style="margin-top: 4mm;" nobr="true">
    @if((float) $invoice->discount_total > 0)
        <tr><td width="50%"></td><td width="32%">{{ __('messages.pdf.subtotal') }}</td><td width="18%" class="r" style="white-space: nowrap;">{{ $money((float) $invoice->net_total + (float) $invoice->discount_total) }}</td></tr>
        <tr><td></td><td>{{ __('messages.pdf.total_discount', ['percent' => $money($invoice->discount_percent)]) }}</td><td class="r">– {{ $money($invoice->discount_total) }}</td></tr>
    @endif
    <tr><td width="50%"></td><td width="32%">{{ __('messages.pdf.net_total') }}</td><td width="18%" class="r" style="white-space: nowrap;">{{ $money($invoice->net_total) }}</td></tr>
    @foreach($invoice->tax_breakdown ?? [] as $group)
        @if($invoice->tax_mode === 'standard')
            <tr><td></td><td>{{ __('messages.pdf.vat_on', ['rate' => rtrim(rtrim(number_format($group['rate'], 2, ',', '.'), '0'), ','), 'net' => $money($group['net'])]) }}</td><td class="r">{{ $money($group['tax']) }}</td></tr>
        @endif
    @endforeach
    <tr class="grand"><td></td><td>{{ $invoice->isCreditNote() ? __('messages.pdf.credit_total') : __('messages.pdf.gross_total') }}</td><td class="r">{{ $money($invoice->gross_total) }} {{ $cur }}</td></tr>
</table>

@if($invoice->tax_mode !== 'standard')
    <p class="small" style="margin-top: 4mm;">{{ __('messages.pdf.tax_note.'.$invoice->tax_mode) }}</p>
@endif

@if($series)
    <table class="items" width="100%" style="margin-top: 6mm;" nobr="true">
        <thead>
        <tr>
            <th width="40%">{{ __('messages.pdf.series_title') }}</th>
            <th width="20%">{{ __('messages.pdf.series_date') }}</th>
            <th width="20%" class="r" align="right" style="text-align: right;">{{ __('messages.pdf.series_net') }}</th>
            <th width="20%" class="r" align="right" style="text-align: right;">{{ __('messages.pdf.series_gross') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($series['rows'] as $row)
            <tr>
                <td>{!! $row['is_this'] ? '<b>'.e($row['number']).'</b>' : e($row['number']) !!}</td>
                <td>{{ $row['date']?->format('d.m.Y') }}</td>
                <td class="r">{{ $money($row['net']) }}</td>
                <td class="r">{{ $money($row['gross']) }}</td>
            </tr>
        @endforeach
        <tr>
            <td colspan="2"><b>{{ __('messages.pdf.series_total') }}</b></td>
            <td class="r"><b>{{ $money($series['total_net']) }}</b></td>
            <td class="r"><b>{{ $money($series['total_gross']) }} {{ $cur }}</b></td>
        </tr>
        </tbody>
    </table>
@endif

@if(! $invoice->isCreditNote())
    <table width="100%" style="margin-top: 8mm;" nobr="true">
        <tr>
            <td width="72%" style="vertical-align: top;">
                <b>{{ __('messages.pdf.payment') }}</b><br>
                @if($invoice->due_date)
                    {{ __('messages.pdf.due_on', ['date' => $invoice->due_date->format('d.m.Y')]) }}<br>
                @endif
                @foreach($skonto as $line){{ $line }}<br>@endforeach
                @if($tenant->iban)
                    <span class="small muted">
                        {{ __('messages.pdf.bank_transfer') }}: {{ $tenant->bank_name }}<br>
                        IBAN {{ trim(chunk_split(strtoupper(str_replace(' ', '', $tenant->iban)), 4, ' ')) }}@if($tenant->bic) · BIC {{ $tenant->bic }}@endif<br>
                        {{ __('messages.pdf.reference') }}: {{ $invoice->displayNumber() }}
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
@endif

@if($footerText)
    <p style="margin-top: 6mm;">{!! nl2br(e($footerText)) !!}</p>
@endif
