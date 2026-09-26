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
  '2_wheeler': {
    'vehicleTypes': [
      {'id': 1, 'name': 'Motorcycle', 'description': null},
    ],
  },
  '4_wheeler': {
    'vehicleTypes': [
      {'id': 2, 'name': 'Sedan', 'description': null},
    ],
  },
  'heavy_vehicle': {
    'vehicleTypes': [
      {'id': 3, 'name': 'Cargo Truck', 'description': null},
    ],
  },
};

final _servicesFixture = {
  'announcement': null,
  'services': [
    {
      'title': 'Towing',
      'description': 'Vehicle towing',
      'image_url': null,
      'category': 'towing',
      'availability_note': null,
    },
    {
      'title': 'Roadside Help',
      'description': 'Roadside assistance',
      'image_url': null,
      'category': 'roadside',
      'availability_note': null,
    },
    {
      'title': 'Recovery',
      'description': 'Vehicle recovery',
      'image_url': null,
      'category': 'recovery',
      'availability_note': null,
    },
  ],
};

http.Client _buildClient({
  Object? currentBooking,
  Object? pendingQuotation,
  bool failSecondary = false,
  bool failServicesOnly = false,
  bool failVehicleTypesOnly = false,
  Set<String> failCategories = const {},
  Set<String> emptyCategories = const {},
  List<Map<String, dynamic>>? servicesOverride,
  Map<String, List<Map<String, dynamic>>>? vehicleTypesOverride,
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
      if (failSecondary || failServicesOnly) return _json({}, status: 500);
      if (servicesOverride != null) {
        return _json({'announcement': null, 'services': servicesOverride});
      }
      return _json(_servicesFixture);
    }
    if (path.contains('/vehicle-types/by-category/')) {
      final category = path.split('/').last;
      if (failSecondary || failVehicleTypesOnly || failCategories.contains(category)) {
        return _json({}, status: 500);
      }
      if (emptyCategories.contains(category)) {
        return _json({'vehicleTypes': []});
      }
      if (vehicleTypesOverride != null) {
        return _json({'vehicleTypes': vehicleTypesOverride[category] ?? []});
      }
      return _json(_vehicleTypesByCategory[category] ?? {'vehicleTypes': []});
    }
    if (path.endsWith('/v1/notifications')) {
      return _json({'success': true, 'unread_count': 2, 'data': []});
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
  bool failSecondary = false,
  bool failServicesOnly = false,
  bool failVehicleTypesOnly = false,
  Set<String> failCategories = const {},
  Set<String> emptyCategories = const {},
  List<Map<String, dynamic>>? servicesOverride,
  Map<String, List<Map<String, dynamic>>>? vehicleTypesOverride,
  void Function(String route, Object? args)? onNavigate,
  ThemeData? theme,
}) async {
  SharedPreferences.setMockInitialValues({
    'auth_token': 'test-token',
    'user_role': 'Customer',
    'user_name': 'Faon Delacruz',
  });
  await http.runWithClient(
    () async {
      await tester.pumpWidget(
        MaterialApp(
          theme: theme,
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
      failSecondary: failSecondary,
      failServicesOnly: failServicesOnly,
      failVehicleTypesOnly: failVehicleTypesOnly,
      failCategories: failCategories,
      emptyCategories: emptyCategories,
      servicesOverride: servicesOverride,
      vehicleTypesOverride: vehicleTypesOverride,
    ),
  );
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  group('HomeScreen', () {
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

    testWidgets('renders the greeting and bottom nav once loaded, without a Book Now CTA button', (tester) async {
      await _pumpHome(tester);

      expect(find.textContaining('Faon'), findsOneWidget);
      expect(find.text('Book Now'), findsOneWidget);
      expect(find.byType(ElevatedButton), findsNothing);
      expect(find.byType(TmBottomNav), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('no Drawer/hamburger exists on Home', (tester) async {
      await _pumpHome(tester);

      expect(find.byType(Drawer), findsNothing);
      expect(find.byIcon(Icons.menu), findsNothing);
      expect(find.byIcon(Icons.menu_rounded), findsNothing);
    });

    testWidgets('bottom navigation contains exactly the five primary destinations', (tester) async {
      await _pumpHome(tester);

      final nav = find.byType(TmBottomNav);
      for (final label in ['Home', 'Bookings', 'Book Now', 'Alerts', 'Profile']) {
        expect(find.descendant(of: nav, matching: find.text(label)), findsOneWidget);
      }
    });

    testWidgets('no active booking shows the compact empty state', (tester) async {
      await _pumpHome(tester);

      expect(find.text('Current Booking'), findsOneWidget);
      expect(find.text('No active booking'), findsOneWidget);
      expect(find.text('Your active towing request will appear here.'), findsOneWidget);
    });

    testWidgets('an active booking renders its real summary fields', (tester) async {
      await _pumpHome(
        tester,
        currentBooking: {
          'id': 1,
          'booking_code': 'TM-0001',
          'status': 'on_the_way',
          'pickup_address': '123 Main St',
          'dropoff_address': '456 Side St',
          'distance_km': 5.2,
          'computed_total': 850.0,
        },
      );

      expect(find.text('TM-0001'), findsOneWidget);
      expect(find.text('On the way'), findsOneWidget);
      expect(find.text('123 Main St'), findsOneWidget);
      expect(find.text('456 Side St'), findsOneWidget);
      expect(find.text('View details'), findsNothing);
      expect(find.text('No active booking'), findsNothing);
    });

    testWidgets('the Current Booking card shows a clear View Booking Details action', (tester) async {
      await _pumpHome(
        tester,
        currentBooking: {
          'id': 1,
          'booking_code': 'TM-0001',
          'status': 'on_the_way',
          'pickup_address': '123 Main St',
          'dropoff_address': '456 Side St',
        },
      );

      expect(find.text('View Booking Details'), findsOneWidget);
    });

    testWidgets('tapping View Booking Details navigates using the real booking code', (tester) async {
      String? capturedRoute;
      Object? capturedArgs;

      await _pumpHome(
        tester,
        currentBooking: {
          'id': 1,
          'booking_code': 'TM-0002',
          'status': 'requested',
          'pickup_address': 'A',
          'dropoff_address': 'B',
        },
        onNavigate: (route, args) {
          capturedRoute = route;
          capturedArgs = args;
        },
      );

      await tester.tap(find.text('View Booking Details'));
      await _settle(tester);

      expect(capturedRoute, '/booking-detail');
      expect(capturedArgs, 'TM-0002');
    });

    testWidgets('shows the customer-facing vehicle type on the current booking card', (tester) async {
      await _pumpHome(
        tester,
        currentBooking: {
          'id': 1,
          'booking_code': 'TM-0001',
          'status': 'requested',
          'pickup_address': 'A',
          'dropoff_address': 'B',
          'vehicle_type_name': 'Pickup Truck',
          'truck_type_name': 'Light Duty',
        },
      );

      expect(find.textContaining('Pickup Truck'), findsOneWidget);
      expect(find.textContaining('Light Duty'), findsNothing);
    });

    testWidgets('a mixed Book Now + Scheduled group shows the group reference and an accurate active-vehicle count', (tester) async {
      await _pumpHome(
        tester,
        currentBooking: {
          'id': 1,
          'booking_code': 'TM-00227',
          'status': 'requested',
          'service_type': 'book_now',
          'pickup_address': 'A',
          'dropoff_address': 'B',
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
        },
      );

      expect(find.text('GRP-1'), findsOneWidget);
      expect(find.text('TM-00227'), findsNothing);
      expect(find.text('Active'), findsOneWidget);
      expect(find.text('2 of 2 vehicles active'), findsOneWidget);

      final activeContainer = tester.widget<Container>(
        find.ancestor(of: find.text('Active'), matching: find.byType(Container)).first,
      );
      expect((activeContainer.decoration as BoxDecoration?)?.color, TmColors.success);
      expect(tester.widget<Text>(find.text('Active')).style?.color, TmColors.black);
    });

    for (final width in [320.0, 360.0, 412.0]) {
      testWidgets('the Active status stays green in dark mode without overflow at ${width.toInt()}px', (tester) async {
        tester.view.physicalSize = Size(width, 900);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        await _pumpHome(
          tester,
          theme: AppTheme.dark,
          currentBooking: {
            'id': 1,
            'booking_code': 'TM-00227',
            'status': 'requested',
            'service_type': 'book_now',
            'pickup_address': 'A',
            'dropoff_address': 'B',
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
          },
        );

        expect(find.text('Active'), findsOneWidget);
        expect(tester.takeException(), isNull);

        final activeContainer = tester.widget<Container>(
          find.ancestor(of: find.text('Active'), matching: find.byType(Container)).first,
        );
        expect((activeContainer.decoration as BoxDecoration?)?.color, TmColors.success);
        expect(tester.widget<Text>(find.text('Active')).style?.color, TmColors.black);
      });
    }

    testWidgets('a grouped current booking shows the combined group total, not the first vehicle\'s own price', (tester) async {
      await _pumpHome(
        tester,
        currentBooking: {
          'id': 1,
          'booking_code': 'TM-00227',
          'status': 'requested',
          'service_type': 'book_now',
          'pickup_address': 'A',
          'dropoff_address': 'B',
          'final_total': 1680.0,
          'computed_total': 1500.0,
          'group_code': 'GRP-1',
          'group_vehicle_count': 2,
          'group_siblings': [
            {
              'booking_code': 'TM-00228',
              'vehicle_type_name': 'Motorcycle',
              'service_type': 'book_now',
              'status': 'requested',
            },
          ],
          'group_totals': {
            'vehicle_count': 2,
            'base_rate': 2500.0,
            'computed_total': 2500.0,
            'vat_amount': 300.0,
            'additional_fee': 0.0,
            'final_total': 2800.0,
          },
        },
      );

      expect(find.text('Group Total'), findsOneWidget);
      expect(find.text('₱2,800.00'), findsOneWidget);
      expect(find.text('₱1,680.00'), findsNothing);
    });

    testWidgets('a grouped current booking shows the group reference, not the selected vehicle\'s TM code', (tester) async {
      await _pumpHome(
        tester,
        currentBooking: {
          'id': 1,
          'booking_code': 'TM-00227',
          'status': 'requested',
          'service_type': 'book_now',
          'pickup_address': 'A',
          'dropoff_address': 'B',
          'vehicle_type_name': 'Sedan',
          'group_code': 'GRP-1',
          'group_vehicle_count': 2,
          'group_siblings': [
            {
              'booking_code': 'TM-00228',
              'vehicle_type_name': 'Motorcycle',
              'service_type': 'book_now',
              'status': 'requested',
            },
          ],
        },
      );

      expect(find.text('GRP-1'), findsOneWidget);
      expect(find.text('TM-00227'), findsNothing);
      expect(find.text('2 of 2 vehicles active'), findsOneWidget);
      expect(find.textContaining('1 Vehicle  ·  Sedan'), findsNothing);
    });

    testWidgets('after a sibling is cancelled, the current booking card shows an accurate active-vehicle count and Remaining Total', (tester) async {
      await _pumpHome(
        tester,
        currentBooking: {
          'id': 1,
          'booking_code': 'TM-00227',
          'status': 'requested',
          'service_type': 'book_now',
          'pickup_address': 'A',
          'dropoff_address': 'B',
          'group_code': 'GRP-1',
          'group_vehicle_count': 2,
          'group_siblings': [
            {
              'booking_code': 'TM-00228',
              'vehicle_type_name': 'Motorcycle',
              'service_type': 'book_now',
              'status': 'cancelled',
            },
          ],
          'group_totals': {
            'vehicle_count': 2,
            'base_rate': 1500.0,
            'computed_total': 1500.0,
            'vat_amount': 180.0,
            'additional_fee': 0.0,
            'final_total': 1680.0,
          },
        },
      );

      expect(find.text('1 of 2 vehicles active'), findsOneWidget);
      expect(find.text('Remaining Total'), findsOneWidget);
      expect(find.text('₱1,680.00'), findsOneWidget);
      expect(find.text('Group Total'), findsNothing);
    });

    testWidgets('when every vehicle in the group is cancelled, the current booking card shows Cancelled', (tester) async {
      await _pumpHome(
        tester,
        currentBooking: {
          'id': 1,
          'booking_code': 'TM-00227',
          'status': 'cancelled',
          'service_type': 'book_now',
          'pickup_address': 'A',
          'dropoff_address': 'B',
          'group_code': 'GRP-1',
          'group_vehicle_count': 2,
          'group_siblings': [
            {
              'booking_code': 'TM-00228',
              'vehicle_type_name': 'Motorcycle',
              'service_type': 'book_now',
              'status': 'cancelled',
            },
          ],
        },
      );

      expect(find.text('Cancelled'), findsOneWidget);
      expect(find.text('GRP-1'), findsOneWidget);
    });

    testWidgets('a standalone current booking still shows its own TM code and vehicle type', (tester) async {
      await _pumpHome(
        tester,
        currentBooking: {
          'id': 1,
          'booking_code': 'TM-00227',
          'status': 'requested',
          'service_type': 'book_now',
          'pickup_address': 'A',
          'dropoff_address': 'B',
          'vehicle_type_name': 'Sedan',
        },
      );

      expect(find.text('TM-00227'), findsOneWidget);
      expect(find.text('1 Vehicle  ·  Sedan'), findsOneWidget);
    });

    testWidgets('tapping View Booking Details on a grouped current booking opens the group overview', (tester) async {
      String? capturedRoute;
      Object? capturedArgs;

      await _pumpHome(
        tester,
        currentBooking: {
          'id': 1,
          'booking_code': 'TM-00227',
          'status': 'requested',
          'service_type': 'book_now',
          'pickup_address': 'A',
          'dropoff_address': 'B',
          'group_code': 'GRP-1',
          'group_vehicle_count': 2,
          'group_siblings': [
            {
              'booking_code': 'TM-00228',
              'vehicle_type_name': 'Motorcycle',
              'service_type': 'book_now',
              'status': 'requested',
            },
          ],
        },
        onNavigate: (route, args) {
          capturedRoute = route;
          capturedArgs = args;
        },
      );

      await tester.tap(find.text('View Booking Details'));
      await _settle(tester);

      expect(capturedRoute, '/booking-detail');
      expect(capturedArgs, {'bookingCode': 'TM-00227', 'asGroupOverview': true});
    });

    testWidgets('real service and vehicle-type data renders from the API response', (tester) async {
      await _pumpHome(tester);

      expect(find.text('Towing'), findsOneWidget);
      expect(find.text('Roadside Help'), findsOneWidget);
      expect(find.text('Recovery'), findsOneWidget);
      expect(find.text('Sedan'), findsOneWidget);
    });

    testWidgets('Book Now is reachable only through the bottom navigation', (tester) async {
      await _pumpHome(tester);

      expect(
        find.descendant(of: find.byType(TmBottomNav), matching: find.text('Book Now')),
        findsOneWidget,
      );
      expect(find.byType(ElevatedButton), findsNothing);
      expect(find.text('Book a Tow'), findsNothing);
    });

    testWidgets('customer-facing service names render without hyphens', (tester) async {
      await _pumpHome(
        tester,
        servicesOverride: [
          {'title': 'Light Duty Towing', 'description': '', 'image_url': null, 'category': 'towing', 'availability_note': null},
          {'title': 'Medium Duty Towing', 'description': '', 'image_url': null, 'category': 'towing', 'availability_note': null},
          {'title': 'Heavy Duty Towing', 'description': '', 'image_url': null, 'category': 'towing', 'availability_note': null},
        ],
      );

      expect(find.text('Light Duty Towing'), findsOneWidget);
      expect(find.text('Medium Duty Towing'), findsOneWidget);
      expect(find.text('Heavy Duty Towing'), findsOneWidget);
      expect(find.text('Light-Duty Towing'), findsNothing);
      expect(find.text('Medium-Duty Towing'), findsNothing);
      expect(find.text('Heavy-Duty Towing'), findsNothing);
    });

    testWidgets('Our Services preview on Home shows at most 3 services', (tester) async {
      await _pumpHome(
        tester,
        servicesOverride: [
          {'title': 'Towing', 'description': '', 'image_url': null, 'category': 'a', 'availability_note': null},
          {'title': 'Roadside Help', 'description': '', 'image_url': null, 'category': 'b', 'availability_note': null},
          {'title': 'Recovery', 'description': '', 'image_url': null, 'category': 'c', 'availability_note': null},
          {'title': 'Impound Release', 'description': '', 'image_url': null, 'category': 'd', 'availability_note': null},
          {'title': 'Fuel Delivery', 'description': '', 'image_url': null, 'category': 'e', 'availability_note': null},
        ],
      );

      expect(find.text('Towing'), findsOneWidget);
      expect(find.text('Roadside Help'), findsOneWidget);
      expect(find.text('Recovery'), findsOneWidget);
      expect(find.text('Impound Release'), findsNothing);
      expect(find.text('Fuel Delivery'), findsNothing);
    });

    testWidgets('Our Services has a View all action that routes to the authenticated Services screen', (tester) async {
      final routes = <String>[];
      await _pumpHome(tester, onNavigate: (route, args) => routes.add(route));

      expect(find.text('View all'), findsWidgets);
      await tester.tap(find.text('View all').first);
      await _settle(tester);

      expect(routes, contains('/customer-services'));
      expect(routes, isNot(contains('/services')));
    });

    testWidgets('Vehicle Types preview on Home is capped at 8 entries', (tester) async {
      await _pumpHome(
        tester,
        vehicleTypesOverride: {
          '2_wheeler': [
            {'id': 1, 'name': 'Scooter'},
            {'id': 2, 'name': 'Motorcycle'},
            {'id': 3, 'name': 'Moped'},
          ],
          '4_wheeler': [
            {'id': 4, 'name': 'Sedan'},
            {'id': 5, 'name': 'SUV'},
            {'id': 6, 'name': 'Pickup'},
            {'id': 7, 'name': 'Van'},
          ],
          'heavy_vehicle': [
            {'id': 8, 'name': 'Cargo Truck'},
            {'id': 9, 'name': 'Dump Truck'},
            {'id': 10, 'name': 'Bus'},
            {'id': 11, 'name': 'Container Truck'},
          ],
        },
      );

      expect(find.text('Scooter'), findsOneWidget);
      expect(find.text('Motorcycle'), findsOneWidget);
      expect(find.text('Moped'), findsOneWidget);
      expect(find.text('Sedan'), findsOneWidget);
      expect(find.text('SUV'), findsOneWidget);
      expect(find.text('Pickup'), findsOneWidget);
      expect(find.text('Van'), findsOneWidget);
      expect(find.text('Cargo Truck'), findsOneWidget);
      expect(find.text('Dump Truck'), findsNothing);
      expect(find.text('Bus'), findsNothing);
      expect(find.text('Container Truck'), findsNothing);
    });

    testWidgets('Vehicle Types has a View all action that routes to the authenticated Vehicle Types screen', (tester) async {
      final routes = <String>[];
      await _pumpHome(tester, onNavigate: (route, args) => routes.add(route));

      final viewAllActions = find.text('View all');
      expect(viewAllActions, findsNWidgets(2));
      await tester.ensureVisible(viewAllActions.last);
      await _settle(tester);
      await tester.tap(viewAllActions.last);
      await _settle(tester);

      expect(routes, contains('/vehicle-types'));
      expect(routes, isNot(contains('/services')));
    });


    testWidgets('a secondary-content API failure shows safe fallback text without blocking Home', (tester) async {
      await _pumpHome(tester, failSecondary: true);

      expect(find.text('Services info is unavailable right now.'), findsOneWidget);
      expect(find.text('Vehicle type info is unavailable right now.'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a Services-only failure does not affect Vehicle Types', (tester) async {
      await _pumpHome(tester, failServicesOnly: true);

      expect(find.text('Services info is unavailable right now.'), findsOneWidget);
      expect(find.text('Sedan'), findsOneWidget);
      expect(find.text('Vehicle type info is unavailable right now.'), findsNothing);
    });

    testWidgets('a Vehicle-Types-only failure does not affect Services', (tester) async {
      await _pumpHome(tester, failVehicleTypesOnly: true);

      expect(find.text('Vehicle type info is unavailable right now.'), findsOneWidget);
      expect(find.text('Towing'), findsOneWidget);
      expect(find.text('Services info is unavailable right now.'), findsNothing);
    });

    testWidgets('a 2_wheeler-only failure still shows the other real categories', (tester) async {
      await _pumpHome(tester, failCategories: {'2_wheeler'});

      expect(find.text('Sedan'), findsOneWidget);
      expect(find.text('Cargo Truck'), findsOneWidget);
      expect(find.text('Motorcycle'), findsNothing);
      expect(find.text('Vehicle type info is unavailable right now.'), findsNothing);
    });

    testWidgets('a 4_wheeler-only failure still shows the other real categories', (tester) async {
      await _pumpHome(tester, failCategories: {'4_wheeler'});

      expect(find.text('Motorcycle'), findsOneWidget);
      expect(find.text('Cargo Truck'), findsOneWidget);
      expect(find.text('Sedan'), findsNothing);
      expect(find.text('Vehicle type info is unavailable right now.'), findsNothing);
    });

    testWidgets('a heavy_vehicle-only failure still shows the other real categories', (tester) async {
      await _pumpHome(tester, failCategories: {'heavy_vehicle'});

      expect(find.text('Motorcycle'), findsOneWidget);
      expect(find.text('Sedan'), findsOneWidget);
      expect(find.text('Cargo Truck'), findsNothing);
      expect(find.text('Vehicle type info is unavailable right now.'), findsNothing);
    });

    testWidgets('a category with no active vehicle types renders no chips for it, not an error', (tester) async {
      await _pumpHome(tester, emptyCategories: {'heavy_vehicle'});

      expect(find.text('Motorcycle'), findsOneWidget);
      expect(find.text('Sedan'), findsOneWidget);
      expect(find.text('Cargo Truck'), findsNothing);
      expect(find.text('Vehicle type info is unavailable right now.'), findsNothing);
    });

    testWidgets('all categories legitimately empty shows the safe unavailable text, not a crash', (tester) async {
      await _pumpHome(
        tester,
        emptyCategories: {'2_wheeler', '4_wheeler', 'heavy_vehicle'},
      );

      expect(find.text('Vehicle type info is unavailable right now.'), findsOneWidget);
      expect(find.text('Towing'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a malformed vehicle-types response fails safely without crashing Home', (tester) async {
      SharedPreferences.setMockInitialValues({
        'auth_token': 'test-token',
        'user_role': 'Customer',
        'user_name': 'Faon Delacruz',
      });
      final client = MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/v1/bookings/current')) return _json({'data': null});
        if (path.endsWith('/v1/quotations/pending')) return _json({'data': null});
        if (path.endsWith('/v1/customer/content')) return _json(_servicesFixture);
        if (path.contains('/vehicle-types/by-category/')) {
          return http.Response('not valid json{{{', 200, headers: {'content-type': 'application/json'});
        }
        if (path.endsWith('/v1/notifications')) return _json({'success': true, 'unread_count': 0, 'data': []});
        return _json({'success': false}, status: 404);
      });

      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: HomeScreen()));
          await _settle(tester);
        },
        () => client,
      );

      expect(find.text('Vehicle type info is unavailable right now.'), findsOneWidget);
      expect(find.text('Towing'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('shows a vehicle-type skeleton while secondary content is still loading', (tester) async {
      SharedPreferences.setMockInitialValues({
        'auth_token': 'test-token',
        'user_role': 'Customer',
        'user_name': 'Faon Delacruz',
      });
      final client = MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/v1/bookings/current')) return _json({'data': null});
        if (path.endsWith('/v1/quotations/pending')) return _json({'data': null});
        if (path.endsWith('/v1/customer/content')) return _json(_servicesFixture);
        if (path.contains('/vehicle-types/by-category/')) {
          await Future<void>.delayed(const Duration(seconds: 2));
          final category = path.split('/').last;
          return _json(_vehicleTypesByCategory[category] ?? {'vehicleTypes': []});
        }
        if (path.endsWith('/v1/notifications')) return _json({'success': true, 'unread_count': 0, 'data': []});
        return _json({'success': false}, status: 404);
      });

      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: HomeScreen()));
          for (var i = 0; i < 5; i++) {
            await tester.pump(const Duration(milliseconds: 50));
          }
        },
        () => client,
      );

      expect(find.text('Towing'), findsOneWidget);
      expect(find.text('Vehicle type info is unavailable right now.'), findsNothing);
      expect(find.text('Vehicle Types'), findsNothing);
      expect(find.byType(SkeletonBox), findsWidgets);
      expect(tester.takeException(), isNull);

      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('tapping the bottom-nav Book Now item routes to the booking flow', (tester) async {
      final routes = <String>[];
      await _pumpHome(tester, onNavigate: (route, args) => routes.add(route));

      await tester.tap(find.descendant(of: find.byType(TmBottomNav), matching: find.text('Book Now')));
      await _settle(tester);

      expect(routes, contains('/book-now'));
    });

    testWidgets('tapping My Bookings/Notifications/Profile navigates to their routes', (tester) async {
      final routes = <String>[];
      await _pumpHome(tester, onNavigate: (route, args) => routes.add(route));

      await tester.tap(find.text('Bookings'));
      await _settle(tester);

      expect(routes, contains('/my-bookings'));
    });

    testWidgets('tapping the already-selected Home tab does not push another route', (tester) async {
      final routes = <String>[];
      await _pumpHome(tester, onNavigate: (route, args) => routes.add(route));

      await tester.tap(find.text('Home'));
      await _settle(tester);

      expect(routes, isEmpty);
    });

    testWidgets('bottom nav tap targets remain reachable at each destination', (tester) async {
      await _pumpHome(tester);

      final nav = find.byType(TmBottomNav);
      for (final label in ['Home', 'Bookings', 'Book Now', 'Alerts', 'Profile']) {
        final finder = find.descendant(of: nav, matching: find.text(label));
        expect(finder, findsOneWidget);
        final size = tester.getSize(finder);
        expect(size.height, greaterThan(0));
      }
    });

    for (final width in [320.0, 340.0, 360.0, 375.0, 390.0, 412.0]) {
      testWidgets('renders without horizontal overflow at $width px width', (tester) async {
        final originalSize = tester.view.physicalSize;
        final originalRatio = tester.view.devicePixelRatio;
        tester.view.physicalSize = Size(width, 800);
        tester.view.devicePixelRatio = 1.0;
        addTearDown(() {
          tester.view.physicalSize = originalSize;
          tester.view.devicePixelRatio = originalRatio;
        });

        await _pumpHome(tester);

        expect(tester.takeException(), isNull);
        expect(find.byType(TmBottomNav), findsOneWidget);
      });
    }

    testWidgets('renders correctly in light mode', (tester) async {
      SharedPreferences.setMockInitialValues({
        'auth_token': 'test-token',
        'user_role': 'Customer',
        'user_name': 'Faon Delacruz',
      });
      await http.runWithClient(
        () async {
          await tester.pumpWidget(
            MaterialApp(
              theme: AppTheme.light,
              themeMode: ThemeMode.light,
              home: const HomeScreen(),
            ),
          );
          await _settle(tester);
        },
        () => _buildClient(),
      );

      expect(tester.takeException(), isNull);
      expect(find.text('No active booking'), findsOneWidget);
    });

    testWidgets('renders correctly in dark mode', (tester) async {
      SharedPreferences.setMockInitialValues({
        'auth_token': 'test-token',
        'user_role': 'Customer',
        'user_name': 'Faon Delacruz',
      });
      await http.runWithClient(
        () async {
          await tester.pumpWidget(
            MaterialApp(
              theme: AppTheme.light,
              darkTheme: AppTheme.dark,
              themeMode: ThemeMode.dark,
              home: const HomeScreen(),
            ),
          );
          await _settle(tester);
        },
        () => _buildClient(),
      );

      expect(tester.takeException(), isNull);
      expect(find.text('No active booking'), findsOneWidget);
    });

    testWidgets('returning from a pushed route refreshes the current booking so cancellations elsewhere stay consistent', (tester) async {
      var currentCallCount = 0;
      final client = MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/v1/bookings/current')) {
          currentCallCount++;
          return _json({'data': null});
        }
        if (path.endsWith('/v1/quotations/pending')) return _json({'data': null});
        if (path.endsWith('/v1/customer/content')) return _json(_servicesFixture);
        if (path.contains('/vehicle-types/by-category/')) return _json({'vehicleTypes': []});
        if (path.endsWith('/v1/notifications')) return _json({'success': true, 'unread_count': 0, 'data': []});
        return _json({'success': false}, status: 404);
      });

      SharedPreferences.setMockInitialValues({
        'auth_token': 'test-token',
        'user_role': 'Customer',
        'user_name': 'Faon Delacruz',
      });

      await http.runWithClient(() async {
        await tester.pumpWidget(
          MaterialApp(
            navigatorObservers: [appRouteObserver],
            onGenerateRoute: (settings) {
              if (settings.name == '/' || settings.name == null) {
                return MaterialPageRoute(builder: (_) => const HomeScreen());
              }
              return MaterialPageRoute(
                builder: (context) => Scaffold(
                  body: TextButton(
                    onPressed: () => Navigator.pop(context),
                    child: const Text('pushed-back'),
                  ),
                ),
              );
            },
          ),
        );
        await _settle(tester);

        expect(currentCallCount, 1);

        Navigator.of(tester.element(find.byType(HomeScreen))).pushNamed('/booking-detail');
        await _settle(tester);

        await tester.tap(find.text('pushed-back'));
        await _settle(tester);

        expect(currentCallCount, 2);
      }, () => client);
    });
  });

  group('TmBottomNav', () {
    Widget host(String route, {int unreadCount = 0}) => MaterialApp(
          home: Scaffold(body: TmBottomNav(currentRoute: route, unreadCount: unreadCount)),
        );

    testWidgets('shows exactly five destinations with visible labels', (tester) async {
      await tester.pumpWidget(host('/home'));

      expect(find.text('Home'), findsOneWidget);
      expect(find.text('Bookings'), findsOneWidget);
      expect(find.text('Book Now'), findsOneWidget);
      expect(find.text('Alerts'), findsOneWidget);
      expect(find.text('Profile'), findsOneWidget);
    });

    testWidgets('shows an unread badge on Alerts when unreadCount is positive', (tester) async {
      await tester.pumpWidget(host('/home', unreadCount: 3));
      expect(find.text('3'), findsOneWidget);

      await tester.pumpWidget(host('/home', unreadCount: 0));
      expect(find.text('3'), findsNothing);
    });

    testWidgets('does not overflow at narrow widths', (tester) async {
      final originalSize = tester.view.physicalSize;
      final originalRatio = tester.view.devicePixelRatio;
      tester.view.physicalSize = const Size(320, 800);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(() {
        tester.view.physicalSize = originalSize;
        tester.view.devicePixelRatio = originalRatio;
      });

      await tester.pumpWidget(host('/home'));

      expect(tester.takeException(), isNull);
    });
  });
}
