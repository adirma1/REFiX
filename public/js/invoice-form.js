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
/* Rechnungsformular: Positionen, Artikelpreise und Summen vorab (die endgültige Berechnung macht der Server). */
(function () {
    'use strict';

    var form = document.getElementById('invoice-form');
    if (!form) { return; }

    var body = document.getElementById('items-body');
    var template = document.getElementById('row-template');
    var totals = document.getElementById('totals');
    var nextIndex = 1000; // Indizes neuer Zeilen kollidieren so nie mit vorhandenen
    var field = function (name) { return form.elements[name]; };

    function parse(value) {
        var v = String(value == null ? '' : value).trim();
        if (v === '') { return 0; }
        if (v.indexOf(',') !== -1) { v = v.replace(/\./g, '').replace(',', '.'); }
        var n = parseFloat(v);
        return isNaN(n) ? 0 : n;
    }

    function round2(n) {
        return Math.sign(n) * Math.round(Math.abs(n) * 100 + 1e-7) / 100;
    }

    function money(n) {
        return n.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatInput(n, minDecimals, maxDecimals) {
        return n.toLocaleString('de-DE', {
            useGrouping: false, minimumFractionDigits: minDecimals, maximumFractionDigits: maxDecimals
        });
    }

    function rows() { return body.querySelectorAll('tr.item-row'); }

    function inputIn(tr, name) { return tr.querySelector('[name$="[' + name + ']"]'); }

    function renumber() {
        rows().forEach(function (tr, i) { tr.querySelector('.pos').textContent = i + 1; });
    }

    function line(label, value, cls) {
        var div = document.createElement('div');
        if (cls) { div.className = cls; }
        var a = document.createElement('span');
        a.textContent = label;
        var b = document.createElement('span');
        b.textContent = value;
        div.appendChild(a);
        div.appendChild(b);
        return div;
    }

    function recalc() {
        var standard = field('tax_mode').value === 'standard';
        var groups = {};
        var currency = field('currency').value;

        rows().forEach(function (tr) {
            var net = round2(parse(inputIn(tr, 'quantity').value) * parse(inputIn(tr, 'unit_price').value)
                * (1 - parse(inputIn(tr, 'discount_percent').value) / 100));
            tr.querySelector('.line-net').textContent = money(net);
            var rate = standard ? parse(inputIn(tr, 'tax_rate').value) : 0;
            groups[rate] = (groups[rate] || 0) + net;
        });

        var discount = parse(field('discount_percent').value);
        var before = 0, net = 0, tax = 0;
        var lines = [];

        Object.keys(groups).map(Number).sort(function (a, b) { return b - a; }).forEach(function (rate) {
            var sum = round2(groups[rate]);
            var discounted = round2(sum * (1 - discount / 100));
            var t = round2(discounted * rate / 100);
            before += sum; net += discounted; tax += t;
            if (standard) {
                lines.push(line(totals.dataset.lVat.replace(':rate', String(rate).replace('.', ',')).replace(':net', money(discounted)), money(t)));
            }
        });

        net = round2(net); tax = round2(tax);
        totals.textContent = '';
        if (discount > 0) {
            totals.appendChild(line(totals.dataset.lDiscount + ' (' + money(discount) + ' %)', '– ' + money(round2(before - net))));
        }
        totals.appendChild(line(totals.dataset.lNet, money(net)));
        lines.forEach(function (l) { totals.appendChild(l); });
        totals.appendChild(line(totals.dataset.lGross, money(round2(net + tax)) + ' ' + currency, 'grand'));
        totals.appendChild(line(totals.dataset.lPreview, '', 'note'));
    }

    function addRow() {
        body.insertAdjacentHTML('beforeend', template.innerHTML.split('__INDEX__').join(String(nextIndex++)));
        renumber();
        return body.lastElementChild;
    }

    function setTax(select, rate) {
        var value = String(rate);
        var found = Array.prototype.some.call(select.options, function (o) { return parse(o.value) === rate; });
        if (!found) {
            var option = document.createElement('option');
            option.value = value; option.textContent = value.replace('.', ',') + ' %';
            select.appendChild(option);
        }
        Array.prototype.forEach.call(select.options, function (o) { if (parse(o.value) === rate) { select.value = o.value; } });
    }

    function applyArticle(tr, articleId) {
        if (!articleId) { return; }
        var customer = field('customer_id').value;
        var url = form.dataset.priceUrl.replace('__ID__', encodeURIComponent(articleId))
            + '?currency=' + encodeURIComponent(field('currency').value)
            + '&date=' + encodeURIComponent((field('issue_date') || field('next_date')).value)
            + (customer ? '&customer=' + encodeURIComponent(customer) : '');

        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
            .then(function (data) {
                inputIn(tr, 'description').value = data.description;
                inputIn(tr, 'unit').value = data.unit || '';
                setTax(inputIn(tr, 'tax_rate'), data.tax_rate);
                if (parse(inputIn(tr, 'quantity').value) === 0) { inputIn(tr, 'quantity').value = '1'; }
                var hint = tr.querySelector('.price-hint');
                if (data.price === null) {
                    hint.hidden = false;
                } else {
                    hint.hidden = true;
                    inputIn(tr, 'unit_price').value = formatInput(data.price, 2, 4);
                }
                recalc();
            })
            .catch(function () { /* Zeile bleibt unverändert */ });
    }

    body.addEventListener('change', function (e) {
        if (e.target.classList.contains('article-select')) {
            applyArticle(e.target.closest('tr'), e.target.value);
        }
        recalc();
    });
    body.addEventListener('input', recalc);

    body.addEventListener('click', function (e) {
        if (!e.target.classList.contains('remove-row')) { return; }
        var tr = e.target.closest('tr');
        if (rows().length > 1) {
            tr.remove();
        } else {
            tr.querySelectorAll('input[type=text], textarea').forEach(function (el) { el.value = ''; });
            tr.querySelector('.article-select').value = '';
        }
        renumber();
        recalc();
    });

    document.getElementById('add-row').addEventListener('click', function () {
        addRow().querySelector('textarea').focus();
        recalc();
    });

    field('customer_id').addEventListener('change', function () {
        var option = this.options[this.selectedIndex];
        if (!option || !option.value) { return; }
        if (option.dataset.currency && field('currency')) { field('currency').value = option.dataset.currency; }
        if (field('payment_term_days') && option.dataset.term) { field('payment_term_days').value = option.dataset.term; }
        if (field('skonto1_percent')) {
            field('skonto1_percent').value = option.dataset.s1p || '';
            field('skonto1_days').value = option.dataset.s1d || '';
        }
        if (option.dataset.buyer && field('buyer_reference')) { field('buyer_reference').value = option.dataset.buyer; }
        recalc();
    });

    ['tax_mode', 'currency', 'discount_percent'].forEach(function (name) {
        field(name).addEventListener('input', recalc);
        field(name).addEventListener('change', recalc);
    });

    if (rows().length === 0) { addRow(); }
    renumber();
    recalc();
})();
