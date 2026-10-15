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
    'options' => [],
    'value' => null,
    'required' => false,
    'blank' => null,
])
<div class="field">
    <label for="{{ $name }}">{{ $label }}@if($required) *@endif</label>
    <select id="{{ $name }}" name="{{ $name }}" @if($required) required @endif>
        @if($blank !== null)<option value="">{{ $blank }}</option>@endif
        @foreach($options as $key => $text)
            <option value="{{ $key }}" @selected((string) old($name, $value) === (string) $key)>{{ $text }}</option>
        @endforeach
    </select>
    @error($name)<div class="error">{{ $message }}</div>@enderror
</div>
