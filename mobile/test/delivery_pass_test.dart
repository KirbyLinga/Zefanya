// test/delivery_pass_test.dart
//
// The buyer-to-rider handover is the one place a 6-digit code is shown on
// screen and then read by another phone, so the payload has to survive a
// round trip without becoming a second, looser definition of "valid".
// These tests pin the codec and the scanner-result rules independently of
// the two screens that use them.

import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/utils/delivery_pass.dart';

void main() {
  group('DeliveryPassPayload', () {
    test('encodes and decodes the job id and six-digit code', () {
      const payload = DeliveryPassPayload(jobId: 'REQ-1001', otp: '482913');

      final encoded = payload.encode();
      final decoded = DeliveryPassPayload.tryParse(encoded);

      expect(encoded, 'ZEFANYA:DELIVERY:REQ-1001:482913');
      expect(decoded, isNotNull);
      expect(decoded!.jobId, 'REQ-1001');
      expect(decoded.otp, '482913');
    });

    test('accepts a lower-case prefix and surrounding whitespace', () {
      final decoded = DeliveryPassPayload.tryParse(
        '  zefanya:delivery:REQ-1001:482913  ',
      );

      expect(decoded, isNotNull);
      expect(decoded!.jobId, 'REQ-1001');
      expect(decoded.otp, '482913');
    });

    test('rejects a code that is not exactly six digits', () {
      expect(
        DeliveryPassPayload.tryParse('ZEFANYA:DELIVERY:REQ-1001:48291'),
        isNull,
      );
      expect(
        DeliveryPassPayload.tryParse('ZEFANYA:DELIVERY:REQ-1001:4829133'),
        isNull,
      );
      expect(
        DeliveryPassPayload.tryParse('ZEFANYA:DELIVERY:REQ-1001:48A913'),
        isNull,
      );
    });

    test('rejects an unrelated QR payload or a missing job id', () {
      expect(DeliveryPassPayload.tryParse('ZEF-5521-01'), isNull);
      expect(DeliveryPassPayload.tryParse('ZEFANYA:DELIVERY::482913'), isNull);
      expect(
        DeliveryPassPayload.tryParse('ZEFANYA:OTHER:REQ-1001:482913'),
        isNull,
      );
    });
  });

  group('readDeliveryPassScan', () {
    test('accepts a typed six-digit code from the manual fallback', () {
      final result = readDeliveryPassScan(' 482913 ', jobId: 'REQ-1001');

      expect(result.error, isNull);
      expect(result.code, '482913');
    });

    test('accepts a pass belonging to the current job', () {
      final result = readDeliveryPassScan(
        'ZEFANYA:DELIVERY:REQ-1001:482913',
        jobId: 'REQ-1001',
      );

      expect(result.error, isNull);
      expect(result.code, '482913');
    });

    test('refuses a pass belonging to another order', () {
      final result = readDeliveryPassScan(
        'ZEFANYA:DELIVERY:REQ-9999:482913',
        jobId: 'REQ-1001',
      );

      expect(result.code, isNull);
      expect(result.error, 'That delivery pass belongs to a different order.');
    });

    test('refuses an unrelated QR code', () {
      final result = readDeliveryPassScan('ZEF-5521-01', jobId: 'REQ-1001');

      expect(result.code, isNull);
      expect(result.error, 'That QR is not a Zefanya delivery pass.');
    });
  });
}
