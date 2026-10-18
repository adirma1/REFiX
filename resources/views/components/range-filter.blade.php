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
{{--
    Aufklappbarer Zeitraum-Filter (Knopf mit Schnellwahlen und eigenem Zeitraum). Steht im GET-Formular der Liste.
    <x-range-filter :label="…" :from="$from" :to="$to" :presets="…" from-key="date_from" to-key="date_to" :clear="['date_from', 'date_to']" route="invoices.index"/>
    presets: [['label' => …, 'query' => [from-key => …, to-key => …], 'on' => optional bool]]; $text überschreibt die Beschriftung bei aktivem Filter.
--}}
@props(['label', 'from' => '', 'to' => '', 'text' => null, 'on' => null, 'presets' => [], 'fromKey' => 'date_from', 'toKey' => 'date_to', 'clear' => [], 'route'])
@php
    $active = $on ?? ($from !== '' || $to !== '');
    $text = $text ?? \App\Support\DateRangeFilter::label($from, $to);
    $clear = array_values(array_unique(array_merge($clear, [$fromKey, $toKey, 'page'])));
    $link = fn (array $query) => route($route, array_merge(\Illuminate\Support\Arr::except(request()->query(), $clear), $query));
@endphp
<span class="dd-wrap">
    <details class="dd">
        <summary @class(['ddb', 'on' => $active])>{{ $label }}@if($active && $text !== ''): {{ $text }}@endif <span aria-hidden="true">▾</span></summary>
        <div class="dd-panel">
            <a href="{{ $link([]) }}" @class(['act' => ! $active])>{{ __('messages.filter.until_all') }}</a>
            @foreach($presets as $preset)
                @php($current = $preset['on'] ?? (($preset['query'][$fromKey] ?? null) === $from && ($preset['query'][$toKey] ?? null) === $to && $active))
                <a href="{{ $link($preset['query']) }}" @class(['act' => $current])>{{ $preset['label'] }}</a>
            @endforeach
            <hr>
            <div class="dd-range">
                <strong>{{ __('messages.filter.until_custom') }}</strong>
                <label for="{{ $fromKey }}">{{ __('messages.filter.until_from') }}</label>
                <input type="date" id="{{ $fromKey }}" name="{{ $fromKey }}" value="{{ $from }}">
                <label for="{{ $toKey }}">{{ __('messages.filter.until_to') }}</label>
                <input type="date" id="{{ $toKey }}" name="{{ $toKey }}" value="{{ $to }}">
                <button class="btn small" type="submit">{{ __('messages.filter.until_apply') }}</button>
            </div>
        </div>
    </details>
    @if($active)
        <a class="dd-x" href="{{ $link([]) }}" title="{{ __('messages.filter.range_reset', ['name' => $label]) }}" aria-label="{{ __('messages.filter.range_reset', ['name' => $label]) }}">✕</a>
    @endif
</span>
@once
    @push('scripts')
    <script>
        // Menü des Zeitraum-Filters: schließt bei Klick daneben und mit Esc
        document.addEventListener('click', function (e) {
            document.querySelectorAll('details.dd[open]').forEach(function (d) { if (!d.contains(e.target)) { d.removeAttribute('open'); } });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { document.querySelectorAll('details.dd[open]').forEach(function (d) { d.removeAttribute('open'); }); }
        });
    </script>
    @endpush
@endonce
