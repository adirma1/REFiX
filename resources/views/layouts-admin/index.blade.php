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
@section('title', __('messages.nav.layouts'))
@section('content')
    <h1>{{ __('messages.nav.layouts') }}</h1>
    <p class="muted">{{ __('messages.layouts.intro') }}</p>

    <div class="toolbar" style="margin-bottom:.75rem">
        <a @class(['btn', 'secondary' => $kind !== 'invoice']) href="{{ route('layouts.index') }}">{{ __('messages.layouts.kind_invoice') }}</a>
        <a @class(['btn', 'secondary' => $kind !== 'dunning']) href="{{ route('layouts.index', ['kind' => 'dunning']) }}">{{ __('messages.layouts.kind_dunning') }}</a>
        <a @class(['btn', 'secondary' => $kind !== 'quote']) href="{{ route('layouts.index', ['kind' => 'quote']) }}">{{ __('messages.layouts.kind_quote') }}</a>
    </div>
    <p class="muted">{{ __('messages.layouts.kind_hint_'.$kind) }}</p>

    <div class="card">
        <h2 style="margin-top:0">{{ __('messages.layouts.active') }}</h2>
        @if($active)
            <p><span class="badge ok">{{ __('messages.layouts.version', ['version' => $active->version]) }}</span> {{ $active->name }}</p>
            <form method="POST" action="{{ route('layouts.default') }}" onsubmit="return confirm('{{ __('messages.layouts.confirm_default') }}')">
                @csrf
                <input type="hidden" name="kind" value="{{ $kind }}">
                <button class="btn secondary" type="submit">{{ __('messages.layouts.use_default') }}</button>
            </form>
        @else
            <p><span class="badge">{{ __('messages.layouts.standard') }}</span> {{ __('messages.layouts.standard_hint') }}</p>
        @endif
    </div>

    <div class="card">
        <h2 style="margin-top:0">1. {{ __('messages.layouts.step_prompt') }}</h2>
        <p class="muted">{{ __('messages.layouts.prompt_help') }}</p>

        <form method="POST" action="{{ route('layouts.wishes') }}">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            <div class="field">
                <label for="layout_wishes">{{ __('messages.layouts.wishes') }}</label>
                <textarea id="layout_wishes" name="layout_wishes" rows="4" maxlength="3000" placeholder="{{ __('messages.layouts.wishes_placeholder') }}">{{ old('layout_wishes', $wishes) }}</textarea>
                @error('layout_wishes')<div class="error">{{ $message }}</div>@enderror
            </div>
            <button class="btn secondary" type="submit">{{ __('messages.layouts.save_wishes') }}</button>
        </form>

        <div class="field" style="margin-top:1rem">
            <label for="prompt_text">{{ __('messages.layouts.prompt') }}</label>
            <textarea id="prompt_text" readonly rows="10" class="mono" style="font-size:.78rem">{{ $prompt }}</textarea>
        </div>
        <button class="btn" type="button" id="copy-prompt">{{ __('messages.layouts.copy') }}</button>
        <span class="muted" id="copy-done" style="display:none;margin-left:.5rem">{{ __('messages.layouts.copied') }}</span>
    </div>

    <div class="card">
        <h2 style="margin-top:0">2. {{ __('messages.layouts.step_paste') }}</h2>
        <p class="muted">{{ __('messages.layouts.paste_help') }}</p>

        @if($errors->has('layout'))
            <div class="alert error">
                <b>{{ __('messages.layouts.rejected') }}</b>
                <ul style="margin:.4rem 0 0 1.2rem;padding:0">
                    @foreach($errors->get('layout') as $messages)
                        @foreach((array) $messages as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('layouts.store') }}">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            <div class="field">
                <label for="name">{{ __('messages.layouts.name') }} *</label>
                <input id="name" name="name" type="text" maxlength="120" required value="{{ old('name', $prefill['name'] ?? '') }}">
                @error('name')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label for="content">{{ __('messages.layouts.content') }} *</label>
                <textarea id="content" name="content" rows="14" required class="mono" style="font-size:.78rem" spellcheck="false">{{ old('content', $prefill['content'] ?? '') }}</textarea>
                @error('content')<div class="error">{{ $message }}</div>@enderror
            </div>

            <details style="margin-bottom:1rem">
                <summary>{{ __('messages.layouts.margins') }}</summary>
                <div class="grid" style="margin-top:.6rem">
                    @foreach(['top', 'right', 'bottom', 'left'] as $side)
                        <div class="field">
                            <label for="margin_{{ $side }}">{{ __('messages.layouts.margin_'.$side) }}</label>
                            <input id="margin_{{ $side }}" name="margin_{{ $side }}" type="number" min="0" max="80" required value="{{ old('margin_'.$side, $margins[$side]) }}">
                            @error('margin_'.$side)<div class="error">{{ $message }}</div>@enderror
                        </div>
                    @endforeach
                </div>
            </details>

            <label style="font-weight:400;display:block;margin-bottom:1rem">
                <input type="checkbox" name="activate" value="1" @checked(old('activate'))> {{ __('messages.layouts.activate_now') }}
            </label>
            <button class="btn" type="submit">{{ __('messages.layouts.check_save') }}</button>
            <a class="btn secondary" href="{{ route('layouts.index', ['standard' => 1, 'kind' => $kind]) }}#content">{{ __('messages.layouts.load_standard') }}</a>
        </form>
    </div>

    <div class="card">
        <h2 style="margin-top:0">3. {{ __('messages.layouts.step_versions') }}</h2>
        @if($versions->isEmpty())
            <p class="muted">{{ __('messages.layouts.no_versions') }}</p>
        @else
            <table>
                <thead>
                <tr>
                    <x-th-sort :sort="$sort" col="version">{{ __('messages.layouts.version_col') }}</x-th-sort>
                    <x-th-sort :sort="$sort" col="name">{{ __('messages.layouts.name') }}</x-th-sort>
                    <x-th-sort :sort="$sort" col="created">{{ __('messages.layouts.created') }}</x-th-sort>
                    <x-th-sort :sort="$sort" col="status">{{ __('messages.layouts.status') }}</x-th-sort>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($versions as $version)
                    <tr>
                        <td>{{ $version->version }}</td>
                        <td>{{ $version->name }}</td>
                        <td class="muted">{{ $version->created_at->format('d.m.Y H:i') }}@if($version->creator) · {{ $version->creator->name }}@endif</td>
                        <td>@if($version->is_active)<span class="badge ok">{{ __('messages.layouts.is_active') }}</span>@endif</td>
                        <td style="white-space:nowrap;text-align:right">
                            <a class="btn small secondary" href="{{ route('layouts.preview', $version) }}" target="_blank" rel="noopener">{{ __('messages.layouts.preview') }}</a>
                            <a class="btn small secondary" href="{{ route('layouts.index', ['from' => $version->id, 'kind' => $kind]) }}#content">{{ __('messages.layouts.copy_as_template') }}</a>
                            @unless($version->is_active)
                                <form method="POST" action="{{ route('layouts.activate', $version) }}" style="display:inline">
                                    @csrf
                                    <button class="btn small" type="submit">{{ __('messages.layouts.activate') }}</button>
                                </form>
                                <form method="POST" action="{{ route('layouts.destroy', $version) }}" style="display:inline" onsubmit="return confirm('{{ __('messages.layouts.confirm_delete') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn small danger" type="submit">{{ __('messages.delete') }}</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection

@push('scripts')
<script>
    // copy the prompt to the clipboard
    document.getElementById('copy-prompt').addEventListener('click', function () {
        var field = document.getElementById('prompt_text');
        var done = function () {
            var note = document.getElementById('copy-done');
            note.style.display = 'inline';
            setTimeout(function () { note.style.display = 'none'; }, 2500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(field.value).then(done, function () { field.select(); document.execCommand('copy'); done(); });
        } else {
            field.select();
            document.execCommand('copy');
            done();
        }
    });
</script>
@endpush
