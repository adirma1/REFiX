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
use App\Models\Dunning;
use App\Models\DunningHold;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Locale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Mahnwesen: Stufe 1 Zahlungserinnerung, Stufe 2 und 3 Mahnungen.
 * Die App schlägt nur vor; erstellt wird erst nach Bestätigung durch einen Benutzer.
 */
class DunningService
{
    public const PLACEHOLDERS = ['anrede', 'belegnummer', 'datum', 'faelligkeit', 'betrag', 'gebuehr', 'zinsen', 'gesamt', 'frist', 'kunde', 'firma'];

    public function __construct(private PaymentService $payments, private DunningPdf $pdf) {}

    /**
     * Fällige Vorschläge: Rechnungen, bei denen jetzt die nächste Stufe ansteht.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function suggestions(?Carbon $on = null): Collection
    {
        $on ??= today();
        $tenant = Tenant::findOrFail(\App\Support\Tenancy::id());

        return $this->payments->openItems()
            ->map(function (array $row) use ($tenant, $on) {
                $check = $this->nextStep($row['invoice'], $row['summary'], $tenant, $on);

                return $check === null ? null : $row + ['level' => $check] + ['calc' => $this->calculate($row['invoice'], $row['summary'], $check, $tenant, $on)];
            })
            ->filter()
            ->values();
    }

    /**
     * Nächste Stufe (1–3) für diese Rechnung oder null, wenn heute nichts ansteht.
     *
     * @param  array<string, mixed>  $summary
     */
    public function nextStep(Invoice $invoice, array $summary, Tenant $tenant, Carbon $on): ?int
    {
        return $this->evaluate($invoice, $summary, $tenant, $on)['level'];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{level: ?int, reason: ?string}
     */
    public function evaluate(Invoice $invoice, array $summary, Tenant $tenant, Carbon $on): array
    {
        if (! $this->payments->bookable($invoice) || $summary['open'] <= 0) {
            return ['level' => null, 'reason' => 'not_open'];
        }

        if ($invoice->due_date === null) {
            return ['level' => null, 'reason' => 'no_due_date'];
        }

        if (DunningHold::where('invoice_id', $invoice->id)->active()->exists()) {
            return ['level' => null, 'reason' => 'hold'];
        }

        $dunnings = Dunning::where('invoice_id', $invoice->id)->active()->get();

        if ($dunnings->contains(fn (Dunning $d) => $d->isPending())) {
            return ['level' => null, 'reason' => 'pending'];
        }

        $last = $dunnings->sortByDesc('level')->first();

        if ($last === null) {
            $due = $invoice->due_date->copy()->addDays((int) $tenant->dunning_days_after_due);

            return $on->gte($due) ? ['level' => 1, 'reason' => null] : ['level' => null, 'reason' => 'not_yet'];
        }

        if ($last->level >= Dunning::MAX_LEVEL) {
            return ['level' => null, 'reason' => 'max_level'];
        }

        // Nächste Stufe erst, wenn die Frist des letzten Schreibens abgelaufen ist.
        return $on->gt($last->pay_by) ? ['level' => $last->level + 1, 'reason' => null] : ['level' => null, 'reason' => 'waiting'];
    }

    /**
     * Beträge einer Stufe. Zinsen gibt es erst ab Stufe 2 und nur, wenn aktiviert und ein Basiszinssatz hinterlegt ist.
     *
     * @param  array<string, mixed>  $summary
     * @return array{open: int, fee: int, interest: int, rate: ?float, days: int, total: int, pay_by: Carbon}
     */
    public function calculate(Invoice $invoice, array $summary, int $level, Tenant $tenant, Carbon $on): array
    {
        $open = $summary['open'];
        $fee = PaymentService::cents($tenant->{'dunning_fee_'.$level});

        $rate = null;
        $days = 0;
        $interest = 0;

        if ($level >= 2 && $tenant->dunning_interest_enabled && $tenant->dunning_base_rate !== null && $invoice->due_date !== null) {
            $rate = max(0.0, (float) $tenant->dunning_base_rate + (float) $tenant->dunning_interest_margin);
            $days = max(0, (int) $invoice->due_date->diffInDays($on));
            $interest = (int) round($open * $rate / 100 * $days / 365);
        }

        return [
            'open' => $open, 'fee' => $fee, 'interest' => $interest, 'rate' => $rate, 'days' => $days,
            'total' => $open + $fee + $interest,
            'pay_by' => $on->copy()->addDays((int) $tenant->dunning_grace_days),
        ];
    }

    /** Erstellt Mahnung samt archiviertem PDF. */
    public function create(Invoice $invoice, User $user, ?Carbon $on = null): Dunning
    {
        $on ??= today();

        return DB::transaction(function () use ($invoice, $user, $on) {
            $tenant = Tenant::findOrFail($invoice->tenant_id);
            $summary = $this->payments->summary($invoice);
            $step = $this->evaluate($invoice, $summary, $tenant, $on);

            if ($step['level'] === null) {
                throw new DunningInvalid(__('messages.dunning.err_'.$step['reason']));
            }

            $calc = $this->calculate($invoice, $summary, $step['level'], $tenant, $on);

            $dunning = Dunning::create([
                'invoice_id' => $invoice->id,
                'level' => $step['level'],
                'dunned_on' => $on->toDateString(),
                'pay_by' => $calc['pay_by']->toDateString(),
                'open_amount' => $this->money($calc['open']),
                'fee' => $this->money($calc['fee']),
                'interest' => $this->money($calc['interest']),
                'interest_rate' => $calc['rate'],
                'interest_days' => $calc['days'],
                'total' => $this->money($calc['total']),
                'currency' => $invoice->currency,
                'created_by' => $user->id,
            ]);

            $path = 'dunnings/'.$tenant->id.'/'.preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice->number).'-M'.$dunning->level.'-'.$dunning->id.'.pdf';
            Storage::disk('local')->put($path, $this->pdf->render($dunning->setRelation('invoice', $invoice), $tenant));
            $dunning->forceFill(['pdf_path' => $path])->save();

            return $dunning;
        });
    }

