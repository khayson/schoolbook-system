/// A title on the approved (NaCCA) list, as delivered by `GET /reference-books/snapshot`.
class ReferenceBook {
  const ReferenceBook({
    required this.id,
    required this.category,
    required this.title,
    required this.searchTitle,
    this.levelId,
    this.level,
    this.band,
    this.subjectId,
    this.subject,
    this.languageId,
    this.language,
    this.publisherId,
    this.publisher,
    this.author,
    this.isbn,
  });

  final int id;

  /// textbook, subject_supplement, reader, guidance, elearning
  final String category;
  final String title;

  /// Server-normalised title (lower-case ASCII, punctuation removed).
  final String searchTitle;
  final int? levelId;

  /// Level name ("Primary 4"), or the printed band ("Lower Primary") for titles listed
  /// only for a range of classes, or null (readers, guidance, e-learning).
  final String? level;

  /// kg, lower_primary, upper_primary, jhs, shs: set when the title has no single level.
  final String? band;
  final int? subjectId;
  final String? subject;
  final int? languageId;
  final String? language;
  final int? publisherId;
  final String? publisher;
  final String? author;
  final String? isbn;

  /// The class must be chosen when adding it as a product.
  bool get needsLevel => levelId == null;

  String get categoryLabel => switch (category) {
        'textbook' => 'Textbook',
        'subject_supplement' => 'Supplement',
        'reader' => 'Reader',
        'guidance' => 'Guidance',
        'elearning' => 'E-learning',
        _ => category,
      };

  /// "Primary 4 · Science · Baobab Publishing"
  String get subtitle => [level ?? categoryLabel, subject, publisher]
      .whereType<String>()
      .where((s) => s.isNotEmpty)
      .join(' · ');

  factory ReferenceBook.fromJson(Map<String, dynamic> json) => ReferenceBook(
        id: json['id'] as int,
        category: json['category'] as String,
        title: json['title'] as String,
        searchTitle: json['search_title'] as String? ?? '',
        levelId: json['level_id'] as int?,
        level: json['level'] as String?,
        band: json['band'] as String?,
        subjectId: json['subject_id'] as int?,
        subject: json['subject'] as String?,
        languageId: json['language_id'] as int?,
        language: json['language'] as String?,
        publisherId: json['publisher_id'] as int?,
        publisher: json['publisher'] as String?,
        author: json['author'] as String?,
        isbn: json['isbn'] as String?,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'category': category,
        'title': title,
        'search_title': searchTitle,
        'level_id': levelId,
        'level': level,
        'band': band,
        'subject_id': subjectId,
        'subject': subject,
        'language_id': languageId,
        'language': language,
        'publisher_id': publisherId,
        'publisher': publisher,
        'author': author,
        'isbn': isbn,
      };
}

/// The shop's products linked to a title (from `GET /reference-books?stocked=1`).
class TitleStock {
  const TitleStock({required this.productsCount, required this.stockOnHand});

  final int productsCount;
  final int stockOnHand;
}
