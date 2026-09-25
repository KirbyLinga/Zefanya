// lib/screens/buyer/search_screen.dart
//
// Search tab: live-filters data/mock_data.dart by product name or
// category as the user types. TODO: swap the local filter for a real
// search API call once the backend is wired up (likely still useful
// to keep an instant local pre-filter for perceived speed).
//
// No page title here on purpose — the bottom nav already says
// "Search", so a big "Search" headline above the search field would
// just be a label restating the label. Instead the field itself is
// the hero: bigger, tinted, asymmetric-cornered, the one interactive
// thing on the screen. Popular searches sit underneath as loosely
// rotated tags, like something stamped down rather than a row of
// identical chips under a caption.

import 'package:flutter/material.dart';
import '../../data/mock_data.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/staggered_reveal.dart';
import '../../widgets/zefanya_mark.dart';
import 'product_details_screen.dart';
import 'widgets/product_card.dart';
import '../../widgets/app_page_route.dart';

class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key, this.focusRequests});

  /// Bump this notifier to focus the search field (and raise the keyboard).
  /// Home's search bar does this when tapped, after switching to this tab,
  /// so "tap search, start typing" actually works in one motion.
  final ValueNotifier<int>? focusRequests;

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final _controller = TextEditingController();
  final _focusNode = FocusNode();
  String _query = '';

  static const _popularSearches = ['Linen', 'Ceramic', 'Tote Bag', 'Scarf', 'Home & Living'];

  @override
  void initState() {
    super.initState();
    widget.focusRequests?.addListener(_onFocusRequested);
  }

  @override
  void didUpdateWidget(covariant SearchScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.focusRequests != widget.focusRequests) {
      oldWidget.focusRequests?.removeListener(_onFocusRequested);
      widget.focusRequests?.addListener(_onFocusRequested);
    }
  }

  // Post-frame: the request arrives in the same frame the tab switches, when
  // this screen is still offstage and can't take focus yet.
  void _onFocusRequested() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _focusNode.requestFocus();
    });
  }

  @override
  void dispose() {
    widget.focusRequests?.removeListener(_onFocusRequested);
    _focusNode.dispose();
    _controller.dispose();
    super.dispose();
  }

  List<Product> get _results {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return const [];
    return mockProducts
        .where((p) => p.name.toLowerCase().contains(q) || p.category.toLowerCase().contains(q))
        .toList();
  }

  void _search(String value) => setState(() => _query = value);

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      body: SafeArea(
        child: Column(
          children: [
            _buildSearchField(theme),
            Expanded(
              child: _query.isEmpty
                  ? _buildSuggestions(theme)
                  : _results.isEmpty
                      ? _buildNoResults(theme)
                      : Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            _buildResultsHeader(),
                            Expanded(child: _buildResultsGrid(context)),
                          ],
                        ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSearchField(ThemeData theme) {
    return Container(
      margin: const EdgeInsets.fromLTRB(20, 16, 20, 0),
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 6),
      decoration: BoxDecoration(
        color: AppColors.primary.withValues(alpha: 0.24),
        // Asymmetric corners rather than a pill — the field itself
        // picks up the same "leaf" shape as a product tile, since it's
        // standing in for the hero image every other screen opens with.
        borderRadius: editorialRadius(0, big: 26, small: 10),
      ),
      child: Row(
        children: [
          const Icon(Icons.search, size: 22, color: AppColors.primaryDark),
          const SizedBox(width: 10),
          Expanded(
            child: TextField(
              controller: _controller,
              focusNode: _focusNode,
              autofocus: true,
              onChanged: _search,
              style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w500),
              decoration: const InputDecoration(
                isDense: true,
                border: InputBorder.none,
                hintText: 'What are you looking for?',
              ),
            ),
          ),
          if (_query.isNotEmpty)
            IconButton(
              icon: const Icon(Icons.close, size: 18, color: AppColors.primaryDark),
              onPressed: () {
                _controller.clear();
                _search('');
              },
            ),
        ],
      ),
    );
  }

  Widget _buildSuggestions(ThemeData theme) {
    // Alternating tints and a small alternating tilt — stamped down
    // rather than a uniform Wrap of identical chips under a label.
    final tints = [AppColors.primary, AppColors.tertiary, AppColors.secondary];
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 32, 20, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // An eyebrow, not a titleMedium caption. The original note
          // here was that a *label* would make these read as a uniform
          // chip row — that's true of a heading, but the tiny all-caps
          // kicker is the app's own quiet voice and it's what the
          // tilted tags were missing an anchor to.
          Text('TRY LOOKING FOR', style: AppType.eyebrow),
          const SizedBox(height: 20),
          Wrap(
            spacing: 14,
            runSpacing: 18,
            children: [
              for (var i = 0; i < _popularSearches.length; i++)
                Transform.rotate(
                  angle: i.isEven ? -0.05 : 0.045,
                  child: ActionChip(
                    label: Text(_popularSearches[i]),
                    backgroundColor: tints[i % tints.length].withValues(alpha: 0.28),
                    side: BorderSide.none,
                    onPressed: () {
                      _controller.text = _popularSearches[i];
                      _search(_popularSearches[i]);
                    },
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildNoResults(ThemeData theme) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          // Was a grey circle with Icons.search_off — the most generic
          // empty state a Material app can show, on a screen the buyer
          // reaches by trying and failing. The monogram is the app's
          // own mark and costs nothing to draw.
          ZefanyaMark(size: 46, color: AppColors.neutral.withValues(alpha: 0.4), strokeWidth: 4),
          const SizedBox(height: 22),
          Text('NOTHING HERE YET', style: AppType.eyebrow),
          const SizedBox(height: 10),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 40),
            child: Text(
              'No results for \u201c$_query\u201d',
              style: AppType.sectionDisplay.copyWith(fontSize: 26),
              textAlign: TextAlign.center,
            ),
          ),
          const SizedBox(height: 8),
          Text('Try a shorter or more general term', style: theme.textTheme.bodySmall),
        ],
      ),
    );
  }

  /// The one numeral on this screen. A result count is a real number
  /// about what the buyer just did, which is exactly the kind of thing
  /// the display scale exists for — and it gives the grid a heading
  /// without restating the word "Search".
  Widget _buildResultsHeader() {
    final count = _results.length;
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 24, 20, 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Text('$count', style: AppType.priceNumeral(38)),
          const SizedBox(width: 10),
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(
              count == 1 ? 'PIECE\nFOUND' : 'PIECES\nFOUND',
              style: AppType.eyebrow.copyWith(height: 1.5),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildResultsGrid(BuildContext context) {
    return GridView.builder(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        mainAxisSpacing: 16,
        crossAxisSpacing: 16,
        childAspectRatio: 0.68,
      ),
      itemCount: _results.length,
      itemBuilder: (context, i) {
        final product = _results[i];
        return StaggeredReveal(
          index: i,
          child: ProductCard(
            product: product,
            index: i,
            heroTag: 'search-${product.id}',
            onTap: () => Navigator.of(context).push(
              AppPageRoute(
                builder: (_) => ProductDetailsScreen(product: product, heroTag: 'search-${product.id}'),
              ),
            ),
          ),
        );
      },
    );
  }
}
