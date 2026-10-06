import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';

void main() {
  group('ApiException.fromDio', () {
    test('maps the envelope: message, code, field errors and details', () {
      final e = ApiException.fromDio({
        'message':
            'This momo reference is already recorded on RCT-2026-000004.',
        'code': 'duplicate_reference',
        'errors': <String, dynamic>{},
        'details': {
          'method': 'momo',
          'reference': 'MP-9',
          'existing_receipt_no': 'RCT-2026-000004',
          'existing_amount': 30000,
        },
      }, statusCode: 409);

      expect(e.statusCode, 409);
      expect(e.code, 'duplicate_reference');
      expect(e.details['existing_receipt_no'], 'RCT-2026-000004');
      expect(e.isNetworkError, isFalse);
    });

    test('validation errors keep their field paths', () {
      final e = ApiException.fromDio({
        'message': 'The reference field is required.',
        'code': 'validation_failed',
        'errors': {
          'reference': ['The reference field is required.'],
          'allocations.0.amount': ['Too big'],
        },
      }, statusCode: 422);

      expect(e.fieldError('reference'), 'The reference field is required.');
      expect(e.fieldError('allocations.0.amount'), 'Too big');
      expect(e.details, isEmpty);
    });

    test('an empty errors list ([] instead of {}) is tolerated', () {
      final e = ApiException.fromDio({
        'message': 'x',
        'code': 'y',
        'errors': [],
      });
      expect(e.fieldErrors, isEmpty);
    });

    test('non-JSON bodies become a generic error', () {
      final e = ApiException.fromDio('<html>500</html>', statusCode: 500);
      expect(e.message, 'Request failed');
      expect(e.code, isNull);
    });

    test('network errors are flagged so callers keep the idempotency key', () {
      final e = ApiException.network();
      expect(e.isNetworkError, isTrue);
      expect(e.code, 'network_error');
    });
  });

  group('describeApiError', () {
    ErrorDescription d(
      String code, [
      Map<String, dynamic> details = const {},
    ]) => describeApiError(
      ApiException(message: 'server says', code: code, details: details),
    );

    test('duplicate_reference names the existing receipt and amount', () {
      final desc = d('duplicate_reference', {
        'method': 'momo',
        'reference': 'MP-9',
        'existing_receipt_no': 'RCT-2026-000004',
        'existing_amount': 30000,
      });
      expect(desc.title, 'Payment already recorded');
      expect(desc.body, contains('Mobile Money reference (MP-9)'));
      expect(desc.body, contains('RCT-2026-000004'));
      expect(desc.body, contains('GHS 300.00'));
    });

    test('allocation and credit errors show the amounts', () {
      expect(
        d('allocation_exceeds_balance', {
          'invoice_no': 'INV-2026-000001',
          'balance_due': 1000,
        }).body,
        contains('INV-2026-000001 has GHS 10.00'),
      );
      expect(
        d('allocation_exceeds_credit', {
          'requested': 1300,
          'credit_balance': 1200,
        }).body,
        'Requested GHS 13.00, available credit GHS 12.00.',
      );
      expect(d('no_credit_available').title, 'No credit');
    });

    test('network errors explain that retrying is safe', () {
      final desc = describeApiError(ApiException.network());
      expect(desc.title, 'No connection');
      expect(desc.body, contains('will not be recorded twice'));
    });

    test('validation_failed shows the first field message', () {
      final desc = describeApiError(
        ApiException(
          message: 'm',
          code: 'validation_failed',
          fieldErrors: const {
            'amount': ['The amount must be at least 1.'],
          },
        ),
      );
      expect(desc.body, 'The amount must be at least 1.');
    });

    test('unknown codes fall back to the server message', () {
      expect(d('something_new').body, 'server says');
    });
  });
}
