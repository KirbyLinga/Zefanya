// lib/screens/shared/chat_screen.dart
//
// Generic messaging thread — reused wherever a "Chat" button already
// existed as a TODO stub: Product Details (buyer <-> seller) and
// Active Delivery (courier <-> seller, courier <-> buyer). Message
// history now lives in the shared ChatState (see state/chat_state.dart)
// so it survives leaving the screen and even an app restart — the
// simulated reply still exists purely to demonstrate the UI pattern,
// since there's no real messaging backend yet.
//
// Design-pass note:
// This was a stock bubble UI — BorderRadius.circular(16) on everything,
// a timestamp stapled inside every single bubble, and a "typing"
// indicator made of three dots that never moved. Because one instance
// of this screen serves every buyer/seller/courier thread in the app,
// it ran the pre-pass look at every call site at once. Three changes:
//
//   1. Bubbles are ANCHORED, not symmetric: the corner nearest the
//      speaker is cut short (6) while the other three stay wide (24).
//      That's the same big/small asymmetry editorialRadius uses, and
//      it means you can tell who said what from the silhouette alone,
//      before reading color.
//   2. Consecutive messages from one speaker are grouped into a run —
//      tight 3px spacing inside a run, 14px between runs — and the
//      timestamp prints once, at the end of the run, outside the
//      bubble. Previously every bubble carried its own time, which is
//      most of what made the thread read as noisy.
//   3. The typing dots actually animate.
//   4. Reduced motion is now respected. This was missed in the initial
//      retrofit (utils/motion.dart) — the animated dots above are the
//      one motion in this file, easy to miss since the screen has no
//      other animation to draw attention to it. Falls back to the dots
//      held at their active color, not the old static grey circles —
//      that static look is exactly the "can't tell typing from stalled"
//      ambiguity point 3 exists to fix, so reduced motion shouldn't
//      silently bring it back for anyone with the setting on.

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../state/chat_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/motion.dart';
import '../../widgets/pressable_scale.dart';

/// Wide corners vs the short "anchor" corner nearest the speaker.
const double _bubbleWide = 24;
const double _bubbleAnchor = 6;

