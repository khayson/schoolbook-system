class NamedLookup {
  const NamedLookup({required this.id, required this.name});

  final int id;
  final String name;

  factory NamedLookup.fromJson(Map<String, dynamic> json) {
    return NamedLookup(id: json['id'] as int, name: json['name'] as String);
  }
}

class LevelLookup extends NamedLookup {
  const LevelLookup({
    required super.id,
    required super.name,
    this.levelGroupId,
    this.levelGroupName,
  });

  final int? levelGroupId;
  final String? levelGroupName;

  factory LevelLookup.fromJson(Map<String, dynamic> json) {
    final group = json['level_group'] as Map<String, dynamic>?;
    return LevelLookup(
      id: json['id'] as int,
      name: json['name'] as String,
      levelGroupId: json['level_group_id'] as int? ?? group?['id'] as int?,
      levelGroupName: group?['name'] as String?,
    );
  }
}

class LanguageLookup extends NamedLookup {
  const LanguageLookup({
    required super.id,
    required super.name,
    required this.code,
  });

  final String code;

  factory LanguageLookup.fromJson(Map<String, dynamic> json) {
    return LanguageLookup(
      id: json['id'] as int,
      name: json['name'] as String,
      code: json['code'] as String? ?? '',
    );
  }
}
