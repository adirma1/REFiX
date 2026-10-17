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
@section('title', __('messages.dunning.title'))
@section('content')
    @php($money = fn ($v) => number_format((float) $v, 2, ',', '.'))
    <h1>{{ __('messages.dunning.title') }}</h1>
    @include('payments._tabs')
    @unless($enabled)
        <div class="alert warn">{{ __('messages.dunning.disabled_banner') }}</div>
    @endunless
    @if($enabled)
        <p class="muted">{{ __('messages.dunning.intro') }}</p>
    @endif

    <div class="toolbar">
        @foreach(['due' => 'tab_due', 'history' => 'tab_history', 'holds' => 'tab_holds'] as $name => $label)
            @continue(! $enabled && $name === 'due')
            <a @class(['btn', 'small', 'secondary' => $tab !== $name]) href="{{ route('dunning.index', ['tab' => $name]) }}">{{ __('messages.dunning.'.$label) }} ({{ $counts[$name] }})</a>
        @endforeach
        <span class="spacer"></span>
        @can('manage-users')
            <a class="btn small secondary" href="{{ route('dunning.settings') }}">{{ __('messages.nav.dunning_settings') }}</a>
        @endcan
    </div>


    @if($tab === 'due')
        <form method="POST" action="{{ route('dunning.store') }}" id="due-form">
            @csrf
            @if($items->count() > 0)
                <div class="toolbar" id="due-actions">
                    <button class="btn small" type="submit" formnovalidate data-needs disabled>{{ __('messages.dunning.create_selected') }} (<span data-count>0</span>)</button>
                    <button class="btn small secondary" type="button" id="hold-toggle" aria-expanded="false" data-needs disabled>{{ __('messages.dunning.hold_selected') }}</button>
                    <span class="muted">{{ __('messages.dunning.create_hint') }}</span>
                </div>
                <div class="card hold-panel" id="hold-panel" hidden>
                    <div class="grid">
                        <div class="field">
                            <label for="hold-reason">{{ __('messages.dunning.hold_reason') }}</label>
                            <input id="hold-reason" type="text" name="reason" maxlength="255" value="{{ old('reason') }}">
                        </div>
                        <div class="field">
                            <label for="hold-until">{{ __('messages.dunning.hold_until') }}</label>
                            <input id="hold-until" type="date" name="hold_until">
                        </div>
                    </div>
                    <button class="btn small" type="submit" id="hold-submit" formaction="{{ route('dunning.hold-many') }}">{{ __('messages.dunning.hold_set_button') }} (<span data-count>0</span>)</button>
                </div>
            @endif
            <table class="dunning-due">
                <thead>
                <tr>
                    <th class="pick-col"><input type="checkbox" class="pick-all" aria-label="{{ __('messages.dunning.select_all') }}"></th>
                    <th>{{ __('messages.invoices.number') }}</th>
                    <th>{{ __('messages.invoices.customer') }}</th>
                    <th>{{ __('messages.invoices.due') }}</th>
                    <th>{{ __('messages.dunning.next_step') }}</th>
                    <th class="num">{{ __('messages.payments.open') }}</th>
                    <th class="num">{{ __('messages.dunning.fee_interest') }}</th>
                    <th class="num">{{ __('messages.dunning.total') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($items as $row)
                    @php($invoice = $row['invoice'])
                    @php($calc = $row['calc'])
                    <tr data-pick>
                        <td class="pick-col"><input type="checkbox" class="pick" name="invoices[]" value="{{ $invoice->id }}" aria-label="{{ __('messages.bank.select') }}"></td>
                        <td><a href="{{ route('invoices.show', $invoice) }}">{{ $invoice->number }}</a></td>
                        <td>{{ $invoice->customer_snapshot['company'] ?? '' }}</td>
                        <td>{{ $invoice->due_date?->format('d.m.Y') }} <span class="badge warn">{{ __('messages.payments.days_overdue', ['n' => $row['summary']['days_overdue']]) }}</span></td>
                        <td>{{ __('messages.dunning.level_'.$row['level']) }}</td>
                        <td class="num">{{ $money($calc['open'] / 100) }}</td>
                        <td class="num">{{ $money(($calc['fee'] + $calc['interest']) / 100) }}</td>
                        <td class="num"><strong>{{ $money($calc['total'] / 100) }}</strong> {{ $invoice->currency }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">{{ __('messages.dunning.empty_due') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </form>
    @elseif($tab === 'history')
        <div class="toolbar" id="hist-actions">
            <button class="btn small secondary" type="button" data-act="pdf" disabled>PDF</button>
            @can('send-mail')
                <button class="btn small secondary" type="button" data-act="mail" disabled @unless($enabled) hidden @endunless>{{ __('messages.invoices.mail_button') }}</button>
            @endcan
            <button class="btn small secondary" type="button" data-act="sent" title="{{ __('messages.dunning.mark_sent_tip') }}" disabled @unless($enabled) hidden @endunless>{{ __('messages.dunning.mark_sent') }}</button>
            <button class="btn small danger" type="button" data-act="void" data-confirm="{{ __('messages.dunning.void_confirm') }}" disabled @unless($enabled) hidden @endunless>{{ __('messages.dunning.void') }}</button>
            <span class="muted" id="hist-hint">{{ __('messages.dunning.pick_first') }}</span>
        </div>
        <form id="hist-form" method="POST" action="{{ route('dunning.index') }}" hidden>@csrf</form>
        <table class="dunning-hist">
            <thead>
            <tr>
                <th class="pick-col"></th>
                <th>{{ __('messages.payments.date') }}</th>
                <th>{{ __('messages.invoices.number') }}</th>
                <th class="step-col">{{ __('messages.dunning.step') }}</th>
                <th class="num">{{ __('messages.dunning.total') }}</th>
                <th>{{ __('messages.dunning.pay_by') }}</th>
                <th>{{ __('messages.invoices.status') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse($items as $dunning)
                <tr data-pick @if($dunning->isVoided()) class="voided" @endif>
                    <td class="pick-col">
                        <input type="radio" class="pick-one" name="dunning" value="{{ $dunning->id }}" aria-label="{{ __('messages.dunning.select_letter') }}"
                            @if(! $dunning->isVoided()) data-pdf="{{ route('dunning.pdf', $dunning) }}" @if($enabled) data-mail="{{ route('dunning.mail', $dunning) }}" @endif @endif
                            @if($enabled && $dunning->isPending()) data-sent="{{ route('dunning.sent', $dunning) }}" data-void="{{ route('dunning.void', $dunning) }}" @endif>
                    </td>
                    <td>{{ $dunning->dunned_on->format('d.m.Y') }}</td>
                    <td><a href="{{ route('invoices.show', $dunning->invoice) }}">{{ $dunning->invoice->number }}</a></td>
                    <td class="step-col">{{ $dunning->label() }}</td>
                    <td class="num">{{ $money($dunning->total) }} {{ $dunning->currency }}</td>
                    <td>{{ $dunning->pay_by->format('d.m.Y') }}</td>
                    <td>
                        @if($dunning->isVoided())
                            <span class="badge">{{ __('messages.dunning.status_voided') }}</span>
                        @elseif($dunning->isSent())
                            <span class="badge ok">{{ __('messages.dunning.status_sent_'.$dunning->sent_via) }} {{ $dunning->sent_at->timezone(config('app.timezone'))->format('d.m.Y') }}</span>
                        @else
                            <span class="badge warn">{{ __('messages.dunning.status_pending') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">{{ __('messages.dunning.empty_history') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    @else
        <table>
            <thead>
            <tr>
                <th>{{ __('messages.invoices.number') }}</th>
                <th>{{ __('messages.dunning.hold_reason') }}</th>
                <th>{{ __('messages.dunning.hold_until') }}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse($items as $hold)
                <tr>
                    <td><a href="{{ route('invoices.show', $hold->invoice) }}">{{ $hold->invoice->number }}</a></td>
                    <td>{{ $hold->reason }}</td>
                    <td>{{ $hold->hold_until?->format('d.m.Y') ?? __('messages.dunning.until_release') }}</td>
                    <td class="actions">
                        @if($enabled)
                        <form method="POST" action="{{ route('dunning.release', $hold) }}">@csrf
                            <button class="btn small secondary" type="submit">{{ __('messages.dunning.release') }}</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">{{ __('messages.dunning.empty_holds') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    @endif

    @push('scripts')
    <script>
    (function () {
        var rowsOf = function (root) { return [].slice.call(root.querySelectorAll('tr[data-pick]')); };

        var due = document.getElementById('due-form');
        if (due) {
            var boxes = [].slice.call(due.querySelectorAll('input.pick'));
            var all = due.querySelector('input.pick-all');
            var needs = [].slice.call(due.querySelectorAll('[data-needs]'));
            var counts = [].slice.call(due.querySelectorAll('[data-count]'));
            var panel = document.getElementById('hold-panel');
            var toggle = document.getElementById('hold-toggle');
            var reason = document.getElementById('hold-reason');
            var holdBtn = document.getElementById('hold-submit');
            var updDue = function () {
                var n = boxes.filter(function (b) { return b.checked; }).length;
                counts.forEach(function (c) { c.textContent = n; });
                needs.forEach(function (b) { b.disabled = n === 0; });
                boxes.forEach(function (b) { b.closest('tr').classList.toggle('selected', b.checked); });
                if (all) { all.checked = n > 0 && n === boxes.length; }
                if (n === 0 && panel) { panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); }
            };
            boxes.forEach(function (b) { b.addEventListener('change', updDue); });
            if (all) { all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); updDue(); }); }
            rowsOf(due).forEach(function (tr) {
                tr.addEventListener('click', function (e) {
                    if (e.target.closest('a, input, button, label')) { return; }
                    var b = tr.querySelector('input.pick'); b.checked = !b.checked; updDue();
                });
            });
            if (toggle) {
                toggle.addEventListener('click', function () {
                    panel.hidden = !panel.hidden;
                    toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
                    if (!panel.hidden) { reason.focus(); }
                });
            }
            if (holdBtn) {
                // Enter in the hold fields must hold, not create letters
                panel.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); holdBtn.click(); }
                });
                holdBtn.addEventListener('click', function (e) {
                    if (reason.value.trim() === '') { e.preventDefault(); reason.required = true; reason.focus(); if (reason.reportValidity) { reason.reportValidity(); } }
                });
            }
            updDue();
        }

        var hist = document.getElementById('hist-actions');
        if (hist) {
            var radios = [].slice.call(document.querySelectorAll('input.pick-one'));
            var form = document.getElementById('hist-form');
            var hint = document.getElementById('hist-hint');
            var current = function () { return radios.filter(function (r) { return r.checked; })[0] || null; };
            var updHist = function () {
                var r = current();
                radios.forEach(function (x) { x.closest('tr').classList.toggle('selected', x === r); });
                [].slice.call(hist.querySelectorAll('button[data-act]')).forEach(function (b) { b.disabled = !(r && r.dataset[b.dataset.act]); });
                hint.hidden = !!r;
            };
            radios.forEach(function (r) { r.addEventListener('change', updHist); });
            rowsOf(document).forEach(function (tr) {
                tr.addEventListener('click', function (e) {
                    if (e.target.closest('a, input, button, label')) { return; }
                    var r = tr.querySelector('input.pick-one'); if (r) { r.checked = true; updHist(); }
                });
            });
            hist.addEventListener('click', function (e) {
                var b = e.target.closest('button[data-act]'); var r = current();
                if (!b || !r || b.disabled) { return; }
                var url = r.dataset[b.dataset.act];
                if (!url) { return; }
                if (b.dataset.act === 'pdf') { window.open(url, '_blank', 'noopener'); }
                else if (b.dataset.act === 'mail') { window.location.href = url; }
                else if (b.dataset.act !== 'void' || window.confirm(b.dataset.confirm)) { form.action = url; form.submit(); }
            });
            updHist();
        }
    })();
    </script>
    @endpush
    @include('pagination.footer', ['paginator' => $items, 'id' => 'dunning'])
@endsection
