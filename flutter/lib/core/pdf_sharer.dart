import 'dart:io';

import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

/// Writes PDF bytes to a temporary file and opens the system share sheet
/// (WhatsApp, email, print...). Behind an interface so widget tests can fake it.
abstract interface class PdfSharer {
  Future<void> sharePdf(
    List<int> bytes, {
    required String fileName,
    String? subject,
  });
}

class SystemPdfSharer implements PdfSharer {
  const SystemPdfSharer();

  @override
  Future<void> sharePdf(
    List<int> bytes, {
    required String fileName,
    String? subject,
  }) async {
    final directory = await getTemporaryDirectory();
    final file = File('${directory.path}${Platform.pathSeparator}$fileName');
    await file.writeAsBytes(bytes, flush: true);

    await SharePlus.instance.share(
      ShareParams(
        files: [XFile(file.path, mimeType: 'application/pdf')],
        subject: subject,
      ),
    );
  }
}