    public function void(Dunning $dunning, User $user): void
    {
        if (! $dunning->isPending()) {
            throw new DunningInvalid(__('messages.dunning.err_not_pending'));
        }

        $dunning->forceFill(['voided_at' => now(), 'voided_by' => $user->id])->save();
        AuditLog::record('dunning.voided', $dunning, ['level' => $dunning->level]);
    }

    public function markSent(Dunning $dunning, User $user, string $via): void
    {
        if ($dunning->isVoided()) {
            throw new DunningInvalid(__('messages.dunning.err_voided'));
        }

        if ($dunning->isSent()) {
            return;
        }

        $dunning->forceFill(['sent_at' => now(), 'sent_via' => $via, 'sent_by' => $user->id])->save();
        AuditLog::record('dunning.sent', $dunning, ['level' => $dunning->level, 'via' => $via]);
    }

    public function hold(Invoice $invoice, string $reason, ?string $until, User $user): DunningHold
    {
        return DunningHold::create([
            'invoice_id' => $invoice->id,
            'reason' => mb_substr(trim($reason), 0, 255),
            'hold_until' => $until,
            'created_by' => $user->id,
        ]);
    }

    public function release(DunningHold $hold): void
    {
        $hold->forceFill(['released_at' => now()])->save();
    }

    /** Text des Schreibens: Mandanten-Text der Stufe oder eingebauter Standard, Platzhalter ersetzt. */
    public function letterText(Dunning $dunning, Tenant $tenant): string
    {
        $template = $tenant->{'dunning_text_'.$dunning->level};
        $template = filled($template) ? (string) $template : self::defaultText($dunning->level);

        return Mail\MailTemplates::render($template, $this->values($dunning, $tenant));
    }

    public static function defaultText(int $level): string
    {
        return Locale::with('de', fn () => __('messages.dunning.default_text_'.$level));
    }

    /** @return array<string, string> */
    public function values(Dunning $dunning, Tenant $tenant): array
    {
        $invoice = $dunning->invoice;
        $money = fn ($v) => number_format((float) $v, 2, ',', '.').' '.$dunning->currency;
        $values = Mail\MailTemplates::values($invoice, $tenant);

        return array_merge($values, [
            'betrag' => $money($dunning->open_amount),
            'gebuehr' => $money($dunning->fee),
            'zinsen' => $money($dunning->interest),
            'gesamt' => $money($dunning->total),
            'frist' => $dunning->pay_by->format('d.m.Y'),
            'faelligkeit' => $invoice->due_date?->format('d.m.Y') ?? '',
        ]);
    }

    public function mailSubject(Dunning $dunning, Tenant $tenant): string
    {
        $subject = Locale::with('de', fn () => __('messages.dunning.mail_subject_'.$dunning->level));

        return trim(preg_replace('/\s+/', ' ', Mail\MailTemplates::render($subject, $this->values($dunning, $tenant))));
    }

    public function mailBody(Dunning $dunning, Tenant $tenant): string
    {
        $body = Locale::with('de', fn () => __('messages.dunning.mail_body_'.$dunning->level));

        return Mail\MailTemplates::render($body, $this->values($dunning, $tenant));
    }

    /** @return array<int, string> unbekannte Platzhalter in einem Text */
    public static function unknownPlaceholders(string $text): array
    {
        preg_match_all('/\{([a-zA-Z_]+)\}/', $text, $m);

        return array_values(array_unique(array_filter($m[1], fn ($name) => ! in_array($name, self::PLACEHOLDERS, true))));
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
