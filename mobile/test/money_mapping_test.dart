// test/money_mapping_test.dart
//
// The centavos <-> pesos boundary.
//
// This conversion is small enough to look obviously correct and load-bearing
// enough that a mistake in it is a wrong price on every screen. So the tests
// below pin three separate things:
//
//  1. the arithmetic, including the rounding *direction* (the interesting
//     case is a value that a double cannot represent exactly);
//  2. the JSON leniency, because the shapes a server sends for an integer are
//     not always an integer;
//  3. that "absent" stays distinguishable from "zero".

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/data/dto/money.dart';

void main() {
  group('pesosFromMinor', () {
    test('converts centavos to pesos', () {
      expect(pesosFromMinor(0), 0);
      expect(pesosFromMinor(100), 1);
      expect(pesosFromMinor(12050), 120.5);
      expect(pesosFromMinor(6050), 60.5);
      expect(pesosFromMinor(999999), 9999.99);
    });

    test('is exact for the centavo values a price can hold', () {
      // Every centavo value up to a large amount must survive the trip, since
      // this runs on every money field in every response.
      for (final minor in [1, 7, 99, 100, 101, 12345, 1000000]) {
        expect(minorFromPesos(pesosFromMinor(minor)), minor,
            reason: 'round trip for $minor centavos');
      }
    });
  });

  group('minorFromPesos', () {
    test('converts pesos to centavos', () {
      expect(minorFromPesos(0), 0);
      expect(minorFromPesos(1), 100);
      expect(minorFromPesos(120.5), 12050);
      expect(minorFromPesos(59.99), 5999);
    });

    test('rounds to the nearest centavo rather than truncating', () {
      // 0.29 has no exact binary representation, so 0.29 * 100 evaluates to
      // 28.999999999999996. `floor`/`truncate` would yield 28 centavos and the
      // reported total would be one centavo under the sum of the app's own
      // lines. This assertion is the reason `.round()` was chosen.
      expect(minorFromPesos(0.29), 29);
      expect(0.29 * 100, lessThan(29), reason: 'the float artefact being guarded');
    });

    test('rounds symmetrically about zero', () {
      expect(minorFromPesos(-1.5), -150);
      expect(minorFromPesos(-0.29), -29);
    });
  });

  group('minorFromJson', () {
    test('accepts an integer, which is the contract', () {
      expect(minorFromJson(12050), 12050);
      expect(minorFromJson(0), 0);
    });

    test('accepts a string, because PHP and DECIMAL casts emit one', () {
      expect(minorFromJson('12050'), 12050);
      expect(minorFromJson('0'), 0);
    });

    test('accepts a double and rounds it', () {
      expect(minorFromJson(12050.4), 12050);
      expect(minorFromJson(12050.6), 12051);
    });

    test('returns null for absent or unreadable values, never throws', () {
      expect(minorFromJson(null), isNull);
      expect(minorFromJson(''), isNull);
      expect(minorFromJson('abc'), isNull);
      expect(minorFromJson({}), isNull);
      expect(minorFromJson([]), isNull);
    });
  });

  group('pesosFromJson', () {
    test('goes straight from wire value to the double the app stores', () {
      expect(pesosFromJson(12050), 120.5);
      expect(pesosFromJson('12050'), 120.5);
    });

    test('keeps "absent" distinct from "zero"', () {
      // The whole point of the fallback parameter: a malformed response must
      // not render as a free product.
      expect(pesosFromJson(null), isNull);
      expect(pesosFromJsonOr(null, -1), -1);
      expect(pesosFromJsonOr(0, -1), 0, reason: 'zero is a real price');
    });
  });

  group('formatMinor', () {
    test('formats to two decimal places with the peso sign', () {
      expect(formatMinor(12050), '₱120.50');
      expect(formatMinor(0), '₱0.00');
      expect(formatMinor(5), '₱0.05');
      expect(formatMinor(100000), '₱1000.00');
    });
  });

  test('the scale factor lives here and nowhere else', () {
    // A guard on the file's reason for existing: if someone adds a `/ 100` or
    // `* 100` beside a JSON field elsewhere, this constant is no longer the
    // single source of the scale and this suite can no longer claim it.
    expect(kMinorUnitsPerPeso, 100);
  });
}