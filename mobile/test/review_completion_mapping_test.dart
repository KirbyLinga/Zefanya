import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:zefanya/data/dto/remote_orders.dart';
import 'package:zefanya/data/dto/remote_review.dart';
import 'package:zefanya/state/review_state.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('delivered and completed map to different receipt eligibility', () {
    final delivered = orderFromJson({'id': '1', 'status': 'DELIVERED'});
    final completed = orderFromJson({
      'id': '1',
      'status': 'COMPLETED',
      'completed_at': '2026-11-01T12:00:00Z',
    });
    expect(delivered.isCompleted, isFalse);
    expect(completed.isCompleted, isTrue);
    expect(completed.completedAt, DateTime.utc(2026, 11, 1, 12));
  });

  test('server edit count and completion date survive review mapping', () {
    final review = reviewFromJson({
      'order_id': '1',
      'product_id': '2',
      'stars': 5,
      'created_at': '2026-11-06T12:00:00Z',
      'completed_at': '2026-11-01T12:00:00Z',
      'edit_count': 1,
    })!;
    expect(review.editCount, 1);
    expect(review.completedAt, DateTime.utc(2026, 11, 1, 12));
    expect(
      ProductReview.fromJson(review.toJson())!.completedAt,
      review.completedAt,
    );
  });

  test('recent review does not restart an older completion window', () async {
    final state = ReviewState();
    final now = DateTime.utc(2026, 11, 7);
    await state.submit(
      ProductReview(
        productId: '2',
        orderId: '1',
        stars: 5,
        createdAt: now.subtract(const Duration(days: 1)),
        completedAt: now.subtract(const Duration(days: 30)),
      ),
    );
    expect(
      state.editRefusal('1', '2', now: now),
      'Reviews can only be edited within 30 days.',
    );
  });
}
