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

namespace App\Services\Layout;

/**
 * Kleine, sichere Vorlagensprache für Belegs-Layouts (kein PHP, kein Blade).
 *
 *   {{ pfad.zum.wert }}            Wert, HTML-maskiert
 *   {{ pfad | br }}                Zeilenumbrüche als <br>
 *   {{ pfad | upper }}             Großbuchstaben
 *   {{#if pfad}} … {{else}} … {{/if}}        bedingter Bereich (leer/0-Elemente/false = nicht gesetzt)
 *   {{#unless pfad}} … {{/unless}}           Gegenteil von #if
 *   {{#each liste}} … {{/each}}              Schleife; innen sind die Felder des Eintrags direkt erreichbar
 */
class TemplateEngine
{
    private const FILTERS = ['br', 'upper'];

    /** @return array<int, array<string, mixed>> */
    public static function parse(string $source): array
    {
        $tokens = preg_split('/(\{\{.*?\}\})/s', $source, -1, PREG_SPLIT_DELIM_CAPTURE);
        $position = 0;
        [$nodes, $end] = self::parseNodes($tokens, $position, null);

        if ($end !== null) {
            throw new TemplateException('Unerwartetes Blockende {{'.$end.'}}.');
        }

        return $nodes;
    }

    /**
     * Liest Knoten bis zum passenden Blockende.
     *
     * @param  array<int, string>  $tokens
     * @return array{0: array<int, array<string, mixed>>, 1: string|null}  Knoten und das gefundene Ende ("/if", "else" …)
     */
    private static function parseNodes(array $tokens, int &$i, ?string $block): array
    {
        $nodes = [];

        while ($i < count($tokens)) {
            $part = $tokens[$i++];

            if (! (str_starts_with($part, '{{') && str_ends_with($part, '}}') && strlen($part) >= 4)) {
                if (str_contains($part, '{{') || str_contains($part, '}}')) {
                    throw new TemplateException('Unvollständiger Platzhalter (geschweifte Klammern nicht paarig) bei: '.self::snippet($part));
                }
                if ($part !== '') {
                    $nodes[] = ['t' => 'text', 'v' => $part];
                }

                continue;
            }

            $tag = trim(substr($part, 2, -2));

            if ($tag === '' || str_contains($tag, '{{') || str_contains($tag, '}}')) {
                throw new TemplateException('Ungültiger Platzhalter: '.self::snippet($part));
            }

            if ($tag === 'else' || $tag[0] === '/') {
                return [$nodes, $tag];
            }

            if ($tag[0] === '#') {
                $pieces = preg_split('/\s+/', substr($tag, 1), 2);
                $kind = $pieces[0];
                $path = trim($pieces[1] ?? '');

                if (! in_array($kind, ['each', 'if', 'unless'], true) || ! self::isPath($path)) {
                    throw new TemplateException('Ungültiger Block: '.self::snippet($part));
                }

                [$inner, $end] = self::parseNodes($tokens, $i, $kind);
                $else = [];

                if ($end === 'else') {
                    if ($kind === 'each') {
                        throw new TemplateException('{{else}} ist in {{#each}} nicht erlaubt.');
                    }
                    [$else, $end] = self::parseNodes($tokens, $i, $kind);
                }

                if ($end !== '/'.$kind) {
                    throw new TemplateException('Der Block {{#'.$kind.' '.$path.'}} wurde nicht geschlossen.');
                }

                $nodes[] = ['t' => $kind, 'path' => $path, 'nodes' => $inner, 'else' => $else];

                continue;
            }

            $pieces = array_map('trim', explode('|', $tag));
            $path = array_shift($pieces);

            if (! self::isPath($path)) {
                throw new TemplateException('Ungültiger Platzhalter: '.self::snippet($part));
            }

            foreach ($pieces as $filter) {
                if (! in_array($filter, self::FILTERS, true)) {
                    throw new TemplateException("Unbekannter Filter „{$filter}“ in ".self::snippet($part).' (erlaubt: '.implode(', ', self::FILTERS).').');
                }
            }

            $nodes[] = ['t' => 'var', 'path' => $path, 'filters' => $pieces];
        }

        if ($block !== null) {
            throw new TemplateException('Der Block {{#'.$block.' …}} wurde nicht geschlossen.');
        }

        return [$nodes, null];
    }

