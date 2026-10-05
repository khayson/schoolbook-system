import 'dart:io';

import 'package:path_provider/path_provider.dart';

/// Where the approved list is kept on the device (a JSON file: a few hundred KB,
/// public data, so no secure storage needed).
abstract interface class ReferenceCacheStore {
  Future<String?> read();
  Future<void> write(String json);
}

class FileReferenceCacheStore implements ReferenceCacheStore {
  FileReferenceCacheStore({Future<Directory> Function()? directory})
      : _directory = directory ?? getApplicationSupportDirectory;

  final Future<Directory> Function() _directory;

  Future<File> _file() async => File('${(await _directory()).path}/approved_list_snapshot.json');

  @override
  Future<String?> read() async {
    final file = await _file();
    return await file.exists() ? file.readAsString() : null;
  }

  @override
  Future<void> write(String json) async {
    final file = await _file();
    final temp = File('${file.path}.tmp');
    await temp.writeAsString(json, flush: true);
    await temp.rename(file.path); // never leave a half-written copy
  }
}

class InMemoryReferenceCacheStore implements ReferenceCacheStore {
  String? value;

  @override
  Future<String?> read() async => value;

  @override
  Future<void> write(String json) async => value = json;
}
