<?php

/*
 * RE-FiX – Rechnungsprogramm
 * Copyright (C) 2026 Martin Adirsch
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of
 * version 2 of the GNU General Public License as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program
 * (file LICENSE). If not, see <https://www.gnu.org/licenses/old-licenses/gpl-2.0.html>.
 *
 * SPDX-License-Identifier: GPL-2.0-only
 */

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Zahlungen zu Rechnungen. Alle Beträge werden intern in ganzen Cent gerechnet.
 * Gebuchte Zahlungen werden nie gelöscht, sondern mit Begründung storniert.
 */
class PaymentService
{
    public const STATUS_OPEN = 'open';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERPAID = 'overpaid';

    public const STATUS_CREDITED = 'credited';

    public static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** Zahlungsstand einer Rechnung. Alle Werte in Cent. */
    public function summary(Invoice $invoice): array
    {
        return $this->summaries([$invoice])[$invoice->id];
    }

    /**
     * Zahlungsstand mehrerer Rechnungen mit nur drei Abfragen.
     *
     * @param  iterable<int, Invoice>  $invoices
     * @return array<int, array<string, mixed>> Rechnungs-ID → Stand (Cent)
     */
    public function summaries(iterable $invoices): array
    {
        $invoices = collect($invoices)->values();
        $ids = $invoices->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $credits = Invoice::where('type', Invoice::TYPE_CREDIT_NOTE)->where('status', Invoice::STATUS_ISSUED)
            ->whereIn('original_invoice_id', $ids)
            ->selectRaw('original_invoice_id as invoice_id, SUM(gross_total) as total')
            ->groupBy('original_invoice_id')->pluck('total', 'invoice_id');

        $payments = Payment::active()->whereIn('invoice_id', $ids)
            ->selectRaw('invoice_id, kind, SUM(amount) as total')
            ->groupBy('invoice_id', 'kind')->get();

        $paidBy = [];
        foreach ($payments as $row) {
            $paidBy[$row->invoice_id][$row->kind] = self::cents($row->total);
        }

        $result = [];
        foreach ($invoices as $invoice) {
            $gross = self::cents($invoice->gross_total);
            $credited = $invoice->isCreditNote() ? 0 : self::cents($credits[$invoice->id] ?? 0);
            $paid = $paidBy[$invoice->id][Payment::KIND_PAYMENT] ?? 0;
            $discount = $paidBy[$invoice->id][Payment::KIND_DISCOUNT] ?? 0;
            $open = $gross - $credited - $paid - $discount;

            if ($open < 0) {
                $status = self::STATUS_OVERPAID;
            } elseif ($open === 0) {
                $status = ($paid + $discount === 0 && $credited > 0) ? self::STATUS_CREDITED : self::STATUS_PAID;
            } elseif ($paid + $discount + $credited > 0) {
                $status = self::STATUS_PARTIAL;
            } else {
                $status = self::STATUS_OPEN;
            }

            $overdue = $open > 0 && $invoice->due_date !== null && $invoice->due_date->lt(today());

            $result[$invoice->id] = [
                'gross' => $gross, 'credited' => $credited, 'paid' => $paid, 'discount' => $discount,
                'open' => $open, 'status' => $status, 'overdue' => $overdue,
                'days_overdue' => $overdue ? (int) $invoice->due_date->diffInDays(today()) : 0,
            ];
        }

        return $result;
    }

    /**
     * Alle Rechnungen mit offenem Betrag (freigegeben, kein Testbeleg), älteste Fälligkeit zuerst.
     *
     * @return \Illuminate\Support\Collection<int, array{invoice: Invoice, summary: array<string, mixed>}>
     */
    public function openItems(): \Illuminate\Support\Collection
    {
        $invoices = Invoice::where('type', Invoice::TYPE_INVOICE)->where('status', Invoice::STATUS_ISSUED)
            ->where('is_test', false)->get();

        $summaries = $this->summaries($invoices);

        return $invoices
            ->map(fn (Invoice $i) => ['invoice' => $i, 'summary' => $summaries[$i->id]])
            ->filter(fn ($row) => $row['summary']['open'] > 0)
            ->sortBy(fn ($row) => [$row['invoice']->due_date?->toDateString() ?? '9999-12-31', $row['invoice']->number])
            ->values();
    }

    /** Kann auf diese Rechnung gebucht werden? */
    public function bookable(Invoice $invoice): bool
    {
        return $invoice->type === Invoice::TYPE_INVOICE
            && $invoice->status === Invoice::STATUS_ISSUED
            && ! $invoice->is_test;
    }

