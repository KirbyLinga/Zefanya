import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/api/api_client.dart';
import 'package:zefanya/data/api/token_store.dart';
import 'package:zefanya/data/repositories/repositories.dart';
import 'package:zefanya/screens/auth/login_screen.dart';
import 'package:zefanya/screens/courier/linehaul_portal.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/config/dev_flags.dart' show kUseLiveApi;

class _Auth extends AuthState {
  UserRole? submittedRole;
  int logoutCalls = 0;
  @override
  Future<void> login({
    UserRole role = UserRole.buyer,
    required String email,
    required String password,
    String? name,
  }) async {
    submittedRole = role;
    this.role = role;
    notifyListeners();
  }

  @override
  Future<void> logout() async {
    logoutCalls++;
    role = null;
    notifyListeners();
  }
}

Future<void> _mount(
  WidgetTester tester,
  Widget screen,
  _Auth auth,
  List<String> paths,
) async {
  SharedPreferences.setMockInitialValues({});
  tester.view.physicalSize = const Size(360, 640);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final client = ApiClient(
    baseUrl: 'https://example.test/api',
    tokenStore: InMemoryTokenStore(),
    httpClient: MockClient((request) async {
      paths.add(request.url.path);
      final Object data = switch (request.url.path.split('/').last) {
        'trips' => <Object>[],
        'history' => {
          'page': 1,
          'last_page': 1,
          'total': 0,
          'timezone': 'Asia/Manila',
          'trips': [],
        },
        'earnings' => {
          'page': 1,
          'last_page': 1,
          'completed_trips': 0,
          'earned_trips': 0,
          'unconfirmed_trips': 0,
          'earned_minor': 0,
          'timezone': 'Asia/Manila',
          'trips': [],
        },
        _ => {
          'setup_required': false,
          'on_duty': false,
          'available': false,
          'status': 'Off Duty',
        },
      };
      return http.Response(
        jsonEncode({'data': data}),
        200,
        headers: {'content-type': 'application/json'},
      );
    }),
  );
  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider<AuthState>.value(value: auth),
        ChangeNotifierProvider(create: (_) => ThemeState()),
        Provider<Repositories>.value(value: Repositories.live(client)),
      ],
      child: MaterialApp(
        theme: AppTheme.light,
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(
            context,
          ).copyWith(textScaler: const TextScaler.linear(1.3)),
          child: child!,
        ),
        home: screen,
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  if (kUseLiveApi) {
    testWidgets(
      'live Courier role link switches the shared form without an extra route',
      (tester) async {
        await _mount(tester, const LoginScreen(), _Auth(), []);
        final link = find.byKey(const ValueKey('courier-mode-switch-link'));
        expect(tester.getSize(link).height, greaterThanOrEqualTo(48));
        await tester.tap(link);
        await tester.pumpAndSettle();
        expect(find.text('COURIER PORTAL'), findsOneWidget);
        expect(find.byType(LoginScreen), findsOneWidget);
        expect(
          Navigator.of(tester.element(find.byType(LoginScreen))).canPop(),
          isFalse,
        );
      },
    );
  }
  testWidgets(
    'Courier shares validated login and submits its real role without unsupported signup or Google',
    (tester) async {
      final auth = _Auth();
      await _mount(
        tester,
        const LoginScreen(initialRole: UserRole.linehaul),
        auth,
        [],
      );
      expect(find.byType(Form), findsOneWidget);
      expect(find.byKey(const ValueKey('login-google-button')), findsNothing);
      expect(find.text('Create a Buyer Account'), findsNothing);
      await tester.ensureVisible(find.text('Login as Courier'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Login as Courier'));
      await tester.pumpAndSettle();
      expect(auth.submittedRole, isNull);
      expect(find.text('Enter your email'), findsOneWidget);
      await tester.enterText(
        find.byType(TextFormField).first,
        'courier@example.test',
      );
      await tester.enterText(find.byType(TextFormField).last, 'Courier@Demo1');
      await tester.ensureVisible(find.text('Login as Courier'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Login as Courier'));
      await tester.pumpAndSettle();
      expect(auth.submittedRole, UserRole.linehaul);
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'Courier role switches back inside shared form without pushing another login route',
    (tester) async {
      await _mount(tester, const LinehaulLoginScreen(), _Auth(), []);
      await tester.ensureVisible(find.text('Back to shopper sign in'));
      await tester.tap(find.text('Back to shopper sign in'));
      await tester.pumpAndSettle();
      expect(find.text('Email or Phone Number'), findsOneWidget);
      expect(find.text('Login as Courier'), findsNothing);
      expect(
        Navigator.of(tester.element(find.byType(LoginScreen))).canPop(),
        isFalse,
      );
    },
  );

  testWidgets(
    'four direct tabs retain history state and Courier uses shared settings with confirmed logout',
    (tester) async {
      final auth = _Auth()
        ..role = UserRole.linehaul
        ..name = 'Demo Courier';
      final paths = <String>[];
      await _mount(tester, const LinehaulPortal(), auth, paths);
      expect(find.byTooltip('Log out'), findsNothing);
      await tester.tap(find.text('History'));
      await tester.pumpAndSettle();
      expect(find.text('Choose dates'), findsOneWidget);
      expect(find.text('Browse all history by date'), findsNothing);
      await tester.tap(find.text('Earnings'));
      await tester.pumpAndSettle();
      expect(find.text('Confirmed earned pay'), findsOneWidget);
      await tester.tap(find.text('History'));
      await tester.pumpAndSettle();
      expect(paths.where((p) => p.endsWith('/history')).length, 1);
      await tester.tap(find.text('Profile'));
      await tester.pumpAndSettle();
      await tester.tap(find.byTooltip('Settings'));
      await tester.pumpAndSettle();
      expect(find.text('Company, vehicle & documents'), findsOneWidget);
      expect(find.text('Addresses'), findsNothing);
      expect(find.text('Account & Security'), findsNothing);
      await tester.scrollUntilVisible(
        find.byKey(const ValueKey('settings-logout')),
        250,
      );
      await tester.tap(find.byKey(const ValueKey('settings-logout')));
      await tester.pumpAndSettle();
      expect(auth.logoutCalls, 0);
      await tester.tap(find.byKey(const ValueKey('settings-logout-stay')));
      await tester.pumpAndSettle();
      expect(auth.logoutCalls, 0);
      await tester.tap(find.byKey(const ValueKey('settings-logout')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('settings-logout-confirm')));
      await tester.pumpAndSettle();
      expect(auth.logoutCalls, 1);
      expect(tester.takeException(), isNull);
    },
  );
}
