// lib/utils/microcopy.dart
//
// Warm, kind, thoughtful microcopy for Zefanya.
// Pure functions with deterministic options for testing and subtle rotation
// for everyday warmth.

class Microcopy {
  Microcopy._();

  /// Time-of-day greeting for Home ("Good morning" / "Good afternoon" / "Good evening"
  /// + person's first name if available).
  static String greeting(DateTime time, {String? name}) {
    final hour = time.hour;
    final String timeGreeting;
    if (hour < 12) {
      timeGreeting = 'Good morning';
    } else if (hour < 18) {
      timeGreeting = 'Good afternoon';
    } else {
      timeGreeting = 'Good evening';
    }

    final trimmedName = name?.trim();
    if (trimmedName != null && trimmedName.isNotEmpty) {
      final firstName = trimmedName.split(' ').first;
      return '$timeGreeting, $firstName';
    }
    return timeGreeting;
  }

  /// Gentle compliments when adding an item to the cart.
  static const List<String> addToCartCompliments = [
    'Lovely choice.',
    'Added, good eye.',
    'On its way to being yours.',
    'A wonderful addition.',
  ];

  static String addToCart({String? productName, int seed = 0}) {
    final compliment = addToCartCompliments[seed.abs() % addToCartCompliments.length];
    final trimmedProduct = productName?.trim();
    if (trimmedProduct != null && trimmedProduct.isNotEmpty) {
      return '$compliment Added $trimmedProduct to cart.';
    }
    return '$compliment Added to your cart.';
  }

  /// Gentle confirmation when saving an item to favorites / wishlist.
  static const List<String> favoriteCompliments = [
    'Saved, good eye.',
    'Kept for later.',
    'Saved to your favorites.',
  ];

  static String favoriteSaved({required bool isSaved, int seed = 0}) {
    if (!isSaved) return 'Removed from saved.';
    return favoriteCompliments[seed.abs() % favoriteCompliments.length];
  }

  /// Gentle confirmation when applying a voucher.
  static String voucherApplied(String code) {
    return 'Voucher "$code" applied. A little gift for you.';
  }

  /// Cart empty state copy.
  static const String cartEmptyTitle = 'Your cart is quiet';
  static const String cartEmptySubtitle = 'Find something thoughtful to bring home.';

  /// Orders empty state copy.
  static const String ordersEmptyTitle = 'No orders yet';
  static const String ordersEmptySubtitle = 'When you find something you love, track it here.';

  /// Search empty state copy.
  static const String searchEmptyEyebrow = 'NOTHING HERE YET';
  static const String searchEmptySubtitle = 'Try a shorter or more general term, or explore our curated favorites.';
  static String searchNoResults(String query) => 'No results for \u201c$query\u201d';
  static const String searchFilterEmpty = 'Nothing matches these filters.';

  /// Saved empty state copy.
  static const String savedEmptyTitle = 'Nothing saved yet';
  static const String savedEmptySubtitle = 'Tap the heart on any product to keep it here.';

  /// Courier dashboard empty state copy.
  static const String pickupsEmptyTitle = 'Nothing to pick up yet';
  static const String pickupsEmptySubtitle = 'New assignments will appear here.';
  static const String deliveriesEmptyTitle = 'Nothing out for delivery yet';
  static const String deliveriesEmptySubtitle = 'Picked-up items will show up here.';

  /// Calm message for errors / failures.
  static String calmError(String? rawError) {
    if (rawError == null || rawError.trim().isEmpty) {
      return 'Something didn\'t go quite as planned. Take your time, we\'re right here.';
    }
    final lower = rawError.toLowerCase();
    if (lower.contains('network') ||
        lower.contains('connect') ||
        lower.contains('socket') ||
        lower.contains('timeout')) {
      return 'We couldn\'t reach the store just now. Take your time, we\'re right here.';
    }
    if (lower.contains('failed to') ||
        lower.contains('fetch') ||
        lower.contains('unexpected')) {
      return 'We couldn\'t complete that just now. Take your time, we\'re right here.';
    }
    return rawError;
  }
}
