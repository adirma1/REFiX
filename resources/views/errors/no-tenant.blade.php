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
@section('title', __('messages.no_tenant.title'))
@section('content')
    <div class="card">
        <h2>{{ __('messages.no_tenant.title') }}</h2>
        <p>{{ __('messages.no_tenant.text') }}</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn secondary" type="submit">{{ __('messages.nav.logout') }}</button>
        </form>
    </div>
@endsection
