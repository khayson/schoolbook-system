<?php

use App\Services\Reference\ReferenceMapper;

test('textbook level spellings map to the shop levels; Basic is Primary', function (string $printed, ?string $label, ?string $slug) {
    expect(ReferenceMapper::textbookLevel($printed))->toBe([$label, $slug]);
})->with([
    ['Basic 1', 'Basic 1', 'primary-1'],
    ['Basic 6', 'Basic 6', 'primary-6'],
    ['B4', 'Basic 4', 'primary-4'],
    ['KG 1', 'KG 1', 'kg-1'],
    ['KG2', 'KG 2', 'kg-2'],
    ['JHS1', 'JHS 1', 'jhs-1'],
    ['JHS 3', 'JHS 3', 'jhs-3'],
    ['jhs 2', 'JHS 2', 'jhs-2'],
    ['Basic 7', null, null],
    ['JHS 4', null, null],
    ['KG 3', null, null],
    ['', null, null],
    ['JHS 3 SUPPLEMENTARY MATERIALS', null, null],
]);

test('publisher spellings that differ only in case, punctuation, suffix or plural are one publisher', function (string $a, string $b) {
    expect(ReferenceMapper::normalizePublisher($a))->toBe(ReferenceMapper::normalizePublisher($b));
})->with([
    ['Hibiscus Books Ltd', 'Hibiscus Books Limited'],
    ['Volta Publications (GH) Ltd', 'Volta Publications Ghana Limited'],
    ['Lakeside Publications and Stationery Limited', 'Lakeside Publication & Stationeries Ltd'],
    ['KBT Heritage Ltd', 'KBT HERITAGE LIMITED'],
    ['Odum Group Co. Ltd', 'Odum Group'],
    ['Kofi’s Series Enterprise', 'Kofis Series Enterprise'],
]);

test('genuinely different publishers stay different', function (string $a, string $b) {
    expect(ReferenceMapper::normalizePublisher($a))->not->toBe(ReferenceMapper::normalizePublisher($b));
})->with([
    ['Harmattan Press', 'Baobab Publishing'],
    ['Lakeside Publications and Stationeies Ltd', 'Lakeside Publications and Stationery Ltd'], // a typo: suggested, not merged
    ['KBT Heritge Ltd', 'KBT Heritage Ltd'],
]);

test('author and publisher are split at the last slash', function (string $printed, ?string $author, string $publisher, bool $odd) {
    expect(ReferenceMapper::splitAuthor($printed))->toBe([$author, $publisher, $odd]);
})->with([
    ['Harmattan Press', null, 'Harmattan Press', false],
    ['Ama K. Owusu /Odwira Printing Press', 'Ama K. Owusu', 'Odwira Printing Press', false],
    ['Yaw Asare & Esi Mensah /Lakeside Publications', 'Yaw Asare & Esi Mensah', 'Lakeside Publications', false],
    ['/Kwame Boateng Sunrise Book Services', null, 'Kwame Boateng Sunrise Book Services', true],
    ['Abena Ofori/', null, 'Abena Ofori', true],
]);

test('search titles ignore case, accents, punctuation and apostrophes', function () {
    expect(ReferenceMapper::searchTitle('Le Français Facile'))->toBe('le francais facile')
        ->and(ReferenceMapper::searchTitle('Learner’s Book – Maths & Beyond!'))->toBe('learners book maths and beyond');
});

test('a supplementary title names a level only when it is the one level inside its band', function (string $title, string $band, ?string $slug) {
    expect(ReferenceMapper::inferLevel(ReferenceMapper::ascii($title), $band))->toBe($slug);
})->with([
    ['Counting Fun for Kindergarten 2', 'kg', 'kg-2'],
    ['Sound It Out Workbook for KG 1', 'kg', 'kg-1'],
    ['Our Story for Basic Schools - Basic 3', 'lower_primary', 'primary-3'],
    ['Sums for Primary 4C', 'upper_primary', null],              // "4C" is not a level
    ['Number Activity Book 4-Term 1', 'upper_primary', null],     // "Book 4" is not a level
    ['Science JHS 2', 'lower_primary', null],                   // outside the band
    ['Bridging Basic 1 and Basic 2', 'lower_primary', null],    // two levels
]);

test('subjects and languages guessed from title keywords', function (string $title, ?string $subject, ?string $language) {
    $ascii = ReferenceMapper::ascii($title);
    expect(ReferenceMapper::inferSubject($ascii))->toBe($subject)
        ->and(ReferenceMapper::inferLanguage($ascii))->toBe($language);
})->with([
    ['Sound It Out Phonics for KG 1', 'english-language', null],
    ['Early Numeracy Skills Book 1', 'mathematics', null],
    ['Lakeside Series – Ghanaian Language – Asante Twi Book 5', 'ghanaian-language', 'tw-as'],
    ['Mfantse Kasa for KG', 'ghanaian-language', 'fante'],
    ['Phonique pour les Petits', 'french', 'fr'],
    ['My French Friends 5', 'french', 'fr'],
    ['Creative Activities for Nursery 1', 'creative-arts', null],
    ['Physics in Daily Life', 'science', null],
    ['The Clever Tortoise', null, null],
    ['Gaming Gadgets', null, null], // "ga" only as a whole word
]);
