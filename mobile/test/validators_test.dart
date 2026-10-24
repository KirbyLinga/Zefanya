// test/validators_test.dart
//
// The field rules shared by registration and Edit Profile. These are the
// rules a widget test can only prove indirectly; here they are pinned
// directly, including the boundary values the workbook uses.

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/utils/validators.dart';

void main() {
  group('person names (A2)', () {
    test('empty and one-character names are refused', () {
      expect(
        validatePersonName('', label: 'First name'),
        'First name is required',
      );
      expect(
        validatePersonName('  ', label: 'First name'),
        'First name is required',
      );
      expect(validatePersonName('J', label: 'First name'), isNotNull);
    });

    test('2 and 50 characters are accepted; 51 is not', () {
      final fifty = List.filled(50, 'a').join();
      final fiftyOne = List.filled(51, 'a').join();
      expect(validatePersonName('Jo', label: 'First name'), isNull);
      expect(validatePersonName(fifty, label: 'First name'), isNull);
      expect(validatePersonName(fiftyOne, label: 'First name'), isNotNull);
    });

    test('letters, spaces, hyphen, apostrophe, period and ñ survive', () {
      for (final name in [
        'Dela Cruz',
        'Maria-Jose',
        "O'Neil",
        'St. John',
        'Peña',
        'Ñico',
      ]) {
        expect(
          validatePersonName(name, label: 'First name'),
          isNull,
          reason: name,
        );
      }
    });

    test('numbers and other symbols are refused', () {
      for (final name in ['Juan2', 'Juan@', 'Juan_', 'Juan#']) {
        expect(
          validatePersonName(name, label: 'First name'),
          isNotNull,
          reason: name,
        );
      }
    });
  });

  group('password (A5)', () {
    test('below eight characters gets the length message', () {
      expect(validatePassword('Aa1!aa'), 'Use at least 8 characters');
    });

    test('each missing character class is refused', () {
      expect(validatePassword('lowercase1!'), isNotNull); // no uppercase
      expect(validatePassword('UPPERCASE1!'), isNotNull); // no lowercase
      expect(validatePassword('NoNumber!!'), isNotNull); // no digit
      expect(validatePassword('NoSymbol11'), isNotNull); // no symbol
    });

    test('eight characters with all four classes pass', () {
      expect(validatePassword('Abcdef1!'), isNull);
    });

    test('spaces are refused', () {
      expect(validatePassword('Abc def1!'), isNotNull);
    });
  });

  group('age (A6)', () {
    test('the 18th birthday counts, the day before does not', () {
      final today = DateTime(2026, 10, 21);
      expect(
        isAtLeastYearsOld(DateTime(2008, 10, 21), 18, today: today),
        isTrue,
      );
      expect(
        isAtLeastYearsOld(DateTime(2008, 10, 22), 18, today: today),
        isFalse,
      );
      expect(ageOn(DateTime(2008, 10, 22), today: today), 17);
    });

    test('a 17-year-old is refused and an 18-year-old accepted', () {
      final today = DateTime(2026, 10, 21);
      expect(
        isAtLeastYearsOld(DateTime(2009, 1, 1), 18, today: today),
        isFalse,
      );
      expect(isAtLeastYearsOld(DateTime(2008, 1, 1), 18, today: today), isTrue);
    });
  });
}
