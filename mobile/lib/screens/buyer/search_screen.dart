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
//
// Recent search history: saved to SharedPreferences under the same
// pattern the cart uses (jsonEncode list of strings). Cap of 8 terms,
// deduped on insert so the same query never appears twice. A clear-all
// affordance lives next to the "RECENT" eyebrow when there is history
// to clear.

import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../data/mock_data.dart';
import '../../state/catalog_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/microcopy.dart';
import '../../utils/money_format.dart';
import '../../utils/product_sort.dart';
import '../../utils/search_filter.dart';
import '../../utils/motion.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/motion/ease_in_frame.dart';
import '../../widgets/zefanya_mark.dart';
import 'product_details_screen.dart';
import 'widgets/product_card.dart';
import 'widgets/sort_chips.dart';
import 'widgets/try_looking_for.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/cart_button.dart';

// ---------------------------------------------------------------------------
// Search history persistence
// ---------------------------------------------------------------------------

const int _kHistoryCap = 8;
const String _kHistoryKey = 'search_history';

/// Loads the saved search history, most-recent-first. Returns [] on any error.
Future<List<String>> _loadHistory() async {
  try {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kHistoryKey);
    if (raw == null) return const [];
    return List<String>.from(jsonDecode(raw) as List);
  } catch (_) {
    return const [];
  }
}

/// Prepends [term] to the history list, dedupes, caps at [_kHistoryCap], saves.
Future<List<String>> _pushHistory(String term) async {
  final trimmed = term.trim();
  if (trimmed.isEmpty) return await _loadHistory();

  final history = List<String>.from(await _loadHistory());
  history.removeWhere((t) => t.toLowerCase() == trimmed.toLowerCase());
  history.insert(0, trimmed);
  if (history.length > _kHistoryCap) history.removeRange(_kHistoryCap, history.length);

  final prefs = await SharedPreferences.getInstance();
  await prefs.setString(_kHistoryKey, jsonEncode(history));
  return history;
}

/// Removes [term] from history (case-insensitive).
Future<List<String>> _removeFromHistory(String term) async {
  final history = List<String>.from(await _loadHistory());
  history.removeWhere((t) => t.toLowerCase() == term.toLowerCase());
  final prefs = await SharedPreferences.getInstance();
  await prefs.setString(_kHistoryKey, jsonEncode(history));
  return history;
}

/// Clears all search history.
Future<void> _clearHistory() async {
  final prefs = await SharedPreferences.getInstance();
  await prefs.remove(_kHistoryKey);
}

