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
/* Kunden-Export: Felder je Gruppe mit einem Klick alle/keine auswählen und die Anzahl der gewählten Felder anzeigen.
   Knöpfe: button[data-group-toggle="alle|keine"] innerhalb von fieldset[data-group]. */
(function () {
    'use strict';

    function update() {
        var all = document.querySelectorAll('input[name="fields[]"]');
        var on = document.querySelectorAll('input[name="fields[]"]:checked').length;
        var out = document.getElementById('fields-count');
        if (out) { out.textContent = on + ' / ' + all.length; }
        var submit = document.getElementById('export-submit');
        if (submit) { submit.disabled = on === 0; }
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('button[data-group-toggle]');
        if (!button) { return; }
        event.preventDefault();
        var group = button.closest('[data-group]');
        if (!group) { return; }
        var check = button.getAttribute('data-group-toggle') === 'all';
        group.querySelectorAll('input[name="fields[]"]').forEach(function (box) { box.checked = check; });
        update();
    });

    document.addEventListener('change', function (event) {
        if (event.target.matches('input[name="fields[]"]')) { update(); }
    });

    update();
})();
