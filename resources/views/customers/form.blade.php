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
@php($editing = $customer->exists)
@section('title', $editing ? __('messages.customers.edit') : __('messages.customers.new'))
@section('content')
    <h1>{{ $editing ? __('messages.customers.edit') : __('messages.customers.new') }}</h1>

    @cannot('edit-master-data')
        <div class="alert warn">{{ __('messages.read_only') }}</div>
    @endcannot

    <form method="POST" action="{{ $editing ? route('customers.update', $customer) : route('customers.store') }}">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.customers.master') }}</h2>
            <div class="grid g-number">
                <x-field name="customer_number" :label="__('messages.customers.number')" :value="$customer->customer_number" :required="$editing" maxlength="30"
                         :tip="$editing ? null : __('messages.customers.number_hint')" />
                <x-field name="company" :label="__('messages.customers.company')" :value="$customer->isPrivate() ? '' : $customer->company"
                         :tip="__('messages.customers.company_hint')" />
            </div>
            <div class="grid g-name">
                <x-field name="salutation" :label="__('messages.customers.salutation')" :value="$customer->salutation" />
                <x-field name="first_name" :label="__('messages.customers.first_name')" :value="$customer->first_name" />
                <x-field name="last_name" :label="__('messages.customers.last_name')" :value="$customer->last_name" />
            </div>
            <div class="grid g-address">
                <x-field name="street" :label="__('messages.customers.street')" :value="$customer->street" required />
                <x-field name="zip" :label="__('messages.customers.zip')" :value="$customer->zip" required />
                <x-field name="city" :label="__('messages.customers.city')" :value="$customer->city" required />
                <x-field name="country" :label="__('messages.customers.country')" :value="$customer->country" required maxlength="2"
                         :tip="__('messages.customers.country_hint')" />
            </div>
            <div class="grid">
                <x-field name="email" type="email" :label="__('messages.customers.email')" :value="$customer->email" required />
                <x-field name="phone" :label="__('messages.customers.phone')" :value="$customer->phone" />
                <x-field name="vat_id" :label="__('messages.customers.vat_id')" :value="$customer->vat_id" />
                <x-field name="buyer_reference" :label="__('messages.customers.buyer_reference')" :value="$customer->buyer_reference"
                         :tip="__('messages.customers.buyer_reference_hint')" />
            </div>
            <div class="grid narrow-wide">
                <x-field name="birthday" type="date" :label="__('messages.customers.birthday')" :value="$customer->birthday?->format('Y-m-d')"
                         :tip="__('messages.customers.birthday_hint')" />
                <x-field name="website" type="text" autocomplete="url" :label="__('messages.customers.website')" :value="$customer->website"
                         :tip="__('messages.customers.website_hint')" />
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.customers.relationship') }}</h2>
            <div class="grid">
                <x-field name="customer_since" type="date" :label="__('messages.customers.customer_since')"
                         :value="$customer->customer_since?->format('Y-m-d')" />
                <x-field name="customer_until" type="date" :label="__('messages.customers.customer_until')"
                         :value="$customer->customer_until?->format('Y-m-d')" :tip="__('messages.customers.customer_until_hint')" />
                <x-field name="tags" :label="__('messages.customers.tags')" :value="$customer->tags" maxlength="30"
                         :tip="__('messages.customers.tags_hint')" />
            </div>
            <div class="field check">
                <input type="hidden" name="active" value="0">
                <label><input type="checkbox" name="active" value="1" @checked(old('active', $customer->active ?? true))>
                    {{ __('messages.customers.active') }}</label>
                <div class="hint">{{ __('messages.customers.active_hint') }}</div>
                @error('active')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.customers.terms') }}</h2>
            <div class="grid">
                <x-select name="currency" :label="__('messages.customers.currency')"
                          :options="array_combine(config('invoice.currencies'), config('invoice.currencies'))"
                          :value="$customer->currency" required />
                <x-field name="payment_term_days" type="number" :label="__('messages.customers.payment_term_days')"
                         :value="$customer->payment_term_days" :tip="__('messages.customers.payment_term_hint')" />
                <x-field name="discount_percent" :label="__('messages.customers.discount_percent')"
                         :value="$customer->discount_percent" />
                <x-field name="discount_days" type="number" :label="__('messages.customers.discount_days')"
                         :value="$customer->discount_days" />
            </div>
            <div class="field">
                <label for="notes">{{ __('messages.customers.notes') }}</label>
                <textarea id="notes" name="notes">{{ old('notes', $customer->notes) }}</textarea>
                @error('notes')<div class="error">{{ $message }}</div>@enderror
            </div>
        </div>

        @can('edit-master-data')
            <button class="btn" type="submit">{{ __('messages.save') }}</button>
        @endcan
        <a class="btn secondary" href="{{ route('customers.index') }}">{{ __('messages.cancel') }}</a>
    </form>
@endsection
