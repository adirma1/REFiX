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
--}}@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="btn secondary disabled">« {{ __('messages.pagination.previous') }}</span>
        @else
            <a class="btn secondary" href="{{ $paginator->previousPageUrl() }}" rel="prev">« {{ __('messages.pagination.previous') }}</a>
        @endif

        <span class="muted">{{ __('messages.pagination.page', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>

        @if ($paginator->hasMorePages())
            <a class="btn secondary" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('messages.pagination.next') }} »</a>
        @else
            <span class="btn secondary disabled">{{ __('messages.pagination.next') }} »</span>
        @endif
    </nav>
@endif
