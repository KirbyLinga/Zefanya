import 'package:flutter/material.dart';

import '../theme/app_theme.dart';
import '../utils/motion.dart';
import '../widgets/zefanya_mark.dart';

class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({super.key, required this.onFinish});

  static const completionKey = 'editorial_onboarding_complete';
  final VoidCallback onFinish;

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  static const _pages = [
    (
      'DISCOVER',
      'Discover something extraordinary.',
      'Explore curated finds from local shops.',
    ),
    (
      'EXPLORE',
      'Find pieces that feel like you.',
      'From fashion and beauty to home and everyday essentials.',
    ),
    (
      'DELIVERED',
      'From local shops to your doorstep.',
      'Shop, follow your orders, and stay updated along the way.',
    ),
  ];

  final _controller = PageController();
  int _page = 0;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _go(int index) {
    if (reduceMotion(context)) {
      _controller.jumpToPage(index);
    } else {
      _controller.animateToPage(
        index,
        duration: const Duration(milliseconds: 230),
        curve: Curves.easeOutCubic,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final palette = AppPalette.of(theme.brightness);
    final last = _page == _pages.length - 1;
    const buttonShape = RoundedRectangleBorder(
      borderRadius: BorderRadius.all(Radius.circular(10)),
    );
    return PopScope(
      canPop: _page == 0,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop && _page > 0) _go(_page - 1);
      },
      child: Scaffold(
        backgroundColor: palette.background,
        body: DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topRight,
              end: Alignment.bottomLeft,
              colors: [palette.surface, palette.background],
            ),
          ),
          child: SafeArea(
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 480),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(24, 5, 24, 12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(
                        children: [
                          ExcludeSemantics(
                            child: ZefanyaMark(
                              size: 25,
                              color: palette.textPrimary,
                            ),
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              'Zefanya',
                              style: AppType.masthead.copyWith(
                                color: palette.textPrimary,
                                fontSize: 29,
                                letterSpacing: -1.1,
                              ),
                            ),
                          ),
                          TextButton(
                            onPressed: widget.onFinish,
                            style: TextButton.styleFrom(
                              minimumSize: const Size(48, 48),
                              foregroundColor: palette.textPrimary,
                            ),
                            child: const Text('Skip'),
                          ),
                        ],
                      ),
                      Divider(height: 1, color: palette.divider),
                      Expanded(
                        child: PageView.builder(
                          key: const ValueKey('onboarding-pages'),
                          controller: _controller,
                          itemCount: _pages.length,
                          onPageChanged: (index) =>
                              setState(() => _page = index),
                          itemBuilder: (context, index) =>
                              _buildPage(context, index, palette),
                        ),
                      ),
                      Divider(height: 1, color: palette.divider),
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        child: Semantics(
                          liveRegion: true,
                          label: 'Page ${_page + 1} of ${_pages.length}',
                          excludeSemantics: true,
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Text(
                                '0${_page + 1} / 03',
                                style: AppType.eyebrow.copyWith(
                                  color: palette.textSecondary,
                                ),
                              ),
                              Row(
                                children: [
                                  for (var i = 0; i < _pages.length; i++)
                                    Container(
                                      margin: const EdgeInsets.only(left: 5),
                                      width: i == _page ? 22 : 9,
                                      height: 3,
                                      color: i == _page
                                          ? palette.primaryDark
                                          : palette.divider,
                                    ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      ),
                      Row(
                        children: [
                          if (_page > 0) ...[
                            OutlinedButton(
                              onPressed: () => _go(_page - 1),
                              style: OutlinedButton.styleFrom(
                                minimumSize: const Size(48, 48),
                                shape: buttonShape,
                                foregroundColor: palette.textPrimary,
                                side: BorderSide(
                                  color: palette.textSecondary.withValues(
                                    alpha: .7,
                                  ),
                                ),
                              ),
                              child: const Text('Back'),
                            ),
                            const SizedBox(width: 8),
                          ],
                          Expanded(
                            child: FilledButton(
                              onPressed: last
                                  ? widget.onFinish
                                  : () => _go(_page + 1),
                              style: FilledButton.styleFrom(
                                backgroundColor: palette.roseGround,
                                foregroundColor: palette.textOnDark,
                                minimumSize: const Size(48, 48),
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 12,
                                  vertical: 10,
                                ),
                                shape: buttonShape,
                              ),
                              child: Text(
                                last ? 'Get Started' : 'Next',
                                textAlign: TextAlign.center,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildPage(BuildContext context, int index, AppPalette palette) {
    final (label, title, description) = _pages[index];
    return LayoutBuilder(
      builder: (context, bounds) => SingleChildScrollView(
        key: PageStorageKey('onboarding-copy-$index'),
        padding: EdgeInsets.only(
          top: (bounds.maxHeight * .08).clamp(14, 42),
          bottom: 18,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _OnboardingArt(
              index: index,
              height: (bounds.maxHeight * .53).clamp(146, 318),
              palette: palette,
            ),
            SizedBox(height: index == 2 ? 13 : 16),
            Text(
              label,
              style: AppType.eyebrow.copyWith(color: palette.textSecondary),
            ),
            const SizedBox(height: 6),
            Semantics(
              header: true,
              child: Text(
                title,
                style: AppType.sectionDisplay.copyWith(
                  fontSize: bounds.maxWidth >= 350 ? 38 : 34,
                  height: 1.13,
                  color: palette.textPrimary,
                ),
              ),
            ),
            const SizedBox(height: 8),
            Text(
              description,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                height: 1.5,
                fontWeight: FontWeight.w500,
                color: palette.textSecondary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _OnboardingArt extends StatelessWidget {
  const _OnboardingArt({
    required this.index,
    required this.height,
    required this.palette,
  });

  final int index;
  final double height;
  final AppPalette palette;

  @override
  Widget build(BuildContext context) => ExcludeSemantics(
    child: SizedBox(
      height: height,
      child: LayoutBuilder(
        builder: (context, bounds) {
          final width = bounds.maxWidth;
          final rule = BoxDecoration(
            border: Border.all(
              color: palette.textSecondary.withValues(alpha: .5),
            ),
            borderRadius: const BorderRadius.only(
              topLeft: Radius.circular(56),
              bottomRight: Radius.circular(14),
            ),
          );
          return Stack(
            children: switch (index) {
              0 => [
                Positioned(
                  right: 0,
                  top: 13,
                  bottom: 0,
                  width: width * .73,
                  child: DecoratedBox(decoration: rule),
                ),
                Positioned(
                  right: 8,
                  top: 0,
                  bottom: 8,
                  width: width * .71,
                  child: _photo(
                    'assets/categories/womens-apparel-320.webp',
                    const BorderRadius.only(
                      topLeft: Radius.circular(56),
                      topRight: Radius.circular(8),
                      bottomRight: Radius.circular(14),
                    ),
                  ),
                ),
                Positioned(
                  left: 0,
                  bottom: height * .075,
                  width: width * .36,
                  height: height * .46,
                  child: _photo(
                    'assets/categories/home-and-garden-320.webp',
                    const BorderRadius.only(
                      topLeft: Radius.circular(10),
                      bottomRight: Radius.circular(30),
                    ),
                    tone: const Color(0xFFDAC6B2),
                  ),
                ),
              ],
              1 => [
                Positioned(
                  left: 0,
                  top: 10,
                  bottom: height * .23,
                  width: width * .65,
                  child: DecoratedBox(decoration: rule),
                ),
                Positioned(
                  left: 8,
                  top: 0,
                  bottom: height * .26,
                  width: width * .65,
                  child: _photo(
                    'assets/categories/health-and-beauty-320.webp',
                    const BorderRadius.only(
                      topLeft: Radius.circular(56),
                      topRight: Radius.circular(8),
                    ),
                  ),
                ),
                Positioned(
                  right: 0,
                  top: height * .30,
                  bottom: 0,
                  width: width * .46,
                  child: _photo(
                    'assets/landing/jewelry-and-watches-320.webp',
                    const BorderRadius.only(
                      topRight: Radius.circular(24),
                      bottomRight: Radius.circular(10),
                    ),
                    tone: const Color(0xFFF4EBE3),
                  ),
                ),
                Positioned(
                  left: 0,
                  bottom: height * .045,
                  width: width * .32,
                  height: height * .27,
                  child: _photo(
                    'assets/categories/books-and-media-320.webp',
                    const BorderRadius.only(
                      topLeft: Radius.circular(8),
                      bottomRight: Radius.circular(18),
                    ),
                    tone: const Color(0xFFE4D3C0),
                  ),
                ),
              ],
              _ => [
                Positioned(
                  left: 0,
                  right: width * .16,
                  top: 10,
                  bottom: 0,
                  child: DecoratedBox(decoration: rule),
                ),
                Positioned(
                  left: 8,
                  right: width * .035,
                  top: 0,
                  bottom: 8,
                  child: _photo(
                    'assets/landing/editorial-parcel.webp',
                    const BorderRadius.only(
                      topLeft: Radius.circular(62),
                      topRight: Radius.circular(8),
                      bottomRight: Radius.circular(20),
                    ),
                  ),
                ),
              ],
            },
          );
        },
      ),
    ),
  );

  Widget _photo(String asset, BorderRadius radius, {Color? tone}) =>
      DecoratedBox(
        decoration: BoxDecoration(color: palette.surface, borderRadius: radius),
        child: Padding(
          padding: const EdgeInsets.all(2),
          child: ClipRRect(
            borderRadius: radius,
            child: Image.asset(
              asset,
              fit: BoxFit.cover,
              color: tone,
              colorBlendMode: tone == null ? null : BlendMode.multiply,
            ),
          ),
        ),
      );
}
