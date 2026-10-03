<?php

namespace App\Services\Reference;

/**
 * Turns the positioned text of a NaCCA approved list into rows. Layout (Dec 2024):
 *
 * - Front matter, including the statistics tables of section 2 (kept for comparison).
 * - Section 3, textbooks: a subject heading at the left margin, then a table
 *   "S/N | TITLE OF MATERIAL | LEVEL | AUTHOR/PUBLISHER".
 * - Section 4, supplementary materials (4.1 subject-based, split into level bands such as
 *   "Lower Primary"; 4.2 readers; 4.3 guidance; 4.4 e-learning): "S/N | TITLE | AUTHOR/PUBLISHER".
 *
 * Header labels are centred over their columns, so a column's real left edge is taken
 * from the data: the most common x where text starts between two header labels, per page
 * and table. Each serial number ("12.") starts a row; everything below it up to the next
 * serial, heading or table header belongs to that row (cells are vertically centred, so a
 * title can sit below its serial). Wrapped cells are joined with spaces.
 */
class ReferenceListParser
{
    private const LINE_TOLERANCE = 2.0;

    /** Headings start at the left margin (≈56.6); serial numbers a little further in (≈62). */
    private const MARGIN_MAX_X = 60.0;

    private const SERIAL_MAX_X = 80.0;

    /** Page furniture: "Page | N" sits at y ≈ 52; nothing printed above y ≈ 790. */
    private const FOOTER_MAX_Y = 60.0;

    private const HEADER_MIN_Y = 790.0;

    /**
     * Generous width of one character (pt): a space is only inserted for a gap clearly
     * wider than the text could fill (a missing space run between cells), never for kerning.
     */
    private const CHAR_WIDTH = 6.0;

    private const GAP = 2.0;

    public const LEVEL_TOKEN = '/\b(KG|Basic|B|JHS|Primary|P)\s*([1-9])\b/i';

    private const CATEGORY_BY_SECTION = [
        '4.1' => 'subject_supplement',
        '4.2' => 'reader',
        '4.3' => 'guidance',
        '4.4' => 'elearning',
    ];

    /**
     * @param  list<PositionedChunk>  $chunks
     */
    public function parse(array $chunks): ParsedReferenceList
    {
        $lines = $this->classify($this->lines($chunks));
        $geometry = $this->geometry($lines);

        $rows = [];
        $skipped = [];
        $stated = ['textbook' => [], 'supplementary' => []];

        $bodyStarted = false;
        $category = 'textbook';
        $section = null;
        $pending = [];
        $statsMode = null;
        $statsBuffer = '';
        $current = null;
        $position = 0;

        $finish = function () use (&$current, &$rows, &$skipped, &$position): void {
            if ($current === null) {
                return;
            }
            $row = $this->buildRow($current, $position + 1);
            if ($row === null) {
                $skipped[] = [
                    'page' => $current['page'],
                    'serial' => $current['serial'],
                    'section' => $current['section'],
                    'reason' => 'blank',
                    'raw' => trim($current['serial'].' '.$this->columnText($current['cols']['publisher'])),
                ];
            } else {
                $rows[] = $row;
                $position++;
            }
            $current = null;
        };

        foreach ($lines as $line) {
            $text = $line['text'];

            if (! $bodyStarted) {
                if ($line['kind'] === 'header') {
                    $bodyStarted = true;
                    $section = $this->sectionLabel($pending);
                    $pending = [];

                    continue;
                }
                if (preg_match('/^2\.1\b/', $text)) {
                    [$statsMode, $statsBuffer] = ['textbook', ''];
                } elseif (preg_match('/^2\.2\b/', $text)) {
                    [$statsMode, $statsBuffer] = ['supplementary', ''];
                } elseif (preg_match('/^(\d\.\d+)\b/', $text, $m)) {
                    // The last section heading before the first table decides its category
                    // (in the real list: "3.0 LIST OF APPROVED TEXTBOOKS", after the contents page).
                    $category = $this->categoryFor($m[1]) ?? $category;
                    $statsMode = null;
                    $pending = [];
                } elseif ($statsMode !== null) {
                    if (preg_match('/NUMBER OF|No\. of/i', $text)) {
                        continue;
                    }
                    if (preg_match('/^(.*?)\s*(\d+)\s*$/u', $text, $m)) {
                        $label = $this->squish($statsBuffer.' '.$m[1]);
                        $stated[$statsMode][$label] = (int) $m[2];
                        $statsBuffer = '';
                    } else {
                        $statsBuffer .= ' '.$text;
                    }
                } elseif ($line['kind'] === 'heading') {
                    $pending[] = $text;
                }

                continue;
            }

            switch ($line['kind']) {
                case 'heading':
                    $finish();
                    if (preg_match('/^(\d\.\d+)\b/', $text, $m)) {
                        $category = $this->categoryFor($m[1]) ?? $category;
                        $section = null;
                        $pending = [];
                    } else {
                        $pending[] = $text;
                    }
                    break;

                case 'header':
                    $finish();
                    if ($pending !== []) {
                        $section = $this->sectionLabel($pending);
                        $pending = [];
                    }
                    break;

                case 'serial':
                    $finish();
                    if ($pending !== []) {
                        $section = $this->sectionLabel($pending);
                        $pending = [];
                    }
                    $current = [
                        'page' => $line['page'],
                        'serial' => rtrim(trim($line['chunks'][0]->text), '.'),
                        'category' => $category,
                        'section' => $section,
                        'cols' => ['title' => [], 'level' => [], 'publisher' => []],
                        'pages' => [$line['page'] => true],
                    ];
                    $this->assign($current, array_slice($line['chunks'], 1), $geometry[$line['segment']] ?? null, $category);
                    break;

                default: // data
                    if ($current === null) {
                        break; // stray text between tables (e.g. a caption): not part of any row
                    }
                    $current['pages'][$line['page']] = true;
                    $this->assign($current, $line['chunks'], $geometry[$line['segment']] ?? null, $current['category']);
            }
        }
        $finish();

        return new ParsedReferenceList($rows, $skipped, $stated);
    }

