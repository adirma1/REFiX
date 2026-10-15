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
@section('title', __('messages.two_factor.title'))
@section('content')
    <div class="card">
        <h2 style="margin-top:0">{{ __('messages.two_factor.title') }}</h2>
        <form method="POST" action="{{ route('two-factor.challenge.store') }}">
            @csrf
            <x-field name="code" :label="__('messages.two_factor.code')" autocomplete="one-time-code" />
            <p class="muted">{{ __('messages.two_factor.or_recovery') }}</p>
            <x-field name="recovery_code" :label="__('messages.two_factor.recovery_code')" autocomplete="off" />
            <button class="btn" type="submit">{{ __('messages.two_factor.verify') }}</button>
            <a href="{{ route('login') }}" class="btn secondary">{{ __('messages.cancel') }}</a>
        </form>
    </div>
@endsection
