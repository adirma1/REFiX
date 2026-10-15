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
use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupCreate extends Command
{
    protected $signature = 'backup:create';

    protected $description = 'Legt ein Backup (Datenbank und Dateien) im Backup-Ordner an';

    public function handle(BackupService $backups): int
    {
        $name = $backups->create();
        AuditLog::record('backup.created', null, ['name' => $name, 'via' => 'console']);

        $this->info("Backup angelegt: storage/app/private/backups/{$name}");

        return self::SUCCESS;
    }
}
