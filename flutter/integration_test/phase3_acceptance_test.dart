// Phase 3.4 acceptance, phone part (docs/acceptance-phase3.md section 5): dashboard,
// light reports, statement and stock-take entry on the Pixel_9a, driven through the real
// app against the reports dataset (section 1) in the throwaway database schoolbook_test,
// served on port 8000 (the app's default). Never run against the dev database: the
// stock-take writes a count.
//
//   flutter test integration_test/phase3_acceptance_test.dart -d emulator-5554 | Tee-Object run.txt
//
// Every expected figure is hand-calculated in docs/acceptance-phase3.md 5.2. The run date
// must be after 2026-07-15 and in a month without dataset sales (any date from August
// 2026): then nothing is sold today or this month and every open invoice is overdue.
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:integration_test/integration_test.dart';
import 'package:provider/provider.dart';
import 'package:schoolbook/app.dart';
import 'package:schoolbook/core/api_client.dart';
import 'package:schoolbook/core/pdf_sharer.dart';

const ownerEmail = String.fromEnvironment('OWNER_EMAIL', defaultValue: 'owner@schoolbook.test');
const ownerPassword = String.fromEnvironment('OWNER_PASSWORD', defaultValue: 'password');

/// Records what the app would hand to the share sheet.
class RecordingPdfSharer implements PdfSharer {
  final List<({String fileName, List<int> bytes})> shared = [];

  @override
  Future<void> sharePdf(List<int> bytes, {required String fileName, String? subject}) async {
    shared.add((fileName: fileName, bytes: bytes));
  }
}

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('Phase 3: dashboard, owing, low stock, statement, stock-take entry', (tester) async {
    void step(String s) => debugPrint('ACCEPTANCE: $s');
    final sharer = RecordingPdfSharer();

    await tester.pumpWidget(SchoolbookApp(pdfSharer: sharer));
    await _waitFor(
      tester,
      find.byWidgetPredicate((w) => w.key == const Key('login_email') || (w is Text && w.data == 'Quick actions')),
    );
    if (find.byTooltip('Sign out').evaluate().isNotEmpty) {
      await tester.tap(find.byTooltip('Sign out'));
      await _settle(tester);
    }
    await _waitFor(tester, find.byKey(const Key('login_email')));
    await tester.enterText(find.byKey(const Key('login_email')), ownerEmail);
    await tester.enterText(find.byKey(const Key('login_password')), ownerPassword);
    await tester.tap(find.byKey(const Key('login_submit')));
    await _waitFor(tester, find.byKey(const Key('card_owed')));
    final api = tester.element(find.byType(Scaffold).first).read<ApiClient>();

    // 1. Dashboard cards (5.2 a) ------------------------------------------------------------
    final cards = {
      'card_sales_today': 'Sales today | GHS 0.00 | 0 sales',
      'card_sales_month': 'This month | GHS 0.00 | 0 sales',
      'card_collections': 'Collected today | GHS 0.00 | Month GHS 0.00',
      'card_owed': 'Owed to you | GHS 490.00',
      'card_overdue': 'Overdue | GHS 490.00',
      'card_credit': 'Credit held | GHS 20.00',
      'card_low_stock': 'Low stock | 3 | products',
    };
    for (final entry in cards.entries) {
      final text = _texts(tester, find.byKey(Key(entry.key)));
      step('1 ${entry.key}: $text');
      expect(text, entry.value);
    }

    // 2. Owed card opens "who owes most": Beta, then Alpha (5.2 b) --------------------------
    await tester.tap(find.byKey(const Key('card_owed')));
    await _waitFor(tester, find.byKey(const Key('owing_total')));
    final owing = _texts(tester, find.byType(ListView));
    step('2 who owes most: $owing');
    expect(owing, contains('Total owed | GHS 490.00'));
    expect(owing.indexOf('Beta School') < owing.indexOf('Alpha School'), isTrue);
    // The "over 90 days" part depends on the run date (5.2 b), so only the totals are fixed.
    expect(owing, contains('Beta School | Overdue GHS 370.00'));
    expect(owing, contains('Alpha School | Overdue GHS 120.00'));
    expect(RegExp(r'Beta School \| [^|]+ \| GHS 370\.00').hasMatch(owing), isTrue);
    expect(RegExp(r'Alpha School \| [^|]+ \| GHS 120\.00').hasMatch(owing), isTrue);
    expect(owing, isNot(contains('Gamma')));

    // 3. Low stock (5.2 c) ------------------------------------------------------------------
    _go(tester, '/reports/low-stock');
    await _waitFor(tester, find.text('Out of stock'));
    final low = _texts(tester, find.byType(ListView));
    step('3 low stock: $low');
    expect(low, 'Maths JHS1 | RPT-C | reorder at 40 | 34 left | Short 6 | '
        'Maths P4 | RPT-A | reorder at 90 | 86 left | Short 4 | '
        'Maths P4 Workbook | RPT-E | reorder at 0 | -1 left | Out of stock');

    // 4. Statement: Beta, January to June, shared from customer detail (5.2 d) -------------
    final beta = await _customerId(api, 'Beta School');
    _go(tester, '/customers/$beta');
    await _waitFor(tester, find.byKey(const Key('customer_statement')));
    await tester.tap(find.byKey(const Key('customer_statement')));
    await _settle(tester);
    await tester.tap(find.byTooltip('Switch to input'));
    await _settle(tester);
    final fields = find.byType(TextField);
    await tester.enterText(fields.at(0), '01/01/2026');
    await tester.enterText(fields.at(1), '06/30/2026');
    await tester.tap(find.text('OK'));
    await _until(tester, () => sharer.shared.isNotEmpty, 'statement shared');
    final pdf = sharer.shared.single;
    step('4 statement shared: ${pdf.fileName}, ${pdf.bytes.length} bytes, starts ${String.fromCharCodes(pdf.bytes.take(4))}');
    expect(pdf.fileName, endsWith('-2026-01-01-2026-06-30.pdf'));
    expect(String.fromCharCodes(pdf.bytes.take(4)), '%PDF');

    // The same period's figures through the API the PDF is rendered from.
    final statement = (await api.get<Map<String, dynamic>>(
      '/customers/$beta/statement',
      queryParameters: {'from': '2026-01-01', 'to': '2026-06-30'},
    )).data!['data'] as Map<String, dynamic>;
    final balances = (statement['lines'] as List).map((l) => (l as Map)['balance']).toList();
    step('4b Beta statement: opening ${statement['opening_balance']}, balances $balances, '
        'totals ${statement['totals']}, closing ${statement['closing_balance']}');
    expect(statement['opening_balance'], 0);
    expect(balances, [12000, 24000, 30000, 28000, 32000, 37000]);
    expect(statement['totals'], {'debits': 39000, 'credits': 2000});
    expect(statement['closing_balance'], 37000);

    // 5. Stock-take entry (5.2 e) -----------------------------------------------------------
    _go(tester, '/stock/counts');
    await _waitFor(tester, find.byKey(const Key('start_count')));
    await tester.tap(find.byKey(const Key('start_count')));
    await _settle(tester);
    await tester.tap(find.byKey(const Key('start_count_confirm')));
    await _waitFor(tester, find.byKey(const Key('count_progress')));
    final reference = _texts(tester, find.byType(AppBar));
    step('5 count opened: $reference, ${_texts(tester, find.byKey(const Key('count_progress')))}');
    expect(_texts(tester, find.byKey(const Key('count_progress'))), '0 of 6 counted');

    const counts = {'RPT-A': ('Maths P4', 84), 'RPT-B': ('Science P4', 44), 'RPT-C': ('Maths JHS1', 30), 'RPT-D': ('Science JHS1', 21), 'RPT-F': ('Science JHS1 Workbook', 8)};
    for (final entry in counts.entries) {
      await tester.enterText(find.byKey(const Key('count_search')), entry.key);
      await _settle(tester);
      await tester.tap(find.text(entry.value.$1));
      await _waitFor(tester, find.byKey(const Key('count_qty_field')));
      await tester.enterText(find.byKey(const Key('count_qty_field')), '${entry.value.$2}');
      await tester.tap(find.byKey(const Key('count_qty_save')));
      await _until(tester, () => find.byKey(const Key('count_qty_field')).evaluate().isEmpty
          && find.byType(CircularProgressIndicator).evaluate().isEmpty, 'count ${entry.key} saved');
      await _settle(tester);
    }
    await tester.enterText(find.byKey(const Key('count_search')), '');
    await _settle(tester);

    final progress = _texts(tester, find.byKey(const Key('count_progress')));
    final variance = _texts(tester, find.byKey(const Key('count_variance_total')));
    step('5b $progress; $variance');
    expect(progress, '5 of 6 counted');
    expect(variance, 'Variance so far: -6 units, GHS -196.00 at cost');

    await tester.tap(find.text('Variances'));
    await _settle(tester);
    final variances = _texts(tester, find.byType(ListView));
    step('5c variances: $variances');
    expect(variances, 'Maths JHS1 | RPT-C | system 34 | 30 | -4 | '
        'Maths P4 | RPT-A | system 86 | 84 | -2 | '
        'Science JHS1 | RPT-D | system 20 | 21 | +1 | '
        'Science JHS1 Workbook | RPT-F | system 9 | 8 | -1');
    expect(find.text('Applying the count is done from the web admin.'), findsOneWidget);
    step('5d done: apply ${reference.replaceAll(' | ', ' ')} on the web admin, then run both reconcile commands');
  });
}

