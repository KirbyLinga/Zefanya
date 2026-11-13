// test/chat_inbox_test.dart
//
// S5.5: mock-mode chat inbox. Distinct conversations, recency order, an unread
// dot that clears when the thread is read, an honest empty state, and a Home
// masthead entry whose badge counts unread messages across all threads.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/config/dev_flags.dart' show kUseLiveApi;
import 'package:google_fonts/google_fonts.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zefanya/screens/buyer/buyer_shell.dart';
import 'package:zefanya/screens/shared/chat_inbox_screen.dart';
import 'package:zefanya/screens/shared/chat_screen.dart';
import 'package:zefanya/state/auth_state.dart';
import 'package:zefanya/state/cart_state.dart';
import 'package:zefanya/state/catalog_state.dart';
import 'package:zefanya/state/chat_state.dart';
import 'package:zefanya/state/courier_state.dart';
import 'package:zefanya/state/notification_state.dart';
import 'package:zefanya/state/order_state.dart';
import 'package:zefanya/state/recently_viewed_state.dart';
import 'package:zefanya/state/review_state.dart';
import 'package:zefanya/state/theme_state.dart';
import 'package:zefanya/state/wishlist_state.dart';
import 'package:zefanya/theme/app_theme.dart';
import 'package:zefanya/widgets/chat_inbox_button.dart';

ChatMessage _incoming(String text) =>
    ChatMessage(text: text, isMe: false, time: 'Now');

Future<ChatState> _chatWithUnread() async {
  final chat = ChatState();
  await chat.load();
  await chat.seedIfEmpty('Sage & Clay', _incoming('Hi! How can I help?'));
  chat.markRead('Sage & Clay');
  await chat.addMessage('Sage & Clay', _incoming('Your order is on the way.'));
  return chat;
}

Widget _app(ChatState chat, Widget home) {
  return ChangeNotifierProvider<ChatState>.value(
    value: chat,
    child: MaterialApp(theme: AppTheme.light, home: home),
  );
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    GoogleFonts.config.allowRuntimeFetching = false;
    AppColors.apply(Brightness.light);
  });

  group('conversations', () {
    test('unread counts the other side until the thread is read', () async {
      final chat = await _chatWithUnread();

      expect(chat.conversations, hasLength(1));
      expect(chat.conversations.single.contactName, 'Sage & Clay');
      expect(
        chat.conversations.single.lastMessage?.text,
        'Your order is on the way.',
      );
      expect(chat.conversations.single.unreadCount, 1);
      expect(chat.totalUnread, 1);

      chat.markRead('Sage & Clay');

      expect(chat.conversations.single.unreadCount, 0);
      expect(chat.totalUnread, 0);
    });

    test('a message the buyer sent is never unread', () async {
      final chat = ChatState();
      await chat.load();
      await chat.seedIfEmpty('Sage & Clay', _incoming('Hello'));
      chat.markRead('Sage & Clay');

      await chat.addMessage(
        'Sage & Clay',
        const ChatMessage(text: 'Thanks!', isMe: true, time: 'Now'),
      );

      expect(chat.totalUnread, 0);
    });

    test('the most recently active thread comes first', () async {
      final chat = ChatState();
      await chat.load();
      await chat.seedIfEmpty('Rattan & Reed', _incoming('Hello'));
      await chat.seedIfEmpty('Sage & Clay', _incoming('Hello'));

      expect(chat.conversations.map((c) => c.contactName), [
        'Sage & Clay',
        'Rattan & Reed',
      ]);

      await chat.addMessage('Rattan & Reed', _incoming('Still there?'));

      expect(chat.conversations.map((c) => c.contactName), [
        'Rattan & Reed',
        'Sage & Clay',
      ]);
    });
  });

  group('inbox screen', () {
    testWidgets('empty state says how a conversation starts', (tester) async {
      final chat = ChatState();
      await chat.load();
      await tester.pumpWidget(_app(chat, const ChatInboxScreen()));
      await tester.pump();

      expect(find.text('No messages yet.'), findsOneWidget);
      expect(
        find.text('Chat with sellers from a product page.'),
        findsOneWidget,
      );
    });

    testWidgets('a conversation shows its last line and an unread dot', (
      tester,
    ) async {
      final chat = await _chatWithUnread();
      await tester.pumpWidget(_app(chat, const ChatInboxScreen()));
      await tester.pump();

      expect(find.text('Sage & Clay'), findsOneWidget);
      expect(find.text('Your order is on the way.'), findsOneWidget);
      expect(
        find.byKey(const ValueKey('chat-unread-Sage & Clay')),
        findsOneWidget,
      );
    });

    testWidgets('opening a conversation marks it read', (tester) async {
      final chat = await _chatWithUnread();
      await tester.pumpWidget(_app(chat, const ChatInboxScreen()));
      await tester.pump();

      await tester.tap(
        find.byKey(const ValueKey('chat-conversation-Sage & Clay')),
      );
      await tester.pumpAndSettle();
      await tester.pump();

      expect(find.byType(ChatScreen), findsOneWidget);
      expect(chat.totalUnread, 0);

      await tester.pumpWidget(const SizedBox.shrink());
    });
  });

  group('Home entry', () {
    testWidgets('badge and label count unread messages', (tester) async {
      final handle = tester.ensureSemantics();
      final chat = await _chatWithUnread();

      await tester.pumpWidget(
        _app(chat, const Scaffold(body: Center(child: ChatInboxButton()))),
      );
      await tester.pump();

      expect(find.byKey(const ValueKey('open-chat-inbox')), findsOneWidget);
      expect(find.text('1'), findsOneWidget);
      expect(find.bySemanticsLabel('Messages, 1 unread'), findsOneWidget);

      handle.dispose();
    });

    testWidgets('no badge at zero', (tester) async {
      final chat = ChatState();
      await chat.load();

      await tester.pumpWidget(
        _app(chat, const Scaffold(body: Center(child: ChatInboxButton()))),
      );
      await tester.pump();

      expect(find.text('0'), findsNothing);
    });

    testWidgets('Home shows Messages only when chat is available', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(390, 1400);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      final catalog = CatalogState();
      await catalog.load();
      final chat = await _chatWithUnread();

      await tester.pumpWidget(
        MediaQuery(
          data: const MediaQueryData(disableAnimations: true),
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<CatalogState>.value(value: catalog),
              ChangeNotifierProvider<ChatState>.value(value: chat),
              ChangeNotifierProvider(create: (_) => AuthState()),
              ChangeNotifierProvider(create: (_) => CartState()),
              ChangeNotifierProvider(create: (_) => CourierState()),
              ChangeNotifierProvider(create: (_) => NotificationState()),
              ChangeNotifierProvider(create: (_) => OrderState()),
              ChangeNotifierProvider(create: (_) => RecentlyViewedState()),
              ChangeNotifierProvider(create: (_) => ReviewState()),
              ChangeNotifierProvider(create: (_) => WishlistState()),
              ChangeNotifierProvider(create: (_) => ThemeState()),
            ],
            child: MaterialApp(theme: AppTheme.light, home: const BuyerShell()),
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      expect(find.byTooltip('Open cart').hitTestable(), findsOneWidget);
      expect(
        find.byKey(const ValueKey('open-chat-inbox')),
        kUseLiveApi ? findsNothing : findsOneWidget,
      );
    });
  });
}
