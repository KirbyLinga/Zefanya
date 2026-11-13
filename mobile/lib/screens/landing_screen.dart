import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../theme/app_theme.dart';
import '../widgets/motion/ease_in_frame.dart';
import '../widgets/zefanya_mark.dart';

class LandingScreen extends StatefulWidget {
  const LandingScreen({
    super.key,
    required this.onDone,
    required this.onCreateAccount,
    this.reduceMotion = false,
    this.animateEditorial = false,
  });

  final VoidCallback onDone;
  final VoidCallback onCreateAccount;
  final bool reduceMotion;
  final bool animateEditorial;

  @override
  State<LandingScreen> createState() => _LandingScreenState();
}

class _LandingScreenState extends State<LandingScreen>
    with SingleTickerProviderStateMixin, WidgetsBindingObserver {
  bool _committed = false;
  late final _motion = AnimationController(
    vsync: this,
    duration: const Duration(seconds: 12),
  );
  bool _visible = false;
  bool _foreground = true;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    final state = WidgetsBinding.instance.lifecycleState;
    _foreground = state == null || state == AppLifecycleState.resumed;
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _visible =
        TickerMode.valuesOf(context).enabled &&
        (ModalRoute.isCurrentOf(context) ?? true);
    _updateMotion();
  }

  @override
  void didUpdateWidget(covariant LandingScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    _updateMotion();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _foreground = state == AppLifecycleState.resumed;
    _updateMotion();
  }

  bool get _animate =>
      widget.animateEditorial &&
      !widget.reduceMotion &&
      !(MediaQuery.maybeDisableAnimationsOf(context) ?? false);

  void _updateMotion() {
    if (_animate && _visible && _foreground && !_committed) {
      if (!_motion.isAnimating) _motion.repeat();
    } else {
      _motion.stop();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _motion.dispose();
    super.dispose();
  }

  void _open(VoidCallback action) {
    if (_committed) return;
    setState(() => _committed = true);
    _updateMotion();
    action();
  }

  @override
  Widget build(BuildContext context) {
    final brightness = Theme.of(context).brightness;
    final palette = AppPalette.of(brightness);
    final dark = brightness == Brightness.dark;
    final secondaryInk = dark && widget.animateEditorial
        ? Color.lerp(palette.textSecondary, palette.textPrimary, .50)!
        : palette.textSecondary;
    const shape = RoundedRectangleBorder(
      borderRadius: BorderRadius.all(Radius.circular(10)),
    );
    const buttonPadding = EdgeInsets.symmetric(horizontal: 18, vertical: 12);
    final buttonText = Theme.of(context).textTheme.labelLarge;

    final content = SafeArea(
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 480),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24),
            child: LayoutBuilder(
              builder: (context, viewport) {
                final height = viewport.maxHeight;
                final sectionGap = (height * .022).clamp(12.0, 18.0);
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Expanded(
                      child: LayoutBuilder(
                        builder: (context, area) => SingleChildScrollView(
                          padding: const EdgeInsets.symmetric(vertical: 12),
                          child: ConstrainedBox(
                            constraints: BoxConstraints(
                              minHeight: (area.maxHeight - 24).clamp(
                                0.0,
                                double.infinity,
                              ),
                            ),
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                _Masthead(
                                  palette: palette,
                                  secondaryInk: secondaryInk,
                                ),
                                SizedBox(height: sectionGap),
                                EaseInFrame(
                                  index: 0,
                                  enabled:
                                      !widget.animateEditorial &&
                                      !widget.reduceMotion &&
                                      !(MediaQuery.maybeDisableAnimationsOf(
                                            context,
                                          ) ??
                                          false),
                                  child: _EditorialCollage(
                                    height: (height * .60 - 138).clamp(
                                      156.0,
                                      378.0,
                                    ),
                                    palette: palette,
                                  ),
                                ),
                                SizedBox(height: sectionGap + 2),
                                _EditorialCopy(
                                  palette: palette,
                                  secondaryInk: secondaryInk,
                                  wide: viewport.maxWidth >= 350,
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                    ),
                    Divider(height: 1, color: palette.divider),
                    const SizedBox(height: 12),
                    FilledButton(
                      onPressed: _committed ? null : () => _open(widget.onDone),
                      style: FilledButton.styleFrom(
                        backgroundColor: palette.roseGround,
                        foregroundColor: palette.textOnDark,
                        overlayColor: palette.textOnDark,
                        shape: shape,
                        padding: buttonPadding,
                        minimumSize: const Size(48, 50),
                        textStyle: buttonText,
                      ),
                      child: const Text('Sign in', textAlign: TextAlign.center),
                    ),
                    const SizedBox(height: 8),
                    OutlinedButton(
                      onPressed: _committed
                          ? null
                          : () => _open(widget.onCreateAccount),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: palette.textPrimary,
                        overlayColor: palette.textPrimary,
                        side: BorderSide(
                          color: palette.textSecondary.withValues(alpha: .65),
                        ),
                        shape: shape,
                        padding: buttonPadding,
                        minimumSize: const Size(48, 50),
                        textStyle: buttonText,
                      ),
                      child: const Text(
                        'Create Buyer account',
                        textAlign: TextAlign.center,
                      ),
                    ),
                    SizedBox(height: sectionGap),
                  ],
                );
              },
            ),
          ),
        ),
      ),
    );
    return Scaffold(
      backgroundColor: palette.background,
      body: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topRight,
            end: Alignment.bottomLeft,
            colors: [palette.surface, palette.background],
          ),
        ),
        child: widget.animateEditorial
            ? Stack(
                fit: StackFit.expand,
                children: [
                  RepaintBoundary(
                    key: const ValueKey('landing-light-sweep'),
                    child: CustomPaint(
                      painter: _LandingLightSweep(
                        palette: palette,
                        dark: dark,
                        motion: _animate ? _motion : null,
                      ),
                    ),
                  ),
                  RepaintBoundary(child: content),
                ],
              )
            : content,
      ),
    );
  }
}