    /** @param  array<int, array<string, mixed>>  $nodes */
    public static function render(array $nodes, array $data): string
    {
        return self::renderNodes($nodes, [$data]);
    }

    /**
     * Prüft alle Pfade gegen ein Beispiel-Datenmodell und meldet unbekannte Platzhalter.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, string>
     */
    public static function unknownPaths(array $nodes, array $sample): array
    {
        $unknown = [];
        self::walk($nodes, [$sample], $unknown);

        return array_values(array_unique($unknown));
    }

    // --- intern -------------------------------------------------------------------------------

    /** @param  array<int, array<string, mixed>>  $stack */
    private static function renderNodes(array $nodes, array $stack): string
    {
        $out = '';

        foreach ($nodes as $node) {
            switch ($node['t']) {
                case 'text':
                    $out .= $node['v'];
                    break;
                case 'var':
                    $out .= self::format(self::lookup($node['path'], $stack), $node['filters']);
                    break;
                case 'if':
                case 'unless':
                    $truthy = self::truthy(self::lookup($node['path'], $stack));
                    $show = $node['t'] === 'if' ? $truthy : ! $truthy;
                    $out .= self::renderNodes($show ? $node['nodes'] : $node['else'], $stack);
                    break;
                case 'each':
                    $list = self::lookup($node['path'], $stack);
                    if (is_array($list)) {
                        foreach ($list as $entry) {
                            $out .= self::renderNodes($node['nodes'], array_merge($stack, [is_array($entry) ? $entry : ['value' => $entry]]));
                        }
                    }
                    break;
            }
        }

        return $out;
    }

    /** @param  array<int, array<string, mixed>>  $stack */
    private static function walk(array $nodes, array $stack, array &$unknown): void
    {
        foreach ($nodes as $node) {
            if (! in_array($node['t'], ['var', 'if', 'unless', 'each'], true)) {
                continue;
            }

            [$found, $value] = self::resolve($node['path'], $stack);

            if (! $found) {
                $unknown[] = '{{'.($node['t'] === 'var' ? '' : '#'.$node['t'].' ').$node['path'].'}}';

                continue;
            }

            if ($node['t'] === 'each') {
                if (! is_array($value)) {
                    $unknown[] = '{{#each '.$node['path'].'}} (keine Liste)';

                    continue;
                }
                $first = $value[0] ?? [];
                self::walk($node['nodes'], array_merge($stack, [is_array($first) ? $first : []]), $unknown);
            } elseif ($node['t'] !== 'var') {
                self::walk($node['nodes'], $stack, $unknown);
                self::walk($node['else'], $stack, $unknown);
            }
        }
    }

    /** @return array{0: bool, 1: mixed} */
    private static function resolve(string $path, array $stack): array
    {
        $segments = explode('.', $path);

        for ($i = count($stack) - 1; $i >= 0; $i--) {
            $value = $stack[$i];
            $found = true;

            foreach ($segments as $segment) {
                if (is_array($value) && array_key_exists($segment, $value)) {
                    $value = $value[$segment];
                } else {
                    $found = false;
                    break;
                }
            }

            if ($found) {
                return [true, $value];
            }
        }

        return [false, null];
    }

    private static function lookup(string $path, array $stack): mixed
    {
        return self::resolve($path, $stack)[1];
    }

    private static function truthy(mixed $value): bool
    {
        return ! ($value === null || $value === false || $value === '' || $value === [] || $value === '0');
    }

    /** @param  array<int, string>  $filters */
    private static function format(mixed $value, array $filters): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        $text = $value === true ? '1' : (string) ($value ?? '');
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        foreach ($filters as $filter) {
            $text = match ($filter) {
                'br' => preg_replace('/\r\n|\r|\n/', '<br>', $text),
                'upper' => mb_strtoupper($text),
            };
        }

        return $text;
    }

    private static function isPath(string $path): bool
    {
        return (bool) preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)*$/i', $path);
    }

    private static function snippet(string $text): string
    {
        return '„'.mb_substr(trim($text), 0, 60).'“';
    }
}
