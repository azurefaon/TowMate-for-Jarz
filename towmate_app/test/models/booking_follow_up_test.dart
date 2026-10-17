import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/models/booking_model.dart';

Map<String, dynamic> _json(Map<String, dynamic> extra) => {
      'id': 1,
      'booking_code': 'TM-00001',
      'status': 'requested',
      'pickup_address': 'A',
      'dropoff_address': 'B',
      'truck_type': {'name': 'Light'},
      ...extra,
    };

void main() {
  test('follow-up fields default to not waiting when the server omits them', () {
    final b = BookingModel.fromJson(_json({}));
    expect(b.isWaitingForDispatcher, isFalse);
    expect(b.canFollowUp, isFalse);
    expect(b.followUpInSeconds, 0);
  });

  test('follow-up fields are read from the booking detail', () {
    final b = BookingModel.fromJson(_json({
      'is_waiting_for_dispatcher': true,
      'can_follow_up': false,
      'follow_up_in_seconds': 240,
    }));
    expect(b.isWaitingForDispatcher, isTrue);
    expect(b.canFollowUp, isFalse);
    expect(b.followUpInSeconds, 240);
  });
}
