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
@section('title', __('messages.about.title'))
@section('medium', '1')
@section('content')
    <h1>{{ config('app.brand') }}</h1>

    <div class="card">
        <p><strong>{{ __('messages.about.version') }}</strong> {{ config('app.version') }}</p>
        <p><strong>{{ __('messages.about.author') }}</strong> Martin Adirsch</p>
    </div>

    <div class="card">
        <h2 style="margin-top:0">{{ __('messages.about.license') }}</h2>
        <p>{{ __('messages.about.license_text') }}</p>
    </div>

    <div class="card">
        <h2 style="margin-top:0">{{ __('messages.about.donate') }}</h2>
        <p>{!! __('messages.about.donate_text', ['email' => '<a href="mailto:martin.adirsch@gmx.de">martin.adirsch@gmx.de</a>']) !!}</p>
    </div>

    @can('manage-users')
        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.about.technical') }}</h2>
            <p class="muted">{{ __('messages.about.php') }} {{ PHP_VERSION }} · {{ __('messages.about.framework') }} {{ app()->version() }}</p>
        </div>
    @endcan
@endsection
