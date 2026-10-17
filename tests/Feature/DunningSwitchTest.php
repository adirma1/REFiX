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

namespace Tests\Feature;

use App\Models\Dunning;
use App\Models\DunningHold;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DunningInvalid;
use App\Services\DunningService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPaymentSetup;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Mahnwesen je Mandant abschaltbar: nur noch lesen. */
class DunningSwitchTest extends TestCase
{
    use CreatesPaymentSetup, CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $accounting;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 10:00:00'));
        Storage::fake('local');

        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeUser($this->tenant, 'admin');
        $this->accounting = $this->makeUser($this->tenant, 'accounting');
        $this->invoice = $this->issueInvoice($this->tenant, $this->makeCustomer($this->tenant), $this->accounting);
    }

    private function actAs(User $user): static
    {
        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id]);
        Tenancy::set($this->tenant->id);

        return $this;
    }

    private function service(): DunningService
    {
        Tenancy::set($this->tenant->id);

        return app(DunningService::class);
    }

    public function test_enabled_by_default_and_suggestions_stop_when_switched_off(): void
    {
        $this->assertTrue($this->tenant->fresh()->dunning_enabled);
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));
        $this->assertCount(1, $this->service()->suggestions());

        $this->tenant->update(['dunning_enabled' => false]);
        $this->assertCount(0, $this->service()->suggestions());

        $this->expectException(DunningInvalid::class);
        $this->service()->create($this->invoice, $this->accounting);
    }

    public function test_existing_dunnings_stay_readable_but_nothing_can_be_changed(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));
        $dunning = $this->service()->create($this->invoice, $this->accounting);
        $hold = $this->service()->hold($this->invoice, 'Kulanz', null, $this->accounting);
        $this->tenant->update(['dunning_enabled' => false]);

        $this->actAs($this->accounting);

        // Lesen: Mahnungen, Sperren und PDF
        $this->get(route('dunning.index'))->assertOk()
            ->assertSee(__('messages.dunning.disabled_banner'))
            ->assertSee('R-2026-0001')
            ->assertDontSee(__('messages.dunning.tab_due'));
        $this->get(route('dunning.index', ['tab' => 'holds']))->assertOk()->assertSee('Kulanz')
            ->assertDontSee(route('dunning.release', $hold));
        $this->get(route('dunning.pdf', $dunning))->assertOk();

        // Ändern: alles gesperrt, es passiert nichts
        $this->post(route('dunning.store'), ['invoices' => [$this->invoice->id]])->assertRedirect()->assertSessionHas('warning');
        $this->post(route('dunning.void', $dunning))->assertRedirect()->assertSessionHas('warning');
        $this->post(route('dunning.sent', $dunning))->assertRedirect()->assertSessionHas('warning');
        $this->post(route('dunning.hold', $this->invoice), ['reason' => 'x'])->assertRedirect()->assertSessionHas('warning');
        $this->post(route('dunning.hold-many'), ['invoices' => [$this->invoice->id], 'reason' => 'x'])->assertRedirect()->assertSessionHas('warning');
        $this->post(route('dunning.release', $hold))->assertRedirect()->assertSessionHas('warning');

        $this->assertSame(1, Dunning::count());
        $this->assertTrue($dunning->fresh()->isPending());
        $this->assertSame(1, DunningHold::active()->count());
    }

    public function test_dashboard_tile_disappears(): void
    {
        $this->actAs($this->accounting)->get(route('dashboard'))->assertOk()->assertSee(__('messages.dashboard.dunning_due'));

        $this->tenant->update(['dunning_enabled' => false]);
        $this->actAs($this->accounting)->get(route('dashboard'))->assertOk()->assertDontSee(__('messages.dashboard.dunning_due'));
    }

    public function test_the_switch_is_saved_on_the_settings_page_and_kept_when_missing(): void
    {
        $valid = [
            'dunning_days_after_due' => 7, 'dunning_grace_days' => 7, 'dunning_fee_1' => '0', 'dunning_fee_2' => '0', 'dunning_fee_3' => '0',
            'dunning_interest_margin' => '9',
        ];
        $this->actAs($this->admin);

        $this->post(route('dunning.settings.update'), $valid + ['dunning_enabled_present' => '1', 'dunning_enabled' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($this->tenant->fresh()->dunning_enabled);

        // Ohne das Feld (ältere Formulare) bleibt der Wert.
        $this->post(route('dunning.settings.update'), $valid)->assertSessionHasNoErrors();
        $this->assertFalse($this->tenant->fresh()->dunning_enabled);

        $this->post(route('dunning.settings.update'), $valid + ['dunning_enabled_present' => '1', 'dunning_enabled' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($this->tenant->fresh()->dunning_enabled);

        $this->get(route('dunning.settings'))->assertOk()->assertSee('name="dunning_enabled"', false);
    }
}
