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
    <p class="muted">{{ __('messages.article_import.map_intro', ['rows' => count($parsed['rows'])]) }}</p>

    <form method="POST" action="{{ route('articles.import.preview', $token) }}">
        @csrf
        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.article_import.step_map') }}</h2>
            <table class="matrix-map">
                <thead>
                <tr>
                    <th>{{ __('messages.article_import.field') }}</th>
                    <th>{{ __('messages.article_import.column') }}</th>
                    <th>{{ __('messages.article_import.example') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($fields as $field)
                    @php($selected = $mapping[$field] ?? null)
                    <tr>
                        <td>{{ $importer->label($field) }}@if(in_array($field, ['name', 'unit'], true)) *@endif</td>
                        <td>
                            <select name="map[{{ $field }}]">
                                <option value="">{{ __('messages.article_import.not_imported') }}</option>
                                @foreach($parsed['headers'] as $index => $header)
                                    <option value="{{ $index }}" @selected((string) $selected === (string) $index)>{{ $header !== '' ? $header : __('messages.article_import.column_n', ['n' => $index + 1]) }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="muted">{{ $selected !== null ? ($parsed['rows'][0][$selected] ?? '') : '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.article_import.mode_title') }}</h2>
            <label style="font-weight:400;display:block"><input type="radio" name="mode" value="skip" @checked($mode !== 'update')> {{ __('messages.article_import.mode_skip') }}</label>
            <label style="font-weight:400;display:block"><input type="radio" name="mode" value="update" @checked($mode === 'update')> {{ __('messages.article_import.mode_update') }}</label>
        </div>
        <button class="btn" type="submit">{{ __('messages.article_import.preview') }}</button>
        <a class="btn secondary" href="{{ route('articles.import') }}">{{ __('messages.cancel') }}</a>
    </form>
@endsection