    private function categoryFor(string $sectionNumber): ?string
    {
        return str_starts_with($sectionNumber, '3.') ? 'textbook' : (self::CATEGORY_BY_SECTION[$sectionNumber] ?? null);
    }

    /**
     * Groups chunks into visual lines (page, then top to bottom, then left to right),
     * dropping the page furniture.
     *
     * @param  list<PositionedChunk>  $chunks
     * @return list<array{page: int, y: float, chunks: list<PositionedChunk>}>
     */
    private function lines(array $chunks): array
    {
        $chunks = array_values(array_filter(
            $chunks,
            fn (PositionedChunk $c) => $c->y > self::FOOTER_MAX_Y && $c->y < self::HEADER_MIN_Y,
        ));
        usort($chunks, fn (PositionedChunk $a, PositionedChunk $b) => [$a->page, -$a->y, $a->x] <=> [$b->page, -$b->y, $b->x]);

        $lines = [];
        foreach ($chunks as $chunk) {
            $last = array_key_last($lines);
            if ($last !== null
                && $lines[$last]['page'] === $chunk->page
                && abs($lines[$last]['y'] - $chunk->y) <= self::LINE_TOLERANCE) {
                $lines[$last]['chunks'][] = $chunk;

                continue;
            }
            $lines[] = ['page' => $chunk->page, 'y' => $chunk->y, 'chunks' => [$chunk]];
        }

        foreach ($lines as &$line) {
            usort($line['chunks'], fn (PositionedChunk $a, PositionedChunk $b) => $a->x <=> $b->x);
        }

        return $lines;
    }

