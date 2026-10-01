/// Display and parse helpers for integer pesewas (1 GHS = 100 pesewas).
/// Parsing never uses floating-point arithmetic.
abstract final class Money {
  /// Formats [pesewas] as `GHS 12.34`.
  static String formatPesewas(int pesewas) {
    final sign = pesewas < 0 ? '-' : '';
    final absolute = pesewas.abs();
    final whole = absolute ~/ 100;
    final fraction = absolute % 100;
    final wholeGrouped = _groupThousands(whole);
    return 'GHS $sign$wholeGrouped.${fraction.toString().padLeft(2, '0')}';
  }

  /// Parses a user-entered GHS amount to pesewas, or null if invalid.
  /// Rejects more than 2 decimal places. No floats.
  static int? parseGhsToPesewas(String input) {
    final normalized = input.trim().replaceAll(',', '');
    if (normalized.isEmpty) {
      return null;
    }

    final match = RegExp(r'^(\d+)(?:\.(\d{1,2}))?$').firstMatch(normalized);
    if (match == null) {
      return null;
    }

    final whole = int.parse(match.group(1)!);
    final fractionRaw = match.group(2);
    final fraction = fractionRaw == null
        ? 0
        : int.parse(fractionRaw.padRight(2, '0'));

    return whole * 100 + fraction;
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
