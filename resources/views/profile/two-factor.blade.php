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
@section('title', __('messages.two_factor.title'))
@section('medium', '1')
@section('content')
    <h1>{{ __('messages.two_factor.title') }}</h1>

    @if($recoveryCodes)
        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.two_factor.recovery_title') }}</h2>
            <div class="alert warn">{{ __('messages.two_factor.recovery_hint') }}</div>
            <div class="codes">
                @foreach($recoveryCodes as $recoveryCode)
                    <div>{{ $recoveryCode }}</div>
                @endforeach
            </div>
            <p style="margin-bottom:0">
                <a class="btn" href="{{ route('dashboard') }}">{{ __('messages.two_factor.continue') }}</a>
                <span class="muted">{{ __('messages.two_factor.continue_hint') }}</span>
            </p>
        </div>
    @endif

    @unless($recoveryCodes)
        <div class="card">
            <form method="POST" action="{{ route('profile.two-factor.preference') }}" id="tf-form">
                @csrf
                <label class="switch-row">
                    <span class="switch">
                        <input type="checkbox" name="enabled" value="1" role="switch" id="tf-switch" @checked($enabled || $wanted)>
                        <span class="slider" aria-hidden="true"></span>
                    </span>
                    <span><strong>{{ __('messages.two_factor.use') }}</strong></span>
                </label>
                <p class="muted">{{ __('messages.two_factor.use_hint') }}</p>
                <div id="tf-password" @if($errors->has('current_password')) @else hidden @endif>
                    <x-field name="current_password" type="password" :label="__('messages.profile.current_password')"
                             :tip="__('messages.two_factor.off_password')" autocomplete="current-password" />
                </div>
                <button class="btn" type="submit" id="tf-save" hidden>{{ __('messages.save') }}</button>
            </form>
        </div>
        <script>
            (function () {
                var sw = document.getElementById('tf-switch'), start = sw.checked, hasError = @json($errors->has('current_password'));
                var pw = document.getElementById('tf-password'), save = document.getElementById('tf-save');
                var sync = function () {
                    save.hidden = sw.checked === start;                 // nur bei einer Änderung speichern
                    pw.hidden = !(start && !sw.checked) && !hasError;  // Passwort nur zum Ausschalten
                    document.getElementById('current_password').required = !pw.hidden;
                };
                sw.addEventListener('change', sync);
                sync();
            })();
        </script>
    @endunless

    @if(! $enabled && $wanted)
        <div class="card">
            <p>{{ __('messages.two_factor.setup_intro') }}</p>
            <div class="qr">{!! $qr !!}</div>
            <p class="muted">{{ __('messages.two_factor.manual_key') }}: <code>{{ $secret }}</code></p>
            <form method="POST" action="{{ route('profile.two-factor.confirm') }}">
                @csrf
                <x-field name="code" :label="__('messages.two_factor.code')" autocomplete="one-time-code" required />
                <button class="btn" type="submit">{{ __('messages.two_factor.enable') }}</button>
            </form>
        </div>
    @elseif($enabled)
        <div class="card">
            <p><span class="badge ok">{{ __('messages.two_factor.active') }}</span>
                {{ __('messages.two_factor.remaining', ['count' => $remaining]) }}</p>

            {{-- Direkt nach dem Anzeigen der Codes nicht anbieten, neue zu erzeugen: das verwirrt beim Einrichten. --}}
            @unless($recoveryCodes)
                <h2>{{ __('messages.two_factor.regenerate') }}</h2>
                <form method="POST" action="{{ route('profile.two-factor.regenerate') }}">
                    @csrf
                    <x-field name="password" type="password" :label="__('messages.profile.current_password')" autocomplete="current-password" required />
                    <button class="btn secondary" type="submit">{{ __('messages.two_factor.regenerate') }}</button>
                </form>
            @endunless

        </div>
    @endif
@endsection
