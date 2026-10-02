import 'package:schoolbook/core/money.dart';

/// Form validators. Widgets never validate inline; they call these.
abstract final class Validators {
  static String? required(String? value, {String label = 'This field'}) {
    return (value == null || value.trim().isEmpty)
        ? '$label is required'
        : null;
  }

  /// A positive GHS amount, exact to the pesewa.
  static String? ghsAmount(String? value, {bool allowZero = false}) {
    if (value == null || value.trim().isEmpty) {
      return 'Enter an amount';
    }
    final pesewas = Money.parseGhsToPesewas(value);
    if (pesewas == null) {
      return 'Use an amount like 1250, 1,250 or 1,250.50';
    }
    if (!allowZero && pesewas == 0) {
      return 'The amount must be more than zero';
    }
    return null;
  }

  /// Optional GHS amount (blank = none), e.g. a credit limit.
  static String? optionalGhsAmount(String? value) {
    if (value == null || value.trim().isEmpty) {
      return null;
    }
    return ghsAmount(value, allowZero: true);
  }

  /// MoMo, bank transfer and cheque need the transaction reference; cash does not.
  static String? paymentReference(String? value, {required String method}) {
    if (method == 'cash') {
      return null;
    }
    if (value == null || value.trim().isEmpty) {
      return 'Enter the transaction or cheque number';
    }
    if (value.trim().length > 100) {
      return 'At most 100 characters';
    }
    return null;
  }

  /// The payment date cannot be in the future (server rule `before_or_equal:now`).
  static String? notInFuture(DateTime? value, {DateTime? now}) {
    if (value == null) {
      return 'Pick a date';
    }
    return value.isAfter(now ?? DateTime.now()) ? 'Cannot be in the future' : null;
  }

  static String? optionalEmail(String? value) {
    if (value == null || value.trim().isEmpty) {
      return null;
    }
    final ok = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(value.trim());
    return ok ? null : 'Enter a valid email';
  }
}
