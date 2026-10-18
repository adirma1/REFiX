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

use App\Models\AuditLog;
use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use App\Support\ListSort;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/** Backups anlegen, herunterladen und löschen (nur Admin). Zurückgespielt wird bewusst nur per Befehl auf dem Server. */
class BackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index(Request $request): View
    {
        $this->authorize('manage-users');

        $sort = ListSort::make($request, 'backups', [
            'default' => fn () => 0, 'file' => 'name', 'created' => 'created', 'size' => 'size',
        ], ['default', 'asc']);

        return view('backups.index', [
            'sort' => $sort,
            'backups' => $sort->sortCollection(collect($this->backups->list())),
            'fingerprint' => BackupService::keyFingerprint(),
        ]);
    }

    public function store(): RedirectResponse
    {
        $this->authorize('manage-users');

        try {
            $name = $this->backups->create();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('backups.index')->with('warning', __('messages.backups.failed', ['error' => mb_substr($e->getMessage(), 0, 300)]));
        }

        AuditLog::record('backup.created', null, ['name' => $name]);

        return redirect()->route('backups.index')->with('status', __('messages.backups.created', ['name' => $name]));
    }

    public function download(string $name): BinaryFileResponse|Response
    {
        $this->authorize('manage-users');

        $path = $this->backups->path($name);
        abort_if($path === null, 404);

        AuditLog::record('backup.downloaded', null, ['name' => $name]);

        return response()->download($path, $name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function destroy(string $name): RedirectResponse
    {
        $this->authorize('manage-users');
        abort_unless($this->backups->delete($name), 404);

        AuditLog::record('backup.deleted', null, ['name' => $name]);

        return redirect()->route('backups.index')->with('status', __('messages.backups.deleted'));
    }
}
