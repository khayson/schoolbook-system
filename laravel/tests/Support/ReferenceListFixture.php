<?php

namespace Tests\Support;

use App\Services\Reference\PositionedChunk;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Synthetic approved list laid out with the real NaCCA PDF's geometry (x/y of each
 * text run): invented titles and publishers only. The real list is copyrighted and is
 * never committed; see docs/reference-catalog.md.
 *
 * Textbook table: serial x 62.4, title 97.6, level 342.8, publisher 405.7.
 * Supplementary table: serial 62.4, title 97.6, publisher 374.1.
 */
final class ReferenceListFixture
{
    /** @var list<PositionedChunk> */
    private array $chunks = [];

    private int $page = 0;

    private float $y = 0;

    public function page(): self
    {
        $this->page++;
        $this->y = 775.0;
        // Page furniture the parser must ignore.
        $this->add(522.6, 51.7, 'Page | ');
        $this->add(554.3, 51.7, (string) $this->page);
        $this->add(56.6, 796.1, ' ');

        return $this;
    }

    public function heading(string $text, float $x = 56.6): self
    {
        $this->add($x, $this->y, $text);
        $this->add($x + strlen($text) * 5.5, $this->y, ' ');
        $this->y -= 13.0;

        return $this;
    }

    public function textbookHeader(): self
    {
        $this->headerRow([65.9 => 'S/N', 83.2 => ' ', 162.3 => 'TITLE OF MATERIAL', 267.2 => ' ', 352.8 => 'LEVEL', 384.9 => ' ', 425.6 => 'AUTHOR/PUBLISHER', 534.2 => ' ']);

        return $this;
    }

    public function supplementHeader(): self
    {
        $this->headerRow([65.9 => 'S/N', 83.2 => ' ', 178.0 => 'TITLE OF MATERIAL', 282.9 => ' ', 409.9 => 'AUTHOR/PUBLISHER', 518.5 => ' ']);

        return $this;
    }

    /**
     * A textbook row. Wrapped cells are given as several lines; like the real list, the
     * serial sits on the row's top line and wrapped titles are vertically centred.
     *
     * @param  string|list<string>  $title
     * @param  string|list<string>  $publisher
     */
    public function textbook(string $serial, string|array $title, ?string $level, string|array $publisher): self
    {
        return $this->row($serial, (array) $title, $level, (array) $publisher, 342.8, 405.7);
    }

    /**
     * @param  string|list<string>  $title
     * @param  string|list<string>  $publisher
     */
    public function supplement(string $serial, string|array $title, string|array $publisher): self
    {
        return $this->row($serial, (array) $title, null, (array) $publisher, null, 374.1);
    }

    /** A numbered row with nothing in it (the real list has two). */
    public function blank(string $serial): self
    {
        $this->add(62.4, $this->y, $serial.'.');
        $this->add(69.6, $this->y, ' ');
        $this->y -= 13.0;

        return $this;
    }

    /** Text that belongs to the previous row but was printed at the top of the next page. */
    public function continuation(string $text, float $x): self
    {
        $this->add($x, $this->y, $text);
        $this->y -= 12.5;

        return $this;
    }

    /**
     * Statistics table line (section 2): label then count.
     */
    public function statistic(string $label, int $count): self
    {
        $this->add(56.6, $this->y, $label.' ');
        $this->add(400.0, $this->y, (string) $count);
        $this->y -= 13.0;

        return $this;
    }

    /**
     * @return list<PositionedChunk>
     */
    public function chunks(): array
    {
        // A PDF does not deliver runs in reading order; neither does the fixture.
        $chunks = $this->chunks;
        usort($chunks, fn (PositionedChunk $a, PositionedChunk $b) => [$a->page, crc32($a->text.$a->x)] <=> [$b->page, crc32($b->text.$b->x)]);

        return $chunks;
    }

    /**
     * Renders the fixture to a real PDF, each text run absolutely positioned at its
     * coordinates, so the extractor and parser can be tested end to end.
     */
    public function toPdf(string $path): string
    {
        $pages = [];
        foreach ($this->chunks as $chunk) {
            if (trim($chunk->text) === '') {
                continue;
            }
            // PDF y counts from the bottom, CSS top from the top; 7pt ≈ ascent of 8pt text.
            $pages[$chunk->page][] = sprintf(
                '<span style="position:absolute; left:%.1fpt; top:%.1fpt; white-space:nowrap;">%s</span>',
                $chunk->x, 842 - $chunk->y - 7, e($chunk->text),
            );
        }
        ksort($pages);
        $html = '<html><head><style>@page { margin: 0; } body { margin: 0; font-family: DejaVu Sans; font-size: 8pt; }'
            .' .page { position: relative; width: 595pt; height: 841pt; page-break-after: always; } .page:last-child { page-break-after: auto; }</style></head><body>'
            .implode('', array_map(fn (array $spans) => '<div class="page">'.implode('', $spans).'</div>', $pages))
            .'</body></html>';

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        Pdf::loadHTML($html)->setPaper('a4')->save($path);

        return $path;
    }

