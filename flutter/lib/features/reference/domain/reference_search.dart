import 'package:schoolbook/features/reference/domain/reference_book.dart';

/// Offline search over the approved list (same rules as the server's
/// ReferenceBookSearch, so results feel the same online and offline):
///
/// - every word must match the start of a word in the title, publisher, author,
///   subject or level ("sun math" finds "Sunrise Mathematics");
/// - "maths"/"math"/"mathematics" are the same word;
/// - a level in the query ("basic 4", "primary 4", "p4", "b4", "kg 2", "jhs 1") filters
///   by level, including titles listed for a band that covers it ("Lower Primary" for p2);
/// - ranking: exact title words, then title prefixes, then other fields; ties by title.
class ReferenceSearchIndex {
  ReferenceSearchIndex(List<ReferenceBook> books)
    : _entries = [for (final b in books) _Entry.of(b)];

  final List<_Entry> _entries;

  static const _stopwords = {
    'a',
    'an',
    'and',
    'the',
    'for',
    'of',
    'in',
    'on',
    'to',
  };

  static const _bandLevels = {
    'kg': {'creche', 'kg 1', 'kg 2'},
    'lower_primary': {'primary 1', 'primary 2', 'primary 3'},
    'upper_primary': {'primary 4', 'primary 5', 'primary 6'},
    'jhs': {'jhs 1', 'jhs 2', 'jhs 3'},
  };

  List<ReferenceBook> search(String query, {int limit = 50}) {
    final parsed = parse(query);
    if (parsed.levels.isEmpty && parsed.words.isEmpty) {
      return const [];
    }

    final scored = <(int, _Entry)>[];
    for (final entry in _entries) {
      if (parsed.levels.isNotEmpty && !entry.atAnyLevel(parsed.levels)) {
        continue;
      }
      var score = 0;
      var all = true;
      for (final word in parsed.words) {
        final s = entry.score(word);
        if (s == 0) {
          all = false;
          break;
        }
        score += s;
      }
      if (all) {
        scored.add((score, entry));
      }
    }
    scored.sort((a, b) {
      final byScore = b.$1.compareTo(a.$1);
      if (byScore != 0) return byScore;
      final byTitle = a.$2.book.title.toLowerCase().compareTo(
        b.$2.book.title.toLowerCase(),
      );
      return byTitle != 0 ? byTitle : a.$2.book.id.compareTo(b.$2.book.id);
    });

    return [for (final s in scored.take(limit)) s.$2.book];
  }

  /// Lower-case ASCII words: accents folded, apostrophes dropped, "&" = "and".
  static List<String> words(String text) {
    var t = text.toLowerCase();
    const folds = {
      'à': 'a',
      'á': 'a',
      'â': 'a',
      'ä': 'a',
      'ã': 'a',
      'å': 'a',
      'ç': 'c',
      'è': 'e',
      'é': 'e',
      'ê': 'e',
      'ë': 'e',
      'ì': 'i',
      'í': 'i',
      'î': 'i',
      'ï': 'i',
      'ñ': 'n',
      'ò': 'o',
      'ó': 'o',
      'ô': 'o',
      'ö': 'o',
      'õ': 'o',
      'ù': 'u',
      'ú': 'u',
      'û': 'u',
      'ü': 'u',
      'ɔ': 'o',
      'ɛ': 'e',
      'ŋ': 'n',
    };
    folds.forEach((from, to) => t = t.replaceAll(from, to));
    t = t.replaceAll(RegExp("['’`]"), '').replaceAll('&', ' and ');
    return t.split(RegExp(r'[^a-z0-9]+')).where((w) => w.isNotEmpty).toList();
  }

  static String _synonym(String word) =>
      word.startsWith('math') ? 'math' : word;

  static ParsedQuery parse(String query) {
    var text = ' ${words(query).join(' ')} ';
    final levels = <String>{};
    final patterns = {
      'primary': RegExp(r' (?:basic|primary|class|b|p) ?([1-6])(?= )'),
      'kg': RegExp(r' (?:kg|kindergarten) ?([12])(?= )'),
      'jhs': RegExp(r' jhs ?([1-3])(?= )'),
    };
    patterns.forEach((name, pattern) {
      text = text.replaceAllMapped(pattern, (m) {
        levels.add('$name ${m[1]}');
        return ' ';
      });
    });

    final found = <String>[];
    for (final w in text.split(' ').where((w) => w.isNotEmpty)) {
      final word = _synonym(w);
      if (!_stopwords.contains(word) && !found.contains(word)) {
        found.add(word);
      }
    }

    return ParsedQuery(levels: levels, words: found);
  }
}

class ParsedQuery {
  const ParsedQuery({required this.levels, required this.words});

  /// Canonical level names: "primary 4", "kg 2", "jhs 1".
  final Set<String> levels;
  final List<String> words;
}

class _Entry {
  _Entry(this.book, this.titleWords, this.otherWords, this.levelName);

  factory _Entry.of(ReferenceBook b) {
    final title = ReferenceSearchIndex.words(b.title)
        .map(ReferenceSearchIndex._synonym)
        .toList();
    final other = [
      ...ReferenceSearchIndex.words(b.publisher ?? ''),
      ...ReferenceSearchIndex.words(b.author ?? ''),
      ...ReferenceSearchIndex.words(b.subject ?? ''),
      ...ReferenceSearchIndex.words(b.level ?? ''),
    ].map(ReferenceSearchIndex._synonym).toList();
    return _Entry(
      b,
      title,
      other,
      ReferenceSearchIndex.words(b.level ?? '').join(' '),
    );
  }

  final ReferenceBook book;
  final List<String> titleWords;
  final List<String> otherWords;
  final String levelName;

  bool atAnyLevel(Set<String> levels) {
    if (book.levelId != null) {
      return levels.contains(levelName);
    }
    final covered = ReferenceSearchIndex._bandLevels[book.band];
    return covered != null && covered.intersection(levels).isNotEmpty;
  }

  /// 4 exact title word, 3 title prefix, 2 exact other word, 1 other prefix, 0 none.
  int score(String word) {
    if (titleWords.contains(word)) return 4;
    if (titleWords.any((w) => w.startsWith(word))) return 3;
    if (otherWords.contains(word)) return 2;
    if (otherWords.any((w) => w.startsWith(word))) return 1;
    return 0;
  }
}
