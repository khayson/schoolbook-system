import 'package:flutter/foundation.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/features/payments/data/payments_repository.dart';
import 'package:schoolbook/features/payments/domain/payment.dart';

/// State and API calls for "record a payment from customer X".
///
/// Idempotency: the intent is "record a payment for this customer". The key for a given
/// payload is persisted on the device *before* the request is sent and forgotten only
/// after the server confirms success. A retry after a network error, or after the app
/// was killed mid-request, sends the same key and payload, so the server replays the
/// first result instead of recording the money twice. Changing any field gives a new key.
/// A definitive error (4xx such as validation or `duplicate_reference`) means nothing was
/// written, so the key is forgotten and no "unfinished payment" banner is shown.
class RecordPaymentController extends ChangeNotifier {
  RecordPaymentController({
    required this._payments,
    required this._pendingStore,
    required this.customerId,
  });

  final PaymentsRepository _payments;
  final PendingSubmissionStore _pendingStore;
  final int customerId;

  bool submitting = false;
  ApiException? error;
  Payment? recorded;

  bool loadingRecent = false;
  String? recentError;
  List<Payment> recent = const [];

  /// A submission that was sent but never confirmed (e.g. the app was killed).
  PendingSubmission? unfinished;

  String get intent => 'record_payment.customer.$customerId';

  /// Request body for `POST /payments`. Amount is already pesewas.
  static Map<String, dynamic> buildPayload({
    required int customerId,
    required int amountPesewas,
    required String method,
    required String? reference,
    required DateTime paidAt,
    required String? notes,
    required bool keepAsCredit,
  }) {
    final trimmedReference = reference?.trim() ?? '';
    final trimmedNotes = notes?.trim() ?? '';
    return {
      'customer_id': customerId,
      'amount': amountPesewas,
      'method': method,
      'reference': trimmedReference.isEmpty ? null : trimmedReference,
      'paid_at': paidAt.toUtc().toIso8601String(),
      'notes': trimmedNotes.isEmpty ? null : trimmedNotes,
      'auto_allocate': !keepAsCredit,
    };
  }

  Future<void> load() async {
    unfinished = await _pendingStore.pending(intent);
    await refreshRecent();
  }

  Future<void> refreshRecent() async {
    loadingRecent = true;
    recentError = null;
    notifyListeners();
    try {
      final page = await _payments.listPayments(customerId: customerId, perPage: 5);
      recent = page.data;
    } on ApiException catch (e) {
      recentError = e.message;
    } finally {
      loadingRecent = false;
      notifyListeners();
    }
  }

  /// Returns the recorded payment, or null (see [error]). The key is kept on any error,
  /// so pressing the button again with the same details is always safe.
  Future<Payment?> submit(Map<String, dynamic> payload) async {
    if (submitting) {
      return null;
    }
    submitting = true;
    error = null;
    notifyListeners();

    try {
      final key = await _pendingStore.keyFor(intent, payload);
      final payment = await _payments.recordPayment(idempotencyKey: key, payload: payload);
      await _pendingStore.complete(intent);
      recorded = payment;
      unfinished = null;
      await refreshRecent();
      return payment;
    } on ApiException catch (e) {
      error = e;
      if (!e.isOutcomeUnknown) {
        await _pendingStore.complete(intent);
      }
      unfinished = await _pendingStore.pending(intent);
      return null;
    } finally {
      submitting = false;
      notifyListeners();
    }
  }

  /// The user checked the recent payments and wants to start fresh.
  Future<void> discardUnfinished() async {
    await _pendingStore.complete(intent);
    unfinished = null;
    notifyListeners();
  }
}
