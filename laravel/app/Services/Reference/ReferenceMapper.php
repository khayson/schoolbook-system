<?php

namespace App\Services\Reference;

use App\Models\Language;
use App\Models\Level;
use App\Models\Publisher;
use App\Models\PublisherAlias;
use App\Models\Subject;
use Illuminate\Support\Str;

/**
 * Maps a parsed row onto the shop's catalog terms: levels (Basic 1-6 = Primary 1-6),
 * level bands for supplementary materials, subjects, languages and publishers. Rules are
 * documented in docs/reference-catalog.md; change both together.
 *
 * Issues are review items: severity "error" blocks accepting the row until it is fixed or
 * excluded; "warning" needs a look but can be accepted. Keyword guesses do not raise an
 * issue; they set confidence "low" so the review page can filter them.
 */
class ReferenceMapper
{
    /** Supplementary 4.1 level-band headings. */
    public const BANDS = [
        'creche/nursery/kindergarten' => 'kg',
        'lower primary' => 'lower_primary',
        'upper primary' => 'upper_primary',
        'junior high school' => 'jhs',
        'senior high school' => 'shs',
    ];

    public const BAND_LEVELS = [
        'kg' => ['creche', 'kg-1', 'kg-2'],
        'lower_primary' => ['primary-1', 'primary-2', 'primary-3'],
        'upper_primary' => ['primary-4', 'primary-5', 'primary-6'],
        'jhs' => ['jhs-1', 'jhs-2', 'jhs-3'],
        'shs' => [],
    ];

    /** Textbook section headings (section 3) => [subject slug, name used if it must be created]. */
    public const TEXTBOOK_SUBJECTS = [
        'language and literacy/english language' => ['english-language', 'English Language'],
        'numeracy/mathematics' => ['mathematics', 'Mathematics'],
        'science' => ['science', 'Science'],
        'creative arts/creative arts and design' => ['creative-arts', 'Creative Arts'],
        'ghanaian languages' => ['ghanaian-language', 'Ghanaian Language'],
        'history of ghana' => ['history', 'History'],
        'our world and our people' => ['our-world-our-people', 'Our World Our People'],
        'religious and moral education' => ['rme', 'RME'],
        'computing' => ['computingict', 'Computing/ICT'],
        'french' => ['french', 'French'],
        'physical education/physical education and health' => ['physical-education-and-health', 'Physical Education and Health'],
        'career technology' => ['career-technology', 'Career Technology'],
    ];

    /** Keyword rules for supplementary titles, first match wins (matched on lower-case ASCII). */
    public const SUBJECT_KEYWORDS = [
        'french' => '/\b(french|francais|phonique)\b|\bpour les\b/',
        'ghanaian-language' => '/\b(twi|fante|fanti|mfantse|ewe|dagbani|dagaare|gonja|nzema|kasem|dangme|ghanaian language)\b/',
        'computingict' => '/\b(computing|computer|computers|ict|coding|robotics|digital)\b/',
        'history' => '/\bhistory\b/',
        'our-world-our-people' => '/\bour world\b|\bowop\b/',
        'rme' => '/\breligious\b|\bmoral\b|\brme\b/',
        'career-technology' => '/\bcareer tech/',
        'social-studies' => '/\bsocial studies\b/',
        'creative-arts' => '/\bcreative (arts?|activit)|\bdrawing\b|\bcolou?ring\b|\bcrafts?\b|\bmusic\b|\bmosaic\b|\bcollage\b/',
        'science' => '/\bscience\b|\bphysics\b|\bchemistry\b|\bbiology\b|\bagric/',
        'mathematics' => '/\bmaths?\b|\bmathematics\b|\bnumeracy\b|\bnumbers?\b|\barithmetic\b|\bfractions?\b|\bcounting\b|\balgebra\b|\btrigonometry\b|\bpythagoras\b/',
        'english-language' => '/\benglish\b|\bphonics?\b|\bliteracy\b|\bread(ing)?\b|\bwrit(e|ing)\b|\bhandwriting\b|\bpenmanship\b|\bcopy ?(book|writing)\b|\bgrammar\b|\bspelling\b|\bcomprehension\b|\bvocabulary\b|\balphabet\b|\bletters?\b|\bsounds?\b|\blanguage activit/',
    ];

