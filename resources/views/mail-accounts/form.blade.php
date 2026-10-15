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
@php($editing = $account->exists)
@section('title', $editing ? __('messages.mail_accounts.edit') : __('messages.mail_accounts.new'))
@section('content')
    <h1>{{ $editing ? __('messages.mail_accounts.edit') : __('messages.mail_accounts.new') }}</h1>

    <form method="POST" action="{{ $editing ? route('mail-accounts.update', $account) : route('mail-accounts.store') }}">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.mail_accounts.server_section') }}</h2>
            <div class="grid">
                <x-field name="name" :label="__('messages.mail_accounts.name')" :value="$account->name" required maxlength="120"
                         :tip="__('messages.mail_accounts.name_tip')" />
                <x-field name="host" :label="__('messages.mail_accounts.host')" :value="$account->host" required maxlength="255"
                         :tip="__('messages.mail_accounts.host_tip')" />
                <x-field name="port" type="number" :label="__('messages.mail_accounts.port')" :value="$account->port" required />
                <x-select name="encryption" :label="__('messages.mail_accounts.encryption')" :value="$account->encryption" required
                          :options="collect(\App\Models\MailAccount::ENCRYPTIONS)->mapWithKeys(fn ($e) => [$e => __('messages.mail_accounts.enc.'.$e)])->all()" />
            </div>
            <div class="grid">
                <x-field name="username" :label="__('messages.mail_accounts.username')" :value="$account->username" maxlength="255" autocomplete="off" />
                <x-field name="smtp_password" type="password" :label="__('messages.mail_accounts.password')" autocomplete="new-password"
                         :tip="$editing && $account->hasPassword() ? __('messages.mail_accounts.password_keep') : __('messages.mail_accounts.password_tip')" />
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.mail_accounts.sender_section') }}</h2>
            <div class="grid">
                <x-field name="from_address" type="email" :label="__('messages.mail_accounts.from_address')" :value="$account->from_address" required maxlength="255" />
                <x-field name="from_name" :label="__('messages.mail_accounts.from_name')" :value="$account->from_name" maxlength="255" />
                <x-field name="reply_to" type="email" :label="__('messages.mail_accounts.reply_to')" :value="$account->reply_to" maxlength="255"
                         :tip="__('messages.mail_accounts.reply_to_tip')" />
                <x-field name="bcc" :label="__('messages.mail_accounts.bcc')" :value="$account->bcc" maxlength="500"
                         :tip="__('messages.mail_accounts.bcc_tip')" />
            </div>
        </div>

        <button class="btn" type="submit">{{ __('messages.save') }}</button>
        <a class="btn secondary" href="{{ route('mail-accounts.index') }}">{{ __('messages.cancel') }}</a>
    </form>

    @if($editing)
        <div class="card" style="margin-top:1.5rem">
            <h2 style="margin-top:0">{{ __('messages.mail_accounts.test_section') }}</h2>
            <p class="muted">{{ __('messages.mail_accounts.test_help') }}</p>
            <form method="POST" action="{{ route('mail-accounts.test', $account) }}" class="test-form">
                @csrf
                <button class="btn secondary" type="submit">{{ __('messages.mail_accounts.test_connection') }}</button>
            </form>
            <form method="POST" action="{{ route('mail-accounts.test', $account) }}" class="test-form" style="margin-top:1rem">
                @csrf
                <x-field name="to" type="email" :label="__('messages.mail_accounts.test_to')" :value="auth()->user()->email" />
                <button class="btn secondary" type="submit">{{ __('messages.mail_accounts.test_send') }}</button>
            </form>
        </div>
    @endif
@endsection
