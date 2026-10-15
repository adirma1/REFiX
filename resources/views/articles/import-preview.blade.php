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
@section('title', __('messages.article_import.title'))
@section('content')
    <h1>{{ __('messages.article_import.title') }}</h1>

    <div class="tiles" style="margin-bottom:1rem">
        <div class="tile"><div class="value">{{ $analysis['ok'] }}</div><div class="label">{{ __('messages.article_import.count_ok') }}</div></div>
        <div class="tile"><div class="value">{{ $analysis['update'] }}</div><div class="label">{{ __('messages.article_import.count_update') }}</div></div>
        <div class="tile"><div class="value">{{ $analysis['duplicate'] }}</div><div class="label">{{ __('messages.article_import.count_duplicate') }}</div></div>
        <div class="tile"><div class="value">{{ $analysis['error'] }}</div><div class="label">{{ __('messages.article_import.count_error') }}</div></div>
    </div>

    @if(count($problems) > 0)
        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.article_import.problems') }}</h2>
            <table class="audit-table">
                <thead>
                <tr>
                    <th>{{ __('messages.article_import.line') }}</th>
                    <th>{{ __('messages.articles.number') }}</th>
                    <th>{{ __('messages.articles.name') }}</th>
                    <th>{{ __('messages.article_import.reason') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($problems as $row)
                    <tr>
                        <td>{{ $row['line'] }}</td>
                        <td>{{ $row['data']['article_number'] ?: '–' }}</td>
                        <td>{{ $row['data']['name'] }}</td>
                        <td>
                            <span @class(['badge', 'warn' => $row['status'] === 'duplicate'])>{{ __('messages.article_import.status_'.$row['status']) }}</span>
                            {{ $row['reason'] }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if($analysis['duplicate'] + $analysis['error'] > count($problems))
                <p class="muted">{{ __('messages.article_import.more_problems', ['count' => $analysis['duplicate'] + $analysis['error'] - count($problems)]) }}</p>
            @endif
        </div>
    @endif

    @if(count($samples) > 0)
        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.article_import.samples') }}</h2>
            <table>
                <thead>
                <tr>
                    <th>{{ __('messages.articles.number') }}</th>
                    <th>{{ __('messages.articles.name') }}</th>
                    <th>{{ __('messages.articles.unit') }}</th>
                    <th>{{ __('messages.articles.tax_rate') }}</th>
                    <th>{{ __('messages.articles.price') }}</th>
                    <th>{{ __('messages.article_import.action') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($samples as $row)
                    <tr>
                        <td>{{ $row['data']['article_number'] ?: '–' }}</td>
                        <td>{{ $row['data']['name'] }}</td>
                        <td>{{ $row['data']['unit'] }}</td>
                        <td class="num">{{ $row['data']['tax_rate'] ?? '–' }}</td>
                        <td class="num">@if($row['data']['price'] !== null){{ $row['data']['price'] }} {{ $row['data']['currency'] }}@else–@endif</td>
                        <td>
                            <span @class(['badge', 'ok' => $row['status'] === 'ok'])>{{ __('messages.article_import.status_'.$row['status']) }}</span>
                            @if($row['price'] === 'new' && $row['status'] === 'update') <span class="muted">{{ __('messages.article_import.new_price') }}</span>@endif
                            @if($row['note'] !== '') <span class="muted">{{ $row['note'] }}</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <form method="POST" action="{{ route('articles.import.run', $token) }}"
          onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled=true;">
        @csrf
        <input type="hidden" name="mode" value="{{ $mode }}">
        @foreach($mapping as $field => $index)
            @if($index !== null)<input type="hidden" name="map[{{ $field }}]" value="{{ $index }}">@endif
        @endforeach
        <button class="btn" type="submit" @disabled($analysis['ok'] + $analysis['update'] === 0)>{{ __('messages.article_import.run', ['count' => $analysis['ok'] + $analysis['update']]) }}</button>
        <a class="btn secondary" href="{{ route('articles.import.map', $token) }}">{{ __('messages.article_import.back') }}</a>
    </form>
@endsection
