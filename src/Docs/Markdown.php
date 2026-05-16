<?php

declare(strict_types=1);

namespace App\Docs;

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

/**
 * A small, dependency-free Markdown renderer that emits a use-php
 * Element tree rather than an HTML string.
 *
 * Why a tree and not HTML: use-php escapes every text node when it
 * renders, so an HTML string would show up as literal `&lt;h1&gt;`.
 * Building H elements means the framework escapes the *text* (correct,
 * XSS-safe) while the structure stays real markup. We control the docs
 * content, so this covers the GitHub-flavored subset they use:
 * headings, fenced code, lists, tables, blockquotes, rules, and inline
 * emphasis/code/links/images.
 */
final class Markdown
{
    /** @var array<string, int> */
    private array $slugSeen = [];

    public function render(string $markdown): Element
    {
        $this->slugSeen = [];
        $lines = \preg_split('/\r\n|\r|\n/', $markdown) ?: [];

        // No wrapper class: the page wraps this in a Tailwind
        // `prose dark:prose-invert` container, which styles the bare
        // elements (headings, p, ul, code, pre, table, …) for both
        // light and dark mode.
        return H::Fragment($this->blocks($lines));
    }

    /**
     * Heading outline (h2/h3) for the on-page table of contents. Uses
     * the same slug logic as {@see render()} so anchors line up.
     *
     * @return list<array{level: int, text: string, id: string}>
     */
    public function outline(string $markdown): array
    {
        $this->slugSeen = [];
        $lines = \preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $out = [];
        $fence = null;

        foreach ($lines as $line) {
            if (null !== $fence) {
                if (\preg_match('/^\s*' . \preg_quote($fence, '/') . '+\s*$/', $line)) {
                    $fence = null;
                }

                continue;
            }
            if (\preg_match('/^\s*(`{3,}|~{3,})/', $line, $m)) {
                $fence = $m[1][0];

                continue;
            }
            if (\preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/u', $line, $m)) {
                $level = \strlen($m[1]);
                $text = \trim($m[2]);
                $id = $this->uniqueSlug($text);
                if (2 === $level || 3 === $level) {
                    $out[] = ['level' => $level, 'text' => $text, 'id' => $id];
                }
            }
        }

        return $out;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<Element>
     */
    private function blocks(array $lines): array
    {
        $blocks = [];
        $n = \count($lines);
        $i = 0;

        while ($i < $n) {
            $line = $lines[$i];

            // Blank line — skip.
            if ('' === \trim($line)) {
                ++$i;

                continue;
            }

            // Fenced code block.
            if (\preg_match('/^\s*(`{3,}|~{3,})\s*([\w+#.-]*)\s*$/', $line, $m)) {
                $fenceChar = $m[1][0];
                $lang = \strtolower($m[2]);
                $code = [];
                ++$i;
                while ($i < $n && !\preg_match('/^\s*' . $fenceChar . '{3,}\s*$/', $lines[$i])) {
                    $code[] = $lines[$i];
                    ++$i;
                }
                ++$i; // closing fence
                $blocks[] = H::pre(
                    children: H::code(
                        className: '' !== $lang ? 'language-' . $lang : null,
                        children: \implode("\n", $code),
                    ),
                );

                continue;
            }

            // ATX heading.
            if (\preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/u', $line, $m)) {
                $blocks[] = $this->heading(\strlen($m[1]), \trim($m[2]));
                ++$i;

                continue;
            }

            // Horizontal rule.
            if (\preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)) {
                $blocks[] = H::hr();
                ++$i;

                continue;
            }

            // Blockquote (consume the contiguous run, render recursively).
            if (\preg_match('/^\s*>\s?/', $line)) {
                $inner = [];
                while ($i < $n && \preg_match('/^\s*>\s?(.*)$/', $lines[$i], $m)) {
                    $inner[] = $m[1];
                    ++$i;
                }
                $blocks[] = H::blockquote(children: $this->blocks($inner));

                continue;
            }

            // Table (GFM): header row followed by a delimiter row.
            if (
                \str_contains($line, '|')
                && isset($lines[$i + 1])
                && \preg_match('/^\s*\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)+\|?\s*$/', $lines[$i + 1])
            ) {
                [$table, $i] = $this->table($lines, $i, $n);
                $blocks[] = $table;

                continue;
            }

            // List (unordered or ordered).
            if (\preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $line)) {
                [$list, $i] = $this->list($lines, $i, $n, 0);
                $blocks[] = $list;

                continue;
            }

            // Paragraph: gather until a blank line or a block starter.
            $para = [];
            while ($i < $n && '' !== \trim($lines[$i]) && !$this->startsBlock($lines[$i])) {
                $para[] = \trim($lines[$i]);
                ++$i;
            }
            $blocks[] = H::p(children: $this->inline(\implode("\n", $para)));
        }