    /**
     * @param  list<array{page: int, y: float, chunks: list<PositionedChunk>}>  $lines
     * @return list<array{page: int, y: float, chunks: list<PositionedChunk>, text: string, kind: string, segment: string, header: ?array}>
     */
    private function classify(array $lines): array
    {
        $table = 0;
        $out = [];

        foreach ($lines as $line) {
            $visible = array_values(array_filter($line['chunks'], fn (PositionedChunk $c) => trim($c->text) !== ''));
            if ($visible === []) {
                continue;
            }
            $text = $this->squish($this->join($line['chunks']));
            $first = $visible[0];

            if (preg_match('/^S\/N\b/', $text)) {
                $kind = 'header';
                $table++;
            } elseif ($first->x < self::SERIAL_MAX_X && preg_match('/^\d+\.$/', trim($first->text))) {
                $kind = 'serial';
            } elseif ($first->x < self::MARGIN_MAX_X || preg_match('/^\d\.\d+\s*[–\-]?\s*[A-Z]{3,}/u', $text)) {
                // Section headings ("4.0 – LIST OF APPROVED ...") may be centred rather than at the margin.
                $kind = 'heading';
            } else {
                $kind = 'data';
            }

            $out[] = [
                'page' => $line['page'],
                'y' => $line['y'],
                'chunks' => $kind === 'serial' ? [$first, ...array_values(array_filter($line['chunks'], fn ($c) => $c !== $first))] : $line['chunks'],
                'text' => $text,
                'kind' => $kind,
                'segment' => $line['page'].':'.$table,
                'header' => $kind === 'header' ? $this->headerColumns($line['chunks']) : null,
            ];
        }

        return $out;
    }

    /**
     * Header label spans: start = label x; end estimated from the label's length (about
     * 4.5pt per character at the list's header size), capped at the next run on the line.
     *
     * @param  list<PositionedChunk>  $chunks
     * @return array<string, array{0: float, 1: float}>
     */
    private function headerColumns(array $chunks): array
    {
        $columns = [];
        foreach ($chunks as $i => $chunk) {
            $label = strtoupper(trim($chunk->text));
            $end = $chunk->x + strlen($label) * 4.5;
            if (isset($chunks[$i + 1])) {
                $end = min($end, $chunks[$i + 1]->x);
            }
            match (true) {
                str_starts_with($label, 'TITLE') => $columns['title'] = [$chunk->x, $end],
                str_starts_with($label, 'LEVEL') => $columns['level'] = [$chunk->x, $end],
                str_starts_with($label, 'AUTHOR'), str_starts_with($label, 'PUBLISHER') => $columns['publisher'] = [$chunk->x, $end],
                default => null,
            };
        }

        return $columns;
    }

    /**
     * Column left edges per page and table: the most common start x between two header labels.
     *
     * @return array<string, array{level: ?float, publisher: ?float}>
     */
    private function geometry(array $lines): array
    {
        $headers = [];
        $starts = [];
        $table = 0;
        foreach ($lines as $line) {
            [, $segmentTable] = explode(':', $line['segment']);
            if ($line['kind'] === 'header') {
                $headers[(int) $segmentTable] = $line['header'];
            }
            if ($line['kind'] !== 'serial' && $line['kind'] !== 'data') {
                continue;
            }
            foreach ($line['kind'] === 'serial' ? array_slice($line['chunks'], 1) : $line['chunks'] as $chunk) {
                if (trim($chunk->text) !== '') {
                    $starts[$line['segment']][] = round($chunk->x, 1);
                }
            }
        }

        $geometry = [];
        $previous = [];
        foreach ($starts as $segment => $xs) {
            $table = (int) explode(':', $segment)[1];
            $header = $headers[$table] ?? null;
            if ($header === null || ! isset($header['title'], $header['publisher'])) {
                continue;
            }
            $titleEnd = $header['title'][1];
            $hasLevel = isset($header['level']);

            $level = $hasLevel ? $this->mode($xs, $titleEnd, $header['level'][0] + 0.5) : null;
            $publisher = $this->mode($xs, $hasLevel ? $header['level'][1] : $titleEnd, $header['publisher'][0] + 0.5);

            $geometry[$segment] = [
                'level' => $hasLevel ? ($level ?? $previous[$table]['level'] ?? $header['level'][0] - 10) : null,
                'publisher' => $publisher ?? $previous[$table]['publisher'] ?? $header['publisher'][0] - 20,
            ];
            $previous[$table] = $geometry[$segment];
        }

        return $geometry;
    }

