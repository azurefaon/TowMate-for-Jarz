import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/services/api_service.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('ApiService booking draft persistence', () {
    test('does nothing when no user is logged in', () async {
      await ApiService.saveBookingDraft({'pickup_address': 'Rizal Park'});
      final draft = await ApiService.loadBookingDraft();
      expect(draft, isNull);
    });

    test('round-trips a saved draft for the logged-in user', () async {
      SharedPreferences.setMockInitialValues({'user_id': 42});

      await ApiService.saveBookingDraft({
        'step': 1,
        'service_type': 'book_now',
        'pickup_address': 'Rizal Park',
        'pickup_lat': 14.5832,
        'pickup_lng': 120.9794,
      });

      final draft = await ApiService.loadBookingDraft();
      expect(draft, isNotNull);
      expect(draft!['pickup_address'], 'Rizal Park');
      expect(draft['step'], 1);
      expect(draft['user_id'], 42);
      expect(draft['saved_at'], isNotNull);
    });

    test('does not restore a draft saved under a different account', () async {
      SharedPreferences.setMockInitialValues({'user_id': 42});
      await ApiService.saveBookingDraft({'pickup_address': 'Rizal Park'});

      final prefs = await SharedPreferences.getInstance();
      await prefs.setInt('user_id', 99);

      final draft = await ApiService.loadBookingDraft();

      expect(draft, isNull);
    });

    test('a mismatched draft is cleared once inspected, so it cannot leak back to the original account either', () async {
      SharedPreferences.setMockInitialValues({'user_id': 42});
      await ApiService.saveBookingDraft({'pickup_address': 'Rizal Park'});

      final prefs = await SharedPreferences.getInstance();
      await prefs.setInt('user_id', 99);
      expect(await ApiService.loadBookingDraft(), isNull);

      await prefs.setInt('user_id', 42);
      expect(await ApiService.loadBookingDraft(), isNull);
    });

    test('clearBookingDraft removes the saved draft', () async {
      SharedPreferences.setMockInitialValues({'user_id': 42});
      await ApiService.saveBookingDraft({'pickup_address': 'Rizal Park'});

      await ApiService.clearBookingDraft();
      final draft = await ApiService.loadBookingDraft();

      expect(draft, isNull);
    });
  });
}
