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
--}}@extends('layouts.guest')
@section('title', __('messages.auth.reset_title'))
@section('content')
    <div class="card">
        <h2 style="margin-top:0">{{ __('messages.auth.reset_title') }}</h2>
        <p class="muted">{{ $email }}</p>
        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">
            <x-field name="password" type="password" :label="__('messages.profile.new_password')" :tip="__('messages.password_hint')" autocomplete="new-password" required />
            <x-field name="password_confirmation" type="password" :label="__('messages.setup.password_confirmation')" autocomplete="new-password" required />
            @if($needsTwoFactor)
                <p class="muted">{{ __('messages.auth.reset_2fa_hint') }}</p>
                <x-field name="code" :label="__('messages.two_factor.code')" autocomplete="one-time-code" />
                <p class="muted">{{ __('messages.two_factor.or_recovery') }}</p>
                <x-field name="recovery_code" :label="__('messages.two_factor.recovery_code')" autocomplete="off" />
            @endif
            <button class="btn" type="submit">{{ __('messages.auth.reset_save') }}</button>
            <a href="{{ route('login') }}" class="btn secondary">{{ __('messages.cancel') }}</a>
        </form>
    </div>
@endsection