    /**
     * @param  list<float>  $xs
     */
    private function mode(array $xs, float $after, float $upTo): ?float
    {
        $counts = [];
        foreach ($xs as $x) {
            if ($x > $after && $x <= $upTo) {
                $key = number_format($x, 1, '.', '');
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        if ($counts === []) {
            return null;
        }
        $best = max($counts);
        $candidates = array_keys(array_filter($counts, fn (int $n) => $n === $best));
        sort($candidates, SORT_NUMERIC);

        return (float) $candidates[0];
    }

    /**
     * @param  list<PositionedChunk>  $chunks
     */
    private function assign(array &$row, array $chunks, ?array $geometry, string $category): void
    {
        foreach ($chunks as $chunk) {
            $column = 'title';
            if ($geometry !== null && $chunk->x >= $geometry['publisher'] - 1.0) {
                $column = 'publisher';
            } elseif ($geometry !== null && $category === 'textbook' && $geometry['level'] !== null && $chunk->x >= $geometry['level'] - 1.0) {
                $column = 'level';
            }
            $row['cols'][$column][] = $chunk;
            $row['pages'][$chunk->page] = true;
        }
    }

    private function buildRow(array $row, int $position): ?ParsedReferenceRow
    {
        $title = $this->columnText($row['cols']['title']);
        $level = $this->columnText($row['cols']['level']);
        $publisher = $this->columnText($row['cols']['publisher']);
        $notes = [];

        if ($title === '') {
            return null;
        }

        if ($row['category'] === 'textbook' && $level === '' && preg_match_all(self::LEVEL_TOKEN, $title, $m, PREG_OFFSET_CAPTURE)) {
            // The level was printed in the same run as the title: take the last level token.
            $lastMatch = end($m[0]);
            $level = $lastMatch[0];
            $title = $this->squish(substr($title, 0, $lastMatch[1]));
            $notes[] = 'level_from_title';
        }

        if (count($row['pages']) > 1) {
            $notes[] = 'continued_on_next_page';
        }

        return new ParsedReferenceRow(
            page: $row['page'],
            position: $position,
            serial: $row['serial'],
            category: $row['category'],
            section: $row['section'],
            title: $title,
            level: $level === '' ? null : $level,
            publisher: $publisher,
            raw: $this->squish(implode(' | ', array_filter([$row['serial'].'.', $title, $level, $publisher], fn ($p) => $p !== ''))),
            notes: $notes,
        );
    }

    /**
     * @param  list<PositionedChunk>  $chunks
     */
    private function columnText(array $chunks): string
    {
        usort($chunks, fn (PositionedChunk $a, PositionedChunk $b) => [$a->page, -round($a->y), $a->x] <=> [$b->page, -round($b->y), $b->x]);

        $lines = [];
        $lastY = null;
        $lastPage = null;
        foreach ($chunks as $chunk) {
            if ($lastY === null || $chunk->page !== $lastPage || abs($lastY - $chunk->y) > self::LINE_TOLERANCE) {
                $lines[] = [];
                $lastY = $chunk->y;
                $lastPage = $chunk->page;
            }
            $lines[array_key_last($lines)][] = $chunk;
        }

        // Cell text: runs concatenated as printed (the list's own space runs separate words;
        // a gap rule here would split words set in two fonts, e.g. "Mm" + "ɔfra").
        return $this->squish(implode(' ', array_map(fn (array $line) => implode('', array_map(fn (PositionedChunk $c) => $c->text, $line)), $lines)));
    }

    /**
     * Joins the runs of a header or heading line (left to right). Some PDFs split words into several runs
     * ("E", "n", "drose"), others put no space run between cells: a space is added only
     * where the gap is wider than the previous run's text could fill.
     *
     * @param  list<PositionedChunk>  $chunks
     */
    private function join(array $chunks): string
    {
        $text = '';
        $previous = null;
        foreach ($chunks as $chunk) {
            if ($previous !== null
                && $chunk->x - ($previous->x + mb_strlen($previous->text) * self::CHAR_WIDTH) > self::GAP
                && ! str_ends_with($text, ' ') && ! str_starts_with($chunk->text, ' ')) {
                $text .= ' ';
            }
            $text .= $chunk->text;
            $previous = $chunk;
        }

        return $text;
    }

    /**
     * @param  list<string>  $pending
     */
    private function sectionLabel(array $pending): ?string
    {
        $label = $this->squish(implode(' ', $pending));
        // "NUMERACY/MATHEMATICS (LEARNER BOOKS,TEACHER GUIDES,WORKBOOK)" -> "NUMERACY/MATHEMATICS"
        $label = $this->squish((string) preg_replace('/\s*\(.*$/s', '', $label));

        return $label === '' ? null : $label;
    }

    private function squish(string $text): string
    {
        $text = str_replace(["\u{00A0}", "\t"], ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
