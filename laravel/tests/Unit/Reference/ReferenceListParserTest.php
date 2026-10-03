<?php

use App\Services\Reference\ParsedReferenceList;
use App\Services\Reference\ParsedReferenceRow;
use App\Services\Reference\PositionedChunk;
use App\Services\Reference\ReferenceListParser;
use Tests\Support\ReferenceListFixture;

function parseFixture(?ReferenceListFixture $fixture = null): ParsedReferenceList
{
    return (new ReferenceListParser)->parse(($fixture ?? ReferenceListFixture::standard())->chunks());
}

/**
 * @return list<array{0: string, 1: ?string, 2: string, 3: string, 4: ?string, 5: string}>
 */
function rowSummary(array $rows): array
{
    return array_map(fn (ParsedReferenceRow $r) => [$r->category, $r->section, $r->serial, $r->title, $r->level, $r->publisher], $rows);
}

test('rows are split into title, level and publisher by column position, with wrapped cells joined', function () {
    expect(rowSummary(parseFixture()->rows))->toBe([
        ['textbook', 'NUMERACY/MATHEMATICS', '1', 'Sunrise Mathematics for Basic Schools', 'Basic 1', 'Sunrise Press Ltd'],
        ['textbook', 'NUMERACY/MATHEMATICS', '2', 'Sunrise Mathematics for Basic Schools', 'Basic 2', 'Sunrise Press Limited'],
        ['textbook', 'NUMERACY/MATHEMATICS', '3', 'Lakeside Series Mathematics for Junior High Schools', 'JHS1', 'Lakeside Publications and Stationery Ltd'],
        ['textbook', 'NUMERACY/MATHEMATICS', '5', 'Counting Fun for Kindergarten', 'KG 2', 'Harmattan Books (GH) Ltd'],
        ['textbook', 'SCIENCE', '1', 'Discover Science', 'Basic 4', 'Baobab Publishing'],
        ['textbook', 'SCIENCE', '2', 'Discover Science', 'Basic 4', 'Baobab Publishing'],
        ['textbook', 'SCIENCE', '3', 'Science Around Us', 'JHS 2', 'Kente Educational Press'],
        ['subject_supplement', 'Lower Primary', '1', 'Number Games Activity Book for Basic 2', null, 'Ama Owusu & Kofi Asare /Sunrise Press Ltd'],
        ['subject_supplement', 'Lower Primary', '2', 'Twi Kasa Workbook 1', null, 'Odwira Publication'],
        ['subject_supplement', 'Lower Primary', '3', 'Asante Twi Reader for Primary 3', null, 'Lakeside Publications and Stationeies Ltd'],
        ['subject_supplement', 'Lower Primary', '4', 'Fun with Fractions for Primary 2', null, 'Lakeside Publications & Stationery Limited'],
        ['reader', null, '1', 'The Clever Tortoise', null, 'Akwaaba Stories'],
    ]);
});

test('a blank numbered row is skipped and reported with its page and section', function () {
    expect(parseFixture()->skipped)->toBe([
        ['page' => 2, 'serial' => '4', 'section' => 'NUMERACY/MATHEMATICS', 'reason' => 'blank', 'raw' => '4'],
    ]);
});

test('page furniture is ignored and a centred section heading does not join the previous row', function () {
    $list = parseFixture();
    $all = collect($list->rows)->map(fn (ParsedReferenceRow $r) => $r->raw)->implode("\n");

    expect($all)->not->toContain('Page |')
        ->and($all)->not->toContain('SUPPLEMENTARY MATERIALS')
        ->and($all)->not->toContain('3.0 LIST');

    // The publisher continued on the next page is joined, and flagged for a look.
    $continued = collect($list->rows)->firstWhere('title', 'Science Around Us');
    expect($continued->publisher)->toBe('Kente Educational Press')
        ->and($continued->notes)->toBe(['continued_on_next_page'])
        ->and($continued->page)->toBe(2);
});

test('the statistics tables printed in the list are captured for comparison', function () {
    expect(parseFixture()->statedCounts)->toBe([
        'textbook' => ['Numeracy and Mathematics' => 5, 'Science' => 3],
        'supplementary' => ['Subject-based materials' => 4, 'Readers (Story books)' => 1],
    ]);
});

test('positions number the parsed rows in list order', function () {
    expect(array_map(fn (ParsedReferenceRow $r) => $r->position, parseFixture()->rows))->toBe(range(1, 12));
});

test('a level printed in the same run as the title is taken from the title end and flagged', function () {
    $fixture = (new ReferenceListFixture)->page()
        ->heading('3.0 LIST OF APPROVED TEXTBOOKS', 134.8)
        ->heading('NUMERACY/MATHEMATICS (LEARNER BOOKS)')
        ->textbookHeader()
        ->textbook('1', 'Algebra Made Easy for Basic 6 Learners JHS 3', null, 'Volta Press');

    $row = parseFixture($fixture)->rows[0];

    expect($row->title)->toBe('Algebra Made Easy for Basic 6 Learners')
        ->and($row->level)->toBe('JHS 3')
        ->and($row->notes)->toBe(['level_from_title']);
});

test('column edges come from the data on each page, not from the centred header labels', function () {
    // Same table, but this page's publisher column starts 8pt further left than the
    // header suggests: still split correctly.
    $fixture = (new ReferenceListFixture)->page()
        ->heading('3.0 LIST OF APPROVED TEXTBOOKS', 134.8)
        ->heading('SCIENCE (LEARNER BOOKS)')
        ->textbookHeader()
        ->textbook('1', 'Plants and Animals', 'Basic 3', 'Forest Press')
        ->textbook('2', 'Weather Watch', 'Basic 3', 'Forest Press');
    $shifted = array_map(
        fn ($c) => abs($c->x - 405.7) < 0.01 ? new PositionedChunk($c->page, 397.5, $c->y, $c->text) : $c,
        $fixture->chunks(),
    );

    expect(rowSummary((new ReferenceListParser)->parse($shifted)->rows))->toBe([
        ['textbook', 'SCIENCE', '1', 'Plants and Animals', 'Basic 3', 'Forest Press'],
        ['textbook', 'SCIENCE', '2', 'Weather Watch', 'Basic 3', 'Forest Press'],
    ]);
});

test('an empty document parses to nothing', function () {
    expect(parseFixture(new ReferenceListFixture)->rows)->toBe([]);
});