Future<int> _customerId(ApiClient api, String name) async {
  final rows = (await api.get<Map<String, dynamic>>('/customers', queryParameters: {'search': name})).data!['data'] as List;
  return ((rows.firstWhere((r) => (r as Map)['name'] == name)) as Map)['id'] as int;
}

/// The visible Text widgets under [finder], joined with " | ".
String _texts(WidgetTester tester, Finder finder) => tester
    .widgetList<Text>(find.descendant(of: finder, matching: find.byType(Text)))
    .map((t) => t.data ?? t.textSpan?.toPlainText() ?? '')
    .where((s) => s.isNotEmpty)
    .join(' | ');

void _go(WidgetTester tester, String location) {
  GoRouter.of(tester.element(find.byType(Navigator).first)).go(location);
}

Future<void> _settle(WidgetTester tester) async {
  try {
    await tester.pumpAndSettle(const Duration(milliseconds: 100), EnginePhase.sendSemanticsUpdate, const Duration(seconds: 10));
  } on FlutterError {
    // still animating
  }
}

Future<void> _waitFor(WidgetTester tester, Finder finder, {Duration timeout = const Duration(seconds: 30)}) async {
  final end = DateTime.now().add(timeout);
  while (DateTime.now().isBefore(end)) {
    await tester.pump(const Duration(milliseconds: 200));
    if (finder.evaluate().isNotEmpty) {
      await _settle(tester);
      return;
    }
  }
  throw TestFailure('Timed out waiting for $finder');
}

Future<void> _until(WidgetTester tester, bool Function() condition, String what, {Duration timeout = const Duration(seconds: 60)}) async {
  final end = DateTime.now().add(timeout);
  while (DateTime.now().isBefore(end)) {
    if (condition()) return;
    await tester.pump(const Duration(milliseconds: 250));
  }
  throw TestFailure('Timed out waiting for: $what');
}
