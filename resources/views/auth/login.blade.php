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
@section('title', __('messages.auth.login'))
@section('content')
    <div class="card">
        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <x-field name="login" :label="__('messages.auth.login_id')" autocomplete="username" required />
            <x-field name="password" type="password" :label="__('messages.auth.password')" autocomplete="current-password" required />
            <div class="field check">
                <label><input type="checkbox" name="remember" value="1"> {{ __('messages.auth.remember') }}</label>
            </div>
            <button class="btn" type="submit">{{ __('messages.auth.login') }}</button>
            <a href="{{ route('password.request') }}" class="btn secondary">{{ __('messages.auth.forgot') }}</a>
        </form>
    </div>
@endsection
