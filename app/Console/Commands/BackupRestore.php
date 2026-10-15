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

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\BackupInvalid;
use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupRestore extends Command
{
    protected $signature = 'backup:restore
                            {file : Dateiname aus dem Backup-Ordner oder voller Pfad zur ZIP-Datei}
                            {--force : Ohne Rückfrage einspielen}
                            {--ignore-key : Auch einspielen, wenn der APP_KEY nicht zur Sicherung passt}';

    protected $description = 'Spielt ein Backup ein und ERSETZT dabei alle Daten (alle Mandanten) und Dateien';

    public function handle(BackupService $backups): int
    {
        $file = (string) $this->argument('file');
        $path = $backups->path($file) ?? (is_file($file) ? $file : null);

        if ($path === null) {
            $this->error('Datei nicht gefunden: '.$file);

            return self::FAILURE;
        }

        try {
            $manifest = $backups->inspect($path);
        } catch (BackupInvalid $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Sicherung vom '.($manifest['created_at'] ?? '?').':');
        foreach ($manifest['tables'] as $table => $info) {
            $this->line(sprintf('  %-24s %6d Zeilen', $table, $info['rows']));
        }
        $this->line('  Dateien: '.count($manifest['files']));
        $this->newLine();
        $this->warn('ACHTUNG: Alle aktuellen Daten (ALLE Mandanten) und Dateien werden durch die Sicherung ersetzt.');

        if (! $this->option('force') && ! $this->confirm('Wirklich zurückspielen?', false)) {
            $this->line('Abgebrochen. Es wurde nichts geändert.');

            return self::FAILURE;
        }

        try {
            $result = $backups->restore($path, (bool) $this->option('ignore-key'));
        } catch (BackupInvalid $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        AuditLog::record('backup.restored', null, ['name' => basename($path), 'rows' => array_sum($result['tables']), 'files' => $result['files']]);

        $this->info('Zurückgespielt: '.array_sum($result['tables']).' Zeilen in '.count($result['tables']).' Tabellen, '.$result['files'].' Dateien.');
        $this->line('Alle Benutzer müssen sich neu anmelden, wenn ihre Sitzung nicht mehr gültig ist.');

        return self::SUCCESS;
    }
}
