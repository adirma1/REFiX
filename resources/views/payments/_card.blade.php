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
--}}{{-- Zahlungskarte an der Rechnung. Erwartet: $invoice, $payments (Collection), $summary, $bookable --}}
@php($money = fn ($cents) => number_format($cents / 100, 2, ',', '.'))
<div class="card" style="margin-top:1.25rem" id="payments">
    <h2 style="margin-top:0">{{ __('messages.payments.title') }} @include('payments._badge', ['status' => $summary['status']])
        @if($summary['overdue'])<span class="badge warn">{{ __('messages.payments.days_overdue', ['n' => $summary['days_overdue']]) }}</span>@endif
    </h2>

    <div class="totals-box" style="margin-left:0">
        <div><span>{{ __('messages.invoices.gross') }}</span><span>{{ $money($summary['gross']) }} {{ $invoice->currency }}</span></div>
        @if($summary['credited'] > 0)<div><span>{{ __('messages.payments.credited') }}</span><span>– {{ $money($summary['credited']) }}</span></div>@endif
        @if($summary['paid'] > 0)<div><span>{{ __('messages.payments.paid') }}</span><span>– {{ $money($summary['paid']) }}</span></div>@endif
        @if($summary['discount'] > 0)<div><span>{{ __('messages.payments.discount') }}</span><span>– {{ $money($summary['discount']) }}</span></div>@endif
        <div class="grand"><span>{{ __('messages.payments.open') }}</span><span>{{ $money($summary['open']) }} {{ $invoice->currency }}</span></div>
    </div>

    @if($payments->isNotEmpty())
        <table class="pay-table" style="margin-top:.75rem">
            <thead>
            <tr>
                <th>{{ __('messages.payments.date') }}</th><th>{{ __('messages.payments.kind') }}</th>
                <th class="num">{{ __('messages.payments.amount') }}</th><th>{{ __('messages.payments.reference') }}</th><th></th>
            </tr>
            </thead>
            <tbody>
            @foreach($payments as $payment)
                <tr @if($payment->isReversed()) style="opacity:.55" @endif>
                    <td>{{ $payment->paid_on->format('d.m.Y') }}</td>
                    <td>{{ $payment->isDiscount() ? __('messages.payments.kind_discount') : __('messages.payments.kind_payment') }}</td>
                    <td class="num">@if($payment->isReversed())
                            <s>{{ $money(\App\Services\PaymentService::cents($payment->amount)) }}</s>
                        @else
                            {{ $money(\App\Services\PaymentService::cents($payment->amount)) }}
                        @endif</td>
                    <td>{{ $payment->reference ?? $payment->note }}
                        @if($payment->isReversed())
                            <span class="badge">{{ __('messages.payments.reversed_badge') }}</span>
                            <span class="muted">{{ $payment->reversed_at->timezone(config('app.timezone'))->format('d.m.Y') }}: {{ $payment->reverse_reason }}</span>
                        @endif
                    </td>
                    <td class="actions">
                        @can('manage-payments')
                            @if(! $payment->isReversed() && ! $payment->isDiscount())
                                <form method="POST" action="{{ route('payments.reverse', $payment) }}" class="reverse-form">
                                    @csrf
                                    <input type="text" name="reason" required maxlength="255" placeholder="{{ __('messages.payments.reverse_reason') }}" aria-label="{{ __('messages.payments.reverse_reason') }}">
                                    <button class="btn small danger" type="submit">{{ __('messages.payments.reverse') }}</button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @can('manage-payments')
        @if($bookable)
            @php($levels = app(\App\Services\PaymentService::class)->skontoLevels($invoice))
            <form method="POST" action="{{ route('payments.store', $invoice) }}" style="margin-top:1rem">
                @csrf
                <div class="grid">
                    <x-field name="amount" :label="__('messages.payments.amount')" :value="$summary['open'] > 0 ? number_format($summary['open'] / 100, 2, ',', '') : ''" required />
                    <x-field name="paid_on" type="date" :label="__('messages.payments.date')" :value="now()->toDateString()" required />
                    <x-field name="reference" :label="__('messages.payments.reference')" maxlength="255" />
                </div>
                @if($levels !== [] && $summary['open'] === $summary['gross'])
                    <div class="field check">
                        <label><input type="checkbox" name="skonto" value="1" @checked(old('skonto'))>
                            {{ __('messages.payments.skonto_check') }}
                            <span class="tip" data-tip="{{ __('messages.payments.skonto_tip') }}" tabindex="0" aria-label="{{ __('messages.payments.skonto_tip') }}">ⓘ</span>
                            <span class="muted">
                                @foreach($levels as $level)
                                    {{ $money($level['pay']) }} ({{ rtrim(rtrim(number_format($level['percent'], 2, ',', ''), '0'), ',') }} %)@if(! $loop->last) / @endif
                                @endforeach
                            </span>
                        </label>
                    </div>
                @endif
                <button class="btn" type="submit">{{ __('messages.payments.book') }}</button>
            </form>
        @elseif($invoice->is_test)
            <p class="muted">{{ __('messages.payments.test_note') }}</p>
        @endif
    @endcan
    @php($dunningList = $invoice->dunnings()->get())
    @if($dunningList->isNotEmpty())
        <h3>{{ __('messages.dunning.card_title') }}</h3>
        <table class="audit-table">
            <tbody>
            @foreach($dunningList as $dunning)
                <tr @if($dunning->isVoided()) style="opacity:.55" @endif>
                    <td>{{ $dunning->dunned_on->format('d.m.Y') }}</td>
                    <td>{{ $dunning->label() }}</td>
                    <td class="num">{{ $money(\App\Services\PaymentService::cents($dunning->total)) }}</td>
                    <td>
                        @if($dunning->isVoided())
                            {{ __('messages.dunning.status_voided') }}
                        @elseif($dunning->isSent())
                            {{ __('messages.dunning.status_sent_'.$dunning->sent_via) }}
                        @else
                            {{ __('messages.dunning.status_pending') }}
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
    @can('manage-payments')
        @if($bookable && $summary['open'] > 0 && ($currentTenant->dunning_enabled ?? true))
            <details style="margin-top:.75rem">
                <summary class="muted" style="cursor:pointer">{{ __('messages.dunning.card_hold') }}</summary>
                <form method="POST" action="{{ route('dunning.hold', $invoice) }}" style="display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.4rem">
                    @csrf
                    <input type="text" name="reason" required maxlength="255" placeholder="{{ __('messages.dunning.hold_reason') }}" aria-label="{{ __('messages.dunning.hold_reason') }}">
                    <input type="date" name="hold_until" aria-label="{{ __('messages.dunning.hold_until') }}" title="{{ __('messages.dunning.hold_until') }}">
                    <button class="btn small" type="submit">{{ __('messages.dunning.hold_set_button') }}</button>
                </form>
            </details>
        @endif
    @endcan
</div>