    /** Language keywords => language code. "Twi" alone is ambiguous (Asante or Akuapem). */
    public const LANGUAGE_KEYWORDS = [
        'fr' => '/\b(french|francais|phonique)\b|\bpour les\b/',
        'tw-as' => '/\basante\b|\bashanti\b/',
        'tw-ak' => '/\bakuapem\b|\bakwapim\b/',
        'fante' => '/\bfante\b|\bfanti\b|\bmfantse\b/',
        'ewe' => '/\bewe\b/',
        'dagbani' => '/\bdagbani\b/',
        'dagaare' => '/\bdagaare\b/',
        'gonja' => '/\bgonja\b/',
        'nzema' => '/\bnzema\b/',
        'kasem' => '/\bkasem\b/',
        'ga-dangme' => '/\bdangme\b/',
        'ga' => '/\bga\b/',
    ];

    /** Dropped when comparing publisher names ("Hibiscus Books Ltd" = "Hibiscus Books Limited"). */
    private const PUBLISHER_NOISE = ['ltd', 'limited', 'company', 'co', 'plc', 'inc', 'gh', 'ghana', 'the'];

    /** @var array<string, int> */
    private array $levels;

    /** @var array<string, int> */
    private array $subjects;

    /** @var array<string, int> */
    private array $languages;

    /** @var array<string, int> normalized spelling => publisher id */
    private array $publishers;

    public function __construct()
    {
        $this->levels = Level::query()->pluck('id', 'slug')->all();
        $this->subjects = Subject::query()->pluck('id', 'slug')->all();
        $this->languages = Language::query()->pluck('id', 'code')->all();

        $this->publishers = [];
        foreach (Publisher::query()->get(['id', 'name']) as $publisher) {
            $this->publishers[self::normalizePublisher($publisher->name)] ??= $publisher->id;
        }
        foreach (PublisherAlias::query()->get(['normalized_alias', 'publisher_id']) as $alias) {
            $this->publishers[$alias->normalized_alias] = $alias->publisher_id;
        }
    }

    /**
     * @return array<string, mixed> import-row attributes (without diff fields), plus search_title
     */
    public function map(ParsedReferenceRow $row): array
    {
        $issues = array_map(fn (string $note) => self::issue($note), $row->notes);
        $confidence = 'high';
        $ascii = self::ascii($row->title);

        // Level / band
        $levelLabel = null;
        $levelId = null;
        $band = null;
        if ($row->category === 'textbook') {
            [$levelLabel, $slug] = self::textbookLevel((string) $row->level);
            $levelId = $slug !== null ? ($this->levels[$slug] ?? null) : null;
            if ($levelId === null) {
                $issues[] = self::issue('unknown_level', ['level' => $row->level]);
                $levelLabel = $row->level === null ? null : Str::limit($row->level, 47);
            }
        } elseif ($row->category === 'subject_supplement') {
            $levelLabel = $row->section;
            $band = self::BANDS[Str::lower((string) $row->section)] ?? null;
            if ($band === null) {
                $issues[] = self::issue('unknown_band', ['section' => $row->section]);
            } elseif (($slug = self::inferLevel($ascii, $band)) !== null) {
                $levelId = $this->levels[$slug] ?? null;
                $confidence = 'low';
            }
        }

        // Subject
        $subjectLabel = $row->section;
        $subjectId = null;
        if ($row->category === 'textbook') {
            $mapped = self::TEXTBOOK_SUBJECTS[Str::lower((string) $row->section)] ?? null;
            if ($mapped === null) {
                $issues[] = self::issue('unknown_subject', ['section' => $row->section]);
            } else {
                $subjectLabel = $mapped[1];
                $subjectId = $this->subjects[$mapped[0]] ?? null;
                if ($subjectId === null) {
                    $issues[] = self::issue('subject_missing', ['slug' => $mapped[0], 'name' => $mapped[1]]);
                }
            }
        } else {
            $slug = self::inferSubject($ascii);
            if ($slug === null && $row->category === 'reader') {
                $slug = 'english-language';
            }
            $subjectLabel = null;
            if ($slug !== null) {
                $subjectId = $this->subjects[$slug] ?? null;
                $subjectLabel = $subjectId === null ? $slug : null;
                $confidence = 'low';
            } elseif ($row->category === 'subject_supplement') {
                $issues[] = self::issue('subject_unknown');
            }
        }

        // Language
        // French textbooks: from the section. Otherwise a language named in the title (a
        // keyword guess, so low confidence), else English. Ghanaian-language material whose
        // language cannot be told is left blank for review.
        $frenchSection = $row->category === 'textbook' && Str::lower((string) $row->section) === 'french';
        $languageCode = $frenchSection ? 'fr' : self::inferLanguage($ascii);
        $ghanaianLanguage = Str::lower((string) $row->section) === 'ghanaian languages'
            || preg_match(self::SUBJECT_KEYWORDS['ghanaian-language'], $ascii) === 1;
        if ($languageCode === null && $ghanaianLanguage) {
            $issues[] = self::issue('language_unknown');
        } elseif ($languageCode === null) {
            $languageCode = 'en';
        } elseif (! $frenchSection) {
            $confidence = 'low';
        }
        $languageId = $languageCode !== null ? ($this->languages[$languageCode] ?? null) : null;

        // Author / publisher
        [$author, $publisher, $separatorIssue] = self::splitAuthor($row->publisher);
        if ($separatorIssue) {
            $issues[] = self::issue('publisher_check');
        }
        if ($publisher === '') {
            $issues[] = self::issue('missing_publisher');
        }

        $searchTitle = self::searchTitle($row->title);
        $publisherId = $publisher === '' ? null : ($this->publishers[self::normalizePublisher($publisher)] ?? null);

        return [
            'page' => $row->page,
            'position' => $row->position,
            'raw_text' => $row->raw,
            'category' => $row->category,
            'source_serial' => Str::limit($row->serial, 20, ''),
            'title' => Str::limit($row->title, 500, ''),
            'search_title' => $searchTitle,
            'level_label' => $levelLabel,
            'level_id' => $levelId,
            'band' => $band,
            'subject_label' => $subjectLabel,
            'subject_id' => $subjectId,
            'language_id' => $languageId,
            'author' => $author === null ? null : Str::limit($author, 300, ''),
            'publisher_label' => Str::limit($publisher, 255, ''),
            'publisher_id' => $publisherId,
            'confidence' => $confidence,
            'issues' => $issues,
        ];
    }

