// lib/screens/buyer/widgets/promo_carousel.dart
//
// Auto-advancing hero banner for Home. Each slide has its own color
// ground and layout — they're not the same template with different text.
//
// Auto-advance stops when:
//   - a finger is on the banner (resumes a full interval after release)
//   - reduced motion is on (swiping still works)
//   - [autoAdvance] is false (Home passes false while another tab shows)

import 'dart:async';

import 'package:flutter/material.dart';
import '../../../data/mock_data.dart';
import '../../../theme/app_theme.dart';
import '../../../utils/motion.dart';
import '../../../widgets/editorial_shapes.dart';
import '../../../widgets/pressable_scale.dart';

// ---------------------------------------------------------------------------
// Slide data → presentation
// ---------------------------------------------------------------------------
//
// The slides live in mock_data.dart as `mockPromoSlides`: they're content
// (copy, layout choice, which icon), not presentation, so running a
// different campaign should be a data change rather than an edit to this
// widget. Same reason a slide's *ground* is an enum over there and not a
// Color — a slide says "I sit on sage", and this file decides what sage
// means in the current mode, once, instead of three slides each branching
// on brightness.

/// A slide's resolved palette colours for the active mode.
({Color ground, Color ink, Color accent}) _colorsFor(PromoGround ground) =>
    switch (ground) {
      // Deep rose in both modes — the one rose that can host small light
      // text (see AppPalette.roseGround).
      PromoGround.brandRose => (
        ground: AppColors.roseGround,
        ink: AppColors.textOnDark,
        accent: AppColors.primary,
      ),
      // Sage and terracotta flip with the mode: powder ground with dark ink
      // on paper, deep ground with light ink on ink.
      PromoGround.sage => (
        ground: AppColors.promoSageGround,
        ink: AppColors.onPromoGround,
        accent: AppColors.promoSageAccent,
      ),
      PromoGround.terracotta => (
        ground: AppColors.promoTerracottaGround,
        ink: AppColors.onPromoGround,
        accent: AppColors.promoTerracottaAccent,
      ),
    };

/// A [PromoSlide] with its colours resolved.
///
/// A view-model, deliberately: the three layout builders below read
/// `slide.background`, `slide.foreground` and `slide.accentColor` exactly
/// as they always have, so none of the ~350 lines of drawing code had to
/// learn about grounds, modes or palettes to get out of the slide list.
class _RenderedSlide {
  _RenderedSlide(this.data) : _colors = _colorsFor(data.ground);

  final PromoSlide data;
  final ({Color ground, Color ink, Color accent}) _colors;

  String get eyebrow => data.eyebrow;
  String get title => data.title;
  String get subtitle => data.subtitle;
  PromoSlideLayout get layout => data.layout;
  IconData get icon => data.icon;
  IconData? get bigIcon => data.bigIcon;

  Color get background => _colors.ground;
  Color get foreground => _colors.ink;
  Color get accentColor => _colors.accent;
}

// ---------------------------------------------------------------------------
// Carousel shell
// ---------------------------------------------------------------------------

class PromoCarousel extends StatefulWidget {
  const PromoCarousel({super.key, this.autoAdvance = true, this.onTapSlide});

  final bool autoAdvance;
  final ValueChanged<int>? onTapSlide;

  @override
  State<PromoCarousel> createState() => _PromoCarouselState();
}

