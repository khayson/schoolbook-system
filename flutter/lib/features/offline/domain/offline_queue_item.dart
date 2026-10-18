/// Outbound work that must reach the server with a stable [idempotencyKey].
enum OfflineQueueType {
  createDraftSale,
  recordPayment;

  String get wireName => switch (this) {
    OfflineQueueType.createDraftSale => 'create_draft_sale',
    OfflineQueueType.recordPayment => 'record_payment',
  };

  static OfflineQueueType fromWire(String value) => switch (value) {
    'create_draft_sale' => OfflineQueueType.createDraftSale,
    'record_payment' => OfflineQueueType.recordPayment,
    _ => throw FormatException('Unknown offline queue type: $value'),
  };
}

enum OfflineQueueStatus {
  pending,
  syncing,
  failed;

  String get wireName => name;

  static OfflineQueueStatus fromWire(String value) =>
      OfflineQueueStatus.values.firstWhere(
        (s) => s.name == value,
        orElse: () => throw FormatException('Unknown queue status: $value'),
      );
}

/// One queued mutating request. Money/stock are never computed here — payload is
/// what the user already intended to send; the server is the source of truth.
class OfflineQueueItem {
  const OfflineQueueItem({
    required this.id,
    required this.type,
    required this.payload,
    required this.idempotencyKey,
    required this.status,
    required this.createdAt,
    this.lastError,
    this.lastErrorCode,
    this.lastStatusCode,
    this.updatedAt,
  });

  factory OfflineQueueItem.fromJson(Map<String, dynamic> json) {
    return OfflineQueueItem(
      id: json['id'] as String,
      type: OfflineQueueType.fromWire(json['type'] as String),
      payload: Map<String, dynamic>.from(json['payload'] as Map),
      idempotencyKey: json['idempotency_key'] as String,
      status: OfflineQueueStatus.fromWire(json['status'] as String),
      lastError: json['last_error'] as String?,
      lastErrorCode: json['last_error_code'] as String?,
      lastStatusCode: json['last_status_code'] as int?,
      createdAt: DateTime.parse(json['created_at'] as String),
      updatedAt: json['updated_at'] == null
          ? null
          : DateTime.parse(json['updated_at'] as String),
    );
  }

  final String id;
  final OfflineQueueType type;
  final Map<String, dynamic> payload;
  final String idempotencyKey;
  final OfflineQueueStatus status;
  final String? lastError;
  final String? lastErrorCode;
  final int? lastStatusCode;
  final DateTime createdAt;
  final DateTime? updatedAt;

  bool get needsUserResolve =>
      status == OfflineQueueStatus.failed &&
      lastStatusCode != null &&
      (lastStatusCode == 409 || lastStatusCode == 422);

  OfflineQueueItem copyWith({
    OfflineQueueStatus? status,
    String? lastError,
    String? lastErrorCode,
    int? lastStatusCode,
    DateTime? updatedAt,
    bool clearError = false,
  }) {
    return OfflineQueueItem(
      id: id,
      type: type,
      payload: payload,
      idempotencyKey: idempotencyKey,
      status: status ?? this.status,
      lastError: clearError ? null : (lastError ?? this.lastError),
      lastErrorCode: clearError ? null : (lastErrorCode ?? this.lastErrorCode),
      lastStatusCode: clearError
          ? null
          : (lastStatusCode ?? this.lastStatusCode),
      createdAt: createdAt,
      updatedAt: updatedAt ?? this.updatedAt,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'type': type.wireName,
    'payload': payload,
    'idempotency_key': idempotencyKey,
    'status': status.wireName,
    'last_error': lastError,
    'last_error_code': lastErrorCode,
    'last_status_code': lastStatusCode,
    'created_at': createdAt.toUtc().toIso8601String(),
    'updated_at': updatedAt?.toUtc().toIso8601String(),
  };
}