        return $blocks;
    }

    private function startsBlock(string $line): bool
    {
        return (bool) \preg_match('/^\s*(`{3,}|~{3,}|#{1,6}\s|>\s?|[-*+]\s|\d+[.)]\s)/', $line)
            || (bool) \preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line);
    }

    private function heading(int $level, string $text): Element
    {
        $level = \min(6, \max(1, $level));
        $id = $this->uniqueSlug($text);
        $children = $this->inline($text);
        $children[] = H::a(
            href: '#' . $id,
            className: 'ml-2 no-underline text-slate-300 opacity-0 hover:opacity-100',
            children: '#',
        );

        $method = 'h' . $level;

        return H::$method(id: $id, children: $children);
    }

    /**
     * @param list<string> $lines
     *
     * @return array{0: Element, 1: int}
     */
    private function table(array $lines, int $i, int $n): array
    {
        $header = $this->tableCells($lines[$i]);
        $aligns = $this->tableAligns($lines[$i + 1]);
        $i += 2;

        $bodyRows = [];
        while ($i < $n && '' !== \trim($lines[$i]) && \str_contains($lines[$i], '|')) {
            $bodyRows[] = $this->tableCells($lines[$i]);
            ++$i;
        }

        $headCells = [];
        foreach ($header as $c => $cell) {
            $headCells[] = H::th(
                style: $this->alignStyle($aligns[$c] ?? null),
                children: $this->inline($cell),
            );
        }

        $body = [];
        foreach ($bodyRows as $row) {
            $cells = [];
            foreach ($row as $c => $cell) {
                $cells[] = H::td(
                    style: $this->alignStyle($aligns[$c] ?? null),
                    children: $this->inline($cell),
                );
            }
            $body[] = H::tr(children: $cells);
        }

        return [
            H::div(className: 'overflow-x-auto', children: H::table(children: [
                H::thead(children: H::tr(children: $headCells)),
                H::tbody(children: $body),
            ])),
            $i,
        ];
    }

    /**
     * @return list<string>
     */
    private function tableCells(string $line): array
    {
        $line = \trim($line);
        $line = \preg_replace('/^\||\|$/', '', $line) ?? $line;
        // Split on | that isn't escaped.
        $parts = \preg_split('/(?<!\\\\)\|/', $line) ?: [];

        return \array_map(static fn (string $s): string => \str_replace('\\|', '|', \trim($s)), $parts);
    }

    /**
     * @return list<?string>
     */
    private function tableAligns(string $line): array
    {
        $out = [];
        foreach ($this->tableCells($line) as $spec) {
            $left = \str_starts_with($spec, ':');
            $right = \str_ends_with($spec, ':');
            $out[] = match (true) {
                $left && $right => 'center',
                $right => 'right',
                $left => 'left',
                default => null,
            };
        }

        return $out;
    }

    private function alignStyle(?string $align): ?string
    {
        return null === $align ? null : 'text-align:' . $align;
    }

    /**
     * Indentation-driven list parser. Handles nested lists and ordered
     * vs unordered; each item is a single logical line plus any deeper
     * nested list.
     *
     * @param list<string> $lines
     *
     * @return array{0: Element, 1: int}
     */
    private function list(array $lines, int $i, int $n, int $minIndent): array
    {
        \preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $lines[$i], $m);
        $ordered = (bool) \preg_match('/\d/', $m[2]);
        $baseIndent = \strlen($m[1]);

        $items = [];
        while ($i < $n) {
            $line = $lines[$i];
            if ('' === \trim($line)) {
                ++$i;

                continue;
            }
            if (!\preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $mm)) {
                break;
            }
            $indent = \strlen($mm[1]);
            if ($indent < $baseIndent) {
                break;
            }
            if ($indent > $baseIndent) {
                // Nested list belongs to the previous item.
                [$sub, $i] = $this->list($lines, $i, $n, $indent);
                if ([] !== $items) {
                    $items[\count($items) - 1]['children'][] = $sub;
                }

                continue;
            }

            // Same indent but the marker switched ul<->ol: that's a new
            // list block — stop and let blocks() start a fresh one.
            if ((bool) \preg_match('/\d/', $mm[2]) !== $ordered) {
                break;
            }

            $items[] = ['children' => $this->inline($mm[3])];
            ++$i;
        }

        $lis = \array_map(
            static fn (array $it): Element => H::li(children: $it['children']),
            $items,
        );

        return [$ordered ? H::ol(children: $lis) : H::ul(children: $lis), $i];
    }

    /**
     * Inline parser: code spans, images, links, strong, emphasis. The
     * combined regex returns the leftmost construct; everything else is
     * literal text (escaped later by the renderer).
     *
     * @return list<Element|string>
     */
    private function inline(string $text): array
    {
        if ('' === $text) {
            return [];
        }

        $pattern = '/'
            . '`(?P<code>[^`]+)`'
            . '|!\[(?P<imgalt>[^\]]*)\]\((?P<imgurl>[^)\s]+)(?:\s+"[^"]*")?\)'
            . '|\[(?P<ltext>[^\]]+)\]\((?P<lurl>[^)\s]+)(?:\s+"[^"]*")?\)'
            . '|(?:\*\*|__)(?P<bold>.+?)(?:\*\*|__)'
            . '|(?<![\w*])(?:\*|_)(?P<em>[^*_\s][^*_]*?)(?:\*|_)(?![\w])'
            . '/us';

        $out = [];
        $cursor = 0;
        $len = \strlen($text);

        while ($cursor < $len) {
            if (!\preg_match($pattern, $text, $m, \PREG_OFFSET_CAPTURE, $cursor)) {
                break;
            }

            $start = $m[0][1];
            if ($start > $cursor) {
                $out[] = $this->softBreaks(\substr($text, $cursor, $start - $cursor));
            }

            if ('' !== ($m['code'][0] ?? '') && -1 !== $m['code'][1]) {
                $out[] = H::code(children: $m['code'][0]);
            } elseif (-1 !== ($m['imgurl'][1] ?? -1)) {
                $out[] = H::img(src: $this->safeUrl($m['imgurl'][0]), alt: $m['imgalt'][0] ?? '');
            } elseif (-1 !== ($m['lurl'][1] ?? -1)) {
                $url = $this->safeUrl($m['lurl'][0]);
                $external = (bool) \preg_match('#^https?://#i', $url);
                $out[] = H::a(
                    href: $url,
                    target: $external ? '_blank' : null,
                    rel: $external ? 'noopener noreferrer' : null,
                    children: $this->inline($m['ltext'][0]),
                );
            } elseif (-1 !== ($m['bold'][1] ?? -1)) {
                $out[] = H::strong(children: $this->inline($m['bold'][0]));
            } elseif (-1 !== ($m['em'][1] ?? -1)) {
                $out[] = H::em(children: $this->inline($m['em'][0]));
            }

            $cursor = $m[0][1] + \strlen($m[0][0]);
        }

        if ($cursor < $len) {
            $out[] = $this->softBreaks(\substr($text, $cursor));
        }

        // Flatten the soft-break helper (it may return an array).
        $flat = [];
        foreach ($out as $node) {
            if (\is_array($node)) {
                foreach ($node as $sub) {
                    $flat[] = $sub;
                }
            } else {
                $flat[] = $node;
            }
        }

        return $flat;
    }

    /**
     * Turn newlines inside a paragraph into <br>. Returns a string
     * when there is nothing to break (the common case).
     *
     * @return list<Element|string>|string
     */
    private function softBreaks(string $text): array|string
    {
        if (!\str_contains($text, "\n")) {
            return $text;
        }

        $pieces = \explode("\n", $text);
        $out = [];
        foreach ($pieces as $idx => $piece) {
            if ($idx > 0) {
                $out[] = H::br();
            }
            if ('' !== $piece) {
                $out[] = $piece;
            }
        }

        return $out;
    }

    private function safeUrl(string $url): string
    {
        $url = \trim($url);
        if (\preg_match('#^\s*(javascript|vbscript|data)\s*:#i', $url)) {
            return '#';
        }

        return $url;
    }

    private function uniqueSlug(string $text): string
    {
        $base = $this->slugify($text);
        if (!isset($this->slugSeen[$base])) {
            $this->slugSeen[$base] = 0;

            return $base;
        }

        return $base . '-' . (++$this->slugSeen[$base]);
    }

    private function slugify(string $text): string
    {
        // Drop inline markup before slugging.
        $text = \preg_replace('/[`*_~\[\]()#!]/u', '', $text) ?? $text;
        $text = \mb_strtolower(\trim($text));
        $text = \preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $text) ?? $text;
        $text = \preg_replace('/[\s_]+/u', '-', $text) ?? $text;
        $text = \preg_replace('/-+/', '-', $text) ?? $text;
        $text = \trim($text, '-');

        return '' === $text ? 'section' : $text;
    }
}
