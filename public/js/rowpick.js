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
/* Auswahl vorn, Aktionen oben (Offene Posten, Bank).
   Einzelauswahl: [data-pick-actions] mit Knöpfen button[data-act]; die Zeilen haben input.pick-one mit data-<act>="URL".
   Knopf-Optionen: data-method="post" oder "delete" (über #pick-form), data-new-tab, data-confirm,
   data-event (löst stattdessen das Ereignis "pick-act" aus; detail = {act, radio}, z. B. zum Öffnen eines Bearbeiten-Feldes).
   Mehrfachauswahl: form[data-multi] mit input.pick, input.pick-all, Knöpfen [data-needs] und Zählern [data-count]. */
(function () {
    var closestInteractive = function (el) { return el.closest('a, input, button, label, select, textarea'); };

    var bar = document.querySelector('[data-pick-actions]');
    if (bar) {
        var radios = [].slice.call(document.querySelectorAll('input.pick-one'));
        var form = document.getElementById('pick-form');
        var hint = document.getElementById('pick-hint');
        var current = function () { return radios.filter(function (r) { return r.checked; })[0] || null; };
        var update = function () {
            var r = current();
            radios.forEach(function (x) { x.closest('tr').classList.toggle('selected', x === r); });
            [].slice.call(bar.querySelectorAll('button[data-act]')).forEach(function (b) { b.disabled = !(r && r.dataset[b.dataset.act]); });
            if (hint) { hint.hidden = !!r; }
            document.dispatchEvent(new CustomEvent('pick-change', { detail: { radio: r } }));
        };
        radios.forEach(function (r) { r.addEventListener('change', update); });
        [].slice.call(document.querySelectorAll('tr[data-pick]')).forEach(function (tr) {
            tr.addEventListener('click', function (e) {
                if (closestInteractive(e.target)) { return; }
                var r = tr.querySelector('input.pick-one');
                if (r) { r.checked = true; update(); }
            });
        });
        bar.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-act]'), r = current();
            if (!b || !r || b.disabled) { return; }
            var url = r.dataset[b.dataset.act];
            if (!url) { return; }
            if (b.dataset.confirm && !window.confirm(b.dataset.confirm)) { return; }
            if (b.hasAttribute('data-event')) { document.dispatchEvent(new CustomEvent('pick-act', { detail: { act: b.dataset.act, radio: r } })); return; }
            if (b.dataset.method === 'post' || b.dataset.method === 'delete') {
                var spoof = document.getElementById('pick-method');
                if (spoof) { spoof.value = b.dataset.method === 'delete' ? 'DELETE' : 'POST'; }
                form.action = url; form.submit();
            }
            else if (b.hasAttribute('data-new-tab')) { window.open(url, '_blank', 'noopener'); }
            else { window.location.href = url; }
        });
        update();
    }

    var multi = document.querySelector('form[data-multi]');
    if (multi) {
        var boxes = [].slice.call(multi.querySelectorAll('input.pick'));
        var all = multi.querySelector('input.pick-all');
        var needs = [].slice.call(multi.querySelectorAll('[data-needs]'));
        var counts = [].slice.call(multi.querySelectorAll('[data-count]'));
        var updateMulti = function () {
            var n = boxes.filter(function (b) { return b.checked; }).length;
            counts.forEach(function (c) { c.textContent = n; });
            needs.forEach(function (b) { b.disabled = n === 0; });
            boxes.forEach(function (b) { b.closest('tr').classList.toggle('selected', b.checked); });
            if (all) { all.checked = n > 0 && n === boxes.length; }
        };
        boxes.forEach(function (b) { b.addEventListener('change', updateMulti); });
        if (all) { all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); updateMulti(); }); }
        [].slice.call(multi.querySelectorAll('tr[data-pick]')).forEach(function (tr) {
            tr.addEventListener('click', function (e) {
                if (closestInteractive(e.target)) { return; }
                var b = tr.querySelector('input.pick'); if (b) { b.checked = !b.checked; updateMulti(); }
            });
        });
        updateMulti();
    }
})();
