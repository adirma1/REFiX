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
use App\Support\ListSort;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('manage-users');

        ['users' => $users, 'actions' => $actions, 'subjects' => $subjects, 'filters' => $filters, 'query' => $query, 'scope' => $scope] = $this->context($request);

        $sort = ListSort::make($request, 'audit', [
            'time' => 'created_at',
            'user' => fn ($q, string $dir) => $q->orderByRaw("(select users.name from users where users.id = audit_logs.user_id) {$dir}"),
            'action' => 'action',
            'details' => fn ($q, string $dir) => $q->orderBy('subject_type', $dir)->orderBy('subject_id', $dir),
        ], ['time', 'desc'], [['id', 'desc']]);

        $logs = $query
            ->with('user:id,name')
            ->tap(fn ($q) => $sort->apply($q))
            ->paginate(PerPage::for($request->user()))
            ->withQueryString();

        return view('audit.index', [
            'sort' => $sort,
            'logs' => $logs,
            'filters' => $filters,
            'users' => $users,
            'actions' => $actions,
            'subjects' => $subjects,
            'filtered' => collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty(),
            'totalCount' => $scope->count(),
        ]);
    }

    /** Protokoll als CSV (Excel, Semikolon, UTF-8 mit BOM) oder Textdatei; es gelten die gesetzten Filter. */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('manage-users');

        $format = $request->query('format') === 'txt' ? 'txt' : 'csv';
        $query = $this->context($request)['query']->with('user:id,name');
        $name = 'protokoll-'.now()->format('Y-m-d');

        return response()->streamDownload(function () use ($query, $format) {
            $out = fopen('php://output', 'w');

            if ($format === 'csv') {
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, [__('messages.audit.time'), __('messages.audit.user'), __('messages.audit.action'), __('messages.audit.subject'), __('messages.audit.details'), 'IP'], ';');
            }

            foreach ($query->lazyById(1000) as $log) {
                $row = [
                    $log->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                    $log->user?->name ?? '',
                    $log->action,
                    $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : '',
                    $log->details ? json_encode($log->details, JSON_UNESCAPED_UNICODE) : '',
                    (string) $log->ip_address,
                ];

                if ($format === 'csv') {
                    // Zellen, die mit = + - @ beginnen, würde Excel als Formel auswerten.
                    fputcsv($out, array_map(fn (string $v) => $v !== '' && str_contains('=+-@', $v[0]) ? "'".$v : $v, $row), ';');
                } else {
                    fwrite($out, implode(' | ', array_map(fn (string $v) => str_replace(["\r", "\n"], ' ', $v), $row))."\n");
                }
            }

            fclose($out);
        }, $name.'.'.$format, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'text/plain; charset=UTF-8']);
    }

    /** Leert das gesamte Protokoll dieses Mandanten (unabhängig von den Filtern). Es bleibt kein Eintrag zurück. */
    public function clear(Request $request): RedirectResponse
    {
        $this->authorize('manage-users');

        $count = $this->context($request)['scope']->delete();

        return redirect()->route('audit.index')->with('status', __('messages.audit.cleared', ['count' => $count]));
    }

    /**
     * Mandant, Benutzer, Auswahllisten, gesetzte Filter und die gefilterte Abfrage (ohne Sortierung).
     *
     * @return array{users: \Illuminate\Support\Collection, actions: \Illuminate\Support\Collection, subjects: \Illuminate\Support\Collection, filters: array<string, mixed>, query: Builder, scope: Builder}
     */
    private function context(Request $request): array
    {
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

        $query = (clone $scope)
            ->when($filters['q'] !== '', function (Builder $query) use ($filters) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
                $query->where(fn ($q) => $q->where('action', 'like', $like)->orWhere('details', 'like', $like));
            })
            ->when($filters['user'], fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($filters['action'], fn (Builder $q, $action) => $q->where('action', $action))
            ->when($filters['subject'], fn (Builder $q, $class) => $q->where('subject_type', $class))
            ->when($filters['from'], fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['to'], fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));

        return compact('users', 'actions', 'subjects', 'filters', 'query', 'scope');
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
