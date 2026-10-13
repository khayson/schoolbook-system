import 'package:flutter/foundation.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';

class RegisterProvider extends ChangeNotifier {
  bool isBusy = false;
  String? errorMessage;

  Future<bool> register(
    AuthRepository repo, {
    required String name,
    required String email,
    required String password,
  }) async {
    errorMessage = null;
    isBusy = true;
    notifyListeners();
    try {
      await repo.register(name: name, email: email, password: password);
      return true;
    } on ApiException catch (e) {
      errorMessage = e.message;
      return false;
    } finally {
      isBusy = false;
      notifyListeners();
    }
  }
}
