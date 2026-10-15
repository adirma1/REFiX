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
@section('title', __('messages.customer_import.title'))
@section('content')
    <h1>{{ __('messages.customer_import.title') }}</h1>

    <div class="tiles" style="margin-bottom:1rem">
        <div class="tile"><div class="value">{{ $analysis['ok'] }}</div><div class="label">{{ __('messages.customer_import.count_ok') }}</div></div>
        <div class="tile"><div class="value">{{ $analysis['duplicate'] }}</div><div class="label">{{ __('messages.customer_import.count_duplicate') }}</div></div>
        <div class="tile"><div class="value">{{ $analysis['error'] }}</div><div class="label">{{ __('messages.customer_import.count_error') }}</div></div>
    </div>

    @if(count($problems) > 0)
        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.customer_import.problems') }}</h2>
            <table class="audit-table">
                <thead>
                <tr>
                    <th>{{ __('messages.customer_import.line') }}</th>
                    <th>{{ __('messages.customers.number') }}</th>
                    <th>{{ __('messages.customers.company') }}</th>
                    <th>{{ __('messages.customer_import.reason') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($problems as $row)
                    <tr>
                        <td>{{ $row['line'] }}</td>
                        <td>{{ $row['auto_number'] ? '–' : $row['data']['customer_number'] }}</td>
                        <td>{{ $row['data']['company'] ?: trim(($row['data']['first_name'] ?? '').' '.($row['data']['last_name'] ?? '')) }}</td>
                        <td>
                            <span @class(['badge', 'warn' => $row['status'] === 'duplicate'])>{{ __('messages.customer_import.status_'.$row['status']) }}</span>
                            {{ $row['reason'] }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if($analysis['duplicate'] + $analysis['error'] > count($problems))
                <p class="muted">{{ __('messages.customer_import.more_problems', ['count' => $analysis['duplicate'] + $analysis['error'] - count($problems)]) }}</p>
            @endif
        </div>
    @endif

    @if(count($samples) > 0)
        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.customer_import.samples') }}</h2>
            <table>
                <thead>
                <tr>
                    <th>{{ __('messages.customers.number') }}</th>
                    <th>{{ __('messages.customers.company') }}</th>
                    <th>{{ __('messages.customers.city') }}</th>
                    <th>{{ __('messages.customers.email') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($samples as $row)
                    <tr>
                        <td>{{ $row['data']['customer_number'] }}@if($row['auto_number']) <span class="muted">({{ __('messages.customer_import.auto') }})</span>@endif</td>
                        <td>{{ $row['data']['company'] ?: trim(($row['data']['first_name'] ?? '').' '.($row['data']['last_name'] ?? '')) }}</td>
                        <td>{{ $row['data']['zip'] }} {{ $row['data']['city'] }}</td>
                        <td>{{ $row['data']['email'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <form method="POST" action="{{ route('customers.import.run', $token) }}"
          onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled=true;">
        @csrf
        @foreach($mapping as $field => $index)
            @if($index !== null)<input type="hidden" name="map[{{ $field }}]" value="{{ $index }}">@endif
        @endforeach
        <button class="btn" type="submit" @disabled($analysis['ok'] === 0)>{{ __('messages.customer_import.run', ['count' => $analysis['ok']]) }}</button>
        <a class="btn secondary" href="{{ route('customers.import.map', $token) }}">{{ __('messages.customer_import.back') }}</a>
    </form>
@endsection
