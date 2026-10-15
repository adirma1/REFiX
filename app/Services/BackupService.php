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

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Sicherung als ein ZIP: alle Datenbanktabellen (je Tabelle eine JSON-Zeilen-Datei) plus alle Dateien des
 * privaten Speichers (archivierte PDFs, Logos). Prüfsummen im Manifest erlauben, die Datei vor dem Zurückspielen
 * zu prüfen. Der APP_KEY ist bewusst NICHT enthalten; das Manifest merkt sich nur seinen Fingerabdruck.
 */
class BackupService
{
    public const DIR = 'backups';

    public const FORMAT = 1;

    /** Reine Laufzeit-Tabellen (Sitzungen, Zwischenspeicher, Warteschlange) und die Migrationsliste werden nicht gesichert. */
    public const EXCLUDED_TABLES = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    private const NAME_PATTERN = '/^backup-\d{8}-\d{6}\.zip$/';

    /** Legt ein Backup an und liefert den Dateinamen. */
    public function create(): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);

        // Zwei Backups in derselben Sekunde überschreiben sich nie.
        $stamp = now();
        while ($disk->exists(self::DIR.'/backup-'.$stamp->format('Ymd-His').'.zip')) {
            $stamp = $stamp->addSecond();
        }
        $name = 'backup-'.$stamp->format('Ymd-His').'.zip';
        $final = $disk->path(self::DIR.'/'.$name);
        $tmpZip = $final.'.part';
        $tmpDir = sys_get_temp_dir().'/backup-'.bin2hex(random_bytes(6));
        mkdir($tmpDir, 0700, true);

        try {
            $zip = new ZipArchive;
            if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new BackupInvalid('Die Backup-Datei konnte nicht angelegt werden.');
            }

            $manifest = [
                'format' => self::FORMAT,
                'created_at' => now()->toIso8601String(),
                'key_fingerprint' => self::keyFingerprint(),
                'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
                'tables' => [],
                'files' => [],
            ];

            foreach ($this->tables() as $table) {
                $file = $tmpDir.'/'.$table.'.jsonl';
                $rows = $this->dumpTable($table, $file);
                $manifest['tables'][$table] = ['rows' => $rows, 'sha256' => hash_file('sha256', $file)];
                $zip->addFile($file, 'db/'.$table.'.jsonl');
            }

            foreach ($disk->allFiles() as $path) {
                if (str_starts_with($path, self::DIR.'/')) {
                    continue; // Backups sichern sich nicht selbst
                }
                $full = $disk->path($path);
                $manifest['files'][$path] = ['sha256' => hash_file('sha256', $full), 'size' => filesize($full)];
                $zip->addFile($full, 'files/'.$path);
            }

            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            if (! $zip->close()) {
                throw new BackupInvalid('Die Backup-Datei konnte nicht geschrieben werden (Speicherplatz?).');
            }

            rename($tmpZip, $final);
            @chmod($final, 0600);
        } catch (\Throwable $e) {
            @unlink($tmpZip);
            throw $e;
        } finally {
            foreach (glob($tmpDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }

        return $name;
    }

    /** @return array<int, array{name: string, size: int, created: int}> neueste zuerst */
    public function list(): array
    {
        $disk = Storage::disk('local');
        $items = [];

        foreach ($disk->files(self::DIR) as $path) {
            $name = basename($path);
            if (preg_match(self::NAME_PATTERN, $name)) {
                $items[] = ['name' => $name, 'size' => $disk->size($path), 'created' => $disk->lastModified($path)];
            }
        }

        usort($items, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $items;
    }

    /** Absoluter Pfad einer vorhandenen Sicherung; null bei ungültigem Namen oder fehlender Datei. */
    public function path(string $name): ?string
    {
        if (! preg_match(self::NAME_PATTERN, $name) || ! Storage::disk('local')->exists(self::DIR.'/'.$name)) {
            return null;
        }

        return Storage::disk('local')->path(self::DIR.'/'.$name);
    }

    public function delete(string $name): bool
    {
        return $this->path($name) !== null && Storage::disk('local')->delete(self::DIR.'/'.$name);
    }

    public static function keyFingerprint(): string
    {
        return substr(hash('sha256', (string) config('app.key')), 0, 12);
    }

    /**
     * Prüft Aufbau und Prüfsummen einer Sicherung, ohne etwas zu ändern.
     *
     * @return array<string, mixed> das Manifest
     *
     * @throws BackupInvalid
     */
    public function inspect(string $zipPath): array
    {
        $zip = new ZipArchive;
        if (! is_file($zipPath) || $zip->open($zipPath) !== true) {
            throw new BackupInvalid('Die Datei ist keine gültige Backup-Datei.');
        }

        try {
            $json = $zip->getFromName('manifest.json');
            $manifest = $json === false ? null : json_decode($json, true);

            if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT || ! isset($manifest['tables'], $manifest['files'], $manifest['migrations'])) {
                throw new BackupInvalid('Das Manifest fehlt oder hat ein unbekanntes Format.');
            }

            foreach ($manifest['tables'] as $table => $info) {
                [$hash, $lines] = $this->hashEntry($zip, 'db/'.$table.'.jsonl');
                if ($hash !== ($info['sha256'] ?? null) || $lines !== (int) ($info['rows'] ?? -1)) {
                    throw new BackupInvalid('Prüfsumme der Tabelle „'.$table.'“ stimmt nicht – die Datei ist beschädigt.');
                }
            }

            foreach ($manifest['files'] as $path => $info) {
                [$hash] = $this->hashEntry($zip, 'files/'.$path);
                if ($hash !== ($info['sha256'] ?? null)) {
                    throw new BackupInvalid('Prüfsumme der Datei „'.$path.'“ stimmt nicht – die Datei ist beschädigt.');
                }
            }
        } finally {
            $zip->close();
        }

        return $manifest;
    }

    /**
     * Spielt eine Sicherung ein und ersetzt dabei ALLE Daten der Datenbank (alle Mandanten) und die Dateien.
     *
     * @return array{tables: array<string, int>, files: int}
     *
     * @throws BackupInvalid
     */
    public function restore(string $zipPath, bool $ignoreKey = false): array
    {
        $manifest = $this->inspect($zipPath);

        if (! $ignoreKey && ($manifest['key_fingerprint'] ?? null) !== self::keyFingerprint()) {
            throw new BackupInvalid('Der APP_KEY dieser Installation passt nicht zur Sicherung. Verschlüsselte Daten (SMTP-Passwörter, Zwei-Faktor-Geheimnisse) wären unlesbar. Trage zuerst den ursprünglichen APP_KEY ein.');
        }

        $known = DB::table('migrations')->pluck('migration')->all();
        if ($unknown = array_diff($manifest['migrations'], $known)) {
            throw new BackupInvalid('Die Sicherung stammt von einer neueren Programmversion (unbekannte Migration: '.reset($unknown).'). Bitte zuerst die Anwendung aktualisieren.');
        }

        $zip = new ZipArchive;
        $zip->open($zipPath);
        $restored = [];

        try {
            $existing = $this->tables();
            $order = $this->orderTables($existing);

            Schema::disableForeignKeyConstraints();
            try {
                DB::transaction(function () use ($zip, $manifest, $order, &$restored) {
                    // Kinder zuerst leeren, Eltern zuerst füllen.
                    foreach (array_reverse($order) as $table) {
                        DB::table($table)->delete();
                    }

                    foreach ($order as $table) {
                        if (isset($manifest['tables'][$table])) {
                            $restored[$table] = $this->loadTable($zip, $table);
                        }
                    }
                });
            } finally {
                Schema::enableForeignKeyConstraints();
            }

            $files = 0;
            foreach (array_keys($manifest['files']) as $path) {
                if (! $this->safeRelativePath($path)) {
                    continue;
                }
                $stream = $zip->getStream('files/'.$path);
                Storage::disk('local')->writeStream($path, $stream);
                fclose($stream);
                $files++;
            }
        } finally {
            $zip->close();
        }

        return ['tables' => $restored, 'files' => $files];
    }

    /** @return array<int, string> */
    private function tables(): array
    {
        $names = array_map(fn ($t) => $t['name'], Schema::getTables());

        return array_values(array_diff($names, self::EXCLUDED_TABLES));
    }

    private function dumpTable(string $table, string $file): int
    {
        $handle = fopen($file, 'wb');
        $count = 0;

        $query = DB::table($table);
        if (Schema::hasColumn($table, 'id')) {
            $query->orderBy('id'); // Eltern vor Kindern bei Selbstbezügen (z. B. Gutschrift → Rechnung)
        }

        foreach ($query->cursor() as $row) {
            fwrite($handle, json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            $count++;
        }

        fclose($handle);

        return $count;
    }

    private function loadTable(ZipArchive $zip, string $table): int
    {
        $columns = array_flip(Schema::getColumnListing($table));
        $stream = $zip->getStream('db/'.$table.'.jsonl');
        $batch = [];
        $count = 0;

        while (($line = fgets($stream)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Nur Spalten, die es in dieser Installation gibt (ältere Sicherung → neuere Tabelle).
            $batch[] = array_intersect_key(json_decode($line, true, 512, JSON_THROW_ON_ERROR), $columns);
            $count++;

            if (count($batch) >= 100) {
                DB::table($table)->insert($batch);
                $batch = [];
            }
        }
        if ($batch) {
            DB::table($table)->insert($batch);
        }
        fclose($stream);

        return $count;
    }

    /** @return array{0: string, 1: int} Prüfsumme und Zeilenanzahl */
    private function hashEntry(ZipArchive $zip, string $entry): array
    {
        $stream = $zip->getStream($entry);
        if ($stream === false) {
            throw new BackupInvalid('Im Backup fehlt der Eintrag „'.$entry.'“.');
        }

        $ctx = hash_init('sha256');
        $lines = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, 1048576);
            if ($chunk === false || $chunk === '') {
                break;
            }
            hash_update($ctx, $chunk);
            $lines += substr_count($chunk, "\n");
        }
        fclose($stream);

        return [hash_final($ctx), $lines];
    }

    private function safeRelativePath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && ! str_starts_with($path, self::DIR.'/');
    }

    /**
     * Tabellen so sortieren, dass referenzierte Tabellen (Eltern) vor den referenzierenden (Kindern) stehen.
     *
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    private function orderTables(array $tables): array
    {
        $deps = [];
        foreach ($tables as $table) {
            $deps[$table] = [];
            foreach (Schema::getForeignKeys($table) as $fk) {
                $parent = $fk['foreign_table'];
                if ($parent !== $table && in_array($parent, $tables, true)) {
                    $deps[$table][$parent] = true;
                }
            }
        }

        $ordered = [];
        while ($deps) {
            $ready = array_keys(array_filter($deps, fn ($d) => array_diff_key($d, array_flip($ordered)) === []));

            if ($ready === []) {
                $ordered = array_merge($ordered, array_keys($deps)); // Zyklus: Rest unverändert (Prüfungen sind abgeschaltet)
                break;
            }

            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($deps[$table]);
            }
        }

        return $ordered;
    }
}
