import 'package:flutter/foundation.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/onboarding_store.dart';
import 'package:schoolbook/core/pending_registration_store.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';
import 'package:schoolbook/features/auth/domain/auth_user.dart';

enum AuthStatus { unknown, authenticated, unauthenticated, pendingApproval }

class AuthProvider extends ChangeNotifier {
  AuthProvider({
    required this._repository,
    required this._pendingStore,
    OnboardingStore? onboardingStore,
  }) : _onboardingStore = onboardingStore ?? OnboardingStore();

  final AuthRepository _repository;
  final PendingRegistrationStore _pendingStore;
  final OnboardingStore _onboardingStore;

  AuthStatus status = AuthStatus.unknown;
  AuthUser? user;
  String? errorMessage;
  bool isBusy = false;

  /// Email of a registered-but-not-yet-approved account.
  String? pendingEmail;

  /// False until the user finishes (or skips) first-launch onboarding.
  bool hasSeenOnboarding = false;

  bool get isAuthenticated => status == AuthStatus.authenticated;

  Future<void> bootstrap() async {
    isBusy = true;
    notifyListeners();
    try {
      hasSeenOnboarding = await _onboardingStore.hasCompleted();

      final hasToken = await _repository.hasStoredToken();
      if (!hasToken) {
        // Check whether the user registered but hasn't been approved yet.
        final email = await _pendingStore.readEmail();
        if (email != null && email.isNotEmpty) {
          pendingEmail = email;
          status = AuthStatus.pendingApproval;
        } else {
          status = AuthStatus.unauthenticated;
        }
        user = null;
        return;
      }
      user = await _repository.fetchMe();
      // If somehow a pending email is still stored after login, clear it.
      await _pendingStore.clear();
      pendingEmail = null;
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

  Future<void> completeOnboarding() async {
    await _onboardingStore.markCompleted();
    hasSeenOnboarding = true;
    notifyListeners();
  }

  Future<bool> login(String email, String password) async {
    errorMessage = null;
    isBusy = true;
    notifyListeners();
    try {
      user = await _repository.login(email: email, password: password);
      // Clear any pending registration on successful login.
      await _pendingStore.clear();
      pendingEmail = null;
      status = AuthStatus.authenticated;
      return true;
    } on ApiException catch (e) {
      // 403 with code 'forbidden' means the account exists but is inactive
      // (pending admin approval). Route them to the waiting room instead of
      // showing a generic error.
      if (e.statusCode == 403 && e.code == 'forbidden') {
        pendingEmail = email;
        await _pendingStore.writeEmail(email);
        status = AuthStatus.pendingApproval;
        notifyListeners();
        return false;
      }
      errorMessage = e.message;
      status = AuthStatus.unauthenticated;
      return false;
    } finally {
      isBusy = false;
      notifyListeners();
    }
  }

  /// Called after successful registration to persist the pending email.
  Future<void> setPendingRegistration(String email) async {
    await _pendingStore.writeEmail(email);
    pendingEmail = email;
    status = AuthStatus.pendingApproval;
    notifyListeners();
  }

  /// Leave the waiting room (or after admin approval) so login is reachable.
  /// Without flipping status off [AuthStatus.pendingApproval], the router
  /// keeps redirecting every route back to `/waiting-room`.
  Future<void> clearPendingRegistration() async {
    await _pendingStore.clear();
    pendingEmail = null;
    status = AuthStatus.unauthenticated;
    notifyListeners();
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
      pendingEmail = null;
      await _pendingStore.clear();
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
