/// A school from the school directory (`/school-directory`, data © OpenStreetMap
/// contributors, ODbL). Mirrors `DirectorySchoolResource`.
class DirectorySchool {
  const DirectorySchool({
    required this.id,
    required this.name,
    required this.region,
    this.district,
    this.town,
    this.phone,
    this.levelLabel,
    this.ownership,
    this.customerId,
  });

  factory DirectorySchool.fromJson(Map<String, dynamic> json) =>
      DirectorySchool(
        id: json['id'] as int,
        name: json['name'] as String,
        region: json['region'] as String,
        district: json['district'] as String?,
        town: json['town'] as String?,
        phone: json['phone'] as String?,
        levelLabel: json['level_label'] as String?,
        ownership: json['ownership'] as String?,
        customerId: json['customer_id'] as int?,
      );

  final int id;
  final String name;
  final String region;
  final String? district;
  final String? town;
  final String? phone;
  final String? levelLabel;

  /// `public`, `private` or null (unknown).
  final String? ownership;

  /// Set once the school has been added as (or linked to) a customer.
  final int? customerId;

  bool get isAdded => customerId != null;

  /// "Gomoa East | Kasoa | Primary, JHS"
  String get details => [
    district,
    town,
    levelLabel,
  ].whereType<String>().where((s) => s.isNotEmpty).join(' | ');
}
