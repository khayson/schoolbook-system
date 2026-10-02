import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:schoolbook/core/error_messages.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/money.dart';
import 'package:schoolbook/features/payments/domain/payment.dart';

class PaymentTile extends StatelessWidget {
  const PaymentTile({super.key, required this.payment, this.onTap, this.showCustomer = false});

  final Payment payment;
  final VoidCallback? onTap;
  final bool showCustomer;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ref = payment.reference == null ? '' : ' | ${payment.reference}';
    final who = showCustomer && payment.customerName != null ? '${payment.customerName}\n' : '';
    return ListTile(
      contentPadding: EdgeInsets.zero,
      onTap: onTap,
      isThreeLine: who.isNotEmpty,
      title: Text(
        '${payment.receiptNo}  ${Money.formatPesewas(payment.amount)}',
        style: payment.isVoid ? const TextStyle(decoration: TextDecoration.lineThrough) : null,
      ),
      subtitle: Text(
        '$who${DateFormat('d MMM y, HH:mm').format(payment.paidAt)} | ${payment.methodLabel}$ref',
      ),
      trailing: payment.isVoid
          ? Chip(label: const Text('Void'), backgroundColor: theme.colorScheme.errorContainer)
          : (payment.unallocatedAmount > 0
              ? Tooltip(
                  message: 'Held as credit',
                  child: Text(Money.formatPesewas(payment.unallocatedAmount), style: theme.textTheme.bodySmall),
                )
              : null),
    );
  }
}

/// Title + body from [describeApiError], in the error colour.
class ErrorCard extends StatelessWidget {
  const ErrorCard({super.key, required this.error});

  final ApiException error;

  @override
  Widget build(BuildContext context) {
    final description = describeApiError(error);
    final scheme = Theme.of(context).colorScheme;
    return Card(
      color: scheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(description.title, style: TextStyle(fontWeight: FontWeight.w600, color: scheme.onErrorContainer)),
            const SizedBox(height: 4),
            Text(description.body, style: TextStyle(color: scheme.onErrorContainer)),
          ],
        ),
      ),
    );
  }
}
