import 'package:flutter/foundation.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';
import 'package:schoolbook/features/auth/domain/auth_user.dart';

enum AuthStatus { unknown, authenticated, unauthenticated }

class AuthProvider extends ChangeNotifier {
  AuthProvider({required AuthRepository repository})
      : _repository = repository;

  final AuthRepository _repository;

  AuthStatus status = AuthStatus.unknown;
  AuthUser? user;
  String? errorMessage;
  bool isBusy = false;

  bool get isAuthenticated => status == AuthStatus.authenticated;

  Future<void> bootstrap() async {
    isBusy = true;
    notifyListeners();
    try {
      final hasToken = await _repository.hasStoredToken();
      if (!hasToken) {
        status = AuthStatus.unauthenticated;
        user = null;
        return;
      }
      user = await _repository.fetchMe();
      status = AuthStatus.authenticated;
    } on ApiException {
      await _repository.clearLocalSession();
      status = AuthStatus.unauthenticated;
      user = null;
    } finally {
      isBusy = false;
      notifyListeners();
    }
  }

  Future<bool> login(String email, String password) async {
    errorMessage = null;
    isBusy = true;
    notifyListeners();
    try {
      user = await _repository.login(email: email, password: password);
      status = AuthStatus.authenticated;
      return true;
    } on ApiException catch (e) {
      errorMessage = e.message;
      status = AuthStatus.unauthenticated;
      return false;
    } finally {
      isBusy = false;
      notifyListeners();
    }
  }

  Future<void> logout() async {
    isBusy = true;
    notifyListeners();
    try {
      await _repository.logout();
    } on ApiException {
      await _repository.clearLocalSession();
    } finally {
      user = null;
      status = AuthStatus.unauthenticated;
      isBusy = false;
      notifyListeners();
    }
  }

  void handleUnauthorized() {
    user = null;
    status = AuthStatus.unauthenticated;
    notifyListeners();
  }
}
