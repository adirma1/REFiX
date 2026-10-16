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
--}}@props([
    'value' => null,
    'credit' => false,
])
@php
    $vatTenant = \App\Models\Tenant::find(\App\Support\Tenancy::id());
    $forced = $vatTenant?->vat_free && ! $credit;
    $options = collect(config('invoice.tax_modes'))
        ->reject(fn ($m) => $m === 'exempt' && ! ($vatTenant?->vat_free || $value === 'exempt'))
        ->mapWithKeys(fn ($m) => [$m => __('messages.invoices.tax_modes.'.$m)])->all();
@endphp
@if($forced)
    <div class="field">
        <label>{{ __('messages.invoices.tax_mode') }}</label>
        <input type="hidden" id="tax_mode" name="tax_mode" value="exempt">
        <input type="text" value="{{ __('messages.invoices.tax_modes.exempt') }}" disabled>
        <div class="hint">{{ __('messages.invoices.tax_mode_locked') }}</div>
    </div>
@else
    <x-select name="tax_mode" :label="__('messages.invoices.tax_mode')" :options="$options" :value="$value" required />
@endif
