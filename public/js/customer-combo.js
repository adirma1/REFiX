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

/*
 * Kundenauswahl mit Suchfeld. Das <select id="customer_id"> bleibt die Quelle der Wahrheit (wird versteckt und
 * weiter abgeschickt); die Auswahl löst sein "change"-Ereignis aus, sodass invoice-form.js unverändert arbeitet.
 * Optional: data-fill-name="<Feld-ID>" füllt dieses Feld mit dem Kundennamen (data-name der Option), solange es
 * leer ist oder noch den zuletzt automatisch eingetragenen Namen enthält.
 */
(function () {
    var sel = document.getElementById('customer_id');
    if (!sel || sel.tagName !== 'SELECT') { return; }

    var nameField = sel.dataset.fillName ? document.getElementById(sel.dataset.fillName) : null;
    var lastAuto = null;
    sel.addEventListener('change', function () {
        var opt = sel.options[sel.selectedIndex];
        if (!nameField || !opt || !opt.value || !opt.dataset.name) { return; }
        var cur = nameField.value.trim();
        if (cur === '' || cur === lastAuto) {
            nameField.value = opt.dataset.name;
            lastAuto = opt.dataset.name;
        }
    });

    function norm(s) {
        return String(s).toLowerCase().replace(/ß/g, 'ss').normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    var items = [];
    Array.prototype.forEach.call(sel.options, function (o) {
        if (!o.value) { return; }
        var label = o.textContent.replace(/\s+/g, ' ').trim();
        items.push({ value: o.value, label: label, key: norm(label), name: norm(o.dataset.name || '') });
    });

    var msgPick = sel.dataset.pickMsg || '';
    var wrap = document.createElement('div');
    wrap.className = 'combo';
    var input = document.createElement('input');
    input.type = 'text';
    input.id = 'customer_search';
    input.className = 'combo-input';
    input.autocomplete = 'off';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', 'customer_list');
    input.placeholder = sel.dataset.placeholder || '';
    input.required = true;
    var clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'combo-x';
    clear.tabIndex = -1;
    clear.textContent = '✕';
    clear.hidden = true;
    var list = document.createElement('ul');
    list.id = 'customer_list';
    list.className = 'combo-list';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    wrap.appendChild(input);
    wrap.appendChild(clear);
    wrap.appendChild(list);
    sel.parentNode.insertBefore(wrap, sel);
    sel.style.display = 'none';
    sel.required = false;
    var label = document.querySelector('label[for="customer_id"]');
    if (label) { label.setAttribute('for', 'customer_search'); }

    var shown = [];
    var active = -1;

    function selectedItem() {
        for (var i = 0; i < items.length; i++) { if (items[i].value === sel.value) { return items[i]; } }
        return null;
    }

    function validity() {
        input.setCustomValidity(sel.value || input.value.trim() === '' ? '' : msgPick);
        clear.hidden = input.value === '';
    }

    function render() {
        list.innerHTML = '';
        shown.forEach(function (it, i) {
            var li = document.createElement('li');
            li.id = 'customer_opt_' + i;
            li.setAttribute('role', 'option');
            li.textContent = it.label;
            if (i === active) { li.className = 'act'; li.setAttribute('aria-selected', 'true'); }
            li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(it); });
            list.appendChild(li);
        });
        if (shown.length === 0) {
            var none = document.createElement('li');
            none.className = 'none';
            none.textContent = sel.dataset.noneMsg || '';
            list.appendChild(none);
        }
        input.setAttribute('aria-activedescendant', active >= 0 ? 'customer_opt_' + active : '');
        var cur = list.querySelector('.act');
        if (cur && cur.scrollIntoView) { cur.scrollIntoView({ block: 'nearest' }); }
    }

    function open(all) {
        var q = all ? '' : norm(input.value).trim();
        var tokens = q === '' ? [] : q.split(/\s+/);
        shown = [];
        var rest = [];
        items.forEach(function (it) {
            var ok = tokens.every(function (t) { return it.key.indexOf(t) !== -1; });
            if (!ok) { return; }
            // Treffer am Anfang der Nummer oder des Namens zuerst
            var first = tokens.length > 0 && (it.key.indexOf(tokens[0]) === 0 || it.name.indexOf(tokens[0]) === 0);
            (first || tokens.length === 0 ? shown : rest).push(it);
        });
        shown = shown.concat(rest);
        var sIt = selectedItem();
        active = shown.length === 0 ? -1 : 0;
        if (all && sIt) { active = Math.max(0, shown.indexOf(sIt)); }
        render();
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    function close() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
    }

    function choose(it) {
        sel.value = it.value;
        input.value = it.label;
        close();
        validity();
        sel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    var init = selectedItem();
    if (init) { input.value = init.label; }
    validity();

    input.addEventListener('focus', function () {
        if (input.select) { input.select(); }
        open(!!sel.value || input.value.trim() === '');
    });
    input.addEventListener('click', function () { if (list.hidden) { open(!!sel.value || input.value.trim() === ''); } });
    input.addEventListener('input', function () {
        sel.value = '';
        validity();
        open(false);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (list.hidden) { open(false); return; }
            if (shown.length === 0) { return; }
            active = (active + (e.key === 'ArrowDown' ? 1 : -1) + shown.length) % shown.length;
            render();
        } else if (e.key === 'Enter') {
            if (!list.hidden) {
                e.preventDefault();
                if (active >= 0 && shown[active]) { choose(shown[active]); }
            }
        } else if (e.key === 'Escape') {
            if (!list.hidden) { e.preventDefault(); close(); }
        }
    });
    input.addEventListener('blur', function () {
        close();
        var cur = selectedItem();
        if (cur) { input.value = cur.label; }
        validity();
    });
    clear.addEventListener('mousedown', function (e) {
        e.preventDefault();
        sel.value = '';
        input.value = '';
        validity();
        input.focus();
        open(true);
    });
})();
