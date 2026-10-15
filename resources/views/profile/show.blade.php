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
@section('title', __('messages.profile.title'))
@section('medium', '1')
@section('content')
    <h1>{{ __('messages.profile.title') }}</h1>

    <div class="card">
        <h2 style="margin-top:0">{{ __('messages.profile.account') }}</h2>
        <p>
            <strong>{{ __('messages.users.username') }}:</strong>
            @if($user->username){{ $user->username }}@else<span class="muted">{{ __('messages.profile.no_username') }}</span>@endif
        </p>
        <p class="muted">{{ __('messages.profile.username_hint') }}</p>
        <form method="POST" action="{{ route('profile.email') }}">
            @csrf
            <x-field name="email" type="email" :label="__('messages.auth.email')" :value="$user->email" autocomplete="email" required />
            <x-field name="email_password" type="password" :label="__('messages.profile.current_password')"
                     :tip="__('messages.profile.email_password_hint')" autocomplete="current-password" required />
            <button class="btn" type="submit">{{ __('messages.save') }}</button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0">{{ __('messages.profile.password') }}</h2>
        <form method="POST" action="{{ route('profile.password') }}">
            @csrf
            <x-field name="current_password" type="password" :label="__('messages.profile.current_password')" autocomplete="current-password" required />
            <x-field name="password" type="password" :label="__('messages.profile.new_password')"
                     :tip="__('messages.password_hint')" autocomplete="new-password" required />
            <x-field name="password_confirmation" type="password" :label="__('messages.setup.password_confirmation')" autocomplete="new-password" required />
            <button class="btn" type="submit">{{ __('messages.save') }}</button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0">{{ __('messages.profile.language') }}</h2>
        <form method="POST" action="{{ route('profile.locale') }}">
            @csrf
            <x-select name="locale" :label="__('messages.profile.language')"
                      :options="['de' => 'Deutsch', 'en' => 'English']" :value="$user->locale" />
            <button class="btn" type="submit">{{ __('messages.save') }}</button>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0">{{ __('messages.two_factor.title') }}</h2>
        @if($user->hasTwoFactor())
            <p><span class="badge ok">{{ __('messages.two_factor.active') }}</span></p>
        @elseif(! \App\Support\TwoFactorPolicy::requiredFor($user))
            <p><span class="badge">{{ __('messages.two_factor.optional_off') }}</span></p>
        @else
            <p><span class="badge warn">{{ __('messages.two_factor.inactive') }}</span></p>
        @endif
        <a class="btn secondary" href="{{ route('profile.two-factor') }}">{{ __('messages.two_factor.manage') }}</a>
    </div>
@endsection
