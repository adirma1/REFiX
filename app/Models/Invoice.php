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

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Invoice extends Model
{
    use Auditable, BelongsToTenant;

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_CREDIT_NOTE = 'credit_note';

    public const BILLING_NORMAL = 'normal';

    public const BILLING_PARTIAL = 'partial';

    public const BILLING_FINAL = 'final';

    public const BILLING_KINDS = [self::BILLING_NORMAL, self::BILLING_PARTIAL, self::BILLING_FINAL];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ISSUED = 'issued';

    /** Felder, die nach der Freigabe noch geändert werden dürfen. */
    private const MUTABLE_AFTER_ISSUE = ['internal_notes', 'updated_at'];

    protected $fillable = [
        'type', 'billing_kind', 'status', 'is_test', 'number', 'customer_id', 'customer_snapshot', 'original_invoice_id',
        'series_invoice_id', 'series_no', 'quote_id', 'recurring_invoice_id',
        'issue_date', 'service_date_from', 'service_date_to', 'due_date',
        'currency', 'tax_mode', 'payment_term_days',
        'skonto1_percent', 'skonto1_days', 'skonto2_percent', 'skonto2_days', 'discount_percent',
        'buyer_reference', 'customer_order_ref', 'intro_text', 'footer_text', 'internal_notes',
        'net_total', 'discount_total', 'tax_total', 'gross_total', 'tax_breakdown',
        'pdf_path', 'issued_at', 'issued_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'customer_snapshot' => 'array',
            'tax_breakdown' => 'array',
            'issue_date' => 'date',
            'service_date_from' => 'date',
            'service_date_to' => 'date',
            'due_date' => 'date',
            'issued_at' => 'datetime',
            'skonto1_percent' => 'decimal:2',
            'skonto2_percent' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'net_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'gross_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Freigegebene Belege sind unveränderlich (GoBD). Korrekturen laufen über eine Gutschrift.
        static::updating(function (Invoice $invoice) {
            if ($invoice->getOriginal('status') !== self::STATUS_DRAFT) {
                $illegal = array_diff(array_keys($invoice->getDirty()), self::MUTABLE_AFTER_ISSUE);

                if ($illegal !== []) {
                    throw new LogicException('Freigegebene Belege können nicht geändert werden.');
                }
            }
        });

        static::deleting(function (Invoice $invoice) {
            if ($invoice->status !== self::STATUS_DRAFT) {
                throw new LogicException('Freigegebene Belege können nicht gelöscht werden.');
            }
        });

        // Positionen eines gelöschten Entwurfs mitlöschen (unabhängig von Fremdschlüssel-Einstellungen).
        static::deleted(function (Invoice $invoice) {
            InvoiceItem::withoutGlobalScopes()->where('invoice_id', $invoice->id)->delete();

            // Positionen eines Angebots, die in diesen Entwurf übernommen waren, werden wieder frei.
            QuoteItem::withoutGlobalScopes()->where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_on')->orderBy('id');
    }

    public function dunnings(): HasMany
    {
        return $this->hasMany(Dunning::class)->orderBy('level')->orderBy('id');
    }

    /** Freigegebene Gutschriften zu dieser Rechnung (mindern den offenen Betrag). */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'original_invoice_id')
            ->where('type', self::TYPE_CREDIT_NOTE)->where('status', self::STATUS_ISSUED);
    }

    public function mailLogs(): HasMany
    {
        return $this->hasMany(MailLog::class)->latest('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function recurring(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class, 'recurring_invoice_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_invoice_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isCreditNote(): bool
    {
        return $this->type === self::TYPE_CREDIT_NOTE;
    }

    public function isPartial(): bool
    {
        return ! $this->isCreditNote() && $this->billing_kind === self::BILLING_PARTIAL;
    }

    public function isFinal(): bool
    {
        return ! $this->isCreditNote() && $this->billing_kind === self::BILLING_FINAL;
    }

    /** Teil- oder Schlussrechnung (gehört zu einer Rechnungsserie). */
    public function inSeries(): bool
    {
        return $this->isPartial() || $this->isFinal();
    }

    /** Die Wurzel der Serie ist die erste Teilrechnung; alle weiteren verweisen auf sie. */
    public function seriesRootId(): ?int
    {
        if (! $this->inSeries()) {
            return null;
        }

        return $this->series_invoice_id ?? ($this->exists ? $this->id : null);
    }

    /**
     * Freigegebene Rechnungen der Serie (Teil- und Schlussrechnungen) in Freigabe-Reihenfolge.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public function seriesMembers(): \Illuminate\Support\Collection
    {
        $root = $this->seriesRootId();

        if ($root === null) {
            return collect();
        }

        return static::query()
            ->where('type', self::TYPE_INVOICE)
            ->where('status', self::STATUS_ISSUED)
            ->where(fn ($q) => $q->where('id', $root)->orWhere('series_invoice_id', $root))
            ->orderBy('series_no')->orderBy('id')
            ->get();
    }

    /** Eine Serie ist abgeschlossen, sobald eine Schlussrechnung freigegeben ist. */
    public function seriesClosed(): bool
    {
        $root = $this->seriesRootId();

        return $root !== null && static::query()
            ->where('type', self::TYPE_INVOICE)->where('status', self::STATUS_ISSUED)
            ->where('billing_kind', self::BILLING_FINAL)->where('series_invoice_id', $root)
            ->exists();
    }

    /** Laufende Nummer in der Serie: bei freigegebenen Belegen die vergebene, bei Entwürfen die voraussichtliche. */
    public function seriesPosition(): int
    {
        if ($this->series_no !== null) {
            return (int) $this->series_no;
        }

        return $this->seriesMembers()->where('id', '!=', $this->id)->count() + 1;
    }

    public function documentTitle(): string
    {
        if ($this->isCreditNote()) {
            return __('messages.invoices.credit_note');
        }

        return match ($this->billing_kind) {
            self::BILLING_PARTIAL => __('messages.invoices.partial_title', ['n' => $this->seriesPosition()]),
            self::BILLING_FINAL => __('messages.invoices.final_title'),
            default => __('messages.invoices.invoice'),
        };
    }

    public function displayNumber(): string
    {
        return $this->number ?? __('messages.invoices.no_number');
    }
}
