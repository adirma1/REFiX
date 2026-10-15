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
@section('title', __('messages.nav.backups'))
@section('content')
    <div class="toolbar">
        <h1 style="margin:0">{{ __('messages.nav.backups') }}</h1>
        <span class="spacer"></span>
        <form method="POST" action="{{ route('backups.store') }}"
              onsubmit="var b=this.querySelector('button'); b.disabled=true; b.textContent='{{ __('messages.backups.creating') }}';">
            @csrf
            <button class="btn" type="submit">{{ __('messages.backups.create') }}</button>
        </form>
    </div>

    <p class="muted">{{ __('messages.backups.intro') }}</p>

    <div class="alert warn">
        <strong>{{ __('messages.backups.key_title') }}</strong>
        {{ __('messages.backups.key_text', ['fingerprint' => $fingerprint]) }}
    </div>

    @if(count($backups) === 0)
        <p class="muted">{{ __('messages.backups.empty') }}</p>
    @else
        <table>
            <thead>
            <tr>
                <th>{{ __('messages.backups.file') }}</th>
                <th>{{ __('messages.backups.created_at') }}</th>
                <th class="num">{{ __('messages.backups.size') }}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach($backups as $backup)
                <tr>
                    <td class="mono">{{ $backup['name'] }}</td>
                    <td>{{ \Illuminate\Support\Carbon::createFromTimestamp($backup['created'])->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</td>
                    <td class="num">{{ number_format($backup['size'] / 1048576, 2, ',', '.') }} MB</td>
                    <td class="actions">
                        <a class="btn small secondary" href="{{ route('backups.download', $backup['name']) }}">{{ __('messages.backups.download') }}</a>
                        <form method="POST" action="{{ route('backups.destroy', $backup['name']) }}" onsubmit="return confirm('{{ __('messages.confirm_delete') }}')">
                            @csrf
                            @method('DELETE')
                            <button class="btn small danger" type="submit">{{ __('messages.delete') }}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="card" style="margin-top:1.5rem">
        <h2 style="margin-top:0">{{ __('messages.backups.restore_title') }}</h2>
        <p class="muted">{{ __('messages.backups.restore_text') }}</p>
        <pre class="mono" style="overflow-x:auto;background:var(--bg);padding:.6rem .8rem;border-radius:6px">docker exec -it -u www-data rechnungsapp-app-1 php artisan backup:restore backup-JJJJMMTT-HHMMSS.zip</pre>
    </div>
@endsection
