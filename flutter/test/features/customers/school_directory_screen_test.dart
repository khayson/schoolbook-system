import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/core/errors.dart';
import 'package:schoolbook/core/idempotency/pending_submission_store.dart';
import 'package:schoolbook/core/pagination.dart';
import 'package:schoolbook/features/customers/data/school_directory_repository.dart';
import 'package:schoolbook/features/customers/domain/customer.dart';
import 'package:schoolbook/features/customers/domain/directory_school.dart';
import 'package:schoolbook/features/customers/presentation/school_directory_screen.dart';

import '../../support/fakes.dart';

const stPeters = DirectorySchool(
  id: 1,
  name: "St. Peter's R/C Basic School",
  region: 'Central',
  district: 'Awutu Senya East Municipal',
  town: 'Kasoa',
  phone: '+233 20 000 0001',
  levelLabel: 'Primary, JHS',
);
const gomoa = DirectorySchool(
  id: 2,
  name: 'Gomoa Fetteh D/A Primary',
  region: 'Central',
  district: 'Gomoa East',
);
const added = DirectorySchool(
  id: 3,
  name: 'Seaview SHS',
  region: 'Greater Accra',
  customerId: 77,
);

class FakeDirectoryRepository extends SchoolDirectoryRepository {
  FakeDirectoryRepository() : super(apiClient: unusedApiClient());

  final List<({String? search, String? region})> searches = [];
  final List<
    ({int school, String key, int? link, String? contact, String? phone})
  >
  adds = [];
  final List<Object> addResults = [];

  @override
  Future<DirectorySearchResult> search({
    String? search,
    String? region,
    int page = 1,
  }) async {
    searches.add((search: search, region: region));
    final q = (search ?? '').toLowerCase();
    final all = [
      stPeters,
      gomoa,
      added,
    ].where((s) => region == null || s.region == region);
    final rows = all
        .where(
          (s) =>
              q.isEmpty ||
              '${s.name} ${s.district} ${s.town}'.toLowerCase().contains(q),
        )
        .toList();
    return DirectorySearchResult(
      page: PaginatedResponse(
        data: rows,
        meta: PaginatedMeta(
          currentPage: 1,
          lastPage: 1,
          perPage: 25,
          total: rows.length,
        ),
      ),
      attribution: 'School directory data © OpenStreetMap contributors',
    );
  }

  @override
  Future<Customer> addAsCustomer(
    int schoolId, {
    required String idempotencyKey,
    int? linkCustomerId,
    String? contactPerson,
    String? phone,
  }) async {
    adds.add((
      school: schoolId,
      key: idempotencyKey,
      link: linkCustomerId,
      contact: contactPerson,
      phone: phone,
    ));
    final result = addResults.removeAt(0);
    if (result is ApiException) {
      throw result;
    }
    return result as Customer;
  }
}

void main() {
  late FakeDirectoryRepository repo;

  Future<void> pump(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    final router = GoRouter(
      initialLocation: '/customers/directory',
      routes: [
        GoRoute(
          path: '/customers/directory',
          builder: (_, _) => const SchoolDirectoryScreen(),
        ),
        GoRoute(
          path: '/customers/:id',
          builder: (_, s) =>
              Scaffold(body: Text('customer ${s.pathParameters['id']}')),
        ),
      ],
    );
    await tester.pumpWidget(
      MultiProvider(
        providers: [
          Provider<SchoolDirectoryRepository>.value(value: repo),
          Provider<PendingSubmissionStore>.value(
            value: PendingSubmissionStore(store: InMemoryKeyValueStore()),
          ),
        ],
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pumpAndSettle();
  }

  setUp(() => repo = FakeDirectoryRepository());

  testWidgets('search and region narrow the list; added schools are marked', (
    tester,
  ) async {
    await pump(tester);
    expect(find.text("St. Peter's R/C Basic School"), findsOneWidget);
    expect(
      find.text('Awutu Senya East Municipal | Kasoa | Primary, JHS'),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('directory_school_3')),
        matching: find.text('Added'),
      ),
      findsOneWidget,
    );
    expect(find.textContaining('OpenStreetMap contributors'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('directory_search')), 'kasoa');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();
    expect(repo.searches.last, (search: 'kasoa', region: null));
    expect(find.byKey(const Key('directory_school_2')), findsNothing);

    await tester.enterText(find.byKey(const Key('directory_search')), '');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.tap(
      find.descendant(
        of: find.byKey(const Key('directory_region')),
        matching: find.text('Greater Accra'),
      ),
    );
    await tester.pumpAndSettle();
    expect(repo.searches.last.region, 'Greater Accra');
    expect(find.byKey(const Key('directory_school_1')), findsNothing);
    expect(find.byKey(const Key('directory_school_3')), findsOneWidget);
  });

  testWidgets(
    'adding a school sends the details once and opens the new customer',
    (tester) async {
      repo.addResults.add(testCustomer(id: 41));
      await pump(tester);

      await tester.tap(find.byKey(const Key('directory_school_1')));
      await tester.pumpAndSettle();
      expect(
        tester
            .widget<TextField>(find.byKey(const Key('directory_phone')))
            .controller!
            .text,
        '+233 20 000 0001',
      );
      await tester.enterText(
        find.byKey(const Key('directory_contact')),
        'Mrs Mensah',
      );
      await tester.tap(find.byKey(const Key('directory_add_confirm')));
      await tester.pumpAndSettle();

      expect(repo.adds.single.school, 1);
      expect(repo.adds.single.contact, 'Mrs Mensah');
      expect(repo.adds.single.phone, '+233 20 000 0001');
      expect(repo.adds.single.key, isNotEmpty);
      expect(find.text('customer 41'), findsOneWidget);
    },
  );

  testWidgets(
    'a school whose name is already a customer is linked, not added twice',
    (tester) async {
      repo.addResults
        ..add(
          ApiException(
            message: 'exists',
            statusCode: 409,
            code: 'customer_name_exists',
            details: {
              'customer_id': 9,
              'code': 'CUS-0009',
              'name': 'GOMOA FETTEH D/A PRIMARY',
            },
          ),
        )
        ..add(testCustomer(id: 9));
      await pump(tester);

      await tester.tap(find.byKey(const Key('directory_school_2')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('directory_add_confirm')));
      await tester.pumpAndSettle();
      expect(find.text('Already a customer?'), findsOneWidget);

      await tester.tap(find.byKey(const Key('directory_link_existing')));
      await tester.pumpAndSettle();
      expect(repo.adds.map((a) => a.link), [null, 9]);
      expect(repo.adds[0].key == repo.adds[1].key, isFalse);
      expect(find.text('customer 9'), findsOneWidget);
    },
  );

  testWidgets('tapping an added school opens its customer', (tester) async {
    await pump(tester);
    await tester.tap(find.byKey(const Key('directory_school_3')));
    await tester.pumpAndSettle();
    expect(find.text('customer 77'), findsOneWidget);
    expect(repo.adds, isEmpty);
  });
}
