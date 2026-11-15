import 'package:flutter_test/flutter_test.dart';
import 'package:zefanya/config/dev_flags.dart';
import 'package:zefanya/data/repositories/auth_repository.dart';
import 'package:zefanya/data/repositories/repositories.dart';

void main() {
  test('release switch selects the expected repository bundle', () {
    const expectedLive = bool.fromEnvironment('TEST_EXPECT_LIVE');
    final repositories = Repositories.forMode(useLive: kUseLiveApi);
    if (expectedLive) {
      expect(repositories.auth, isA<LiveAuthRepository>());
      expect(repositories.client, isNotNull);
    } else {
      expect(repositories.auth, isA<MockAuthRepository>());
      expect(repositories.client, isNull);
    }
  });
}