    public function publisherIdFor(string $label): ?int
    {
        return $this->publishers[self::normalizePublisher($label)] ?? null;
    }

    /**
     * Identity of a title across editions: category, normalized title, level (or band),
     * publisher (id once known, so renaming a publisher does not change it).
     */
    public static function naturalKey(string $category, string $searchTitle, ?int $levelId, ?string $band, ?string $levelLabel, ?int $publisherId, string $publisherLabel): string
    {
        $level = match (true) {
            $levelId !== null => 'l'.$levelId,
            $band !== null => 'b:'.$band,
            default => 'x:'.self::ascii((string) $levelLabel),
        };
        $publisher = $publisherId !== null ? 'p'.$publisherId : 'n:'.self::normalizePublisher($publisherLabel);

        return sha1(implode('|', [$category, $searchTitle, $level, $publisher]));
    }

    /**
     * @return array{0: ?string, 1: ?string} [display label, level slug]
     */
    public static function textbookLevel(string $label): array
    {
        if (! preg_match('/^\s*(KG|Basic|B|JHS|Primary|P)\s*([1-9])\s*$/i', $label, $m)) {
            return [null, null];
        }
        $n = (int) $m[2];

        return match (Str::upper($m[1])) {
            'KG' => $n <= 2 ? ['KG '.$n, 'kg-'.$n] : [null, null],
            'JHS' => $n <= 3 ? ['JHS '.$n, 'jhs-'.$n] : [null, null],
            default => $n <= 6 ? ['Basic '.$n, 'primary-'.$n] : [null, null],
        };
    }

    /**
     * A supplementary title naming exactly one level inside its band ("... for Kindergarten 2").
     */
    public static function inferLevel(string $asciiTitle, string $band): ?string
    {
        $found = [];
        $patterns = [
            'kg-' => '/\b(?:kg|kindergarten)\s*([12])(?![0-9a-z])/',
            'primary-' => '/\b(?:basic|primary|class|b|p)\s*([1-6])(?![0-9a-z])/',
            'jhs-' => '/\b(?:jhs|junior high school)\s*([1-3])(?![0-9a-z])/',
        ];
        foreach ($patterns as $prefix => $pattern) {
            if (preg_match_all($pattern, $asciiTitle, $m)) {
                foreach ($m[1] as $n) {
                    $found[$prefix.$n] = true;
                }
            }
        }
        $found = array_keys($found);

        return count($found) === 1 && in_array($found[0], self::BAND_LEVELS[$band] ?? [], true) ? $found[0] : null;
    }

