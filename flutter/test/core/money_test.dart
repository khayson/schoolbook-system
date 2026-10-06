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
      expect(Money.formatPesewas(-19600), '−GHS 196.00');
      expect(Money.formatPesewas(-123456789), '−GHS 1,234,567.89');
    });

    test('parseGhsToPesewas converts exact decimals without floats', () {
      expect(Money.parseGhsToPesewas('19.99'), 1999);
      expect(Money.parseGhsToPesewas('0.29'), 29);
      expect(Money.parseGhsToPesewas('1.10'), 110);
      expect(Money.parseGhsToPesewas('12.50'), 1250);
      expect(Money.parseGhsToPesewas('12.5'), 1250);
      expect(Money.parseGhsToPesewas('12'), 1200);
      expect(Money.parseGhsToPesewas(' 7.05 '), 705);
    });

    test('thousands commas are accepted only when correctly grouped', () {
      expect(Money.parseGhsToPesewas('1,234.56'), 123456);
      expect(Money.parseGhsToPesewas('1,250'), 125000);
      expect(Money.parseGhsToPesewas('1,000,000.10'), 100000010);
      expect(Money.parseGhsToPesewas('1,00'), isNull);
      expect(Money.parseGhsToPesewas('12,50.00'), isNull);
      expect(Money.parseGhsToPesewas('1.000,50'), isNull);
      expect(Money.parseGhsToPesewas(',100'), isNull);
    });

    test(
      'parseGhsToPesewas rejects invalid input, >2 decimals and the cap',
      () {
        expect(Money.parseGhsToPesewas(''), isNull);
        expect(Money.parseGhsToPesewas('abc'), isNull);
        expect(Money.parseGhsToPesewas('-1'), isNull);
        expect(Money.parseGhsToPesewas('1.234'), isNull);
        expect(Money.parseGhsToPesewas('0.001'), isNull);
        expect(Money.parseGhsToPesewas('1e3'), isNull);
        expect(Money.parseGhsToPesewas('1000000000.00'), Money.maxPesewas);
        expect(Money.parseGhsToPesewas('1000000000.01'), isNull);
      },
    );

    test('toGhsInput round-trips through the parser', () {
      for (final p in [0, 5, 29, 110, 1999, 125050, 100000000000]) {
        expect(Money.parseGhsToPesewas(Money.toGhsInput(p)), p);
      }
      expect(Money.toGhsInput(125050), '1250.50');
    });
  });
}