class _PromoCarouselState extends State<PromoCarousel> {
  final _controller = PageController(viewportFraction: 0.92);
  Timer? _timer;
  int _page = 0;
  bool _fingerDown = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _syncTimer();
  }

  @override
  void didUpdateWidget(covariant PromoCarousel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.autoAdvance != widget.autoAdvance) _syncTimer();
  }

  void _syncTimer() {
    _timer?.cancel();
    _timer = null;
    if (!widget.autoAdvance || _fingerDown || reduceMotion(context)) return;
    _timer = Timer.periodic(const Duration(seconds: 4), (_) {
      if (!mounted || !_controller.hasClients) return;
      final next = (_page + 1) % mockPromoSlides.length;
      _controller.animateToPage(
        next,
        duration: const Duration(milliseconds: 450),
        curve: Curves.easeOutCubic,
      );
    });
  }

  void _setFingerDown(bool down) {
    _fingerDown = down;
    _syncTimer();
  }

  @override
  void dispose() {
    _timer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Column(
      children: [
        SizedBox(
          height: 152,
          child: Listener(
            onPointerDown: (_) => _setFingerDown(true),
            onPointerUp: (_) => _setFingerDown(false),
            onPointerCancel: (_) => _setFingerDown(false),
            child: PageView.builder(
              controller: _controller,
              itemCount: mockPromoSlides.length,
              onPageChanged: (i) => setState(() => _page = i),
              itemBuilder: (context, i) {
                final card = _SlideCard(
                  slide: _RenderedSlide(mockPromoSlides[i]),
                );
                return Padding(
                  padding: const EdgeInsets.fromLTRB(4, 20, 4, 0),
                  child: widget.onTapSlide == null
                      ? card
                      : PressableScale(
                          onTap: () => widget.onTapSlide!(i),
                          semanticLabel:
                              '${mockPromoSlides[i].title}. ${mockPromoSlides[i].subtitle}',
                          child: card,
                        ),
                );
              },
            ),
          ),
        ),
        const SizedBox(height: 10),
        // Dot indicator — color tracks the active slide's accentColor
        // so it always has contrast against its slide's ground and ties
        // each indicator dot visually to its card.
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: List.generate(mockPromoSlides.length, (i) {
            final active = i == _page;
            // Resolved through the same ground → palette mapping the card
            // uses, so the dot can't drift from the slide it describes.
            final dotColor = active
                ? _colorsFor(mockPromoSlides[i].ground).accent
                : AppColors.divider;
            return AnimatedContainer(
              duration: const Duration(milliseconds: 250),
              margin: const EdgeInsets.symmetric(horizontal: 3),
              width: active ? 18 : 6,
              height: 6,
              decoration: BoxDecoration(
                color: dotColor,
                borderRadius: radiusOf(ShapeTier.pill),
              ),
            );
          }),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Slide card — dispatches to one of three layout builders
// ---------------------------------------------------------------------------

class _SlideCard extends StatelessWidget {
  const _SlideCard({required this.slide});

  final _RenderedSlide slide;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final ext = Theme.of(context).extension<AppThemeExtension>()!;

    return ClipRRect(
      borderRadius: BorderRadius.circular(ext.heroRadius),
      child: switch (slide.layout) {
        PromoSlideLayout.textLeft => _TextLeftLayout(slide: slide),
        PromoSlideLayout.centeredHero => _CenteredHeroLayout(slide: slide),
        PromoSlideLayout.iconSplit => _IconSplitLayout(slide: slide),
      },
    );
  }
}

// ---------------------------------------------------------------------------
// Layout A — textLeft (slide 0, deep rose)
// Big text left, decorative arch shape right. The arch shape echoes the
// product-card arch on Home so the visual language carries.
// ---------------------------------------------------------------------------

class _TextLeftLayout extends StatelessWidget {
  const _TextLeftLayout({required this.slide});

  final _RenderedSlide slide;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);

    return Stack(
      fit: StackFit.expand,
      children: [
        // Ground
        ColoredBox(color: slide.background),

        // Soft radial glow in the top-right so the card isn't a flat block.
        Positioned(
          top: -40,
          right: -40,
          child: SizedBox(
            width: 200,
            height: 200,
            child: DecoratedBox(
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: RadialGradient(
                  colors: [
                    slide.accentColor.withValues(alpha: 0.35),
                    slide.accentColor.withValues(alpha: 0.0),
                  ],
                ),
              ),
            ),
          ),
        ),

        // Arch silhouette, right-side, same language as CoverStoryTile.
        Positioned(
          right: -10,
          top: -6,
          bottom: -6,
          width: 100,
          child: CustomPaint(
            painter: _ArchPainter(
              color: slide.accentColor.withValues(alpha: 0.18),
            ),
          ),
        ),

        // Copy, left column.
        Positioned(
          left: 20,
          top: 0,
          bottom: 0,
          right: 100,
          child: Align(
            alignment: Alignment.center,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  slide.eyebrow,
                  style: AppType.eyebrow.copyWith(
                    color: slide.foreground.withValues(alpha: 0.65),
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  slide.title,
                  style: theme.textTheme.titleLarge?.copyWith(
                    color: slide.foreground,
                    height: 1.1,
                    fontSize: 20,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  slide.subtitle,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: slide.foreground.withValues(alpha: 0.72),
                  ),
                  maxLines: 2,
                ),
              ],
            ),
          ),
        ),

        // Rotated icon seal — same motif as the old carousel but only
        // appearing here rather than on every slide identically.
        Positioned(
          top: -8,
          right: 16,
          child: Transform.rotate(
            angle: -0.2,
            child: Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(
                color: AppColors.surface,
                shape: BoxShape.circle,
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.18),
                    blurRadius: 10,
                    offset: const Offset(0, 4),
                  ),
                ],
              ),
              alignment: Alignment.center,
              child: Icon(slide.icon, color: slide.background, size: 20),
            ),
          ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Layout B — centeredHero (slide 1, sage)
// Full-ground color. Large centered headline, icon used as oversized
// background motif (low opacity) rather than a small circle.
// ---------------------------------------------------------------------------

