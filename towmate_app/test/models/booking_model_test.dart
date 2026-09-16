import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/models/booking_model.dart';

BookingModel _booking(String status) => BookingModel.fromJson({
      'id': 1,
      'booking_code': 'TM-00001',
      'status': status,
      'pickup_address': 'A',
      'dropoff_address': 'B',
      'truck_type_name': 'Light Duty',
    });

void main() {
  group('BookingModel.humanStatus', () {
    const realStatuses = {
      'requested': 'Requested',
      'reviewed': 'Under review',
      'quoted': 'Quoted',
      'quotation_sent': 'Quotation sent',
      'scheduled': 'Scheduled',
      'scheduled_confirmed': 'Scheduled — Confirmed',
      'confirmed': 'Confirmed',
      'accepted': 'Accepted',
      'assigned': 'Unit assigned',
      'on_the_way': 'On the way',
      'arrived_pickup': 'Arrived at pickup',
      'in_progress': 'Towing in progress',
      'loading_vehicle': 'Loading vehicle',
      'on_job': 'On the way to drop-off',
      'arrived_dropoff': 'Arrived at destination',
      'waiting_verification': 'Awaiting verification',
      'delayed': 'Delayed',
      'completed': 'Completed',
      'cancelled': 'Cancelled',
      'rejected': 'Rejected',
      'not_responding': 'You did not respond',
    };

    realStatuses.forEach((status, label) {
      test('maps "$status" to a real customer-facing label, never the raw enum value', () {
        final humanStatus = _booking(status).humanStatus;
        expect(humanStatus, label);
        expect(humanStatus, isNot(status));
      });
    });

    test('an unrecognized status falls back to the raw value rather than crashing', () {
      expect(_booking('some_future_status').humanStatus, 'some_future_status');
    });
  });

  group('BookingModel.isCancellableByCustomer', () {
    for (final status in ['requested', 'scheduled', 'scheduled_confirmed']) {
      test('"$status" is cancellable by the customer, matching the backend rule', () {
        expect(_booking(status).isCancellableByCustomer, isTrue);
      });
    }

    for (final status in [
      'quotation_sent',
      'confirmed',
      'accepted',
      'assigned',
      'on_the_way',
      'arrived_pickup',
      'in_progress',
      'loading_vehicle',
      'on_job',
      'arrived_dropoff',
      'waiting_verification',
      'completed',
      'cancelled',
      'rejected',
      'not_responding',
    ]) {
      test('"$status" is not cancellable by the customer once past the allowed window', () {
        expect(_booking(status).isCancellableByCustomer, isFalse);
      });
    }
  });

  group('BookingModel.isHistorical', () {
    for (final status in ['completed', 'cancelled', 'rejected', 'not_responding']) {
      test('"$status" is historical', () {
        expect(_booking(status).isHistorical, isTrue);
      });
    }

    for (final status in ['requested', 'scheduled', 'scheduled_confirmed', 'on_the_way', 'waiting_verification']) {
      test('"$status" is active, not historical', () {
        expect(_booking(status).isHistorical, isFalse);
      });
    }
  });
}
