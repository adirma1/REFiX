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
--}}<div class="toolbar" style="margin-bottom:.5rem">
    <a @class(['btn', 'secondary' => ! request()->routeIs('payments.*')]) href="{{ route('payments.index') }}">{{ __('messages.payments.open_items') }}</a>
    <a @class(['btn', 'secondary' => ! request()->routeIs('bank.*')]) href="{{ route('bank.index') }}">{{ __('messages.bank.title') }}</a>
    <a @class(['btn', 'secondary' => ! request()->routeIs('dunning.*')]) href="{{ route('dunning.index') }}">{{ __('messages.dunning.title') }}</a>
</div>
