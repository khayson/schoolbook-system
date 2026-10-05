import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/features/reference/domain/reference_search.dart';

import '../../support/fakes.dart';

void main() {
  // Distinct publishers so publisher matches are deliberate.
  final books = [
    testBook(id: 1, title: 'Sunrise Mathematics for Basic Schools', level: 'Primary 4', levelId: 9, publisher: 'Harmattan Books'),
    testBook(id: 2, title: 'Sunrise Mathematics for Basic Schools', level: 'Primary 2', levelId: 7, publisher: 'Harmattan Books'),
    testBook(id: 3, title: 'Maths Made Easy', level: 'Primary 4', levelId: 9, publisher: 'Volta Press'),
    testBook(id: 4, title: 'Discover Science', level: 'Primary 4', levelId: 9, subject: 'Science', publisher: 'Baobab Publishing'),
    testBook(id: 5, title: 'Number Games Activity Book', level: 'Lower Primary', levelId: null, band: 'lower_primary', author: 'Ama Owusu', publisher: 'Odwira Publication'),
    testBook(id: 6, title: 'Le Français Facile', level: 'JHS 1', levelId: 12, subject: 'French', publisher: 'Kente Educational'),
    testBook(id: 7, title: "Learner's Guide to Sunrise Stories", level: null, levelId: null, category: 'reader', subject: 'English Language', publisher: 'Akwaaba Stories'),
    testBook(id: 8, title: 'Counting Fun for Kindergarten', level: 'KG 2', levelId: 3, publisher: 'Harmattan Books'),
  ];
  final index = ReferenceSearchIndex(books);
  List<int> ids(String q) => index.search(q).map((b) => b.id).toList();

  test('every word must match the start of a word; maths = math = mathematics', () {
    expect(ids('sunrise math'), [1, 2]); // same title: ties by id
    expect(ids('sun mathematics'), [1, 2]);
    // Exact title word "maths" first, then title "Mathematics", then subject Mathematics only.
    expect(ids('maths'), [3, 1, 2, 8, 5]);
    expect(ids('sunrise science'), isEmpty);
  });

  test('a level in the query filters by level: basic 4, primary 4, p4 and b4 are the same', () {
    for (final q in ['sunrise basic 4', 'sunrise primary 4', 'sunrise p4', 'Sunrise B4']) {
      expect(ids(q), [1], reason: q);
    }
    expect(ids('kg2'), [8]);
    expect(ids('jhs 1'), [6]);
  });

  test('band-only titles are found under any class their band covers', () {
    expect(ids('p2'), [5, 2]);
    expect(ids('number p3'), [5]);
    expect(ids('number p4'), isEmpty);
  });

  test('publisher, author and subject are searched too, ranked below title matches', () {
    expect(ids('baobab'), [4]);
    expect(ids('owusu'), [5]);
    expect(ids('science'), [4]); // "Discover Science" (title) and subject Science
    expect(ids('sunrise'), [7, 1, 2]); // exact title word for all three: ties by title, then id
    expect(ids('harmattan'), [8, 1, 2]); // publisher only
  });

  test('accents, apostrophes, case and small words do not matter', () {
    expect(ids('francais'), [6]);
    expect(ids('LEARNERS guide'), [7]);
    expect(ids('the counting fun for kindergarten'), [8]);
    expect(ids('   '), isEmpty);
  });

  test('query parsing', () {
    final p = ReferenceSearchIndex.parse('Sunrise Maths Basic 4 for the P2');
    expect(p.levels, {'primary 4', 'primary 2'});
    expect(p.words, ['sunrise', 'math']);
    expect(ReferenceSearchIndex.parse('Book 4').words, ['book', '4']);
  });
}
