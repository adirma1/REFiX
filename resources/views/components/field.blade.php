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
--}}@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'required' => false,
    'hint' => null,
    'tip' => null,
    'autocomplete' => null,
    'step' => null,
    'maxlength' => null,
])
<div class="field">
    <label for="{{ $name }}">{{ $label }}@if($required) *@endif
        @if($tip)<span class="tip" data-tip="{{ $tip }}" tabindex="0" aria-label="{{ $tip }}">ⓘ</span>@endif
    </label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
        @if($type !== 'password') value="{{ old($name, $value) }}" @endif
        @if($required) required @endif
        @if($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if($step) step="{{ $step }}" @endif
        @if($maxlength) maxlength="{{ $maxlength }}" @endif
        @if($tip) title="{{ $tip }}" @endif>
    @if($hint)<div class="hint">{{ $hint }}</div>@endif
    @error($name)<div class="error">{{ $message }}</div>@enderror
</div>
