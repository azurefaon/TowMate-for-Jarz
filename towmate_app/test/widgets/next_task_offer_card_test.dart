import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/models/next_task_offer.dart';
import 'package:towmate_app/services/team_leader_service.dart';
import 'package:towmate_app/widgets/next_task_offer_card.dart';

http.Response _json(Object body, int status) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

Map<String, dynamic> _offerJson({String status = 'offered', int? secondsLeft = 540}) => {
      'id': 7,
      'status': status,
      'seconds_left': secondsLeft,
      'current_booking_code': 'BK-CURRENT',
      'booking': {
        'booking_code': 'BK-NEXT',
        'pickup_address': '12 Sample St, Quezon City',
        'dropoff_address': 'Sample Dropoff',
        'tow_class': 'Light Duty',
        'vehicle_info': 'Toyota Vios',
        'is_scheduled': false,
        'scheduled_for': null,
      },
    };

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  setUp(() {
    SharedPreferences.setMockInitialValues({'auth_token': 'live-token', 'user_role': 'Team Leader'});
  });

  group('NextTaskOffer', () {
    test('parses the task without any customer contact fields', () {
      final offer = NextTaskOffer.fromJson(_offerJson());
      expect(offer.bookingCode, 'BK-NEXT');
      expect(offer.towClass, 'Light Duty');
      expect(offer.currentBookingCode, 'BK-CURRENT');
      expect(offer.isReserved, isFalse);
    });

    test('the countdown is anchored to the server value and only counts down', () {
      final offer = NextTaskOffer.fromJson(_offerJson(secondsLeft: 60));
      expect(offer.secondsLeft(offer.receivedAt), 60);
      expect(offer.secondsLeft(offer.receivedAt.add(const Duration(seconds: 20))), 40);
      expect(offer.secondsLeft(offer.receivedAt.subtract(const Duration(seconds: 30))), 60,
          reason: 'a device clock moved back must not extend the server deadline');
      expect(offer.isExpired(offer.receivedAt.add(const Duration(seconds: 61))), isTrue);
    });

    test('a reserved offer has no countdown', () {
      final offer = NextTaskOffer.fromJson(_offerJson(status: 'accepted', secondsLeft: null));
      expect(offer.isReserved, isTrue);
      expect(offer.secondsLeft(), isNull);
      expect(offer.isExpired(), isFalse);
    });
  });

  group('TeamLeaderService next offer', () {
    Future<T> with_<T>(http.Client c, Future<T> Function() body) => http.runWithClient(body, () => c);

    test('returns null when nothing is queued', () async {
      final client = MockClient((r) async => _json({'success': true, 'data': null}, 200));
      expect(await with_(client, TeamLeaderService.getNextOffer), isNull);
    });

    test('reads the queued offer', () async {
      late http.Request seen;
      final client = MockClient((r) async {
        seen = r;
        return _json({'success': true, 'data': _offerJson()}, 200);
      });
      final offer = await with_(client, TeamLeaderService.getNextOffer);
      expect(offer!.id, 7);
      expect(seen.url.path, endsWith('/v1/team-leader/next-offer'));
      expect(seen.headers['Authorization'], 'Bearer live-token');
    });

    test('accept posts to the accept endpoint and returns the reserved offer', () async {
      late http.Request seen;
      final client = MockClient((r) async {
        seen = r;
        return _json({'success': true, 'message': 'Reserved.', 'data': _offerJson(status: 'accepted', secondsLeft: null)}, 200);
      });
      final result = await with_(client, () => TeamLeaderService.acceptNextOffer(7));
      expect(result['success'], isTrue);
      expect((result['offer'] as NextTaskOffer).isReserved, isTrue);
      expect(seen.method, 'POST');
      expect(seen.url.path, endsWith('/v1/team-leader/next-offer/7/accept'));
    });

    test('an expired or taken offer is reported as stale, not as a crash', () async {
      final client = MockClient((r) async => _json({'success': false, 'message': 'This offer has expired.', 'reason': 'expired'}, 409));
      final result = await with_(client, () => TeamLeaderService.acceptNextOffer(7));
      expect(result['success'], isFalse);
      expect(result['stale'], isTrue);
      expect(result['message'], isNotEmpty);
    });
  });

  group('NextTaskOfferCard', () {
    Future<void> pump(WidgetTester tester, NextTaskOffer offer, VoidCallback onChanged) async {
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(body: Align(alignment: Alignment.bottomCenter, child: NextTaskOfferCard(offer: offer, onChanged: onChanged))),
      ));
    }

    testWidgets('shows the task, the countdown and honest wording', (tester) async {
      await pump(tester, NextTaskOffer.fromJson(_offerJson()), () {});
      expect(find.text('Next task offer'), findsOneWidget);
      expect(find.textContaining('BK-NEXT'), findsOneWidget);
      expect(find.textContaining('Light Duty'), findsOneWidget);
      expect(find.textContaining('12 Sample St'), findsOneWidget);
      expect(find.byKey(const Key('nextTaskCountdown')), findsOneWidget);
      expect(find.textContaining('only reserves'), findsOneWidget);
      expect(find.byKey(const Key('nextTaskAccept')), findsOneWidget);
      expect(find.byKey(const Key('nextTaskDecline')), findsOneWidget);
      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('a reserved offer collapses to a one-line indicator with no Accept/Decline', (tester) async {
      await pump(tester, NextTaskOffer.fromJson(_offerJson(status: 'accepted', secondsLeft: null)), () {});
      expect(find.byKey(const Key('nextTaskReservedIndicator')), findsOneWidget);
      expect(find.textContaining('Next task reserved · BK-NEXT · Light Duty'), findsOneWidget);
      expect(find.byKey(const Key('nextTaskOfferCard')), findsNothing);
      expect(find.byKey(const Key('nextTaskAccept')), findsNothing);
      expect(find.byKey(const Key('nextTaskRelease')), findsNothing); // secondary: behind the details
      expect(find.byKey(const Key('nextTaskCountdown')), findsNothing);
      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('tapping the indicator shows details and Release; Hide details collapses it again', (tester) async {
      await pump(tester, NextTaskOffer.fromJson(_offerJson(status: 'accepted', secondsLeft: null)), () {});
      await tester.tap(find.byKey(const Key('nextTaskReservedIndicator')));
      await tester.pump();
      expect(find.byKey(const Key('nextTaskOfferCard')), findsOneWidget);
      expect(find.textContaining('Dispatch will assign it'), findsOneWidget);
      expect(find.byKey(const Key('nextTaskRelease')), findsOneWidget);

      await tester.tap(find.byKey(const Key('nextTaskCollapse')));
      await tester.pump();
      expect(find.byKey(const Key('nextTaskReservedIndicator')), findsOneWidget);
      expect(find.byKey(const Key('nextTaskOfferCard')), findsNothing);
      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('releasing from the details calls decline and asks the owner to refresh', (tester) async {
      var changed = 0;
      final requests = <String>[];
      final client = MockClient((r) async {
        requests.add(r.url.path);
        return _json({'success': true, 'message': 'Declined.', 'data': null}, 200);
      });
      await pump(tester, NextTaskOffer.fromJson(_offerJson(status: 'accepted', secondsLeft: null)), () => changed++);
      await tester.tap(find.byKey(const Key('nextTaskReservedIndicator')));
      await tester.pump();

      await tester.runAsync(() => http.runWithClient(() async {
            await tester.tap(find.byKey(const Key('nextTaskRelease')));
            await Future<void>.delayed(const Duration(milliseconds: 200));
          }, () => client));
      await tester.pump();

      expect(requests.single, endsWith('/next-offer/7/decline'));
      expect(changed, 1);
      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('accepting calls the service and asks the owner to refresh', (tester) async {
      var changed = 0;
      final requests = <String>[];
      final client = MockClient((r) async {
        requests.add('${r.method} ${r.url.path}');
        return _json({'success': true, 'data': _offerJson(status: 'accepted', secondsLeft: null)}, 200);
      });
      await pump(tester, NextTaskOffer.fromJson(_offerJson()), () => changed++);

      await tester.runAsync(() => http.runWithClient(() async {
            await tester.tap(find.byKey(const Key('nextTaskAccept')));
            await Future<void>.delayed(const Duration(milliseconds: 200));
          }, () => client));
      await tester.pump();

      expect(requests.single, endsWith('/next-offer/7/accept'));
      expect(changed, 1);
      // Accepting collapses the big card straight away.
      expect(find.byKey(const Key('nextTaskReservedIndicator')), findsOneWidget);
      expect(find.byKey(const Key('nextTaskOfferCard')), findsNothing);
      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('declining calls the decline endpoint', (tester) async {
      var changed = 0;
      final requests = <String>[];
      final client = MockClient((r) async {
        requests.add(r.url.path);
        return _json({'success': true, 'message': 'Declined.', 'data': null}, 200);
      });
      await pump(tester, NextTaskOffer.fromJson(_offerJson()), () => changed++);

      await tester.runAsync(() => http.runWithClient(() async {
            await tester.tap(find.byKey(const Key('nextTaskDecline')));
            await Future<void>.delayed(const Duration(milliseconds: 200));
          }, () => client));
      await tester.pump();

      expect(requests.single, endsWith('/next-offer/7/decline'));
      expect(changed, 1);
      await tester.pumpWidget(const SizedBox());
    });

    testWidgets('a failed answer shows the reason and keeps the buttons', (tester) async {
      final client = MockClient((r) async => _json({'success': false, 'message': 'This offer has expired.'}, 409));
      await pump(tester, NextTaskOffer.fromJson(_offerJson()), () {});

      await tester.runAsync(() => http.runWithClient(() async {
            await tester.tap(find.byKey(const Key('nextTaskAccept')));
            await Future<void>.delayed(const Duration(milliseconds: 200));
          }, () => client));
      await tester.pump();

      expect(find.byKey(const Key('nextTaskError')), findsOneWidget);
      expect(find.byKey(const Key('nextTaskAccept')), findsOneWidget);
      await tester.pumpWidget(const SizedBox());
    });
  });
}