class _LandingLightSweep extends CustomPainter {
  _LandingLightSweep({
    required this.palette,
    required this.dark,
    required this.motion,
  }) : super(repaint: motion);

  final AppPalette palette;
  final bool dark;
  final Animation<double>? motion;

  @override
  void paint(Canvas canvas, Size size) {
    // Start mid-sweep so motion is visible immediately. Sine keeps both
    // position and velocity continuous across the loop boundary.
    final travel = math.sin(2 * math.pi * (motion?.value ?? 0));
    final cream = dark ? palette.textPrimary : palette.background;
    final highlight = Color.lerp(palette.roseGround, cream, dark ? .38 : .22)!;
    // Limit the brightest/darkest stop so secondary copy keeps its contrast.
    final strength = dark ? .42 : .14;
    final bounds = Offset.zero & size;
    final shader = RadialGradient(
      center: Alignment(.82 * travel, -.62 * travel),
      radius: .88,
      colors: [
        highlight.withValues(alpha: strength),
        palette.roseGround.withValues(alpha: strength * .46),
        palette.roseGround.withValues(alpha: 0),
      ],
      stops: const [0, .54, 1],
    ).createShader(bounds);
    canvas.drawRect(bounds, Paint()..shader = shader);
  }

  @override
  bool shouldRepaint(_LandingLightSweep oldDelegate) =>
      oldDelegate.palette != palette ||
      oldDelegate.dark != dark ||
      oldDelegate.motion != motion;
}

class _Masthead extends StatelessWidget {
  const _Masthead({required this.palette, required this.secondaryInk});

  final AppPalette palette;
  final Color secondaryInk;

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Wrap(
        crossAxisAlignment: WrapCrossAlignment.center,
        spacing: 15,
        runSpacing: 8,
        children: [
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              ExcludeSemantics(
                child: ZefanyaMark(size: 28, color: palette.textPrimary),
              ),
              const SizedBox(width: 10),
              Flexible(
                child: Text(
                  'Zefanya',
                  style: AppType.masthead.copyWith(
                    fontSize: 34,
                    letterSpacing: -1.1,
                    color: palette.textPrimary,
                  ),
                ),
              ),
            ],
          ),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              ColoredBox(
                color: palette.primaryDark,
                child: const SizedBox(width: 12, height: 1),
              ),
              const SizedBox(width: 7),
              Flexible(
                child: Text(
                  'THE LOCAL EDIT',
                  style: AppType.eyebrow.copyWith(color: secondaryInk),
                ),
              ),
            ],
          ),
        ],
      ),
      const SizedBox(height: 12),
      Divider(height: 1, color: palette.divider),
    ],
  );
}

