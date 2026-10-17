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
@section('title', __('messages.nav.dashboard'))
@php
    // Cent → "1.234,56 EUR"; je Währung eine Zeile, die Hauptwährung (EUR bzw. die erste) groß.
    $fmt = fn (int $cents) => number_format($cents / 100, 2, ',', '.');
    $lines = function (array $byCurrency) use ($fmt, $stats) {
        $out = [];
        foreach ($stats['currencies'] as $cur) {
            $out[] = ['cur' => $cur, 'text' => $fmt((int) ($byCurrency[$cur] ?? 0)).' '.$cur, 'cents' => (int) ($byCurrency[$cur] ?? 0)];
        }
        return $out ?: [['cur' => 'EUR', 'text' => $fmt(0).' EUR', 'cents' => 0]];
    };

    // Balkendiagramm: Währung mit Umsatz, bevorzugt EUR
    $chartCur = null;
    foreach ($stats['currencies'] as $cur) {
        foreach ($stats['months'] as $m) {
            if (($m['values'][$cur] ?? 0) !== 0) { $chartCur = $cur; break 2; }
        }
    }
    $max = 0;
    if ($chartCur) {
        foreach ($stats['months'] as $m) { $max = max($max, $m['values'][$chartCur] ?? 0); }
    }
    $trend = null;
    $prev = (int) ($stats['revenue_prev_month'][$chartCur ?? 'EUR'] ?? 0);
    $now = (int) ($stats['revenue_month'][$chartCur ?? 'EUR'] ?? 0);
    if ($prev > 0) { $trend = (int) round(($now - $prev) / $prev * 100); }

    $tiles = [
            ['key' => 'open', 'data' => $stats['open'], 'label' => __('messages.dashboard.open_items', ['n' => $stats['open_count']]), 'href' => $canPayments ? route('payments.index') : null, 'tone' => null],
            ['key' => 'overdue', 'data' => $stats['overdue'], 'label' => __('messages.dashboard.overdue', ['n' => $stats['overdue_count']]), 'href' => $canPayments ? route('payments.index', ['filter' => 'overdue']) : null, 'tone' => $stats['overdue_count'] > 0 ? 'warn' : null],
            ['key' => 'rev_month', 'data' => $stats['revenue_month'], 'label' => __('messages.dashboard.revenue_month'), 'href' => null, 'tone' => null],
            ['key' => 'rev_year', 'data' => $stats['revenue_year'], 'label' => __('messages.dashboard.revenue_year', ['year' => now()->format('Y')]), 'href' => null, 'tone' => null],
            ['key' => 'paid', 'data' => $stats['paid_month'], 'label' => __('messages.dashboard.paid_month'), 'href' => null, 'tone' => null],
        ];
