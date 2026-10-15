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
--}}<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') – {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
<main class="narrow">
    <h1 style="text-align:center"><img src="{{ asset('img/refix-logo.png') }}" alt="" height="44" style="vertical-align:middle;background:#1b4f9c;border-radius:8px;padding:6px 8px;margin-right:.4rem"> {{ config('app.brand') }}</h1>
    @if(session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
    @yield('content')
    <div class="langswitch">
        <a href="{{ route('locale', 'de') }}">Deutsch</a> · <a href="{{ route('locale', 'en') }}">English</a>
    </div>
    <p class="version-line">{{ config('app.brand') }} {{ __('messages.about.version') }} {{ config('app.version') }}</p>
</main>
</body>
</html>