    /**
     * The fixture used by the parser, staging and publishing tests: 14 numbered rows
     * (one blank), two textbook subjects, a level band, a reader, page furniture,
     * a centred section heading, a wrapped heading, wrapped cells, level spelling
     * variants, a duplicate, a row continued on the next page, publisher spelling
     * variants and the statistics tables.
     */
    public static function standard(): self
    {
        return (new self)
            ->page()
            ->heading('2.1 SUMMARY OF RECOMMENDED TEXTBOOKS (KG -JHS).')
            ->heading('SUBJECT NUMBER OF BOOKS')
            ->statistic('Numeracy and Mathematics', 5)
            ->statistic('Science', 3)
            ->heading('2.2 SUMMARY OF RECOMMENDED SUPPLEMENTARY MATERIALS.')
            ->statistic('Subject-based materials', 4)
            ->statistic('Readers (Story books)', 1)
            ->page()
            ->heading('3.0 LIST OF APPROVED TEXTBOOKS FROM KINDERGARTEN TO JHS', 134.8)
            ->heading('NUMERACY/MATHEMATICS (LEARNER BOOKS,TEACHER')
            ->heading('GUIDES,WORKBOOK)')
            ->textbookHeader()
            ->textbook('1', 'Sunrise Mathematics for Basic Schools', 'Basic 1', 'Sunrise Press Ltd')
            ->textbook('2', 'Sunrise Mathematics for Basic Schools', 'Basic 2', 'Sunrise Press Limited')
            ->textbook('3', ['Lakeside Series Mathematics for Junior High', 'Schools'], 'JHS1', ['Lakeside Publications and', 'Stationery Ltd'])
            ->blank('4')
            ->textbook('5', 'Counting Fun for Kindergarten', 'KG 2', 'Harmattan Books (GH) Ltd')
            ->heading('SCIENCE (LEARNER BOOKS,TEACHER GUIDES)')
            ->textbookHeader()
            ->textbook('1', 'Discover Science', 'Basic 4', 'Baobab Publishing')
            ->textbook('2', 'Discover Science', 'Basic 4', 'Baobab Publishing')
            ->textbook('3', 'Science Around Us', 'JHS 2', 'Kente')
            ->page()
            ->continuation('Educational Press', 405.7)
            ->heading('4.0 – LIST OF APPROVED SUPPLEMENTARY MATERIALS', 166.3)
            ->heading('4.1 SUBJECT-BASED SUPPLEMENTARY MATERIALS')
            ->heading('Lower Primary')
            ->supplementHeader()
            ->supplement('1', 'Number Games Activity Book for Basic 2', ['Ama Owusu & Kofi Asare /Sunrise', 'Press Ltd'])
            ->supplement('2', 'Twi Kasa Workbook 1', 'Odwira Publication')
            ->supplement('3', 'Asante Twi Reader for Primary 3', 'Lakeside Publications and Stationeies Ltd')
            ->supplement('4', 'Fun with Fractions for Primary 2', 'Lakeside Publications & Stationery Limited')
            ->heading('4.2 READERS (STORY BOOKS)')
            ->supplementHeader()
            ->supplement('1', 'The Clever Tortoise', 'Akwaaba Stories');
    }

    /**
     * @param  array<string, string>  $labels  x => text
     */
    private function headerRow(array $labels): void
    {
        foreach ($labels as $x => $text) {
            $this->add((float) $x, $this->y, $text);
        }
        $this->y -= 13.0;
    }

    /**
     * @param  list<string>  $title
     * @param  list<string>  $publisher
     */
    private function row(string $serial, array $title, ?string $level, array $publisher, ?float $levelX, float $publisherX): self
    {
        $top = $this->y;
        $height = max(count($title), count($publisher), 1);
        $titleTop = count($title) < $height ? $top - 6.2 : $top;

        $this->add(62.4, $top, $serial.'.');
        $this->add(69.6, $top, ' ');
        $this->add(80.4, $top, ' ');
        foreach ($title as $i => $line) {
            $this->add(97.6, $titleTop - $i * 12.5, $line);
        }
        if ($level !== null && $levelX !== null) {
            $this->add($levelX, $top - ($height - 1) * 6.25, $level);
        }
        foreach ($publisher as $i => $line) {
            $this->add($publisherX, $top - $i * 12.5, $line);
        }
        $this->y = $top - $height * 12.5 - 0.6;

        return $this;
    }

    private function add(float $x, float $y, string $text): void
    {
        $this->chunks[] = new PositionedChunk($this->page, $x, $y, $text);
    }
}
