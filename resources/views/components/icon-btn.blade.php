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
--}}
{{-- Quadratischer Icon-Button für Tabellenzeilen: <x-icon-btn kind="edit" :href="$url"/> (Link), kind="download" :href (Link), kind="delete" (Submit im Formular). --}}
@props(['kind', 'href' => null])
@php
    $label = match ($kind) { 'edit' => __('messages.edit'), 'delete' => __('messages.delete'), 'download' => __('messages.backups.download'), default => '' };
@endphp
@if($kind === 'delete')
    <button type="submit" {{ $attributes->class(['ibtn', 'danger']) }} title="{{ $label }}" aria-label="{{ $label }}">
@else
    <a href="{{ $href }}" {{ $attributes->class(['ibtn']) }} title="{{ $label }}" aria-label="{{ $label }}">
@endif
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        @if($kind === 'edit')
            <path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17z"/><path d="M14.5 7.5l3 3"/>
        @elseif($kind === 'delete')
            <path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6.5 7l1 13h9l1-13"/><path d="M10 11v6M14 11v6"/>
        @else
            <path d="M12 4v10"/><path d="M8 10.5l4 4 4-4"/><path d="M5 19h14"/>
        @endif
    </svg>
@if($kind === 'delete')
    </button>
@else
    </a>
@endif
