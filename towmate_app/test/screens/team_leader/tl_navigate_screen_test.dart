import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:towmate_app/models/task_model.dart';
import 'package:towmate_app/screens/team_leader/tl_navigate_screen.dart';

TaskModel buildTask({required String status}) {
  return TaskModel(
    id: 1,
    bookingCode: 'TM-00100',
    status: status,
    pickupAddress: '123 Commonwealth Avenue, Quezon City, Metro Manila',
    dropoffAddress: '456 Ayala Avenue, Makati City, Metro Manila',
    pickupLat: 14.676,
    pickupLng: 121.0437,
    dropoffLat: 14.5547,
    dropoffLng: 121.0244,
    distanceKm: 8.4,
    customerName: 'Juan Dela Cruz',
    customerPhone: '09171234567',
    customerEmail: 'juan@example.test',
    finalTotal: 4658.08,
    truckTypeName: 'Medium Duty',
    serviceType: 'book_now',
  );
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();

  Future<void> settle(WidgetTester tester) async {
    for (var i = 0; i < 10; i++) {
      await tester.pump(const Duration(milliseconds: 50));
    }
  }

  Future<void> pumpScreen(WidgetTester tester, TaskModel task) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: TlNavigateScreen(task: task)),
      ),
    );
    await settle(tester);
  }

  setUp(() {
    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      const MethodChannel('flutter.baseflow.com/geolocator'),
      (call) async => call.method == 'checkPermission' ? 2 : null,
    );
    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      const MethodChannel('flutter.baseflow.com/geolocator_android'),
      (call) async => call.method == 'checkPermission' ? 2 : null,
    );
  });

  tearDown(() {
    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      const MethodChannel('flutter.baseflow.com/geolocator'),
      null,
    );
    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      const MethodChannel('flutter.baseflow.com/geolocator_android'),
      null,
    );
  });

  group('TlNavigateScreen', () {
    testWidgets(
        'pickup phase (on_the_way) fails initialization safely instead of crashing',
        (tester) async {
      await pumpScreen(tester, buildTask(status: 'on_the_way'));

      expect(tester.takeException(), isNull);
      expect(find.text('Navigation unavailable'), findsOneWidget);
      expect(find.text('Try Again'), findsOneWidget);
    });

    testWidgets(
        'drop-off phase (on_job) fails initialization safely instead of crashing',
        (tester) async {
      await pumpScreen(tester, buildTask(status: 'on_job'));

      expect(tester.takeException(), isNull);
      expect(find.text('Navigation unavailable'), findsOneWidget);
      expect(find.text('Try Again'), findsOneWidget);
    });

    testWidgets('shows a location-permission message when permission is denied',
        (tester) async {
      binding.defaultBinaryMessenger.setMockMethodCallHandler(
        const MethodChannel('flutter.baseflow.com/geolocator'),
        (call) async {
          if (call.method == 'checkPermission') return 0;
          if (call.method == 'requestPermission') return 0;
          return null;
        },
      );
      binding.defaultBinaryMessenger.setMockMethodCallHandler(
        const MethodChannel('flutter.baseflow.com/geolocator_android'),
        (call) async {
          if (call.method == 'checkPermission') return 0;
          if (call.method == 'requestPermission') return 0;
          return null;
        },
      );

      await pumpScreen(tester, buildTask(status: 'arrived_dropoff'));

      expect(tester.takeException(), isNull);
      expect(find.text('Location permission needed'), findsOneWidget);
      expect(find.text('Try Again'), findsOneWidget);
    });

    testWidgets('Try Again re-attempts initialization without crashing',
        (tester) async {
      await pumpScreen(tester, buildTask(status: 'on_the_way'));
      expect(find.text('Try Again'), findsOneWidget);

      await tester.tap(find.text('Try Again'));
      await settle(tester);

      expect(tester.takeException(), isNull);
      expect(find.text('Navigation unavailable'), findsOneWidget);
    });
  });
}
