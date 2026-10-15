import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:schoolbook/core/theme/app_theme.dart';
import 'package:schoolbook/shared/widgets/app_icons.dart';
import 'package:schoolbook/shared/widgets/list_row_actions.dart';

void main() {
  testWidgets('long-press sheet returns the chosen action id', (tester) async {
    String? picked;
    await tester.pumpWidget(
      MaterialApp(
        theme: buildLightTheme(),
        home: Scaffold(
          body: Builder(
            builder: (context) => TextButton(
              onPressed: () async {
                picked = await showListActionsSheet(
                  context,
                  title: 'Draft #1',
                  actions: const [
                    ListRowAction(
                      id: 'edit',
                      label: 'Edit draft',
                      icon: AppIcons.edit,
                    ),
                    ListRowAction(
                      id: 'cancel',
                      label: 'Cancel draft',
                      icon: AppIcons.delete,
                      danger: true,
                    ),
                  ],
                );
              },
              child: const Text('Open'),
            ),
          ),
        ),
      ),
    );

    await tester.tap(find.text('Open'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('list_action_edit')));
    await tester.pumpAndSettle();
    expect(picked, 'edit');
  });

  testWidgets('partial swipe opens the action; tap runs it', (tester) async {
    String? fired;
    await tester.pumpWidget(
      MaterialApp(
        theme: buildLightTheme(),
        home: Scaffold(
          body: Center(
            child: SizedBox(
              width: 320,
              child: SwipeableRow(
                trailing: const [
                  ListRowAction(
                    id: 'pay',
                    label: 'Pay',
                    icon: AppIcons.payment,
                  ),
                ],
                onAction: (a) => fired = a.id,
                child: const SizedBox(
                  height: 72,
                  child: Center(child: Text('Invoice')),
                ),
              ),
            ),
          ),
        ),
      ),
    );

    final row = find.text('Invoice');
    // Open onto the button, but not past it.
    await tester.drag(row, const Offset(-60, 0));
    await tester.pumpAndSettle();
    expect(fired, isNull);
    final box = tester.getRect(find.byType(SwipeableRow));
    await tester.tapAt(Offset(box.right - 24, box.center.dy));
    await tester.pumpAndSettle();
    expect(fired, 'pay');
  });

  testWidgets('swipe past the far end runs the action', (tester) async {
    String? fired;
    await tester.pumpWidget(
      MaterialApp(
        theme: buildLightTheme(),
        home: Scaffold(
          body: Center(
            child: SizedBox(
              width: 320,
              child: SwipeableRow(
                trailing: const [
                  ListRowAction(
                    id: 'pay',
                    label: 'Pay',
                    icon: AppIcons.payment,
                  ),
                ],
                onAction: (a) => fired = a.id,
                child: const SizedBox(
                  height: 72,
                  child: Center(child: Text('Invoice')),
                ),
              ),
            ),
          ),
        ),
      ),
    );

    // Past the 76px button into overscroll → auto-run.
    await tester.drag(find.text('Invoice'), const Offset(-160, 0));
    await tester.pumpAndSettle();
    expect(fired, 'pay');
  });

  testWidgets('swipe past the leading end runs the action', (tester) async {
    String? fired;
    await tester.pumpWidget(
      MaterialApp(
        theme: buildLightTheme(),
        home: Scaffold(
          body: Center(
            child: SizedBox(
              width: 320,
              child: SwipeableRow(
                leading: const [
                  ListRowAction(
                    id: 'sale',
                    label: 'Sale',
                    icon: AppIcons.sales,
                  ),
                ],
                onAction: (a) => fired = a.id,
                child: const SizedBox(
                  height: 72,
                  child: Center(child: Text('School')),
                ),
              ),
            ),
          ),
        ),
      ),
    );

    await tester.drag(find.text('School'), const Offset(160, 0));
    await tester.pumpAndSettle();
    expect(fired, 'sale');
  });
}
