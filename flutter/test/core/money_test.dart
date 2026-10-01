import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/money.dart';

void main() {
  group('Money', () {
    test('formatPesewas shows GHS with two decimals', () {
      expect(Money.formatPesewas(0), 'GHS 0.00');
      expect(Money.formatPesewas(6000), 'GHS 60.00');
      expect(Money.formatPesewas(123456), 'GHS 1,234.56');
      expect(Money.formatPesewas(29), 'GHS 0.29');
      expect(Money.formatPesewas(1999), 'GHS 19.99');
      expect(Money.formatPesewas(110), 'GHS 1.10');
    });

    test('parseGhsToPesewas converts exact decimals without floats', () {
      expect(Money.parseGhsToPesewas('19.99'), 1999);
      expect(Money.parseGhsToPesewas('0.29'), 29);
      expect(Money.parseGhsToPesewas('1.10'), 110);
      expect(Money.parseGhsToPesewas('12.50'), 1250);
      expect(Money.parseGhsToPesewas('1,234.56'), 123456);
      expect(Money.parseGhsToPesewas('12'), 1200);
    });

    test('parseGhsToPesewas rejects invalid input and >2 decimals', () {
      expect(Money.parseGhsToPesewas(''), isNull);
      expect(Money.parseGhsToPesewas('abc'), isNull);
      expect(Money.parseGhsToPesewas('-1'), isNull);
      expect(Money.parseGhsToPesewas('1.234'), isNull);
      expect(Money.parseGhsToPesewas('0.001'), isNull);
    });
  });
}
