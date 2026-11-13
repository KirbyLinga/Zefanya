import 'package:flutter/material.dart';

import '../../../theme/app_theme.dart';
import '../../../utils/motion.dart';
import '../categories_screen.dart';

class EditorialHero extends StatefulWidget {
  const EditorialHero({
    super.key,
    required this.categories,
    required this.onCategory,
    required this.onBrowse,
  });

  final List<String> categories;
  final ValueChanged<String> onCategory;
  final VoidCallback onBrowse;

  static List<String> selectCategories(Iterable<String> categories) {
    final available = categories
        .where((name) => name.trim().isNotEmpty && name != 'All')
        .toSet();
    const priorities = [
      ['Fashion', "Men's Apparel", "Women's Apparel"],
      ['Home & Living', 'Home and Garden'],
      ['Beauty', 'Health and Beauty'],
    ];
    return <String>{
      for (final group in priorities)
        if (group.where(available.contains).firstOrNull case final name?) name,
      ...available,
    }.take(3).toList(growable: false);
  }

  @override
  State<EditorialHero> createState() => _EditorialHeroState();
}

class _EditorialHeroState extends State<EditorialHero> {
  final _controller = PageController();
  int _page = 0;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _openPage(int index) {
    if (reduceMotion(context)) {
      _controller.jumpToPage(index);
    } else {
      _controller.animateToPage(
        index,
        duration: const Duration(milliseconds: 240),
        curve: Curves.easeOutCubic,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final categories = widget.categories;
    if (categories.isEmpty) {
      return OutlinedButton.icon(
        onPressed: widget.onBrowse,
        icon: const Icon(Icons.grid_view_outlined, size: 18),
        label: const Text('Browse categories'),
      );
    }
    return Column(
      children: [
        LayoutBuilder(
          builder: (context, bounds) {
            final width = bounds.maxWidth;
            var height = (width * .44).clamp(138.0, 178.0);
            for (var index = 0; index < categories.length; index++) {
              final copyWidth = width - _photoWidth(context, width, index) - 32;
              final needed =
                  44 +
                  _measure(
                    context,
                    'THE LOCAL EDIT',
                    AppType.eyebrow,
                    copyWidth,
                  ) +
                  _measure(context, categories[index], _titleStyle, copyWidth) +
                  _measure(
                    context,
                    'Explore category',
                    _actionStyle(context),
                    copyWidth,
                  );
              if (needed > height) height = needed;
            }
            return SizedBox(
              width: width,
              height: height,
              child: categories.length == 1
                  ? _panel(context, categories.single, 0)
                  : PageView.builder(
                      key: const ValueKey('editorial-hero-pages'),
                      controller: _controller,
                      itemCount: categories.length,
                      onPageChanged: (page) => setState(() => _page = page),
                      itemBuilder: (context, index) =>
                          _panel(context, categories[index], index),
                    ),
            );
          },
        ),
        if (categories.length > 1)
          Row(
            children: [
              for (var index = 0; index < categories.length; index++)
                Semantics(
                  selected: _page == index,
                  child: IconButton(
                    tooltip:
                        'Feature ${index + 1} of ${categories.length}: ${categories[index]}',
                    onPressed: () => _openPage(index),
                    icon: Container(
                      width: _page == index ? 18 : 6,
                      height: 6,
                      decoration: BoxDecoration(
                        color: _page == index
                            ? AppColors.primaryDark
                            : AppColors.textSecondary,
                        borderRadius: BorderRadius.circular(6),
                      ),
                    ),
                  ),
                ),
            ],
          ),
      ],
    );
  }

  TextStyle get _titleStyle =>
      AppType.sectionDisplay.copyWith(fontSize: 27, height: 1.08);

  TextStyle _actionStyle(BuildContext context) => Theme.of(
    context,
  ).textTheme.labelMedium!.copyWith(fontWeight: FontWeight.w700);

  double _photoWidth(BuildContext context, double width, int index) {
    if (MediaQuery.textScalerOf(context).scale(14) >= 23) return width * .36;
    return width * (index == 1 ? .43 : .50);
  }

  double _measure(
    BuildContext context,
    String value,
    TextStyle style,
    double width,
  ) {
    final painter = TextPainter(
      text: TextSpan(
        text: value,
        style: DefaultTextStyle.of(context).style.merge(style),
      ),
      textScaler: MediaQuery.textScalerOf(context),
      textDirection: Directionality.of(context),
    )..layout(maxWidth: width);
    final height = painter.height;
    painter.dispose();
    return height;
  }

  Widget _panel(
    BuildContext context,
    String category,
    int index,
  ) => LayoutBuilder(
    builder: (context, bounds) {
      final photoWidth = _photoWidth(context, bounds.maxWidth, index);
      final frame = switch (index) {
        1 => const BorderRadius.only(
          topLeft: Radius.circular(24),
          topRight: Radius.circular(32),
          bottomRight: Radius.circular(10),
        ),
        2 => const BorderRadius.only(
          topLeft: Radius.circular(12),
          topRight: Radius.circular(38),
          bottomLeft: Radius.circular(28),
        ),
        _ => const BorderRadius.only(
          topLeft: Radius.circular(56),
          topRight: Radius.circular(8),
          bottomRight: Radius.circular(10),
        ),
      };
      final right = index == 1 ? 11.0 : 6.0;
      final top = index == 2 ? 11.0 : 6.0;
      final bottom = index == 1 ? 7.0 : 13.0;
      return Semantics(
        button: true,
        label: 'Explore $category',
        child: Material(
          color: Color.lerp(AppColors.surface, AppColors.primary, .09),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
            side: BorderSide(color: AppColors.divider),
          ),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: () => widget.onCategory(category),
            child: Stack(
              alignment: Alignment.centerLeft,
              children: [
                Positioned.fill(
                  left: bounds.maxWidth - photoWidth + 14,
                  child: ColoredBox(
                    color: Color.lerp(
                      AppColors.surface,
                      AppColors.primary,
                      .26,
                    )!,
                  ),
                ),
                Positioned(
                  right: right + 5,
                  top: top + 5,
                  bottom: bottom - 5,
                  width: photoWidth,
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      border: Border.all(
                        color: AppColors.primaryDark.withValues(alpha: .5),
                      ),
                      borderRadius: frame,
                    ),
                  ),
                ),
                Positioned(
                  right: right,
                  top: top,
                  bottom: bottom,
                  width: photoWidth,
                  child: ClipRRect(
                    borderRadius: frame,
                    child: CategoryArtwork(
                      category: category,
                      iconSize: 34,
                      alignment: index == 1
                          ? const Alignment(0, -.16)
                          : Alignment.center,
                      tone: index == 1 ? const Color(0xFFE1CEBC) : null,
                    ),
                  ),
                ),
                Padding(
                  padding: EdgeInsets.fromLTRB(15, 12, photoWidth + 17, 12),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('THE LOCAL EDIT', style: AppType.eyebrow),
                      const SizedBox(height: 6),
                      Text(category, style: _titleStyle),
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 5),
                        child: ColoredBox(
                          color: AppColors.primaryDark.withValues(alpha: .65),
                          child: const SizedBox(width: 19, height: 1),
                        ),
                      ),
                      Text('Explore category', style: _actionStyle(context)),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    },
  );
}
