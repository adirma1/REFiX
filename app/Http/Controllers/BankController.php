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

namespace App\Http\Controllers;

use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Services\Bank\BankCsvParser;
use App\Services\Bank\BankFileInvalid;
use App\Services\Bank\BankFileReader;
use App\Services\Bank\BankImporter;
use App\Services\Bank\BankMatcher;
use App\Services\PaymentInvalid;
use App\Services\PaymentService;
use App\Support\PerPage;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/** Bankdateien einlesen (CAMT, CSV) und Eingänge Rechnungen zuordnen. */
class BankController extends Controller
{
    private const TOKEN = '/^[a-f0-9]{32}$/';

    private const TABS = [BankTransaction::NEW, BankTransaction::BOOKED, BankTransaction::IGNORED];

    public function __construct(
        private BankImporter $importer,
        private BankMatcher $matcher,
        private PaymentService $payments,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('manage-payments');

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : BankTransaction::NEW;

        $transactions = BankTransaction::where('status', $tab)
            ->with('payment.invoice')
            ->orderByDesc('booking_date')->orderByDesc('id')
            ->paginate(PerPage::for($request->user()))->withQueryString();

        $suggestions = $tab === BankTransaction::NEW ? $this->matcher->suggest($transactions->items()) : [];

        $options = $tab === BankTransaction::NEW
            ? $this->payments->openItems()->map(fn ($row) => [
                'id' => $row['invoice']->id,
                'label' => $row['invoice']->number.' – '.($row['invoice']->customer_snapshot['company'] ?? '').' – '
                    .number_format($row['summary']['open'] / 100, 2, ',', '.').' '.$row['invoice']->currency,
            ])->all()
            : [];

        return view('bank.index', [
            'tab' => $tab,
            'transactions' => $transactions,
            'suggestions' => $suggestions,
            'options' => $options,
            'counts' => BankTransaction::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->all(),
            'imports' => BankImport::latest('id')->limit(5)->get(),
            'matcher' => BankMatcher::class,
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $this->authorize('manage-payments');

        $request->validate(['file' => ['required', 'file', 'max:'.(BankFileReader::MAX_BYTES / 1024)]]);

        $raw = (string) file_get_contents($request->file('file')->getRealPath());
        $name = $request->file('file')->getClientOriginalName();
        $format = BankFileReader::detect($raw);

        if ($format === 'csv') {
            try {
                BankCsvParser::parse($raw); // früh prüfen: lesbar, Spalten vorhanden
            } catch (BankFileInvalid $e) {
                return redirect()->route('bank.index')->with('warning', $e->getMessage());
            }

            $this->prune();
            $token = bin2hex(random_bytes(16));
            Storage::disk('local')->put($this->path($token), $raw);
            Storage::disk('local')->put($this->path($token, 'name'), $name);

            return redirect()->route('bank.csv', $token);
        }

        try {
            $statement = BankFileReader::read($raw, $format);
        } catch (BankFileInvalid $e) {
            return redirect()->route('bank.index')->with('warning', $e->getMessage());
        }

        return $this->imported($this->importer->import($statement, $name, $request->user()));
    }

    public function csv(string $token): View|RedirectResponse
    {
        $this->authorize('manage-payments');

        if (($parsed = $this->load($token)) === null) {
            return $this->expired();
        }

        return view('bank.csv', [
            'token' => $token,
            'parsed' => $parsed,
            'mapping' => old('map', BankCsvParser::guess($parsed['headers'])),
            'fields' => BankCsvParser::FIELDS,
            'required' => ['date'],
        ]);
    }

    public function csvImport(Request $request, string $token): RedirectResponse
    {
        $this->authorize('manage-payments');

        if (($parsed = $this->load($token)) === null) {
            return $this->expired();
        }

        $mapping = [];
        foreach (BankCsvParser::FIELDS as $field) {
            $value = $request->input('map.'.$field, '');
            $mapping[$field] = ($value !== '' && ctype_digit((string) $value) && (int) $value < count($parsed['headers'])) ? (int) $value : null;
        }

        try {
            $converted = BankCsvParser::convert($parsed['rows'], $mapping);
        } catch (BankFileInvalid $e) {
            return redirect()->route('bank.csv', $token)->withInput()->with('warning', $e->getMessage());
        }

        $name = Storage::disk('local')->exists($this->path($token, 'name')) ? Storage::disk('local')->get($this->path($token, 'name')) : 'bank.csv';
        $statement = new \App\Services\Bank\ParsedStatement('csv', null, $converted['transactions']);
        $import = $this->importer->import($statement, $name, $request->user());

        Storage::disk('local')->delete([$this->path($token), $this->path($token, 'name')]);

        return $this->imported($import, $converted['skipped']);
    }

    public function book(Request $request): RedirectResponse
    {
        $this->authorize('manage-payments');

        $selected = array_keys(array_filter((array) $request->input('book', [])));
        $invoiceIds = (array) $request->input('invoice', []);
        $skonto = (array) $request->input('skonto', []);

        $booked = 0;
        $errors = [];

        foreach ($selected as $txId) {
            $tx = BankTransaction::where('status', BankTransaction::NEW)->find((int) $txId);
            $invoice = isset($invoiceIds[$txId]) && ctype_digit((string) $invoiceIds[$txId])
                ? Invoice::find((int) $invoiceIds[$txId]) : null;

            if ($tx === null) {
                continue;
            }

            if ($invoice === null) {
                $errors[] = __('messages.bank.err_no_invoice', ['purpose' => $this->short($tx)]);

                continue;
            }

            try {
                $this->payments->book($invoice, [
                    'amount' => $tx->cents() / 100,
                    'paid_on' => $tx->booking_date->toDateString(),
                    'reference' => $tx->reference ?? $tx->purpose,
                    'note' => $tx->counterparty_name,
                ], $request->user(), $tx, ! empty($skonto[$txId]));
                $booked++;
            } catch (PaymentInvalid $e) {
                $errors[] = $this->short($tx).': '.$e->getMessage();
            }
        }

        $redirect = redirect()->route('bank.index', ['tab' => $request->input('tab') === 'new' ? null : $request->input('tab')]);

        if ($booked > 0) {
            $redirect->with('status', __('messages.bank.booked_n', ['n' => $booked]));
        }
        if ($errors !== []) {
            $redirect->with('warning', implode(' · ', array_slice($errors, 0, 5)));
        } elseif ($booked === 0) {
            $redirect->with('warning', __('messages.bank.nothing_selected'));
        }

        return $redirect;
    }

    public function ignore(BankTransaction $transaction): RedirectResponse
    {
        $this->authorize('manage-payments');

        if ($transaction->status === BankTransaction::NEW) {
            $transaction->forceFill(['status' => BankTransaction::IGNORED])->save();
        }

        return redirect()->route('bank.index');
    }

    /** Mehrere markierte neue Buchungen auf einmal ignorieren (Kontrollkästchen book[id]). */
    public function ignoreMany(Request $request): RedirectResponse
    {
        $this->authorize('manage-payments');

        $ids = array_map('intval', array_keys(array_filter((array) $request->input('book', []))));
        $n = BankTransaction::where('status', BankTransaction::NEW)->whereIn('id', $ids)->update(['status' => BankTransaction::IGNORED]);

        return $n > 0
            ? redirect()->route('bank.index')->with('status', __('messages.bank.ignored_n', ['n' => $n]))
            : redirect()->route('bank.index')->with('warning', __('messages.bank.nothing_selected'));
    }

    public function restore(BankTransaction $transaction): RedirectResponse
    {
        $this->authorize('manage-payments');

        if ($transaction->status === BankTransaction::IGNORED) {
            $transaction->forceFill(['status' => BankTransaction::NEW])->save();
        }

        return redirect()->route('bank.index', ['tab' => BankTransaction::IGNORED]);
    }

    private function imported(BankImport $import, int $skipped = 0): RedirectResponse
    {
        $message = __('messages.bank.imported', ['new' => $import->new_count, 'dup' => $import->duplicate_count]);
        if ($skipped > 0) {
            $message .= ' '.__('messages.bank.skipped_rows', ['n' => $skipped]);
        }

        return redirect()->route('bank.index')->with('status', $message);
    }

    private function short(BankTransaction $tx): string
    {
        return $tx->booking_date->format('d.m.Y').' '.number_format($tx->cents() / 100, 2, ',', '.');
    }

    /** @return array{headers: array<int, string>, rows: array<int, array<int, string>>, delimiter: string}|null */
    private function load(string $token): ?array
    {
        if (! preg_match(self::TOKEN, $token) || ! Storage::disk('local')->exists($this->path($token))) {
            return null;
        }

        try {
            return BankCsvParser::parse(Storage::disk('local')->get($this->path($token)));
        } catch (BankFileInvalid) {
            return null;
        }
    }

    private function path(string $token, string $ext = 'csv'): string
    {
        return 'bank-imports/'.Tenancy::id().'/'.$token.'.'.$ext;
    }

    private function expired(): RedirectResponse
    {
        return redirect()->route('bank.index')->with('warning', __('messages.bank.expired'));
    }

    /** Hochgeladene Dateien, die nie importiert wurden, nach einem Tag wieder entfernen. */
    private function prune(): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files('bank-imports/'.Tenancy::id()) as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }
}
