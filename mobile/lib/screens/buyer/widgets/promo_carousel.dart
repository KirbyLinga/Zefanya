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
import '../../../theme/app_theme.dart';
import '../../../utils/motion.dart';
import '../../../widgets/pressable_scale.dart';

// ---------------------------------------------------------------------------
// Slide data
// ---------------------------------------------------------------------------

enum _SlideLayout {
  /// Large eyebrow + stacked text left, decorative shape right.
  textLeft,

  /// Full-bleed tinted surface, centered bold headline, small icon seal.
  centeredHero,

  /// Split: icon motif fills the left half, copy lives on the right.
  iconSplit,
}

class _PromoSlide {
  const _PromoSlide({
    required this.title,
    required this.subtitle,
    required this.eyebrow,
    required this.layout,
    required this.background,
    required this.foreground,
    required this.accentColor,
    required this.icon,
    this.bigIcon,
  });

  final String title;
  final String subtitle;
  final String eyebrow;
  final _SlideLayout layout;

  /// The card's ground color (or gradient start).
  final Color background;

  /// Text + icon color.
  final Color foreground;

  /// Highlight / decorative accent — usually lighter or darker than background.
  final Color accentColor;

  final IconData icon;

  /// Optional second, larger icon used as a background motif on some layouts.
  final IconData? bigIcon;
}

const _slides = [
  // Slide 0 — "New This Season"
  // Ground: deep rose. Layout: textLeft.
  // The main brand color gets the prime slot so the banner matches the
  // nav bar and buttons — consistent accent identity.
  _PromoSlide(
    eyebrow: 'JUST ARRIVED',
    title: 'New This\nSeason',
    subtitle: 'Fresh arrivals from Willow & Bloom',
    layout: _SlideLayout.textLeft,
    background: AppColors.primaryDark,
    foreground: AppColors.textOnDark,
    accentColor: AppColors.primary,
    icon: Icons.local_florist_outlined,
  ),

  // Slide 1 — "15% Off Home & Living"
  // Ground: sage. Layout: centeredHero.
  // Sage has been living only as a nav-bar badge and background glow;
  // giving it a whole banner surface is the first place it reads as a
  // real second accent rather than decoration.
  _PromoSlide(
    eyebrow: 'LIMITED TIME',
    title: '15% Off\nHome & Living',
    subtitle: 'Code NEWHOME15 · up to ₱150 off',
    layout: _SlideLayout.centeredHero,
    background: AppColors.tertiary,
    foreground: AppColors.textPrimary,
    accentColor: Color(0xFF3E6B45), // successText — darkened sage for contrast
    icon: Icons.local_offer_outlined,
    bigIcon: Icons.cottage_outlined,
  ),

  // Slide 2 — "Free Shipping"
  // Ground: warm blush. Layout: iconSplit.
  // Blush is the palette's lightest value, so this reads as the calmest
  // slide — right after the two denser ones, the rhythm changes.
  _PromoSlide(
    eyebrow: 'ALWAYS ON',
    title: 'Free\nShipping',
    subtitle: 'On every order over ₱1,000',
    layout: _SlideLayout.iconSplit,
    background: AppColors.secondary,
    foreground: AppColors.textPrimary,
    accentColor: AppColors.primaryDark,
    icon: Icons.local_shipping_outlined,
    bigIcon: Icons.directions_bike_outlined,
  ),
];

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
      final next = (_page + 1) % _slides.length;
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
              itemCount: _slides.length,
              onPageChanged: (i) => setState(() => _page = i),
              itemBuilder: (context, i) {
                final card = _SlideCard(slide: _slides[i]);
                return Padding(
                  padding: const EdgeInsets.fromLTRB(4, 20, 4, 0),
                  child: widget.onTapSlide == null
                      ? card
                      : PressableScale(
                          onTap: () => widget.onTapSlide!(i),
                          semanticLabel:
                              '${_slides[i].title}. ${_slides[i].subtitle}',
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
          children: List.generate(_slides.length, (i) {
            final active = i == _page;
            final dotColor = active
                ? _slides[i].accentColor
                : AppColors.divider;
            return AnimatedContainer(
              duration: const Duration(milliseconds: 250),
              margin: const EdgeInsets.symmetric(horizontal: 3),
              width: active ? 18 : 6,
              height: 6,
              decoration: BoxDecoration(
                color: dotColor,
                borderRadius: BorderRadius.circular(999),
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

  final _PromoSlide slide;

  @override
  Widget build(BuildContext context) {
    final ext = Theme.of(context).extension<AppThemeExtension>()!;

    return ClipRRect(
      borderRadius: BorderRadius.circular(ext.heroRadius),
      child: switch (slide.layout) {
        _SlideLayout.textLeft    => _TextLeftLayout(slide: slide),
        _SlideLayout.centeredHero => _CenteredHeroLayout(slide: slide),
        _SlideLayout.iconSplit   => _IconSplitLayout(slide: slide),
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

  final _PromoSlide slide;

  @override
  Widget build(BuildContext context) {
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
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(slide.eyebrow,
                  style: AppType.eyebrow.copyWith(
                      color: slide.foreground.withValues(alpha: 0.65))),
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

  final _PromoSlide slide;

  @override
  Widget build(BuildContext context) {
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
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                slide.eyebrow,
                style: AppType.eyebrow.copyWith(
                    color: slide.accentColor.withValues(alpha: 0.8)),
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

  final _PromoSlide slide;

  @override
  Widget build(BuildContext context) {
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
          child: ColoredBox(
            color: slide.accentColor.withValues(alpha: 0.10),
          ),
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
            painter: _DiagonalDividerPainter(
              color: slide.background,
            ),
          ),
        ),

        // Right copy.
        Positioned(
          left: 120,
          right: 16,
          top: 0,
          bottom: 0,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                slide.eyebrow,
                style: AppType.eyebrow.copyWith(
                    color: slide.foreground.withValues(alpha: 0.55)),
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