    public static function inferSubject(string $asciiTitle): ?string
    {
        foreach (self::SUBJECT_KEYWORDS as $slug => $pattern) {
            if (preg_match($pattern, $asciiTitle)) {
                return $slug;
            }
        }

        return null;
    }

    public static function inferLanguage(string $asciiTitle): ?string
    {
        foreach (self::LANGUAGE_KEYWORDS as $code => $pattern) {
            if (preg_match($pattern, $asciiTitle)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * "Yaw Asare & Esi Mensah /Lakeside Publications" => [authors, publisher].
     *
     * @return array{0: ?string, 1: string, 2: bool} [author, publisher, odd separator]
     */
    public static function splitAuthor(string $label): array
    {
        $label = self::squish($label);
        if (! str_contains($label, '/')) {
            return [null, $label, false];
        }
        $at = strrpos($label, '/');
        $author = self::squish(substr($label, 0, $at));
        $publisher = self::squish(substr($label, $at + 1));

        return match (true) {
            $author === '' => [null, $publisher, true],
            $publisher === '' => [null, $author, true],
            default => [$author, $publisher, false],
        };
    }

    public static function searchTitle(string $title): string
    {
        $text = str_replace('&', ' and ', self::ascii($title));

        return self::squish((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /**
     * Spelling-insensitive publisher form: case, punctuation, "&"/"and", company suffixes
     * and simple plurals ignored. "Lakeside Publications and Stationeries Ltd" and
     * "Lakeside Publication & Stationery Limited" are the same publisher.
     */
    public static function normalizePublisher(string $name): string
    {
        $text = str_replace('&', ' and ', self::ascii($name));
        $tokens = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_filter($tokens, fn (string $t) => ! in_array($t, self::PUBLISHER_NOISE, true));
        $tokens = array_map(function (string $t): string {
            if (strlen($t) > 4 && str_ends_with($t, 'ies')) {
                return substr($t, 0, -3).'y';
            }
            if (strlen($t) > 3 && str_ends_with($t, 's') && ! str_ends_with($t, 'ss')) {
                return substr($t, 0, -1);
            }

            return $t;
        }, $tokens);

        return implode(' ', $tokens);
    }

    /**
     * Lower-case ASCII with apostrophes dropped ("Learner's" -> "learners").
     */
    public static function ascii(string $text): string
    {
        return str_replace("'", '', Str::lower(Str::ascii(self::squish($text))));
    }

    public static function squish(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }

    /**
     * @return array{code: string, severity: string, message: string, data?: array}
     */
    public static function issue(string $code, array $data = []): array
    {
        [$severity, $message] = match ($code) {
            'missing_publisher' => ['error', 'No author or publisher could be read.'],
            'unknown_level' => ['error', 'The level is not KG 1-2, Basic 1-6 or JHS 1-3.'],
            'duplicate' => ['error', 'Same title, level and publisher as another row in this list.'],
            'unknown_subject' => ['warning', 'Section heading not mapped to a subject.'],
            'subject_missing' => ['warning', 'Subject "'.($data['name'] ?? '').'" does not exist yet; it is created on publish.'],
            'subject_unknown' => ['warning', 'No subject could be inferred from the title.'],
            'unknown_band' => ['warning', 'Level band heading not recognised.'],
            'language_unknown' => ['warning', 'Ghanaian language material, but the language could not be told from the title.'],
            'publisher_check' => ['warning', 'Author/publisher separator in an odd place; check the publisher.'],
            'publisher_similar' => ['warning', 'Publisher spelled like "'.($data['suggestion'] ?? '').'"; same publisher?'],
            'level_from_title' => ['warning', 'Level read from the end of the title; check the title.'],
            'continued_on_next_page' => ['warning', 'Row continues on the next page; check it was joined correctly.'],
            default => ['warning', $code],
        };

        return array_filter([
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'data' => $data === [] ? null : $data,
        ], fn ($value) => $value !== null);
    }
}
