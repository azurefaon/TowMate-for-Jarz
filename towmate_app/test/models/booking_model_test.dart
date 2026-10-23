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
  _closureGroup();

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

void _closureGroup() {
  group('BookingModel closure details', () {
    BookingModel closed(String status, {String? by, String? reason}) => BookingModel.fromJson({
          'id': 1,
          'booking_code': 'TM-00001',
          'status': status,
          'pickup_address': 'A',
          'dropoff_address': 'B',
          'closed_by': by,
          'rejection_reason': reason,
        });

    test('dispatcher cancellation shows the actor and the recorded reason', () {
      final b = closed('cancelled', by: 'dispatcher', reason: ' No unit available ');
      expect(b.closureHeadline, 'Cancelled by Dispatcher');
      expect(b.closureReasonLine, 'Reason: No unit available');
    });

    test('dispatcher rejection is labelled as a rejection', () {
      final b = closed('rejected', by: 'dispatcher', reason: 'Out of coverage');
      expect(b.closureHeadline, 'Rejected by Dispatcher');
    });

    test('customer cancellation is attributed to the customer', () {
      expect(closed('cancelled', by: 'customer').closureHeadline, 'Cancelled by you');
    });

    test('missing reason falls back to a neutral line', () {
      final b = closed('cancelled', by: 'unknown');
      expect(b.closureHeadline, 'Booking Cancelled');
      expect(b.closureReasonLine, 'Reason not provided.');
    });

    test('an unknown actor keeps the recorded reason and the real status wording', () {
      final c = closed('cancelled', by: 'unknown', reason: 'Booking cancelled by dispatcher.');
      expect(c.closureHeadline, 'Booking Cancelled');
      expect(c.closureReasonLine, 'Reason: Booking cancelled by dispatcher.');
      expect(closed('rejected', by: 'unknown').closureHeadline, 'Booking Rejected');
      expect(closed('rejected').closureHeadline, 'Booking Rejected');
    });

    test('bookings that were not cancelled or rejected have no closure details', () {
      final b = closed('completed', by: 'dispatcher', reason: 'x');
      expect(b.closureHeadline, isNull);
      expect(b.closureReasonLine, isNull);
    });
  });
}
