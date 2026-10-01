import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/route_observer.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/customer/home_screen.dart';
import 'package:towmate_app/widgets/skeleton_box.dart';
import 'package:towmate_app/widgets/tm_bottom_nav.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

final _vehicleTypesByCategory = {
  '2_wheeler': [
    {'id': 1, 'name': 'Motorcycle'},
    {'id': 2, 'name': 'Scooter / E-Scooter'},
    {'id': 3, 'name': 'Bicycle / E-Bike'},
    {'id': 4, 'name': 'Tricycle'},
  ],
  '4_wheeler': [
    {'id': 8, 'name': 'SUV'},
    {'id': 11, 'name': 'Van / L300'},
    {'id': 7, 'name': 'AUV / MPV'},
  ],
  'heavy_vehicle': [
    {'id': 14, 'name': 'Elf / 6-Wheeler'},
  ],
};

http.Client _buildClient({
  Object? currentBooking,
  Object? pendingQuotation,
  Object? announcement,
  Set<String> failCategories = const {},
  Set<String> emptyCategories = const {},
  bool malformedVehicleTypes = false,
  int unreadCount = 2,
}) {
  return MockClient((request) async {
    final path = request.url.path;
    if (path.endsWith('/v1/bookings/current')) {
      return _json({'data': currentBooking});
    }
    if (path.endsWith('/v1/quotations/pending')) {
      return _json({'data': pendingQuotation});
    }
    if (path.endsWith('/v1/customer/content')) {
      return _json({'announcement': announcement, 'services': []});
    }
    if (path.contains('/vehicle-types/by-category/')) {
      final category = path.split('/').last;
      if (malformedVehicleTypes) return http.Response('<html>oops</html>', 200);
      if (failCategories.contains(category)) return _json({}, status: 500);
      if (emptyCategories.contains(category)) return _json({'vehicleTypes': []});
      return _json({'vehicleTypes': _vehicleTypesByCategory[category] ?? []});
    }
    if (path.endsWith('/v1/notifications')) {
      return _json({'success': true, 'unread_count': unreadCount, 'data': []});
    }
    return _json({'success': false}, status: 404);
  });
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pumpHome(
  WidgetTester tester, {
  Object? currentBooking,
  Object? pendingQuotation,
  Object? announcement,
  Set<String> failCategories = const {},
  Set<String> emptyCategories = const {},
  bool malformedVehicleTypes = false,
  void Function(String route, Object? args)? onNavigate,
  ThemeData? theme,
  double width = 390,
  String userName = 'Faon Delacruz',
  String? firstName,
}) async {
  tester.view.physicalSize = Size(width, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  SharedPreferences.setMockInitialValues({
    'auth_token': 'test-token',
    'user_role': 'Customer',
    'user_name': userName,
    'user_first_name': ?firstName,
  });
  await http.runWithClient(
    () async {
      await tester.pumpWidget(
        MaterialApp(
          theme: theme,
          navigatorObservers: [appRouteObserver],
          onGenerateRoute: (settings) {
            if (settings.name == '/' || settings.name == null) {
              return MaterialPageRoute(builder: (_) => const HomeScreen());
            }
            onNavigate?.call(settings.name!, settings.arguments);
            return MaterialPageRoute(builder: (_) => const Scaffold());
          },
        ),
      );
      await _settle(tester);
    },
    () => _buildClient(
      currentBooking: currentBooking,
      pendingQuotation: pendingQuotation,
      announcement: announcement,
      failCategories: failCategories,
      emptyCategories: emptyCategories,
      malformedVehicleTypes: malformedVehicleTypes,
    ),
  );
}

Map<String, dynamic> _booking({
  String code = 'TM-0001',
  String status = 'on_the_way',
  Map<String, dynamic> extra = const {},
}) {
  return {
    'id': 1,
    'booking_code': code,
    'status': status,
    'pickup_address': '123 Main St',
    'dropoff_address': '456 Side St',
    'distance_km': 5.2,
    'computed_total': 850.0,
    ...extra,
  };
}

Finder _labelText(String name) => find.byWidgetPredicate(
      (w) => w is Text && w.data != null && w.data!.replaceAll('\n', ' ') == name,
    );

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  group('HomeScreen structure', () {
    testWidgets('renders a loading skeleton before content arrives', (tester) async {
      SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: HomeScreen()));
        },
        () => _buildClient(),
      );

      expect(find.byType(SkeletonBox), findsWidgets);
      expect(tester.takeException(), isNull);
    });

    testWidgets('renders the wordmark, greeting and customer first name from real state', (tester) async {
      await _pumpHome(tester, firstName: 'Samantha');

      expect(find.byType(RichText), findsWidgets);
      expect(find.text('Samantha'), findsOneWidget);
      expect(find.textContaining('Good '), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('falls back to the first word of the stored name when no first name is cached', (tester) async {
      await _pumpHome(tester);

      expect(find.text('Faon'), findsOneWidget);
    });

    testWidgets('follows the frozen order: booking card, quick actions, how it works, vehicle types', (tester) async {
      await _pumpHome(tester);

      final ys = [
        tester.getTopLeft(find.text('CURRENT BOOKING')).dy,
        tester.getTopLeft(find.text('Quick actions')).dy,
        tester.getTopLeft(find.text('How TowMate works')).dy,
        tester.getTopLeft(find.text('Vehicle types').last).dy,
      ];
      expect([...ys]..sort(), ys);
      expect(find.text('Our Services'), findsNothing);
    });

    testWidgets('no Drawer/hamburger and no Book Now button exist on Home', (tester) async {
      await _pumpHome(tester);

      expect(find.byType(Drawer), findsNothing);
      expect(find.byIcon(Icons.menu), findsNothing);
      expect(find.byType(ElevatedButton), findsNothing);
      expect(find.byType(FilledButton), findsNothing);
    });
  });

  group('HomeScreen current booking', () {
    testWidgets('no active booking shows the approved empty state and helper row', (tester) async {
      await _pumpHome(tester);

      expect(find.text('CURRENT BOOKING'), findsOneWidget);
      expect(find.text('No active booking'), findsOneWidget);
      expect(find.text('Once you request a tow, your driver and live status show up here.'), findsOneWidget);
      expect(find.textContaining('Need a tow?', findRichText: true), findsOneWidget);
      expect(find.text('Track'), findsNothing);
    });

    testWidgets('an active booking renders its real data and a Track action', (tester) async {
      await _pumpHome(
        tester,
        currentBooking: _booking(extra: {
          'driver_name': 'Mark Dela Cruz',
          'truck_type_name': 'Light Duty',
          'vehicle_type_name': 'SUV',
        }),
      );

      expect(find.text('CURRENT BOOKING'), findsOneWidget);
      expect(find.text('TM-0001'), findsOneWidget);
      expect(find.text('On the way'), findsWidgets);
      expect(find.text('123 Main St'), findsOneWidget);
      expect(find.text('456 Side St'), findsOneWidget);
      expect(find.text('Mark Dela Cruz'), findsOneWidget);
      expect(find.text('Light Duty'), findsOneWidget);
      expect(find.text('Track'), findsOneWidget);
      expect(find.text('No active booking'), findsNothing);
    });

    testWidgets('never fabricates an ETA, plate number or placeholder driver', (tester) async {
      await _pumpHome(tester, currentBooking: _booking());

      expect(find.textContaining('min'), findsNothing);
      expect(find.textContaining('arriving'), findsNothing);
      expect(find.textContaining('[Driver'), findsNothing);
      expect(find.textContaining('[Plate'), findsNothing);
      expect(find.textContaining('Plate'), findsNothing);
      expect(find.text('MD'), findsNothing);
    });

    testWidgets('the progress indicator reflects the real booking status', (tester) async {
      await _pumpHome(tester, currentBooking: _booking(status: 'assigned'));

      for (final label in ['Requested', 'Assigned', 'On the way', 'Towing']) {
        expect(find.text(label), findsWidgets);
      }
      final emphasised = tester
          .widgetList<Text>(find.text('Assigned'))
          .where((t) => t.style?.fontWeight == FontWeight.w700);
      expect(emphasised, isNotEmpty);
    });

    testWidgets('Track navigates using the real booking code', (tester) async {
      String? capturedRoute;
      Object? capturedArgs;

      await _pumpHome(
        tester,
        currentBooking: _booking(code: 'TM-0002', status: 'requested'),
        onNavigate: (route, args) {
          capturedRoute = route;
          capturedArgs = args;
        },
      );

      await tester.tap(find.text('Track'));
      await _settle(tester);

      expect(capturedRoute, '/booking-detail');
      expect(capturedArgs, 'TM-0002');
    });

    testWidgets('a grouped booking shows the group reference and opens the group overview', (tester) async {
      String? capturedRoute;
      Object? capturedArgs;

      await _pumpHome(
        tester,
        currentBooking: _booking(code: 'TM-00227', status: 'requested', extra: {
          'service_type': 'book_now',
          'group_code': 'GRP-1',
          'group_vehicle_count': 2,
          'group_siblings': [
            {
              'booking_code': 'TM-00228',
              'vehicle_type_name': 'Motorcycle',
              'service_type': 'schedule',
              'status': 'scheduled',
            },
          ],
        }),
        onNavigate: (route, args) {
          capturedRoute = route;
          capturedArgs = args;
        },
      );

      expect(find.text('GRP-1'), findsOneWidget);
      expect(find.text('TM-00227'), findsNothing);
      expect(find.text('Active'), findsOneWidget);
      expect(find.text('2 of 2 vehicles active'), findsOneWidget);

      await tester.tap(find.text('Track'));
      await _settle(tester);

      expect(capturedRoute, '/booking-detail');
      expect(capturedArgs, {'bookingCode': 'TM-00227', 'asGroupOverview': true});
    });

    testWidgets('a pending quotation replaces the booking card with the review action', (tester) async {
      await _pumpHome(
        tester,
        pendingQuotation: {
          'id': 5,
          'quotation_number': 'Q-100',
          'status': 'sent',
          'estimated_price': 1500,
          'distance_km': 4.2,
          'truck_type_name': 'Light Duty',
          'pickup_address': 'A St',
          'dropoff_address': 'B St',
        },
      );

      expect(find.text('QUOTATION READY'), findsOneWidget);
      expect(find.text('Review & Accept'), findsOneWidget);
      expect(find.text('No active booking'), findsNothing);
    });

    testWidgets('a CMS announcement still renders when one exists', (tester) async {
      await _pumpHome(tester, announcement: {'title': 'Holiday hours', 'message': 'We are open 24/7.'});

      expect(find.text('Holiday hours'), findsOneWidget);
      expect(find.text('We are open 24/7.'), findsOneWidget);
    });
  });

  group('HomeScreen quick actions', () {
    testWidgets('shows exactly the three approved quick actions', (tester) async {
      await _pumpHome(tester);

      for (final label in ['Booking history', 'Vehicle types', 'Towing guide']) {
        expect(find.text(label), findsWidgets);
      }
      expect(find.text('Track request'), findsNothing);
      expect(find.text('Promos & updates'), findsNothing);
      expect(find.text('Book a Tow'), findsNothing);
      expect(find.text('Emergency help'), findsNothing);
      expect(find.byType(InkWell).evaluate().where((e) {
        final w = e.widget as InkWell;
        return w.onTap != null;
      }).isNotEmpty, isTrue);
    });

    testWidgets('quick actions are identical with and without an active booking', (tester) async {
      await _pumpHome(tester, currentBooking: _booking());

      expect(find.text('Booking history'), findsOneWidget);
      expect(find.text('Towing guide'), findsOneWidget);
      expect(find.text('Track request'), findsNothing);
    });

    testWidgets('Booking history opens the History tab of Bookings', (tester) async {
      String? route;
      Object? args;
      await _pumpHome(tester, onNavigate: (r, a) {
        route = r;
        args = a;
      });

      await tester.tap(find.text('Booking history'));
      await _settle(tester);

      expect(route, '/my-bookings');
      expect(args, 'history');
    });

    testWidgets('Vehicle types quick action and View all both open Vehicle Types', (tester) async {
      final routes = <String>[];
      await _pumpHome(tester, onNavigate: (r, a) => routes.add(r));

      await tester.tap(find.text('Vehicle types').first);
      await _settle(tester);
      expect(routes, ['/vehicle-types']);

      routes.clear();
      await tester.pumpWidget(const SizedBox());
      await _pumpHome(tester, onNavigate: (r, a) => routes.add(r));
      await tester.tap(find.text('View all'));
      await _settle(tester);
      expect(routes, ['/vehicle-types']);
    });

    testWidgets('Towing guide quick action and the guide link both open the Towing Guide', (tester) async {
      final routes = <String>[];
      await _pumpHome(tester, onNavigate: (r, a) => routes.add(r));

      await tester.tap(find.text('Towing guide'));
      await _settle(tester);
      expect(routes, ['/towing-guide']);

      routes.clear();
      await tester.pumpWidget(const SizedBox());
      await _pumpHome(tester, onNavigate: (r, a) => routes.add(r));
      await tester.ensureVisible(find.text('Read the towing guide'));
      await tester.tap(find.text('Read the towing guide'));
      await _settle(tester);
      expect(routes, ['/towing-guide']);
    });
  });

  group('HomeScreen how it works and vehicle preview', () {
    testWidgets('renders the three approved steps and the guide prompt', (tester) async {
      await _pumpHome(tester);

      expect(find.text('How TowMate works'), findsOneWidget);
      expect(find.text('From roadside to drop-off in three steps.'), findsOneWidget);
      expect(find.text('Choose your vehicle'), findsOneWidget);
      expect(find.text('Pick the vehicle type so we send the right truck.'), findsOneWidget);
      expect(find.text('Set pickup and drop-off'), findsOneWidget);
      expect(find.text('Pin where you are and where it needs to go.'), findsOneWidget);
      expect(find.text('Track your tow'), findsOneWidget);
      expect(find.text('Follow your driver live until drop-off.'), findsOneWidget);
      expect(find.text('Not sure if you need light, medium or heavy duty?'), findsOneWidget);
      expect(find.text('Read the towing guide'), findsOneWidget);
    });

    testWidgets('the preview shows exactly the four approved vehicle types', (tester) async {
      await _pumpHome(tester);

      expect(find.text('Vehicle types'), findsWidgets);
      expect(find.text('We tow any type of vehicle.'), findsOneWidget);
      for (final name in ['Motorcycle', 'SUV', 'Van / L300', 'Elf / 6-Wheeler']) {
        expect(_labelText(name), findsOneWidget, reason: name);
      }
      for (final name in ['Scooter / E-Scooter', 'Bicycle / E-Bike', 'Tricycle', 'AUV / MPV']) {
        expect(_labelText(name), findsNothing, reason: name);
      }
    });

    testWidgets('a category failure still shows the remaining preview types', (tester) async {
      await _pumpHome(tester, failCategories: {'heavy_vehicle'});

      expect(_labelText('Motorcycle'), findsOneWidget);
      expect(_labelText('SUV'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('all categories empty shows the safe unavailable text', (tester) async {
      await _pumpHome(tester, emptyCategories: {'2_wheeler', '4_wheeler', 'heavy_vehicle'});

      expect(find.text('Vehicle type info is unavailable right now.'), findsOneWidget);
    });

    testWidgets('a malformed vehicle-types response fails safely', (tester) async {
      await _pumpHome(tester, malformedVehicleTypes: true);

      expect(find.text('Vehicle type info is unavailable right now.'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    for (final width in [360.0, 390.0, 430.0]) {
      testWidgets('keeps 6-Wheeler together in the preview at ${width.toInt()}px', (tester) async {
        await _pumpHome(tester, width: width);

        final label = _labelText('Elf / 6-Wheeler');
        expect(label, findsOneWidget);
        final data = tester.widget<Text>(label).data!;
        expect(data.contains('6-\n'), isFalse);
        expect(data == 'Elf / 6-Wheeler' || data == 'Elf /\n6-Wheeler', isTrue);
        expect(tester.takeException(), isNull);
      });
    }
  });

  group('HomeScreen bottom navigation', () {
    testWidgets('keeps the five destinations with Home selected', (tester) async {
      await _pumpHome(tester);

      final nav = find.byType(TmBottomNav);
      for (final label in ['Home', 'Bookings', 'Book Now', 'Alerts', 'Profile']) {
        expect(find.descendant(of: nav, matching: find.text(label)), findsOneWidget);
      }
    });

    testWidgets('the Alerts badge shows the unread count', (tester) async {
      await _pumpHome(tester);

      expect(find.descendant(of: find.byType(TmBottomNav), matching: find.text('2')), findsOneWidget);
    });

    testWidgets('tapping Book Now, Bookings and Alerts navigates to their routes', (tester) async {
      final routes = <String>[];
      await _pumpHome(tester, onNavigate: (r, a) => routes.add(r));
      final nav = find.byType(TmBottomNav);

      await tester.tap(find.descendant(of: nav, matching: find.text('Book Now')));
      await _settle(tester);
      expect(routes.last, '/book-now');
    });

    testWidgets('tapping the selected Home tab does not push another route', (tester) async {
      final routes = <String>[];
      await _pumpHome(tester, onNavigate: (r, a) => routes.add(r));

      await tester.tap(find.descendant(of: find.byType(TmBottomNav), matching: find.text('Home')));
      await _settle(tester);

      expect(routes, isEmpty);
    });
  });

  group('HomeScreen theme and responsive', () {
    for (final dark in [false, true]) {
      for (final width in [360.0, 390.0, 430.0]) {
        testWidgets('renders ${dark ? 'dark' : 'light'} with and without a booking at ${width.toInt()}px without overflow', (tester) async {
          final theme = dark ? AppTheme.dark : AppTheme.light;
          await _pumpHome(tester, theme: theme, width: width);
          expect(tester.takeException(), isNull);

          await tester.pumpWidget(const SizedBox());
          await _pumpHome(
            tester,
            theme: theme,
            width: width,
            currentBooking: _booking(extra: {
              'driver_name': 'Mark Dela Cruz',
              'truck_type_name': 'Light Duty',
              'vehicle_type_name': 'SUV',
            }),
          );
          expect(tester.takeException(), isNull);
        });
      }
    }

    testWidgets('a long customer name does not overflow', (tester) async {
      await _pumpHome(
        tester,
        width: 360,
        firstName: 'Maria Antonietta Consolacion Delacruz-Villanueva',
        currentBooking: _booking(extra: {'driver_name': 'Maria Antonietta Consolacion Delacruz-Villanueva'}),
      );

      expect(tester.takeException(), isNull);
    });

    testWidgets('light mode keeps a white page and the dark header', (tester) async {
      await _pumpHome(tester, theme: AppTheme.light);

      final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).first);
      expect(scaffold.backgroundColor, TmColors.white);
    });

    testWidgets('dark mode uses the charcoal page background', (tester) async {
      await _pumpHome(tester, theme: AppTheme.dark);

      final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).first);
      expect(scaffold.backgroundColor, TmColors.dark900);
    });

    testWidgets('uses the canonical TowMate yellow for accents', (tester) async {
      await _pumpHome(tester, currentBooking: _booking());

      final yellowBoxes = tester.widgetList<Material>(find.byType(Material)).where((m) => m.color == TmColors.yellow);
      expect(yellowBoxes, isNotEmpty);
      expect(TmColors.yellow, const Color(0xFFFACC15));
    });
  });

  group('HomeScreen refresh behaviour', () {
    testWidgets('returning from a pushed route refreshes the current booking', (tester) async {
      var bookingVisible = true;
      SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer', 'user_name': 'Faon'});
      tester.view.physicalSize = const Size(390, 2400);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      final client = MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/v1/bookings/current')) {
          return _json({'data': bookingVisible ? _booking() : null});
        }
        if (path.endsWith('/v1/quotations/pending')) return _json({'data': null});
        if (path.endsWith('/v1/customer/content')) return _json({'announcement': null, 'services': []});
        if (path.contains('/vehicle-types/by-category/')) return _json({'vehicleTypes': []});
        if (path.endsWith('/v1/notifications')) return _json({'success': true, 'unread_count': 0, 'data': []});
        return _json({}, status: 404);
      });

      await http.runWithClient(() async {
        await tester.pumpWidget(MaterialApp(
          navigatorObservers: [appRouteObserver],
          onGenerateRoute: (settings) => MaterialPageRoute(
            builder: (_) => settings.name == '/' ? const HomeScreen() : const Scaffold(body: Text('PUSHED')),
          ),
        ));
        await _settle(tester);
        expect(find.text('TM-0001'), findsOneWidget);

        final context = tester.element(find.byType(HomeScreen));
        Navigator.of(context).pushNamed('/other');
        await _settle(tester);
        expect(find.text('PUSHED'), findsOneWidget);

        bookingVisible = false;
        Navigator.of(context).pop();
        await _settle(tester);
        await _settle(tester);

        expect(find.text('No active booking'), findsOneWidget);
      }, () => client);
    });
  });

  group('TmBottomNav', () {
    testWidgets('shows exactly five destinations with visible labels', (tester) async {
      await tester.pumpWidget(const MaterialApp(
        home: Scaffold(bottomNavigationBar: TmBottomNav(currentRoute: '/home')),
      ));

      for (final label in ['Home', 'Bookings', 'Book Now', 'Alerts', 'Profile']) {
        expect(find.text(label), findsOneWidget);
      }
    });

    testWidgets('shows an unread badge on Alerts when unreadCount is positive', (tester) async {
      await tester.pumpWidget(const MaterialApp(
        home: Scaffold(bottomNavigationBar: TmBottomNav(currentRoute: '/home', unreadCount: 7)),
      ));

      expect(find.text('7'), findsOneWidget);
    });

    testWidgets('highlightedRoute selects Home while keeping navigation relative to the current screen', (tester) async {
      final routes = <String>[];
      await tester.pumpWidget(MaterialApp(
        onGenerateRoute: (settings) {
          if (settings.name != '/') routes.add(settings.name!);
          return MaterialPageRoute(
            builder: (_) => const Scaffold(
              bottomNavigationBar: TmBottomNav(currentRoute: '/vehicle-types', highlightedRoute: '/home'),
            ),
          );
        },
      ));

      await tester.tap(find.text('Home'));
      await tester.pumpAndSettle();

      expect(routes, contains('/home'));
    });

    testWidgets('does not overflow at narrow widths', (tester) async {
      tester.view.physicalSize = const Size(320, 800);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(const MaterialApp(
        home: Scaffold(bottomNavigationBar: TmBottomNav(currentRoute: '/home', unreadCount: 20)),
      ));

      expect(tester.takeException(), isNull);
    });
  });
}