@endphp
@section('content')
    <h1>{{ __('messages.nav.dashboard') }}</h1>

    <div class="tiles">
        @foreach($tiles as $tile)
            @php $rows = $lines($tile['data']); @endphp
            @if($tile['href'])<a class="tile" href="{{ $tile['href'] }}" style="color:inherit;text-decoration:none">@else<div class="tile">@endif
                <div class="value" @if($tile['tone'] === 'warn') style="color:var(--warn)" @endif>{{ $rows[0]['text'] }}</div>
                @foreach(array_slice($rows, 1) as $extra)
                    @if($extra['cents'] !== 0)<div class="muted">{{ $extra['text'] }}</div>@endif
                @endforeach
                <div class="label">{{ $tile['label'] }}</div>
            @if($tile['href'])</a>@else</div>@endif
        @endforeach

        @if($canPayments && $dunningSuggestions !== null)
            <a class="tile" href="{{ route('dunning.index') }}" style="color:inherit;text-decoration:none">
                <div class="value">{{ $dunningSuggestions }}</div>
                <div class="label">{{ __('messages.dashboard.dunning_due') }}</div>
            </a>
        @endif
        @if($recurringDrafts !== null)
            <a class="tile" href="{{ route('recurring.index', ['drafts' => 1]) }}" style="color:inherit;text-decoration:none">
                <div class="value" @if($recurringDrafts > 0) style="color:var(--warn)" @endif>{{ $recurringDrafts }}</div>
                <div class="label">{{ __('messages.dashboard.recurring_drafts') }}</div>
            </a>
        @endif
        <a class="tile" href="{{ route('invoices.index', ['status' => 'draft']) }}" style="color:inherit;text-decoration:none">
            <div class="value">{{ $draftCount }}</div>
            <div class="label">{{ __('messages.dashboard.drafts') }}</div>
        </a>
        <a class="tile" href="{{ route('invoices.index', ['status' => 'issued', 'type' => 'invoice']) }}" style="color:inherit;text-decoration:none">
            <div class="value">{{ $issuedThisMonth }}</div>
            <div class="label">{{ __('messages.dashboard.issued_month') }}</div>
        </a>
    </div>

    @php
        $cs = $customerStats;
        $tagMax = max(1, $cs['tags'] ? max($cs['tags']) : 1, $cs['untagged']);
    @endphp
    <div class="card" style="margin-top:1.25rem">
        <h2 style="margin-top:0;display:flex;justify-content:space-between;align-items:baseline;gap:1rem;flex-wrap:wrap">
            <span>{{ __('messages.nav.customers') }}</span>
            <a href="{{ route('customers.index') }}" style="font-size:.9rem;font-weight:400">{{ __('messages.dashboard.customers_all') }}</a>
        </h2>
        <div class="tiles">
            <a class="tile" href="{{ route('customers.index') }}" style="color:inherit;text-decoration:none">
                <div class="value">{{ $cs['total'] }}</div>
                <div class="label">{{ __('messages.dashboard.customers_total') }}</div>
            </a>
            <a class="tile" href="{{ route('customers.index', ['status' => 'active']) }}" style="color:inherit;text-decoration:none">
                <div class="value" style="color:var(--ok)">{{ $cs['active'] }}</div>
                <div class="label">{{ __('messages.filter.active') }} · {{ $cs['active_pct'] }} %</div>
            </a>
            <a class="tile" href="{{ route('customers.index', ['status' => 'inactive']) }}" style="color:inherit;text-decoration:none">
                <div class="value">{{ $cs['inactive'] }}</div>
                <div class="label">{{ __('messages.filter.inactive') }} · {{ $cs['inactive_pct'] }} %</div>
            </a>
        </div>
        @if($cs['active'] > 0)
            <h3 style="margin:1.1rem 0 .5rem;font-size:1rem">{{ __('messages.dashboard.customers_by_tag') }}</h3>
            <div class="tagbars">
                @foreach($cs['tags'] as $tag => $n)
                    <a class="tagrow" href="{{ route('customers.index', ['status' => 'active', 'tag' => $tag]) }}">
                        <span class="mono">{{ $tag }}</span>
                        <span class="track"><span class="bar" style="width:{{ round($n / $tagMax * 100) }}%"></span></span>
                        <span class="n">{{ $n }}</span>
                    </a>
                @endforeach
                @if($cs['untagged'] > 0)
                    <div class="tagrow muted">
                        <span>{{ __('messages.dashboard.customers_untagged') }}</span>
                        <span class="track"><span class="bar" style="width:{{ round($cs['untagged'] / $tagMax * 100) }}%;opacity:.45"></span></span>
                        <span class="n">{{ $cs['untagged'] }}</span>
                    </div>
                @endif
            </div>
            @if($cs['more'] > 0)
                <p class="muted" style="margin:.4rem 0 0">{{ __('messages.dashboard.customers_more_tags', ['n' => $cs['more']]) }}</p>
            @endif
            <p class="muted" style="margin:.4rem 0 0;font-size:.85rem">{{ __('messages.dashboard.customers_tag_note') }}</p>
        @endif
    </div>

    <div class="card" style="margin-top:1.25rem">
        <h2 style="margin-top:0">
            {{ __('messages.dashboard.revenue_chart') }}
            @if($chartCur)<span class="badge">{{ $chartCur }}</span>@endif
            @if($trend !== null)
                <span class="badge {{ $trend >= 0 ? 'ok' : 'warn' }}" title="{{ __('messages.dashboard.vs_prev_month') }}">{{ $trend >= 0 ? '+' : '' }}{{ $trend }} %</span>
            @endif
        </h2>
        @if($chartCur && $max > 0)
            @php
                $w = 640; $h = 190; $left = 6; $bottom = 22; $top = 18; $slot = ($w - $left) / 12; $bar = $slot * 0.62;
                $plotH = $h - $bottom - $top;
            @endphp
            <svg viewBox="0 0 {{ $w }} {{ $h }}" role="img" aria-label="{{ __('messages.dashboard.revenue_chart') }}" style="width:100%;height:auto;max-height:240px">
                <line x1="{{ $left }}" y1="{{ $h - $bottom }}" x2="{{ $w }}" y2="{{ $h - $bottom }}" stroke="var(--line)" />
                @foreach($stats['months'] as $i => $m)
                    @php
                        $value = (int) ($m['values'][$chartCur] ?? 0);
                        $barH = $value > 0 ? max(2, $plotH * $value / $max) : 0;
                        $x = $left + $i * $slot + ($slot - $bar) / 2;
                        $isNow = $i === 11;
                    @endphp
                    <g>
                        <title>{{ $m['label'] }}: {{ $fmt($value) }} {{ $chartCur }}</title>
                        @if($barH > 0)
                            <rect x="{{ round($x, 1) }}" y="{{ round($h - $bottom - $barH, 1) }}" width="{{ round($bar, 1) }}" height="{{ round($barH, 1) }}" rx="2" fill="var(--primary)" opacity="{{ $isNow ? '1' : '0.62' }}" />
                        @endif
                        <text x="{{ round($x + $bar / 2, 1) }}" y="{{ $h - 6 }}" text-anchor="middle" font-size="10" fill="var(--muted)">{{ $m['label'] }}</text>
                    </g>
                @endforeach
                <text x="{{ $left }}" y="11" font-size="10" fill="var(--muted)">{{ $fmt($max) }}</text>
            </svg>
            <p class="muted" style="margin:.25rem 0 0">{{ __('messages.dashboard.revenue_note') }}</p>
        @else
            <p class="muted">{{ __('messages.dashboard.no_revenue') }}</p>
        @endif
    </div>

    @if($stats['overdue_top']->isNotEmpty())
        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.dashboard.overdue_list') }}</h2>
            <table>
                <thead>
                <tr>
                    <th>{{ __('messages.invoices.number') }}</th>
                    <th>{{ __('messages.invoices.customer') }}</th>
                    <th>{{ __('messages.invoices.due') }}</th>
                    <th class="num">{{ __('messages.payments.open') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($stats['overdue_top'] as $row)
                    <tr>
                        <td><a href="{{ route('invoices.show', $row['invoice']) }}">{{ $row['invoice']->number }}</a></td>
                        <td>{{ $row['invoice']->customer_snapshot['company'] ?? '' }}</td>
                        <td>{{ $row['invoice']->due_date?->format('d.m.Y') }} <span class="badge warn">{{ __('messages.payments.days_overdue', ['n' => $row['summary']['days_overdue']]) }}</span></td>
                        <td class="num">{{ $fmt($row['summary']['open']) }} {{ $row['invoice']->currency }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if($canPayments)
                <p style="margin-bottom:0"><a href="{{ route('payments.index', ['filter' => 'overdue']) }}">{{ __('messages.dashboard.all_overdue') }} →</a></p>
            @endif
        </div>
    @endif

    <div class="tiles" style="margin-top:1.25rem">
        <a class="tile" href="{{ route('articles.index') }}" style="color:inherit;text-decoration:none">
            <div class="value">{{ $articleCount }}</div>
            <div class="label">{{ __('messages.nav.articles') }}</div>
        </a>
    </div>
@endsection
