import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/auth_token_store.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/pending_registration_store.dart';
import 'package:schoolbook/features/auth/data/auth_repository.dart';
import 'package:schoolbook/features/auth/presentation/auth_provider.dart';
import 'package:schoolbook/features/auth/presentation/waiting_room_screen.dart';

class _FakeAuthRepository extends AuthRepository {
  _FakeAuthRepository()
      : super(
          apiClient: ApiClient(tokenStore: AuthTokenStore()),
          tokenStore: AuthTokenStore(),
        );

  Map<String, dynamic>? statusResult;
  Object? statusError;

  @override
  Future<Map<String, dynamic>> checkAccountStatus(String email) async {
    final err = statusError;
    if (err != null) throw err;
    return statusResult ??
        {
          'status': 'pending',
          'message':
              'Your account is still under review. An admin needs to activate it — usually within 1–2 business days.',
        };
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
  });

  Future<void> pumpWaitingRoom(
    WidgetTester tester, {
    required _FakeAuthRepository repo,
  }) async {
    final auth = AuthProvider(
      repository: repo,
      pendingStore: PendingRegistrationStore(),
    );
    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider<AuthProvider>.value(value: auth),
          Provider<AuthRepository>.value(value: repo),
        ],
        child: const MaterialApp(
          home: WaitingRoomScreen(email: 'pending@schoolbook.test'),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('check status shows backend pending message', (tester) async {
    final repo = _FakeAuthRepository()
      ..statusResult = {
        'status': 'pending',
        'message':
            'Your account is still under review. An admin needs to activate it — usually within 1–2 business days.',
      };

    await pumpWaitingRoom(tester, repo: repo);
    await tester.ensureVisible(find.byKey(const Key('waiting_room_check')));
    await tester.tap(find.byKey(const Key('waiting_room_check')));
    await tester.pump(); // start async
    await tester.pump(const Duration(milliseconds: 100));
    await tester.pump(const Duration(milliseconds: 400)); // toast enter

    expect(
      find.text(
        'Your account is still under review. An admin needs to activate it — usually within 1–2 business days.',
      ),
      findsOneWidget,
    );
    expect(find.textContaining('Could not check'), findsNothing);
    expect(find.textContaining('Couldn’t check'), findsNothing);
  });

  testWidgets('check status shows backend error message as-is', (tester) async {
    final repo = _FakeAuthRepository()
      ..statusError = ApiException(
        message: 'No account was found for this email.',
        code: 'account_not_found',
        statusCode: 404,
      );

    await pumpWaitingRoom(tester, repo: repo);
    await tester.ensureVisible(find.byKey(const Key('waiting_room_check')));
    await tester.tap(find.byKey(const Key('waiting_room_check')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));
    await tester.pump(const Duration(milliseconds: 400));

    expect(find.text('No account was found for this email.'), findsOneWidget);
    expect(find.textContaining('Could not check'), findsNothing);
  });
}
