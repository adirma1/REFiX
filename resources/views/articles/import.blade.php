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
@section('medium', '1')
@section('content')
    <h1>{{ __('messages.article_import.title') }}</h1>
    <p class="muted">{{ __('messages.article_import.intro') }}</p>

    <div class="card">
        <h2 style="margin-top:0">{{ __('messages.article_import.step_upload') }}</h2>
        <form method="POST" action="{{ route('articles.import.upload') }}" enctype="multipart/form-data">
            @csrf
            <div class="field">
                <label for="file">{{ __('messages.article_import.file') }}
                    <span class="tip" data-tip="{{ __('messages.article_import.file_tip') }}" tabindex="0" aria-label="{{ __('messages.article_import.file_tip') }}">ⓘ</span>
                </label>
                <input id="file" name="file" type="file" accept=".csv,.txt,text/csv" required>
                @error('file')<div class="error">{{ $message }}</div>@enderror
            </div>
            <button class="btn" type="submit">{{ __('messages.article_import.next') }}</button>
            <a class="btn secondary" href="{{ route('articles.index') }}">{{ __('messages.cancel') }}</a>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0">{{ __('messages.article_import.rules_title') }}</h2>
        <ul>
            <li>{{ __('messages.article_import.rule_header') }}</li>
            <li>{{ __('messages.article_import.rule_required') }}</li>
            <li>{{ __('messages.article_import.rule_defaults') }}</li>
            <li>{{ __('messages.article_import.rule_price') }}</li>
            <li>{{ __('messages.article_import.rule_duplicates') }}</li>
        </ul>
        <a class="btn secondary" href="{{ route('articles.import.template') }}">{{ __('messages.article_import.template') }}</a>
    </div>
@endsection
