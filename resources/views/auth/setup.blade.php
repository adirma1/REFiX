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
@section('title', __('messages.setup.title'))
@section('content')
    <div class="card">
        <h2 style="margin-top:0">{{ __('messages.setup.title') }}</h2>
        <p class="muted">{{ __('messages.setup.intro') }}</p>
        <form method="POST" action="{{ route('setup.store') }}">
            @csrf
            <x-field name="company" :label="__('messages.setup.company')" required />
            <x-field name="name" :label="__('messages.setup.name')" autocomplete="name" required />
            <x-field name="email" type="email" :label="__('messages.setup.email')" autocomplete="username" required />
            <x-field name="password" type="password" :label="__('messages.setup.password')"
                     :tip="__('messages.password_hint')" autocomplete="new-password" required />
            <x-field name="password_confirmation" type="password" :label="__('messages.setup.password_confirmation')"
                     autocomplete="new-password" required />
            <button class="btn" type="submit">{{ __('messages.setup.submit') }}</button>
        </form>
    </div>
@endsection