    /**
     * Skonto-Stufen der Rechnung: Zahlbetrag und Abzug in Cent.
     *
     * @return array<int, array{level: int, percent: float, days: int, pay: int, discount: int}>
     */
    public function skontoLevels(Invoice $invoice): array
    {
        $levels = [];
        $gross = self::cents($invoice->gross_total);

        foreach ([1, 2] as $level) {
            $percent = $invoice->{"skonto{$level}_percent"};
            $days = $invoice->{"skonto{$level}_days"};

            if ($percent === null || (float) $percent <= 0 || $days === null) {
                continue;
            }

            // Gleiche Rundung wie auf dem Beleg: Brutto × (1 − Prozent/100), auf Cent gerundet.
            $pay = self::cents(round((float) $invoice->gross_total * (1 - (float) $percent / 100), 2));
            $levels[] = ['level' => $level, 'percent' => (float) $percent, 'days' => (int) $days, 'pay' => $pay, 'discount' => $gross - $pay];
        }

        return $levels;
    }

    /**
     * Zahlung buchen.
     *
     * @param  array{amount: float|int|string, paid_on: mixed, reference?: ?string, note?: ?string}  $data  Betrag in Euro
     * @param  bool  $skonto  Betrag entspricht einer Skonto-Stufe: Differenz wird als Skontoabzug mitgebucht
     */
    public function book(Invoice $invoice, array $data, User $user, ?BankTransaction $transaction = null, bool $skonto = false): Payment
    {
        return DB::transaction(function () use ($invoice, $data, $user, $transaction, $skonto) {
            if (! $this->bookable($invoice)) {
                throw new PaymentInvalid(__('messages.payments.err_not_bookable'));
            }

            $amount = self::cents($data['amount']);

            if ($amount <= 0) {
                throw new PaymentInvalid(__('messages.payments.err_amount'));
            }

            if ($transaction !== null && $transaction->status !== BankTransaction::NEW) {
                throw new PaymentInvalid(__('messages.bank.err_already_handled'));
            }

            $before = $this->summary($invoice);
            $discountCents = 0;

            if ($skonto) {
                $match = collect($this->skontoLevels($invoice))->first(fn ($l) => $l['pay'] === $amount);

                if ($match === null || $before['open'] !== $before['gross'] || $match['discount'] <= 0) {
                    throw new PaymentInvalid(__('messages.payments.err_skonto'));
                }

                $discountCents = $match['discount'];
            }

            $paidOn = \Illuminate\Support\Carbon::parse($data['paid_on'])->toDateString();

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'kind' => Payment::KIND_PAYMENT,
                'amount' => number_format($amount / 100, 2, '.', ''),
                'paid_on' => $paidOn,
                'reference' => $this->clip($data['reference'] ?? null),
                'note' => $this->clip($data['note'] ?? null),
                'bank_transaction_id' => $transaction?->id,
                'created_by' => $user->id,
            ]);

            if ($discountCents > 0) {
                // Gleicher Zeitstempel wie die Zahlung: so gehört der Abzug beim Stornieren eindeutig dazu.
                $discount = new Payment([
                    'invoice_id' => $invoice->id,
                    'kind' => Payment::KIND_DISCOUNT,
                    'amount' => number_format($discountCents / 100, 2, '.', ''),
                    'paid_on' => $paidOn,
                    'note' => __('messages.payments.skonto_note'),
                    'bank_transaction_id' => $transaction?->id,
                    'created_by' => $user->id,
                ]);
                $discount->created_at = $payment->created_at;
                $discount->save();
            }

            if ($transaction !== null) {
                $transaction->forceFill(['status' => BankTransaction::BOOKED, 'payment_id' => $payment->id])->save();
            }

            return $payment;
        });
    }

    /** Zahlung (und ggf. zugehörigen Skontoabzug) stornieren; eine verbuchte Bankbewegung wird wieder offen. */
    public function reverse(Payment $payment, string $reason, User $user): void
    {
        DB::transaction(function () use ($payment, $reason, $user) {
            if ($payment->isReversed()) {
                throw new PaymentInvalid(__('messages.payments.err_already_reversed'));
            }

            $reason = trim($reason);
            if ($reason === '') {
                throw new PaymentInvalid(__('messages.payments.err_reason'));
            }

            $stamp = ['reversed_at' => now(), 'reversed_by' => $user->id, 'reverse_reason' => $this->clip($reason)];

            $group = Payment::where('invoice_id', $payment->invoice_id)->active()
                ->where(function ($q) use ($payment) {
                    $q->where('id', $payment->id);
                    if ($payment->kind === Payment::KIND_PAYMENT) {
                        $q->orWhere(fn ($d) => $d->where('kind', Payment::KIND_DISCOUNT)->where('created_at', $payment->created_at));
                    }
                    if ($payment->bank_transaction_id !== null) {
                        $q->orWhere('bank_transaction_id', $payment->bank_transaction_id);
                    }
                })->get();

            foreach ($group as $row) {
                $row->forceFill($stamp)->save();
            }

            if ($payment->bank_transaction_id !== null) {
                BankTransaction::where('id', $payment->bank_transaction_id)
                    ->update(['status' => BankTransaction::NEW, 'payment_id' => null]);
            }

            AuditLog::record('payment.reversed', $payment, ['reason' => $reason, 'rows' => $group->count()]);
        });
    }

    private function clip(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : mb_substr($value, 0, 255);
    }
}
