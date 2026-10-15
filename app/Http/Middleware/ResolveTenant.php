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

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bestimmt den aktiven Mandanten aus der Sitzung und prüft, ob der Benutzer ihm zugeordnet ist.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenants = $user->tenants()->orderBy('name')->get();

        if ($tenants->isEmpty()) {
            return response()->view('errors.no-tenant', [], 403);
        }

        $current = $tenants->firstWhere('id', $request->session()->get('tenant_id')) ?? $tenants->first();

        $request->session()->put('tenant_id', $current->id);
        Tenancy::set($current->id);

        view()->share([
            'currentTenant' => $current,
            'userTenants' => $tenants,
            'currentRole' => $current->pivot->role,
        ]);

        return $next($request);
    }
}
