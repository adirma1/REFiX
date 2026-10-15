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
--}}<div style="border-top: 0.2mm solid #999; padding-top: 1.5mm; font-family: dejavusans; font-size: 6.3pt; color: #444;">
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td width="35%" style="vertical-align: top; padding-right: 2mm;">
                <b style="white-space: nowrap;">{{ $tenant->name }}</b><br>
                {{ $tenant->street }}<br>
                {{ trim($tenant->zip.' '.$tenant->city) }}
                @if($tenant->managing_director)<br>{{ __('messages.pdf.managing_director') }}: {{ $tenant->managing_director }}@endif
            </td>
            <td width="33%" style="vertical-align: top; padding-right: 2mm;">
                @if($tenant->phone){{ $tenant->phone }}<br>@endif
                @if($tenant->email){{ $tenant->email }}<br>@endif
                @if($tenant->website){{ $tenant->website }}<br>@endif
                @if($tenant->vat_id)USt-IdNr. {{ $tenant->vat_id }}<br>@endif
                @if($tenant->tax_number){{ __('messages.pdf.tax_number') }} {{ $tenant->tax_number }}@endif
            </td>
            <td width="32%" style="vertical-align: top;">
                @if($tenant->bank_name){{ $tenant->bank_name }}<br>@endif
                @if($tenant->iban)<span style="white-space: nowrap;">IBAN {{ $tenant->iban }}</span><br>@endif
                @if($tenant->bic)BIC {{ $tenant->bic }}<br>@endif
                @if($tenant->court){{ $tenant->court }} {{ $tenant->register_number }}@endif
            </td>
        </tr>
    </table>
    <div style="text-align: right; margin-top: 1mm;">{{ __('messages.pdf.page') }} {PAGENO} / {nbpg}</div>
</div>
