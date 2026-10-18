import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/features/exchanges/domain/stock_exchange.dart';

void main() {
  test('parses sides, quantities, and settlement', () {
    final exchange = StockExchange.fromJson({
      'id': 9,
      'exchange_no': null,
      'customer_id': 3,
      'counterparty_name': 'Kojo',
      'status': 'draft',
      'settlement': 'installment',
      'created_by': 1,
      'updated_at': '2026-10-18T12:00:00Z',
      'items': [
        {
          'id': 1,
          'direction': 'in',
          'product_id': 10,
          'quantity': 4,
          'unit_cost': 1100,
          'product': {
            'id': 10,
            'sku': 'A',
            'title': 'Taken Book',
            'level_id': 1,
            'subject_id': 1,
            'language_id': 1,
            'cost_price': 1000,
            'selling_price': 2000,
            'reorder_level': 0,
            'stock_on_hand': 0,
            'is_active': true,
          },
        },
        {
          'id': 2,
          'direction': 'out',
          'product_id': 11,
          'quantity': 2,
          'unit_price': 3000,
          'product': {
            'id': 11,
            'sku': 'B',
            'title': 'Gave Book',
            'level_id': 1,
            'subject_id': 1,
            'language_id': 1,
            'cost_price': 1000,
            'selling_price': 3000,
            'reorder_level': 0,
            'stock_on_hand': 5,
            'is_active': true,
          },
        },
      ],
    });

    expect(exchange.isDraft, isTrue);
    expect(exchange.settlement, ExchangeSettlement.installment);
    expect(exchange.settlement!.label, 'Installment');
    expect(exchange.takenCopies, 4);
    expect(exchange.theyTookCopies, 2);
    expect(exchange.theyTookItems.first.unitPrice, 3000);
    expect(exchange.takenItems.first.direction.label, contains('Taken'));
  });
}
