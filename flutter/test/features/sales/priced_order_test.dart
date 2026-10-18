import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/features/sales/domain/sale.dart';

void main() {
  group('PricedOrder.fromJson', () {
    test('parses applied_rules, line warnings, discount_total and order warnings', () {
      final order = PricedOrder.fromJson({
        'lines': [
          {
            'product_id': 7,
            'product_title': 'English Reader P4',
            'quantity': 10,
            'base_price': 1000,
            'unit_price': 900,
            'discount_per_unit': 100,
            'discount_amount': 1000,
            'line_total': 9000,
            'applied_rules': [
              {
                'id': 3,
                'name': 'School 10%',
                'effect': {
                  'type': 'percent_off',
                  'value': 1000,
                  'unit_price_before': 1000,
                  'unit_price_after': 900,
                },
              },
            ],
            'warnings': [
              'Unit price 900 is below cost 950 for product [ENG-P4].',
            ],
            'is_price_overridden': false,
            'override_reason': null,
          },
        ],
        'subtotal': 9000,
        'discount_total': 450,
        'tax_total': 0,
        'total': 8550,
        'warnings': [
          'Unit price 900 is below cost 950 for product [ENG-P4].',
        ],
      });

      expect(order.subtotal, 9000);
      expect(order.discountTotal, 450);
      expect(order.total, 8550);
      expect(order.warnings, [
        'Unit price 900 is below cost 950 for product [ENG-P4].',
      ]);

      final line = order.lines.single;
      expect(line.discountPerUnit, 100);
      expect(line.discountAmount, 1000);
      expect(line.hasDiscount, isTrue);
      expect(line.warnings, hasLength(1));
      expect(line.appliedRules, hasLength(1));
      expect(line.appliedRules.single.id, 3);
      expect(line.appliedRules.single.name, 'School 10%');
      expect(
        line.appliedRules.single.unitPriceChangeLabel,
        'GHS 10.00 → GHS 9.00',
      );
    });

    test('tolerates missing applied_rules, warnings and discount_total', () {
      final order = PricedOrder.fromJson({
        'lines': [
          {
            'product_id': 1,
            'product_title': 'Maths',
            'quantity': 1,
            'base_price': 500,
            'unit_price': 500,
            'line_total': 500,
          },
        ],
        'subtotal': 500,
        'total': 500,
      });

      expect(order.discountTotal, 0);
      expect(order.warnings, isEmpty);
      expect(order.lines.single.appliedRules, isEmpty);
      expect(order.lines.single.warnings, isEmpty);
      expect(order.lines.single.hasDiscount, isFalse);
    });
  });

  group('SaleLine.fromJson', () {
    test('parses applied_rules from a sale item resource', () {
      final line = SaleLine.fromJson({
        'product_id': 2,
        'product_title': 'Science',
        'quantity': 2,
        'base_price': 2000,
        'unit_price': 1800,
        'discount_amount': 400,
        'line_total': 3600,
        'applied_rules': [
          {
            'id': 9,
            'name': 'Bulk amount off',
            'effect': {
              'type': 'amount_off',
              'value': 200,
              'unit_price_before': 2000,
              'unit_price_after': 1800,
            },
          },
        ],
        'is_price_overridden': false,
      });

      expect(line.appliedRules.single.name, 'Bulk amount off');
      expect(line.discountAmount, 400);
      expect(line.warnings, isEmpty);
    });
  });
}
