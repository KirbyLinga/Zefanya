import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:zefanya/widgets/dirty_guard.dart';

void main() {
  testWidgets('dirty form Back offers Stay and Discard; clean Back exits', (
    tester,
  ) async {
    GoogleFonts.config.allowRuntimeFetching = false;
    var dirty = true;
    final navigator = GlobalKey<NavigatorState>();
    await tester.pumpWidget(
      MaterialApp(
        navigatorKey: navigator,
        home: const Scaffold(body: Text('Home')),
      ),
    );
    navigator.currentState!.push(
      MaterialPageRoute<void>(
        builder: (_) => DirtyGuard(
          hasUnsavedInput: () => dirty,
          child: const Scaffold(body: Text('Form')),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('Discard changes?'), findsOneWidget);
    await tester.tap(find.text('Stay'));
    await tester.pumpAndSettle();
    expect(find.text('Form'), findsOneWidget);
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    await tester.tap(find.text('Discard'));
    await tester.pumpAndSettle();
    expect(find.text('Home'), findsOneWidget);
    dirty = false;
    navigator.currentState!.push(
      MaterialPageRoute<void>(
        builder: (_) => DirtyGuard(
          hasUnsavedInput: () => dirty,
          child: const Scaffold(body: Text('Form')),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('Home'), findsOneWidget);
    expect(find.text('Discard changes?'), findsNothing);
  });
}
