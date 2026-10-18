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
@section('title', __('messages.nav.audit'))
@section('content')
    <div class="page-head">
        <h1>{{ __('messages.nav.audit') }}</h1>
        <div class="head-actions" title="{{ $filtered ? __('messages.audit.export_filtered') : __('messages.audit.export_all') }}">
            <a class="btn secondary" href="{{ route('audit.export', array_merge(request()->query(), ['format' => 'csv'])) }}">{{ __('messages.audit.export_csv') }}</a>
            <a class="btn secondary" href="{{ route('audit.export', array_merge(request()->query(), ['format' => 'txt'])) }}">{{ __('messages.audit.export_txt') }}</a>
            @if($totalCount > 0)
                <form method="POST" action="{{ route('audit.clear') }}"
                      onsubmit="return confirm(@js(__('messages.audit.clear_confirm', ['count' => $totalCount])))">
                    @csrf
                    @method('DELETE')
                    <button class="btn danger" type="submit">{{ __('messages.audit.clear') }}</button>
                </form>
            @endif
        </div>
    </div>

    <form method="GET" action="{{ route('audit.index') }}" class="card">
        <div class="grid">
            <div class="field">
                <label for="q">{{ __('messages.audit.search') }}</label>
                <input type="text" id="q" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('messages.audit.search_hint') }}">
            </div>
            <div class="field">
                <label for="user">{{ __('messages.audit.user') }}</label>
                <select id="user" name="user">
                    <option value="">{{ __('messages.audit.all_users') }}</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}" @selected($filters['user'] === $user->id)>{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="action">{{ __('messages.audit.action') }}</label>
                <select id="action" name="action">
                    <option value="">{{ __('messages.audit.all_actions') }}</option>
                    @foreach($actions as $action)
                        <option value="{{ $action }}" @selected($filters['action'] === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="subject">{{ __('messages.audit.subject') }}</label>
                <select id="subject" name="subject">
                    <option value="">{{ __('messages.audit.all_subjects') }}</option>
                    @foreach($subjects as $class => $label)
                        <option value="{{ $class }}" @selected($filters['subject'] === $class)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="from">{{ __('messages.audit.from') }}</label>
                <input type="date" id="from" name="from" value="{{ $filters['from'] }}">
            </div>
            <div class="field">
                <label for="to">{{ __('messages.audit.to') }}</label>
                <input type="date" id="to" name="to" value="{{ $filters['to'] }}">
            </div>
        </div>
        <button class="btn" type="submit">{{ __('messages.audit.filter') }}</button>
        @if($filtered)
            <a class="btn secondary" href="{{ route('audit.index') }}">{{ __('messages.audit.reset') }}</a>
        @endif
        <span class="muted" style="margin-left:.75rem">{{ __('messages.audit.results', ['count' => $logs->total()]) }}</span>
    </form>

    <table class="audit-table">
        <thead>
        <tr>
            <x-th-sort :sort="$sort" col="time">{{ __('messages.audit.time') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="user">{{ __('messages.audit.user') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="action">{{ __('messages.audit.action') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="details">{{ __('messages.audit.details') }}</x-th-sort>
        </tr>
        </thead>
        <tbody>
        @forelse($logs as $log)
            <tr>
                <td style="white-space:nowrap">{{ $log->created_at->format('d.m.Y H:i:s') }}</td>
                <td>{{ $log->user?->name ?? '–' }}</td>
                <td><code>{{ $log->action }}</code></td>
                <td class="muted">
                    @if($log->subject_type){{ class_basename($log->subject_type) }} #{{ $log->subject_id }}@endif
                    @if($log->details)<div class="mono">{{ json_encode($log->details, JSON_UNESCAPED_UNICODE) }}</div>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">{{ $filtered ? __('messages.audit.no_match') : __('messages.audit.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    @include('pagination.footer', ['paginator' => $logs, 'id' => 'audit'])
@endsection
