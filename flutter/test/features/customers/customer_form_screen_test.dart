import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/features/customers/data/customers_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/customers/presentation/customer_form_screen.dart';

import '../../support/fakes.dart';

class _RecordingCustomersRepository extends FakeCustomersRepository {
  final List<Map<String, dynamic>> created = [];

  @override
  Future<Customer> createCustomer(Map<String, dynamic> payload) async {
    created.add(payload);
    return testCustomer(id: 42);
  }
}

void main() {
  late _RecordingCustomersRepository repo;

  Future<void> pumpForm(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1080, 3200);
    tester.view.devicePixelRatio = 1.5;
    addTearDown(tester.view.reset);

    final router = GoRouter(
      initialLocation: '/customers/new',
      routes: [
        GoRoute(
          path: '/customers/new',
          builder: (_, _) => const CustomerFormScreen(),
        ),
        GoRoute(
          path: '/customers/:id',
          builder: (_, s) =>
              Scaffold(body: Text('customer ${s.pathParameters['id']}')),
        ),
      ],
    );
    await tester.pumpWidget(
      Provider<CustomersRepository>.value(
        value: repo,
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> pickRegion(WidgetTester tester, String region) async {
    await tester.tap(find.text('Region'));
    await tester.pumpAndSettle();
    await tester.tap(find.text(region).last);
    await tester.pumpAndSettle();
  }

  setUp(() => repo = _RecordingCustomersRepository());

  testWidgets(
    'name and region are required; the credit limit must be exact GHS',
    (tester) async {
      await pumpForm(tester);
      await tester.enterText(
        find.byKey(const Key('cust_credit_limit')),
        '10.999',
      );
      await tester.tap(find.byKey(const Key('cust_save')));
      await tester.pumpAndSettle();

      expect(find.text('Name is required'), findsOneWidget);
      expect(find.text('Pick a region'), findsOneWidget);
      expect(
        find.text('Use an amount like 1250, 1,250 or 1,250.50'),
        findsOneWidget,
      );
      expect(repo.created, isEmpty);
    },
  );

  testWidgets('saving sends API keys with the credit limit in pesewas', (
    tester,
  ) async {
    await pumpForm(tester);
    await tester.enterText(
      find.byKey(const Key('cust_name')),
      '  Bethel Academy ',
    );
    await pickRegion(tester, 'Bono East');
    await tester.enterText(
      find.byKey(const Key('cust_credit_limit')),
      '2,500.29',
    );
    await tester.tap(find.byKey(const Key('cust_save')));
    await tester.pumpAndSettle();

    expect(repo.created.single, containsPair('name', 'Bethel Academy'));
    expect(repo.created.single, containsPair('region', 'Bono East'));
    expect(repo.created.single, containsPair('type', 'school'));
    expect(repo.created.single, containsPair('credit_limit', 250029));
    expect(repo.created.single, containsPair('email', null));
    expect(find.text('customer 42'), findsOneWidget);
  });

  testWidgets('a blank credit limit means no limit', (tester) async {
    await pumpForm(tester);
    await tester.enterText(
      find.byKey(const Key('cust_name')),
      'No Limit School',
    );
    await pickRegion(tester, 'Volta');
    await tester.tap(find.byKey(const Key('cust_save')));
    await tester.pumpAndSettle();

    expect(repo.created.single['credit_limit'], isNull);
  });

  test('all 16 regions of Ghana are offered', () {
    expect(CustomerOptions.regions, hasLength(16));
  });
}
