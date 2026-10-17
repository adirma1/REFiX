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
@php($editing = $tenant->exists)
@section('title', $editing ? __('messages.tenants.edit') : __('messages.tenants.new'))
@section('content')
    <h1>{{ $editing ? __('messages.tenants.edit') : __('messages.tenants.new') }}</h1>

    <form method="POST" enctype="multipart/form-data" action="{{ $editing ? route('tenants.update', $tenant) : route('tenants.store') }}">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.tenants.company_data') }}</h2>
            <div class="grid">
                <x-field name="name" :label="__('messages.tenants.name')" :value="$tenant->name" required />
                <x-field name="managing_director" :label="__('messages.tenants.managing_director')" :value="$tenant->managing_director" />
            </div>
            <div class="grid">
                <x-field name="street" :label="__('messages.customers.street')" :value="$tenant->street" />
                <x-field name="zip" :label="__('messages.customers.zip')" :value="$tenant->zip" />
                <x-field name="city" :label="__('messages.customers.city')" :value="$tenant->city" />
                <x-field name="country" :label="__('messages.customers.country')" :value="$tenant->country" required maxlength="2" />
            </div>
            <div class="grid">
                <x-field name="phone" :label="__('messages.customers.phone')" :value="$tenant->phone" :tip="__('messages.tenants.footer_free_text')" />
                <x-field name="email" :label="__('messages.tenants.email')" :value="$tenant->email" :tip="__('messages.tenants.footer_free_text')" />
                <x-field name="website" :label="__('messages.tenants.website')" :value="$tenant->website" :tip="__('messages.tenants.footer_free_text')" />
            </div>
            <div class="grid">
                <x-field name="vat_id" :label="__('messages.customers.vat_id')" :value="$tenant->vat_id" />
                <x-field name="tax_number" :label="__('messages.tenants.tax_number')" :value="$tenant->tax_number" />
                <x-field name="court" :label="__('messages.tenants.court')" :value="$tenant->court" />
                <x-field name="register_number" :label="__('messages.tenants.register_number')" :value="$tenant->register_number" />
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.tenants.bank') }}</h2>
            <div class="grid">
                <x-field name="bank_name" :label="__('messages.tenants.bank_name')" :value="$tenant->bank_name" />
                <x-field name="iban" :label="__('messages.tenants.iban')" :value="$tenant->iban" />
                <x-field name="bic" :label="__('messages.tenants.bic')" :value="$tenant->bic" />
                <x-field name="default_payment_days" type="number" :label="__('messages.tenants.default_payment_days')"
                         :value="$tenant->default_payment_days" required />
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.tenants.invoice_settings') }}</h2>
            <div class="field check">
                <input type="hidden" name="show_due_text" value="0">
                <label><input type="checkbox" name="show_due_text" value="1" @checked(old('show_due_text', $tenant->show_due_text ?? true))>
                    {{ __('messages.tenants.show_due_text') }}</label>
                <div class="hint">{{ __('messages.tenants.show_due_text_hint') }}</div>
            </div>
            <div class="field check">
                <input type="hidden" name="show_customer_number" value="0">
                <label><input type="checkbox" name="show_customer_number" value="1" @checked(old('show_customer_number', $tenant->show_customer_number ?? true))>
                    {{ __('messages.tenants.show_customer_number') }}</label>
                <div class="hint">{{ __('messages.tenants.show_customer_number_hint') }}</div>
            </div>
            <div class="field check">
                <input type="hidden" name="show_service_date" value="0">
                <label><input type="checkbox" name="show_service_date" value="1" @checked(old('show_service_date', $tenant->show_service_date ?? true))>
                    {{ __('messages.tenants.show_service_date') }}</label>
                <div class="hint">{{ __('messages.tenants.show_service_date_hint') }}</div>
            </div>
            <div class="grid">
                <x-field name="invoice_number_format" :label="__('messages.tenants.invoice_number_format')" :value="$tenant->invoice_number_format"
                         required maxlength="60" :tip="__('messages.numbering.format_hint')" />
                <x-field name="credit_note_number_format" :label="__('messages.tenants.credit_note_number_format')" :value="$tenant->credit_note_number_format"
                         required maxlength="60" :tip="__('messages.numbering.format_hint')" />
                <x-field name="quote_number_format" :label="__('messages.tenants.quote_number_format')" :value="$tenant->quote_number_format ?? 'A-{YYYY}-{NNNN}'"
                         required maxlength="60" :tip="__('messages.numbering.format_hint')" />
                <x-field name="quote_valid_days" type="number" :label="__('messages.tenants.quote_valid_days')" :value="$tenant->quote_valid_days ?? 30" required />
            </div>
            <div class="field">
                <label for="invoice_intro">{{ __('messages.tenants.invoice_intro') }}
                    <span class="tip" data-tip="{{ __('messages.tenants.invoice_texts_hint') }}" tabindex="0" aria-label="{{ __('messages.tenants.invoice_texts_hint') }}">ⓘ</span>
                </label>
                <textarea id="invoice_intro" name="invoice_intro">{{ old('invoice_intro', $tenant->invoice_intro) }}</textarea>
                @error('invoice_intro')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="invoice_footer">{{ __('messages.tenants.invoice_footer') }}
                    <span class="tip" data-tip="{{ __('messages.tenants.invoice_texts_hint') }}" tabindex="0" aria-label="{{ __('messages.tenants.invoice_texts_hint') }}">ⓘ</span>
                </label>
                <textarea id="invoice_footer" name="invoice_footer">{{ old('invoice_footer', $tenant->invoice_footer) }}</textarea>
                @error('invoice_footer')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="logo">{{ __('messages.tenants.logo') }}
                    <span class="tip" data-tip="{{ __('messages.tenants.logo_hint') }}" tabindex="0" aria-label="{{ __('messages.tenants.logo_hint') }}">ⓘ</span>
                </label>
                <input id="logo" name="logo" type="file" accept="image/png,image/jpeg">
                @error('logo')<div class="error">{{ $message }}</div>@enderror
                @if($tenant->logo_path)
                    <label style="font-weight:400"><input type="checkbox" name="remove_logo" value="1"> {{ __('messages.tenants.remove_logo') }}</label>
                @endif
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.tenants.vat_title') }}</h2>
            <p class="muted">{{ __('messages.tenants.vat_explain') }}</p>
            <div class="field check">
                <input type="hidden" name="vat_free" value="0">
                <label><input type="checkbox" name="vat_free" value="1" id="vat_free" @checked(old('vat_free', $tenant->vat_free))>
                    {{ __('messages.tenants.vat_free') }}</label>
            </div>
            <div id="vat-free-fields">
                <div class="field">
                    <label for="vat_preset">{{ __('messages.tenants.vat_preset') }}</label>
                    <select id="vat_preset">
                        <option value="">{{ __('messages.tenants.vat_preset_none') }}</option>
                        @foreach(__('messages.tenants.vat_presets') as $key => $preset)
                            <option value="{{ $key }}" data-text="{{ $preset['text'] }}">{{ $preset['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="vat_free_note">{{ __('messages.tenants.vat_note') }}</label>
                    <textarea id="vat_free_note" name="vat_free_note" maxlength="500">{{ old('vat_free_note', $tenant->vat_free_note) }}</textarea>
                    <div class="hint">{{ __('messages.tenants.vat_note_hint') }}</div>
                    @error('vat_free_note')<div class="error">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.testmode.title') }}</h2>
            <p class="muted">{{ __('messages.testmode.explain') }}</p>
            <div class="field check">
                <input type="hidden" name="test_mode" value="0">
                <label><input type="checkbox" name="test_mode" value="1" @checked(old('test_mode', $tenant->test_mode))>
                    {{ __('messages.testmode.enable') }}</label>
            </div>
        </div>

        <button class="btn" type="submit">{{ __('messages.save') }}</button>
        <a class="btn secondary" href="{{ route('tenants.index') }}">{{ __('messages.cancel') }}</a>
    </form>
@endsection

@push('scripts')
    <script src="{{ asset('js/vat-note.js') }}?v={{ filemtime(public_path('js/vat-note.js')) }}"></script>
@endpush
