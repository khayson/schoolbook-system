/// Display and parse helpers for integer pesewas (1 GHS = 100 pesewas).
/// Parsing never uses floating-point arithmetic.
abstract final class Money {
  /// Largest single amount accepted from input: GHS 1,000,000,000.00 (same cap as the
  /// server's `Money::MAX_PESEWAS`).
  static const int maxPesewas = 100000000000;

  /// Plain digits, or thousands correctly grouped in threes (1,250 / 1,250,000.50),
  /// with at most 2 decimals. Same rule as the admin's GHS input.
  static final RegExp _pattern = RegExp(
    r'^(\d{1,10}|\d{1,3}(,\d{3}){1,3})(\.(\d{1,2}))?$',
  );

  /// Sign of a displayed negative amount, before the currency (U+2212 minus sign). The
  /// same style as the server's `Money::formatGhsGrouped`.
  static const String negativeSign = '−';

  /// Formats [pesewas] as `GHS 12.34`; negative amounts as `−GHS 196.00`.
  static String formatPesewas(int pesewas) {
    final sign = pesewas < 0 ? negativeSign : '';
    final absolute = pesewas.abs();
    final whole = absolute ~/ 100;
    final fraction = absolute % 100;
    final wholeGrouped = _groupThousands(whole);
    return '${sign}GHS $wholeGrouped.${fraction.toString().padLeft(2, '0')}';
  }

  /// Formats pesewas as an editable GHS string without the prefix: `1250.50`.
  static String toGhsInput(int pesewas) {
    final whole = pesewas.abs() ~/ 100;
    final fraction = pesewas.abs() % 100;
    return '${pesewas < 0 ? '-' : ''}$whole.${fraction.toString().padLeft(2, '0')}';
  }

  /// Parses a user-entered GHS amount to pesewas, or null if invalid.
  /// Rejects more than 2 decimal places, misplaced commas and amounts over
  /// [maxPesewas]. No floats.
  static int? parseGhsToPesewas(String input) {
    final trimmed = input.trim();
    final match = _pattern.firstMatch(trimmed);
    if (match == null) {
      return null;
    }

    final whole = int.parse(match.group(1)!.replaceAll(',', ''));
    final fractionRaw = match.group(4);
    final fraction = fractionRaw == null
        ? 0
        : int.parse(fractionRaw.padRight(2, '0'));

    final pesewas = whole * 100 + fraction;
    return pesewas > maxPesewas ? null : pesewas;
  }

  /// Formats pesewas for compact list subtitles (same as [formatPesewas]).
  static String formatPrice(int pesewas) => formatPesewas(pesewas);

  static String _groupThousands(int value) {
    final digits = value.toString();
    final buffer = StringBuffer();
    for (var i = 0; i < digits.length; i++) {
      final fromEnd = digits.length - i;
      if (i > 0 && fromEnd % 3 == 0) {
        buffer.write(',');
      }
      buffer.write(digits[i]);
    }
    return buffer.toString();
  }
}