class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key, required this.contactName, required this.contactRole});

  final String contactName;
  final String contactRole;

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  final _controller = TextEditingController();
  final _scrollController = ScrollController();
  bool _isTyping = false;
  bool _hasText = false;

  @override
  void initState() {
    super.initState();
    _controller.addListener(() {
      final has = _controller.text.trim().isNotEmpty;
      if (has != _hasText) setState(() => _hasText = has);
    });
    // Seed an opening line the first time this contact's thread is
    // opened — no-op if there's already history (incl. from a
    // previous session, since ChatState is persisted).
    context.read<ChatState>().seedIfEmpty(
          widget.contactName,
          const ChatMessage(text: 'Hi! Thanks for reaching out — how can I help?', isMe: false, time: '10:02 AM'),
        );
  }

  @override
  void dispose() {
    _controller.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _send() {
    final text = _controller.text.trim();
    if (text.isEmpty) return;

    final chat = context.read<ChatState>();
    chat.addMessage(widget.contactName, ChatMessage(text: text, isMe: true, time: 'Now'));
    _controller.clear();
    setState(() => _isTyping = true);
    _scrollToBottom();

    // Simulated reply so this reads as a live thread rather than a
    // dead-end form — remove once a real backend sends real replies.
    Future.delayed(const Duration(milliseconds: 1100), () {
      if (!mounted) return;
      setState(() => _isTyping = false);
      chat.addMessage(
        widget.contactName,
        const ChatMessage(text: 'Got it — I\'ll check and get back to you shortly.', isMe: false, time: 'Now'),
      );
      _scrollToBottom();
    });
  }

  void _scrollToBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_scrollController.hasClients) return;
      _scrollController.animateTo(
        _scrollController.position.maxScrollExtent,
        duration: const Duration(milliseconds: 250),
        curve: Curves.easeOut,
      );
    });
  }

  // Deterministic per-contact color so different threads are visually
  // distinguishable instead of every avatar being the same flat sage
  // circle regardless of who you're talking to.
  static const _avatarPalette = [AppColors.tertiary, AppColors.primary, AppColors.primaryDark, AppColors.secondary];

  Color _avatarColorFor(String name) => _avatarPalette[name.hashCode.abs() % _avatarPalette.length];

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final messages = context.watch<ChatState>().messagesFor(widget.contactName);
    final avatarColor = _avatarColorFor(widget.contactName);

    return Scaffold(
      backgroundColor: Colors.transparent, // the app-wide gradient in main.dart paints this
      appBar: AppBar(
        titleSpacing: 0,
        title: Row(
          children: [
            Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [avatarColor, Color.lerp(avatarColor, Colors.black, 0.3)!],
                ),
              ),
              alignment: Alignment.center,
              child: Text(
                widget.contactName.isNotEmpty ? widget.contactName[0].toUpperCase() : '?',
                style: const TextStyle(color: AppColors.textOnDark, fontWeight: FontWeight.w700),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  // Role above name, as an eyebrow — same lockup the
                  // rest of the app uses for "tiny label, then the
                  // thing itself".
                  Text(
                    widget.contactRole.toUpperCase(),
                    style: AppType.eyebrow.copyWith(fontSize: 9, letterSpacing: 2.0),
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 2),
                  Text(
                    widget.contactName,
                    style: theme.textTheme.titleSmall?.copyWith(height: 1.1),
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
          ],
        ),
        actions: [
          IconButton(
            onPressed: () {
              // clearSnackBars() first — this icon has no cooldown, so
              // mashing it queued a fresh 4s "Calling..." behind each
              // previous one instead of just restarting the one shown.
              ScaffoldMessenger.of(context)
                ..clearSnackBars()
                ..showSnackBar(SnackBar(content: Text('Calling ${widget.contactName}...')));
            },
            icon: const Icon(Icons.call_outlined),
          ),
        ],
      ),
      body: Column(
        children: [
          Expanded(
            child: ListView.builder(
              controller: _scrollController,
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
              itemCount: messages.length + (_isTyping ? 1 : 0),
              itemBuilder: (context, i) {
                if (_isTyping && i == messages.length) {
                  return const _TypingIndicator();
                }
                final message = messages[i];
                // A "run" is consecutive messages from the same
                // speaker. The tail of a run is what carries the
                // timestamp and the gap before the next speaker.
                final next = i + 1 < messages.length ? messages[i + 1] : null;
                final nextIsTyping = _isTyping && i == messages.length - 1;
                final isRunEnd = nextIsTyping || next == null || next.isMe != message.isMe;
                return _Bubble(theme: theme, message: message, isRunEnd: isRunEnd);
              },
            ),
          ),
          _buildInputBar(theme),
        ],
      ),
    );
  }

  Widget _buildInputBar(ThemeData theme) {
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
      decoration: BoxDecoration(
        color: AppColors.surface,
        border: const Border(top: BorderSide(color: AppColors.divider)),
        boxShadow: [
          BoxShadow(color: AppColors.neutral.withValues(alpha: 0.1), blurRadius: 12, offset: const Offset(0, -3)),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Row(
          children: [
            Expanded(
              child: TextField(
                controller: _controller,
                textInputAction: TextInputAction.send,
                onSubmitted: (_) => _send(),
                decoration: const InputDecoration(hintText: 'Type a message...'),
              ),
            ),
            const SizedBox(width: 8),
            // Fades to 40% opacity when the field is empty — communicates
            // "nothing to send" without a separate disabled state.
            AnimatedOpacity(
              opacity: _hasText ? 1.0 : 0.4,
              duration: const Duration(milliseconds: 150),
              child: PressableScale(
                onTap: _send,
                child: Container(
                  width: 42,
                  height: 42,
                  decoration: const BoxDecoration(
                    color: AppColors.primaryDark,
                    shape: BoxShape.circle,
                  ),
                  alignment: Alignment.center,
                  child: const Icon(Icons.send_rounded, size: 18, color: AppColors.textOnDark),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// One message. The short corner is always on the speaker's side, so
/// the silhouette alone says who's talking.
class _Bubble extends StatelessWidget {
  const _Bubble({required this.theme, required this.message, required this.isRunEnd});

  final ThemeData theme;
  final ChatMessage message;
  final bool isRunEnd;

  @override
  Widget build(BuildContext context) {
    final isMe = message.isMe;

    final radius = BorderRadius.only(
      topLeft: Radius.circular(isMe ? _bubbleWide : _bubbleAnchor),
      topRight: Radius.circular(isMe ? _bubbleAnchor : _bubbleWide),
      bottomLeft: const Radius.circular(_bubbleWide),
      bottomRight: const Radius.circular(_bubbleWide),
    );

    return Padding(
      // Tight inside a run, open between runs — the rhythm is what
      // makes a thread scannable without any extra chrome.
      padding: EdgeInsets.only(bottom: isRunEnd ? 14 : 3),
      child: Column(
        crossAxisAlignment: isMe ? CrossAxisAlignment.end : CrossAxisAlignment.start,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 15, vertical: 11),
            constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.74),
            decoration: BoxDecoration(
              color: isMe ? AppColors.primaryDark : AppColors.surfaceMuted,
              borderRadius: radius,
              border: isMe ? null : Border.all(color: AppColors.divider),
            ),
            child: Text(
              message.text,
              style: theme.textTheme.bodyMedium?.copyWith(
                height: 1.35,
                color: isMe ? AppColors.textOnDark : AppColors.textPrimary,
              ),
            ),
          ),
          // One timestamp per run, outside the bubble. Previously every
          // bubble carried its own, which is most of what made the
          // thread feel cluttered.
          if (isRunEnd)
            Padding(
              padding: const EdgeInsets.only(top: 5, left: 4, right: 4),
              child: Text(
                message.time.toUpperCase(),
                style: AppType.eyebrow.copyWith(fontSize: 9, letterSpacing: 1.6),
              ),
            ),
        ],
      ),
    );
  }
}

/// Three dots that actually move. The previous version generated three
/// static circles, so "typing" looked identical to a stalled thread.
///
/// Reduced motion: NOT the old static-grey-dots look — that's the exact
/// ambiguity this widget was built to fix, so falling back to it would
/// silently undo the point for anyone with the setting on. Held instead
/// at the same peak color the animated dots reach mid-bounce, so it
/// still reads as "active" at a glance, just without the motion.
class _TypingIndicator extends StatefulWidget {
  const _TypingIndicator();

  @override
  State<_TypingIndicator> createState() => _TypingIndicatorState();
}

class _TypingIndicatorState extends State<_TypingIndicator> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1100),
  );

  bool _started = false;
  bool _reduced = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    _reduced = reduceMotion(context);
    if (!_reduced) _controller.repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.only(bottom: 14),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: AppColors.surfaceMuted,
          border: Border.all(color: AppColors.divider),
          borderRadius: const BorderRadius.only(
            topLeft: Radius.circular(_bubbleAnchor),
            topRight: Radius.circular(_bubbleWide),
            bottomLeft: Radius.circular(_bubbleWide),
            bottomRight: Radius.circular(_bubbleWide),
          ),
        ),
        child: _reduced
            ? Row(
                mainAxisSize: MainAxisSize.min,
                children: List.generate(
                  3,
                  (i) => Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 2.5),
                    child: Container(
                      width: 6,
                      height: 6,
                      decoration: const BoxDecoration(shape: BoxShape.circle, color: AppColors.primaryDark),
                    ),
                  ),
                ),
              )
            : AnimatedBuilder(
                animation: _controller,
                builder: (context, _) {
                  return Row(
                    mainAxisSize: MainAxisSize.min,
                    children: List.generate(3, (i) {
                      // Each dot is a third of a cycle behind the last, so
                      // the pulse travels left to right.
                      final phase = (_controller.value - i * 0.18) % 1.0;
                      final lift = phase < 0.4 ? Curves.easeInOut.transform(phase / 0.4) : 0.0;
                      return Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 2.5),
                        child: Transform.translate(
                          offset: Offset(0, -3 * lift),
                          child: Container(
                            width: 6,
                            height: 6,
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              color: Color.lerp(
                                AppColors.textSecondary.withValues(alpha: 0.45),
                                AppColors.primaryDark,
                                lift,
                              ),
                            ),
                          ),
                        ),
                      );
                    }),
                  );
                },
              ),
      ),
    );
  }
}
