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
use App\Models\Tenant;
use App\Support\Tenancy;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('manage-users');

        $tenant = Tenant::findOrFail(Tenancy::id());
        $users = $tenant->users()->orderBy('users.name')->get(['users.id', 'users.name']);
        $scope = $this->scope($tenant->id, $users->pluck('id')->all());

        // Auswahllisten nur aus tatsächlich vorhandenen Werten
        $actions = (clone $scope)->distinct()->orderBy('action')->pluck('action');
        $subjects = (clone $scope)->whereNotNull('subject_type')->distinct()->pluck('subject_type')
            ->mapWithKeys(fn ($class) => [$class => class_basename($class)])->sort();

        $filters = [
            'q' => trim((string) $request->query('q')),
            'user' => $users->pluck('id')->contains((int) $request->query('user')) ? (int) $request->query('user') : null,
            'action' => $actions->contains($request->query('action')) ? $request->query('action') : null,
            'subject' => $subjects->has($request->query('subject')) ? $request->query('subject') : null,
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
        ];

        $logs = (clone $scope)
            ->with('user:id,name')
            ->when($filters['q'] !== '', function (Builder $query) use ($filters) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
                $query->where(fn ($q) => $q->where('action', 'like', $like)->orWhere('details', 'like', $like));
            })
            ->when($filters['user'], fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($filters['action'], fn (Builder $q, $action) => $q->where('action', $action))
            ->when($filters['subject'], fn (Builder $q, $class) => $q->where('subject_type', $class))
            ->when($filters['from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['to'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))
            ->latest('id')
            ->paginate(PerPage::for($request->user()))
            ->withQueryString();

        return view('audit.index', [
            'logs' => $logs,
            'filters' => $filters,
            'users' => $users,
            'actions' => $actions,
            'subjects' => $subjects,
            'filtered' => collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty(),
        ]);
    }

    /** Einträge des Mandanten sowie Anmeldungen seiner Benutzer (die noch ohne Mandant protokolliert werden). */
    private function scope(int $tenantId, array $userIds): Builder
    {
        return AuditLog::query()->where(function ($query) use ($tenantId, $userIds) {
            $query->where('tenant_id', $tenantId)
                ->orWhere(fn ($q) => $q->whereNull('tenant_id')->whereIn('user_id', $userIds));
        });
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null;
    }
}