class _EditorialCopy extends StatelessWidget {
  const _EditorialCopy({
    required this.palette,
    required this.secondaryInk,
    required this.wide,
  });

  final AppPalette palette;
  final Color secondaryInk;
  final bool wide;

  @override
  Widget build(BuildContext context) {
    final heading = AppType.sectionDisplay.copyWith(
      color: palette.textPrimary,
      fontSize: wide ? 38 : 34,
      height: 1.10,
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Semantics(
          header: true,
          child: Text.rich(
            TextSpan(
              text: 'Distinctly local.\n',
              children: [
                TextSpan(
                  text: 'Yours to discover.',
                  style: AppType.pullQuote.copyWith(
                    fontSize: heading.fontSize,
                    height: heading.height,
                    letterSpacing: heading.letterSpacing,
                    color: palette.textPrimary,
                  ),
                ),
              ],
            ),
            style: heading,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          'Discover fashion, accessories and pieces for home from local shops.',
          style: Theme.of(context).textTheme.bodyMedium?.copyWith(
            color: secondaryInk,
            fontWeight: FontWeight.w500,
            height: 1.5,
          ),
        ),
      ],
    );
  }
}

class _EditorialCollage extends StatelessWidget {
  const _EditorialCollage({required this.height, required this.palette});

  final double height;
  final AppPalette palette;

  @override
  Widget build(BuildContext context) => ExcludeSemantics(
    child: SizedBox(
      height: height,
      child: LayoutBuilder(
        builder: (context, bounds) {
          final width = bounds.maxWidth;
          return Stack(
            children: [
              Positioned(
                left: 0,
                top: 9,
                width: width * .62,
                bottom: 0,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    border: Border.all(
                      color: palette.textSecondary.withValues(alpha: .45),
                    ),
                    borderRadius: const BorderRadius.only(
                      topLeft: Radius.circular(64),
                      bottomRight: Radius.circular(10),
                    ),
                  ),
                ),
              ),
              Positioned(
                left: 7,
                top: 0,
                width: width * .65,
                bottom: 8,
                child: _photo(
                  'mens-apparel.webp',
                  const BorderRadius.only(
                    topLeft: Radius.circular(64),
                    topRight: Radius.circular(8),
                    bottomRight: Radius.circular(10),
                  ),
                  alignment: Alignment.center,
                ),
              ),
              Positioned(
                right: 0,
                top: height * .10,
                width: width * .35,
                height: height * .42,
                child: _photo(
                  'jewelry-and-watches-320.webp',
                  const BorderRadius.only(
                    topLeft: Radius.circular(4),
                    topRight: Radius.circular(20),
                  ),
                  alignment: const Alignment(0, .28),
                  tone: const Color(0xFFF4EBE3),
                ),
              ),
              Positioned(
                right: width * .03,
                bottom: 0,
                width: width * .33,
                height: height * .44,
                child: _photo(
                  'home-and-garden-320.webp',
                  const BorderRadius.only(
                    topLeft: Radius.circular(4),
                    bottomRight: Radius.circular(20),
                  ),
                  tone: const Color(0xFFDAC6B2),
                ),
              ),
            ],
          );
        },
      ),
    ),
  );

  Widget _photo(
    String asset,
    BorderRadius corners, {
    Alignment alignment = Alignment.center,
    Color? tone,
  }) => DecoratedBox(
    decoration: BoxDecoration(
      color: Color.lerp(palette.surface, palette.background, .55),
      borderRadius: corners,
    ),
    child: Padding(
      padding: const EdgeInsets.all(2),
      child: ClipRRect(
        borderRadius: corners,
        child: Image.asset(
          'assets/landing/$asset',
          fit: BoxFit.cover,
          alignment: alignment,
          color: tone,
          colorBlendMode: tone == null ? null : BlendMode.multiply,
        ),
      ),
    ),
  );
}
