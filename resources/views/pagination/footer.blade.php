{{--
    RE-FiX – Rechnungsprogramm
    Copyright (C) 2026 Martin Adirsch

    This program is free software; you can redistribute it and/or modify it under the terms of
    version 2 of the GNU General Public License as published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
    without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
    See the GNU General Public License for more details.

    You should have received a copy of the GNU General Public License along with this program
    (file LICENSE). If not, see <https://www.gnu.org/licenses/old-licenses/gpl-2.0.html>.

    SPDX-License-Identifier: GPL-2.0-only
--}}{{-- Fußzeile jeder Liste: Zeilen pro Seite wählen und seitenweise blättern. --}}
<div class="list-footer">
    <form method="POST" action="{{ route('profile.per-page') }}" class="per-page">
        @csrf
        <label for="per_page_{{ $id ?? 'x' }}">{{ __('messages.pagination.per_page') }}</label>
        <select id="per_page_{{ $id ?? 'x' }}" name="per_page" onchange="this.form.submit()">
            @foreach(\App\Support\PerPage::OPTIONS as $option)
                <option value="{{ $option }}" @selected($paginator->perPage() === $option)>{{ $option }}</option>
            @endforeach
        </select>
        <noscript><button class="btn secondary" type="submit">{{ __('messages.save') }}</button></noscript>
    </form>
    <div class="pagination">{{ $paginator->links() }}</div>
</div>
