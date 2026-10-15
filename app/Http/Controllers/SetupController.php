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
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function show(): View
    {
        return view('auth.setup');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = DB::transaction(function () use ($data) {
            $tenant = Tenant::create(['name' => $data['company']]);
            Tenancy::set($tenant->id);

            $user = User::create([
                'name' => $data['name'],
                'username' => User::DEFAULT_ADMIN_USERNAME,
                'email' => $data['email'],
                'password' => $data['password'],
                'locale' => app()->getLocale(),
            ]);

            $tenant->users()->attach($user->id, ['role' => 'admin']);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('tenant_id', Tenancy::id());

        AuditLog::record('setup.completed', $user);

        return redirect()->route('profile.two-factor');
    }
}
