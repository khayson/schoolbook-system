import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';

/// A user-facing title and body for an [ApiException], keyed by the API's error `code`
/// (docs/api.md, Errors). Screens show this instead of raw server messages.
class ErrorDescription {
  const ErrorDescription(this.title, this.body);

  final String title;
  final String body;
}

ErrorDescription describeApiError(ApiException e) {
  final d = e.details;
  String ghs(Object? pesewas) =>
      pesewas is int ? Money.formatPesewas(pesewas) : '';

  if (e.isNetworkError) {
    return const ErrorDescription(
      'No connection',
      'We could not reach the server. Tap the button again when you are back online: '
          'the same request is sent, so it will not be recorded twice.',
    );
  }

  switch (e.code) {
    case 'duplicate_code':
      return ErrorDescription(
        'Code already used',
        'This code is already on ${d['title'] ?? 'another product'} (${d['sku'] ?? '?'})'
            '${d['deleted'] == true ? ', a deleted product' : ''}. Scan it to open that product instead.',
      );
    case 'code_slot_taken':
      return ErrorDescription(
        'Product already has a code',
        'This product already has ${d['field'] == 'isbn' ? 'an ISBN' : 'a barcode'} (${d['current']}). '
            'Change it on the product in the admin if this new code is the right one.',
      );
    case 'duplicate_reference':
      return ErrorDescription(
        'Payment already recorded',
        'This ${_method(d['method'])} reference (${d['reference']}) is already on '
            'receipt ${d['existing_receipt_no'] ?? '?'}'
            '${d['existing_amount'] != null ? ' for ${ghs(d['existing_amount'])}' : ''}. '
            'Check the recent payments. If that one was a mistake, void it first.',
      );
    case 'allocation_exceeds_balance':
      return ErrorDescription(
        'More than the invoice balance',
        '${d['invoice_no']} has ${ghs(d['balance_due'])} left to pay.',
      );
    case 'allocation_exceeds_payment':
      return ErrorDescription(
        'More than the payment',
        'The invoices add up to ${ghs(d['allocated_total'])} but the payment is ${ghs(d['amount'])}.',
      );
    case 'allocation_exceeds_credit':
      return ErrorDescription(
        'Not enough credit',
        'Requested ${ghs(d['requested'])}, available credit ${ghs(d['credit_balance'])}.',
      );
    case 'no_credit_available':
      return const ErrorDescription('No credit', 'This customer has no credit to apply.');
    case 'sale_not_payable':
      return ErrorDescription('Invoice cannot be paid', e.message);
    case 'payment_already_void':
      return ErrorDescription('Payment is void', e.message);
    case 'request_in_progress':
      return const ErrorDescription(
        'Still processing',
        'The previous attempt is still being processed. Wait a moment and try again.',
      );
    case 'idempotency_key_mismatch':
      return const ErrorDescription(
        'Form changed during a retry',
        'Submit again to record it with the new details.',
      );
    case 'validation_failed':
      final first = e.fieldErrors.values.expand((v) => v).firstOrNull;
      return ErrorDescription('Check the details', first ?? e.message);
    case 'forbidden':
      return const ErrorDescription('Not allowed', 'Only the owner account can do this.');
    case 'unauthenticated':
      return const ErrorDescription('Signed out', 'Please sign in again.');
    default:
      return ErrorDescription('Something went wrong', e.message);
  }
}

String _method(Object? method) => switch (method) {
      'momo' => 'Mobile Money',
      'bank_transfer' => 'bank transfer',
      'cheque' => 'cheque',
      _ => method?.toString() ?? '',
    };
