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
/* Mandant „ohne Umsatzsteuer“: Textfelder nur bei gesetztem Häkchen zeigen, Vorschlag in den Hinweistext übernehmen. */
(function () {
    'use strict';

    var check = document.getElementById('vat_free');
    var box = document.getElementById('vat-free-fields');
    var preset = document.getElementById('vat_preset');
    var note = document.getElementById('vat_free_note');

    if (!check || !box || !preset || !note) { return; }

    function toggle() { box.style.display = check.checked ? '' : 'none'; }

    check.addEventListener('change', toggle);
    preset.addEventListener('change', function () {
        var option = preset.options[preset.selectedIndex];
        if (option && option.dataset.text) { note.value = option.dataset.text; }
    });
    toggle();
})();
