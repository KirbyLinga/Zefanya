// test/microcopy_test.dart
//
// Tests for warm microcopy pure functions.

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/utils/microcopy.dart';

void main() {
  group('Microcopy.greeting', () {
    test('returns morning greeting before 12pm', () {
      final morning9 = DateTime(2026, 10, 20, 9, 30);
      expect(Microcopy.greeting(morning9), 'Good morning');
      expect(Microcopy.greeting(morning9, name: 'Clara Santos'), 'Good morning, Clara');
    });

    test('returns afternoon greeting from 12pm to 6pm', () {
      final noon = DateTime(2026, 10, 20, 12, 0);
      final afternoon3 = DateTime(2026, 10, 20, 15, 45);
      expect(Microcopy.greeting(noon), 'Good afternoon');
      expect(Microcopy.greeting(afternoon3, name: 'Marco'), 'Good afternoon, Marco');
    });

    test('returns evening greeting at or after 6pm', () {
      final evening6 = DateTime(2026, 10, 20, 18, 0);
      final night10 = DateTime(2026, 10, 20, 22, 15);
      expect(Microcopy.greeting(evening6), 'Good evening');
      expect(Microcopy.greeting(night10, name: 'Elena Maria'), 'Good evening, Elena');
    });

    test('handles empty and whitespace names gracefully', () {
      final time = DateTime(2026, 10, 20, 10, 0);
      expect(Microcopy.greeting(time, name: ''), 'Good morning');
      expect(Microcopy.greeting(time, name: '   '), 'Good morning');
      expect(Microcopy.greeting(time, name: null), 'Good morning');
    });
  });

  group('Microcopy.addToCart', () {
    test('produces deterministic compliment based on seed', () {
      final msg0 = Microcopy.addToCart(seed: 0);
      final msg1 = Microcopy.addToCart(seed: 1);
      final msg4 = Microcopy.addToCart(seed: 4);

      expect(msg0, 'Lovely choice. Added to your cart.');
      expect(msg1, 'Added, good eye. Added to your cart.');
      expect(msg4, msg0); // Wraps around
    });

    test('includes product name when provided', () {
      final msg = Microcopy.addToCart(productName: 'Handwoven Rattan Bag', seed: 0);
      expect(msg, 'Lovely choice. Added Handwoven Rattan Bag to cart.');
    });

    test('falls back to generic cart message for empty product names', () {
      expect(Microcopy.addToCart(productName: '', seed: 0), 'Lovely choice. Added to your cart.');
      expect(Microcopy.addToCart(productName: '  ', seed: 0), 'Lovely choice. Added to your cart.');
    });
  });

  group('Microcopy.favoriteSaved', () {
    test('returns removal message when item is unsaved', () {
      expect(Microcopy.favoriteSaved(isSaved: false), 'Removed from saved.');
      expect(Microcopy.favoriteSaved(isSaved: false, seed: 2), 'Removed from saved.');
    });

    test('returns rotating compliments when item is saved', () {
      expect(Microcopy.favoriteSaved(isSaved: true, seed: 0), 'Saved, good eye.');
      expect(Microcopy.favoriteSaved(isSaved: true, seed: 1), 'Kept for later.');
      expect(Microcopy.favoriteSaved(isSaved: true, seed: 2), 'Saved to your favorites.');
      expect(Microcopy.favoriteSaved(isSaved: true, seed: 3), 'Saved, good eye.');
    });
  });

  group('Microcopy.voucherApplied', () {
    test('formats voucher code with kind confirmation', () {
      expect(
        Microcopy.voucherApplied('ZEFANYA10'),
        'Voucher "ZEFANYA10" applied. A little gift for you.',
      );
    });
  });

  group('Microcopy.searchNoResults', () {
    test('formats search query in typographic quotes', () {
      expect(Microcopy.searchNoResults('linen dress'), 'No results for “linen dress”');
    });
  });

  group('Microcopy.calmError', () {
    test('converts null or empty errors to gentle default', () {
      expect(
        Microcopy.calmError(null),
        'Something didn\'t go quite as planned. Take your time, we\'re right here.',
      );
      expect(
        Microcopy.calmError('   '),
        'Something didn\'t go quite as planned. Take your time, we\'re right here.',
      );
    });

    test('handles network and connection errors with kind reassurance', () {
      expect(
        Microcopy.calmError('SocketException: Connection refused'),
        'We couldn\'t reach the store just now. Take your time, we\'re right here.',
      );
      expect(
        Microcopy.calmError('Network request timed out'),
        'We couldn\'t reach the store just now. Take your time, we\'re right here.',
      );
    });

    test('handles failed to fetch errors cleanly', () {
      expect(
        Microcopy.calmError('Failed to fetch data from remote server'),
        'We couldn\'t complete that just now. Take your time, we\'re right here.',
      );
    });

    test('preserves specific validation or domain messages', () {
      expect(Microcopy.calmError('Invalid email or password'), 'Invalid email or password');
      expect(Microcopy.calmError('Code has expired'), 'Code has expired');
    });
  });

  group('Microcopy string constants', () {
    test('empty state strings are calm and non-empty', () {
      expect(Microcopy.cartEmptyTitle, isNotEmpty);
      expect(Microcopy.cartEmptySubtitle, contains('thoughtful'));
      expect(Microcopy.ordersEmptyTitle, isNotEmpty);
      expect(Microcopy.ordersEmptySubtitle, contains('track it here'));
      expect(Microcopy.savedEmptyTitle, isNotEmpty);
      expect(Microcopy.pickupsEmptyTitle, isNotEmpty);
      expect(Microcopy.deliveriesEmptyTitle, isNotEmpty);
      expect(Microcopy.searchEmptyEyebrow, isNotEmpty);
    });
  });
}
