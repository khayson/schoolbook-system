class PaginatedMeta {
  PaginatedMeta({
    required this.currentPage,
    required this.lastPage,
    required this.perPage,
    required this.total,
  });

  final int currentPage;
  final int lastPage;
  final int perPage;
  final int total;

  factory PaginatedMeta.fromJson(Map<String, dynamic> json) {
    return PaginatedMeta(
      currentPage: _int(json['current_page'], 1),
      lastPage: _int(json['last_page'], 1),
      perPage: _int(json['per_page'], 25),
      total: _int(json['total'], 0),
    );
  }

  static int _int(dynamic value, int fallback) {
    if (value is int) {
      return value;
    }
    if (value is num) {
      return value.toInt();
    }
    return fallback;
  }
}

class PaginatedResponse<T> {
  PaginatedResponse({required this.data, required this.meta});

  final List<T> data;
  final PaginatedMeta meta;
}
