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
@section('title', __('messages.nav.tenants'))
@section('content')
    <div class="toolbar">
        <h1 style="margin:0">{{ __('messages.nav.tenants') }}</h1>
        <span class="spacer"></span>
        <a class="btn" href="{{ route('tenants.create') }}">{{ __('messages.tenants.new') }}</a>
    </div>

    <table>
        <thead>
        <tr>
            <x-th-sort :sort="$sort" col="name">{{ __('messages.tenants.name') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="city">{{ __('messages.tenants.city') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="mode">{{ __('messages.tenants.mode') }}</x-th-sort>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach($tenants as $tenant)
            <tr>
                <td>{{ $tenant->name }}
                    @if($tenant->id === $currentTenant->id)<span class="badge ok">{{ __('messages.tenants.active') }}</span>@endif
                </td>
                <td>{{ $tenant->zip }} {{ $tenant->city }}</td>
                <td>
                    @if($tenant->test_mode)
                        <span class="badge warn">{{ __('messages.testmode.on') }}</span>
                    @else
                        <span class="badge">{{ __('messages.testmode.off') }}</span>
                    @endif
                </td>
                <td class="actions">
                    <div class="icon-actions"><x-icon-btn kind="edit" :href="route('tenants.edit', $tenant)"/></div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endsection