class _CenteredHeroLayout extends StatelessWidget {
  const _CenteredHeroLayout({required this.slide});

  final _RenderedSlide slide;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);

    return Stack(
      fit: StackFit.expand,
      children: [
        ColoredBox(color: slide.background),

        // Background motif — the big icon at very low opacity so it
        // reads as texture, not a second focal point.
        if (slide.bigIcon != null)
          Positioned(
            right: -16,
            bottom: -20,
            child: Icon(
              slide.bigIcon,
              size: 130,
              color: slide.accentColor.withValues(alpha: 0.12),
            ),
          ),

        // Horizontal rule accent — one hard line anchors the card so
        // the centered layout doesn't float.
        Positioned(
          left: 20,
          right: 20,
          top: 30,
          child: Container(
            height: 1.5,
            color: slide.accentColor.withValues(alpha: 0.3),
          ),
        ),

        // Centered copy.
        Positioned(
          left: 20,
          right: 20,
          top: 0,
          bottom: 0,
          child: Align(
            alignment: Alignment.center,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.center,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  slide.eyebrow,
                  style: AppType.eyebrow.copyWith(
                    color: slide.accentColor.withValues(alpha: 0.8),
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  slide.title,
                  style: theme.textTheme.titleLarge?.copyWith(
                    color: slide.foreground,
                    height: 1.1,
                    fontSize: 22,
                    fontWeight: FontWeight.w700,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 6),
                Text(
                  slide.subtitle,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: slide.foreground.withValues(alpha: 0.72),
                  ),
                  textAlign: TextAlign.center,
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Layout C — iconSplit (slide 2, blush)
// Left half is a tinted motif panel, right half is the copy.
// ---------------------------------------------------------------------------

class _IconSplitLayout extends StatelessWidget {
  const _IconSplitLayout({required this.slide});

  final _RenderedSlide slide;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);

    return Stack(
      fit: StackFit.expand,
      children: [
        ColoredBox(color: slide.background),

        // Left motif panel — slightly darker tint of the same ground.
        Positioned(
          left: 0,
          top: 0,
          bottom: 0,
          width: 110,
          child: ColoredBox(color: slide.accentColor.withValues(alpha: 0.10)),
        ),

        // Big icon in the left panel.
        Positioned(
          left: 0,
          top: 0,
          bottom: 0,
          width: 110,
          child: Center(
            child: Icon(
              slide.bigIcon ?? slide.icon,
              size: 58,
              color: slide.accentColor.withValues(alpha: 0.7),
            ),
          ),
        ),

        // Diagonal divider between the two halves — slight lean instead
        // of a straight vertical line so it reads as designed, not default.
        Positioned(
          left: 96,
          top: 0,
          bottom: 0,
          width: 28,
          child: CustomPaint(
            painter: _DiagonalDividerPainter(color: slide.background),
          ),
        ),

        // Right copy.
        Positioned(
          left: 120,
          right: 16,
          top: 0,
          bottom: 0,
          child: Align(
            alignment: Alignment.center,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  slide.eyebrow,
                  style: AppType.eyebrow.copyWith(
                    color: slide.foreground.withValues(alpha: 0.55),
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  slide.title,
                  style: theme.textTheme.titleLarge?.copyWith(
                    color: slide.foreground,
                    height: 1.1,
                    fontSize: 20,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  slide.subtitle,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: slide.foreground.withValues(alpha: 0.72),
                  ),
                  maxLines: 2,
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

// ---------------------------------------------------------------------------
// Custom painters
// ---------------------------------------------------------------------------

/// Arch silhouette used as a decorative shape on layout A.
class _ArchPainter extends CustomPainter {
  const _ArchPainter({required this.color});

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()..color = color;
    final radius = size.width / 2;

    final path = Path()
      ..moveTo(0, size.height)
      ..lineTo(0, radius)
      ..arcToPoint(
        Offset(size.width, radius),
        radius: Radius.circular(radius),
        clockwise: true,
      )
      ..lineTo(size.width, size.height)
      ..close();

    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant _ArchPainter old) => old.color != color;
}

/// Diagonal edge between the icon panel and the copy panel on layout C.
class _DiagonalDividerPainter extends CustomPainter {
  const _DiagonalDividerPainter({required this.color});

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    // Paints a triangle that covers the right side of the left panel,
    // creating a slanted edge instead of a hard vertical cut.
    final path = Path()
      ..moveTo(0, 0)
      ..lineTo(size.width, 0)
      ..lineTo(size.width, size.height)
      ..lineTo(size.width * 0.1, size.height)
      ..close();
    canvas.drawPath(path, Paint()..color = color);
  }

  @override
  bool shouldRepaint(covariant _DiagonalDividerPainter old) =>
      old.color != color;
}
