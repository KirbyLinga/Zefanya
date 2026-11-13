import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/screens/buyer/widgets/cancel_order_sheet.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/widgets/editorial_status_badge.dart';
import 'package:zefanya/widgets/form_sheet_body.dart';
import 'package:zefanya/widgets/inline_error_notice.dart';

void main() {
  for (final brightness in Brightness.values) {
    for (final size in const [Size(360, 640), Size(360, 740), Size(411, 890)]) {
      testWidgets('shared presentation at $size in ${brightness.name}', (
        tester,
      ) async {
        tester.view.physicalSize = size;
        tester.view.devicePixelRatio = 1;
        tester.view.viewPadding = const FakeViewPadding(top: 28, bottom: 20);
        tester.view.padding = const FakeViewPadding(top: 28, bottom: 20);
        addTearDown(tester.view.reset);
        AppColors.apply(brightness);
        addTearDown(() => AppColors.apply(Brightness.light));
        final palette = AppPalette.of(brightness);
        String? selected;
        await tester.pumpWidget(
          MaterialApp(
            theme: brightness == Brightness.dark
                ? AppTheme.dark
                : AppTheme.light,
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(context).copyWith(
                textScaler: const TextScaler.linear(2),
                disableAnimations: true,
              ),
              child: child!,
            ),
            home: Builder(
              builder: (context) => Scaffold(
                body: SafeArea(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      for (final (label, tint) in [
                        ('Waiting for confirmation', palette.primaryDark),
                        ('Received with issues', palette.danger),
                        ('Completed', palette.tertiary),
                      ])
                        EditorialStatusBadge(label: label, tint: tint),
                      TextButton(
                        onPressed: () async {
                          selected = await showCancelOrderSheet(
                            context,
                            'ORDER-123',
                          );
                        },
                        child: const Text('Open order sheet'),
                      ),
                      TextButton(
                        onPressed: () => showModalBottomSheet<void>(
                          context: context,
                          useSafeArea: true,
                          isScrollControlled: true,
                          builder: (_) => FormSheetBody(
                            padding: const EdgeInsets.all(20),
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                const TextField(
                                  decoration: InputDecoration(
                                    labelText: 'Reason',
                                  ),
                                ),
                                const InlineErrorNotice(
                                  'The request failed. Check your connection and try again.',
                                ),
                                FilledButton(
                                  onPressed: () {},
                                  child: const Text('Retry report'),
                                ),
                              ],
                            ),
                          ),
                        ),
                        child: const Text('Open report'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        );

        for (final element in find.byType(EditorialStatusBadge).evaluate()) {
          final badge = element.widget as EditorialStatusBadge;
          final foreground = tester
              .widget<Text>(find.text(badge.label))
              .style!
              .color!;
          for (final surface in [palette.surface, palette.surfaceMuted]) {
            final background = Color.alphaBlend(
              badge.tint.withValues(alpha: .18),
              surface,
            );
            final light = brightness == Brightness.dark
                ? foreground
                : background;
            final dark = brightness == Brightness.dark
                ? background
                : foreground;
            expect(
              (light.computeLuminance() + .05) /
                  (dark.computeLuminance() + .05),
              greaterThanOrEqualTo(4.5),
            );
          }
        }

        await tester.tap(find.text('Open order sheet'));
        await tester.pumpAndSettle();
        final confirm = find.widgetWithText(ElevatedButton, 'Cancel order');
        expect(tester.widget<ElevatedButton>(confirm).onPressed, isNull);
        expect(tester.getSize(confirm).height, greaterThanOrEqualTo(48));
        final reason = find.text('Changed my mind');
        await tester.ensureVisible(reason);
        await tester.tap(reason);
        await tester.ensureVisible(confirm);
        await tester.pumpAndSettle();
        await tester.tap(confirm.hitTestable());
        await tester.pumpAndSettle();
        expect(selected, 'Changed my mind');

        await tester.tap(find.text('Open report'));
        await tester.pumpAndSettle();
        await tester.showKeyboard(find.byType(TextField));
        tester.view.viewInsets = const FakeViewPadding(bottom: 270);
        await tester.pumpAndSettle();
        final retry = find.text('Retry report');
        await tester.ensureVisible(retry);
        await tester.pumpAndSettle();
        expect(retry.hitTestable(), findsOneWidget);
        expect(
          find.descendant(
            of: find.byType(InlineErrorNotice),
            matching: find.byWidgetPredicate(
              (widget) =>
                  widget is Semantics && widget.properties.liveRegion == true,
            ),
          ),
          findsOneWidget,
        );
        expect(tester.getBottomRight(retry).dy, lessThan(size.height - 270));
        expect(tester.takeException(), isNull);
      });
    }
  }
}
