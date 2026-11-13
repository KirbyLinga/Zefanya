import 'dart:async';
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
  List<String> paths, {
  Future<http.Response> Function(http.Request)? respond,
}) async {
  SharedPreferences.setMockInitialValues({});
  tester.view.physicalSize = const Size(360, 640);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final client = ApiClient(
    baseUrl: 'https://example.test/api',
    tokenStore: InMemoryTokenStore(),
    httpClient: MockClient((request) async {
      paths.add(request.url.path);
      if (respond != null) return respond(request);
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
  testWidgets(
    'foreground return refreshes trips; offline failure recovers without status writes',
    (tester) async {
      var status = 'Loading';
      var offline = false;
      var reads = 0;
      final paths = <String>[];
      await _mount(
        tester,
        const LinehaulPortal(),
        _Auth()..role = UserRole.linehaul,
        paths,
        respond: (request) async {
          expectSync(request.method, 'GET');
          if (request.url.path.endsWith('/trips')) {
            reads++;
            if (offline) {
              return http.Response(
                jsonEncode({'message': 'No connection. Please retry.'}),
                503,
                headers: {'content-type': 'application/json'},
              );
            }
          }
          return http.Response(
            jsonEncode({
              'data': request.url.path.endsWith('/trips')
                  ? [
                      {
                        'id': 12,
                        'status': status,
                        'origin': 'Pila',
                        'destination': 'Calamba',
                        'seal_number': 'BH-0000771',
                        'items': [
                          {
                            'tracking_number': 'TRK-001',
                            'loaded': true,
                            'received': false,
                          },
                        ],
                      },
                    ]
                  : {
                      'setup_required': false,
                      'on_duty': false,
                      'available': false,
                      'status': 'Off Duty',
                    },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        },
      );
      expect(reads, 1);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await tester.pump(const Duration(minutes: 2));
      expect(reads, 1);
      status = 'In Transit';
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(reads, 2);
      expect(find.text('In Transit'), findsOneWidget);
      expect(find.text('Loading'), findsNothing);
      expect(find.text('Seal: BH-0000771'), findsOneWidget);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(
        reads,
        2,
        reason: 'Duplicate resumed notification must not reload',
      );
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      offline = true;
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(find.text('Your trips could not be loaded'), findsOneWidget);
      expect(find.text('In Transit'), findsNothing);
      offline = false;
      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();
      expect(reads, 4);
      expect(find.text('In Transit'), findsOneWidget);
      expect(find.text('Seal: BH-0000771'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

  for (final tab in ['History', 'Earnings']) {
    testWidgets(
      'foreground return refreshes visible $tab and keeps its query',
      (tester) async {
        final queries = <Map<String, String>>[];
        await _mount(
          tester,
          const LinehaulPortal(),
          _Auth()..role = UserRole.linehaul,
          [],
          respond: (request) async {
            expectSync(request.method, 'GET');
            final endpoint = request.url.path.split('/').last;
            final report = endpoint == tab.toLowerCase();
            if (report) queries.add(request.url.queryParameters);
            final Object data = report
                ? {
                    'page': int.parse(request.url.queryParameters['page']!),
                    'last_page': 1,
                    'timezone': 'Asia/Manila',
                    'trips': [],
                    if (tab == 'History')
                      'total': queries.length
                    else ...{
                      'completed_trips': 1,
                      'earned_trips': 1,
                      'unconfirmed_trips': 0,
                      'earned_minor': queries.length * 100000,
                    },
                  }
                : endpoint == 'trips'
                ? []
                : {
                    'setup_required': false,
                    'on_duty': false,
                    'available': false,
                    'status': 'Off Duty',
                  };
            return http.Response(
              jsonEncode({'data': data}),
              200,
              headers: {'content-type': 'application/json'},
            );
          },
        );
        await tester.tap(find.text(tab));
        await tester.pumpAndSettle();
        expect(queries.length, 1);
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
        tester.binding.handleAppLifecycleStateChanged(
          AppLifecycleState.resumed,
        );
        await tester.pumpAndSettle();
        expect(queries.length, 2);
        expect(queries.last, queries.first);
        expect(
          find.text(
            tab == 'History' ? '2 history records in this range' : '₱2,000.00',
          ),
          findsOneWidget,
        );
        expect(tester.takeException(), isNull);
      },
    );
  }

  testWidgets(
    'revisited reports refresh selected dates and page, retry keeps context',
    (tester) async {
      final history = <Map<String, String>>[];
      final earnings = <Map<String, String>>[];
      final pending = Completer<http.Response>();
      http.Response response(Object data) => http.Response(
        jsonEncode({'data': data}),
        200,
        headers: {'content-type': 'application/json'},
      );
      Map<String, Object?> report(http.Request request, {required bool pay}) =>
          {
            'page': int.parse(request.url.queryParameters['page']!),
            'last_page': 2,
            'timezone': 'Asia/Manila',
            'trips': [],
            if (pay) ...{
              'earned_minor': earnings.length * 100000,
              'earned_trips': 21,
              'unconfirmed_trips': 0,
              'completed_trips': 21,
            } else
              'total': 21 + history.length,
          };
      await _mount(
        tester,
        const LinehaulPortal(),
        _Auth()..role = UserRole.linehaul,
        [],
        respond: (request) async {
          expect(request.method, 'GET');
          if (request.url.path.endsWith('/history')) {
            history.add(request.url.queryParameters);
            if (history.length == 3) return pending.future;
            return response(report(request, pay: false));
          }
          if (request.url.path.endsWith('/earnings')) {
            earnings.add(request.url.queryParameters);
            if (earnings.length == 3) {
              return http.Response(
                jsonEncode({'message': 'Report unavailable'}),
                503,
                headers: {'content-type': 'application/json'},
              );
            }
            return response(report(request, pay: true));
          }
          return response(
            request.url.path.endsWith('/trips')
                ? []
                : {
                    'setup_required': false,
                    'on_duty': false,
                    'available': false,
                    'status': 'Off Duty',
                  },
          );
        },
      );
      await tester.tap(find.text('History'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Next'));
      await tester.pumpAndSettle();
      expect(history.last['page'], '2');
      final selectedHistory = Map<String, String>.of(history.last);
      await tester.tap(find.text('Earnings'));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Next'),
        150,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.drag(find.byType(ListView).first, const Offset(0, -180));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Next'));
      await tester.pumpAndSettle();
      expect(earnings.last['page'], '2');
      final selectedEarnings = Map<String, String>.of(earnings.last);
      await tester.tap(find.text('History'));
      await tester.pump();
      expect(history.last, selectedHistory);
      expect(find.text('24 history records in this range'), findsNothing);
      await tester.tap(find.text('Profile'));
      await tester.pump();
      await tester.tap(find.text('History'));
      await tester.pump();
      expect(
        history.length,
        3,
        reason: 'Pending refresh must not be duplicated',
      );
      pending.complete(
        response({
          'page': 2,
          'last_page': 2,
          'timezone': 'Asia/Manila',
          'trips': [],
          'total': 24,
        }),
      );
      await tester.pumpAndSettle();
      expect(find.text('24 history records in this range'), findsOneWidget);
      expect(find.text('Page 2 of 2'), findsOneWidget);
      await tester.tap(find.text('History'));
      await tester.pumpAndSettle();
      expect(
        history.length,
        3,
        reason: 'Reselecting active tab must not reload',
      );
      await tester.tap(find.text('Earnings'));
      await tester.pumpAndSettle();
      expect(earnings.last, selectedEarnings);
      expect(find.text('₱2,000.00'), findsOneWidget);
      final stale = find.text(
        'Showing the last loaded earnings for these dates.',
      );
      expect(stale, findsOneWidget);
      final retry = find.text('Retry');
      await Scrollable.ensureVisible(tester.element(retry), alignment: .45);
      await tester.pumpAndSettle();
      await tester.tap(retry.hitTestable());
      await tester.pumpAndSettle();
      expect(earnings.length, 4);
      expect(earnings.last, selectedEarnings);
      expect(find.text('₱4,000.00'), findsOneWidget);
      expect(stale, findsNothing);
      expect(find.text('₱2,000.00'), findsNothing);
      await tester.scrollUntilVisible(
        find.text('Page 2 of 2'),
        150,
        scrollable: find.byType(Scrollable).first,
      );
      expect(find.text('Page 2 of 2'), findsOneWidget);
      expect(tester.takeException(), isNull);
    },
  );

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
      expect(paths.where((p) => p.endsWith('/history')).length, 2);
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
