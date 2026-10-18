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
{{-- Sortierbarer Spaltenkopf: <x-th-sort :sort="$sort" col="number" class="num">Nummer</x-th-sort> --}}
@props(['sort', 'col'])
@php
    // Der Pfeil bleibt am letzten Wort hängen und rutscht nie allein in eine neue Zeile (Köpfe höchstens so viele Zeilen wie Wörter).
    $label = trim((string) $slot);
    $cut = strrpos($label, ' ');
    $head = $cut === false ? '' : substr($label, 0, $cut + 1);
    $tail = $cut === false ? $label : substr($label, $cut + 1);
@endphp
<th {{ $attributes->class(['sortable', 'sorted' => $sort->isActive($col)]) }} aria-sort="{{ $sort->ariaSort($col) }}">
    <a class="sort-link" href="{{ $sort->url($col) }}" title="{{ __('messages.sort.title') }}">{!! $head !!}<span class="sort-tail">{!! $tail !!}<span class="sort-ind" aria-hidden="true">{{ $sort->isActive($col) ? ($sort->dir === 'asc' ? '▲' : '▼') : '↕' }}</span></span></a>
</th>