// ---------------------------------------------------------------------------
// Screen
// ---------------------------------------------------------------------------

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
  ProductSort _sort = ProductSort.featured;

  /// Extra client-side filters — all from data the catalogue already
  /// carries (price, rating). See utils/search_filter.dart.
  double? _maxPrice;
  double _minRating = 0;

  SearchFilter get _filter =>
      SearchFilter(maxPrice: _maxPrice, minRating: _minRating);

  static const _priceBounds = [300.0, 500.0, 1000.0];
  List<String> _history = const [];

  static const _popularSearches = ['Linen', 'Ceramic', 'Tote Bag', 'Scarf', 'Home & Living'];

  @override
  void initState() {
    super.initState();
    widget.focusRequests?.addListener(_onFocusRequested);
    _loadHistory().then((h) {
      if (mounted) setState(() => _history = h);
    });
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

  /// The query matches, sorted but UNFILTERED — the honest "N pieces
  /// found" and the branch condition. Filtering happens separately in
  /// [_results] so a filter that hides everything can't also hide the
  /// chips that would clear it (the dead-end Claude caught).
  List<Product> get _matches => sortProducts(
      context.watch<CatalogState>().search(_query), _sort);

  /// The matches for what has been typed so far — sorted, then
  /// price/rating filtered, all client-side from catalogue data.
  List<Product> get _results => filterProducts(_matches, _filter);

  void _clearFilters() => setState(() {
        _maxPrice = null;
        _minRating = 0;
      });

  /// "Matches exist but filters hide them" — with the way out.
  Widget _buildFilterEmpty(ThemeData theme) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
      child: Row(
        children: [
          Expanded(
            child: Text(
              Microcopy.searchFilterEmpty,
              style: theme.textTheme.bodySmall?.copyWith(
                color: AppColors.textSecondary,
              ),
            ),
          ),
          TextButton(
            onPressed: _clearFilters,
            child: const Text('Clear filters'),
          ),
        ],
      ),
    );
  }

  void _search(String value) => setState(() => _query = value);

  /// Executes a search term — updates the field, triggers search, saves history.
  void _applyTerm(String term) {
    _controller.text = term;
    _search(term);
    _focusNode.unfocus();
    _pushHistory(term).then((h) {
      if (mounted) setState(() => _history = h);
    });
  }

  /// Called when the user submits the field (keyboard action or unfocus).
  void _onSubmitted(String value) {
    final trimmed = value.trim();
    if (trimmed.isEmpty) return;
    _applyTerm(trimmed);
  }

  void _clearAll() {
    _clearHistory().then((_) {
      if (mounted) setState(() => _history = const []);
    });
  }

  void _removeItem(String term) {
    _removeFromHistory(term).then((h) {
      if (mounted) setState(() => _history = h);
    });
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: SafeArea(
        child: Column(
          children: [
            _buildSearchField(theme),
            Expanded(
              // Branch on _matches (query hits), not _results (filtered):
              // a filter that hides everything must still show the chips
              // that clear it, plus the "nothing matches" way out.
              // Crossfade between idle / empty / results so tapping a
              // chip never jumps the layout — fade only, no slide.
              // Reduced motion swaps instantly (duration zero still
              // swaps: the end state, not a blank).
              child: AnimatedSwitcher(
                duration: reduceMotion(context)
                    ? Duration.zero
                    : const Duration(milliseconds: 220),
                child: _query.isEmpty
                    ? _buildSuggestions(theme, key: const ValueKey('search-suggestions'))
                    : _matches.isEmpty
                        ? _buildNoResults(theme, key: const ValueKey('search-no-results'))
                        : Column(
                            key: const ValueKey('search-results'),
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                            _buildResultsHeader(),
                            EaseInFrame(
                              index: 0,
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  ProductSortChips(
                                    sort: _sort,
                                    onChanged: (sort) => setState(() => _sort = sort),
                                  ),
                                  const SizedBox(height: 8),
                                  _buildFilterChips(),
                                ],
                              ),
                            ),
                            const SizedBox(height: 4),
                            // Filters hid everything the query matched: say so
                            // and offer the way out, instead of a dead end
                            // with no visible way to clear.
                            if (_results.isEmpty)
                              _buildFilterEmpty(theme),
                            Expanded(child: _buildResultsGrid(context)),
                          ],
                        ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSearchField(ThemeData theme) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 16, 12, 0),
      child: Row(
        children: [
          Expanded(
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 6),
              decoration: BoxDecoration(
                color: AppColors.primary.withValues(alpha: 0.24),
                borderRadius: editorialRadius(0, ShapeTier.card),
              ),
              child: Row(
                children: [
                  Icon(Icons.search, size: 22, color: AppColors.primaryDark),
                  const SizedBox(width: 10),
                  Expanded(
                    child: TextField(
                      controller: _controller,
                      focusNode: _focusNode,
                      // No autofocus: the shell requests focus explicitly when
                      // arriving via Home's search bar (focusRequests). Autofocus
                      // here would raise the keyboard on every return to the tab
                      // — including back from a product page — over a query the
                      // person may only have been half done typing.
                      onChanged: _search,
                      onSubmitted: _onSubmitted,
                      textInputAction: TextInputAction.search,
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
                      tooltip: 'Clear search',
                      icon: Icon(Icons.close, size: 18, color: AppColors.primaryDark),
                      onPressed: () {
                        _controller.clear();
                        _search('');
                      },
                    ),
                ],
              ),
            ),
          ),
          const SizedBox(width: 8),
          const CartButton(),
        ],
      ),
    );
  }

  Widget _buildSuggestions(ThemeData theme, {Key? key}) {
    return ListView(
      key: key,
      padding: const EdgeInsets.fromLTRB(20, 32, 20, 24),
      children: [
        // ---- Recent search history --------------------------------
        if (_history.isNotEmpty) ...[
          Row(
            children: [
              Text('RECENT', style: AppType.eyebrow),
              const Spacer(),
              GestureDetector(
                onTap: _clearAll,
                child: Text(
                  'Clear all',
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: AppColors.primaryDark,
                    decoration: TextDecoration.underline,
                    decorationColor: AppColors.primaryDark,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final term in _history)
                InputChip(
                  label: Text(term),
                  avatar: Icon(Icons.history, size: 16, color: AppColors.textSecondary),
                  onPressed: () => _applyTerm(term),
                  onDeleted: () => _removeItem(term),
                  deleteIconColor: AppColors.textSecondary,
                  side: BorderSide.none,
                  backgroundColor: AppColors.surfaceMuted,
                ),
            ],
          ),
          const SizedBox(height: 32),
        ],
        // ---- Popular searches -------------------------------------
        // Stagger entrance + touch press + query reaction live in
        // TryLookingFor; idle query is always '' here (any keystroke
        // swaps to results), so the row shows the authored order.
        Text('TRY LOOKING FOR', style: AppType.eyebrow),
        const SizedBox(height: 20),
        TryLookingFor(
          terms: _popularSearches,
          query: '',
          onSelect: _applyTerm,
        ),
      ],
    );
  }

  Widget _buildNoResults(ThemeData theme, {Key? key}) {
    return Center(
      key: key,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          ZefanyaMark(size: 46, color: AppColors.neutral.withValues(alpha: 0.4), strokeWidth: 4),
          const SizedBox(height: 22),
          Text(Microcopy.searchEmptyEyebrow, style: AppType.eyebrow),
          const SizedBox(height: 10),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 40),
            child: Text(
              Microcopy.searchNoResults(_query),
              style: AppType.sectionDisplay.copyWith(fontSize: 26),
              textAlign: TextAlign.center,
            ),
          ),
          const SizedBox(height: 8),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 32),
            child: Text(
              Microcopy.searchEmptySubtitle,
              style: theme.textTheme.bodySmall,
              textAlign: TextAlign.center,
            ),
          ),
          // Tappable retries from the existing popular terms (same list
          // as the idle state) — a dead end that offers a way out.
          // Dim-only: authored order is kept (reordering here would
          // jump per keystroke); non-matches dim against the query.
          const SizedBox(height: 18),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24),
            child: TryLookingFor(
              terms: _popularSearches,
              query: _query,
              reorder: false,
              onSelect: _applyTerm,
            ),
          ),
        ],
      ),
    );
  }

  /// Price + rating filter chips under the sort row. All client-side
  /// from catalogue data (price, rating) — no endpoint, no new state
  /// class. "Any price" clears the bound; tapping the active bound
  /// again clears it too.
  Widget _buildFilterChips() {
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.symmetric(horizontal: 20),
      child: Row(
        children: [
          for (final bound in _priceBounds) ...[
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: FilterChip(
                label: Text('Under ${formatPeso(bound)}'),
                selected: _maxPrice == bound,
                onSelected: (_) => setState(
                  () => _maxPrice = _maxPrice == bound ? null : bound,
                ),
              ),
            ),
          ],
          FilterChip(
            label: const Text('4.5★ & up'),
            selected: _minRating >= 4.5,
            onSelected: (_) => setState(
              () => _minRating = _minRating >= 4.5 ? 0 : 4.5,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildResultsHeader() {
    // Match count, not filtered count: "0 pieces found" would lie when
    // the query hit but the filters hid everything (the row below says
    // exactly that). Grid still renders _results.
    final count = _matches.length;
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
        return EaseInFrame(
          index: i.clamp(0, 5),
          child: ProductCard(
            product: product,
            index: i,
            heroTag: 'search-${product.id}',
            onTap: () {
              _onSubmitted(_query); // save to history when tapping a result
              Navigator.of(context).push(
                AppPageRoute(
                  builder: (_) => ProductDetailsScreen(product: product, heroTag: 'search-${product.id}'),
                ),
              );
            },
          ),
        );
      },
    );
  }
}
