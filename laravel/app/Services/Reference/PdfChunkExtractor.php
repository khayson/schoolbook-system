<?php

namespace App\Services\Reference;

use Smalot\PdfParser\Parser;

/**
 * Reads positioned text runs from a PDF. Column positions are what make the NaCCA
 * table parseable: the plain text loses the separation between title, level and
 * publisher on about a quarter of the rows.
 */
class PdfChunkExtractor
{
    /**
     * @return list<PositionedChunk>
     */
    public function extract(string $path): array
    {
        $document = (new Parser)->parseFile($path);
        $chunks = [];

        foreach ($document->getPages() as $index => $page) {
            foreach ($page->getDataTm() as [$matrix, $text]) {
                $chunks[] = new PositionedChunk($index + 1, (float) $matrix[4], (float) $matrix[5], (string) $text);
            }
        }

        return $chunks;
    }
}
