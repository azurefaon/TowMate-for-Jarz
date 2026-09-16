import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:towmate_app/screens/customer/customer_services_screen.dart';
import 'package:towmate_app/widgets/skeleton_box.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pumpScreen(
  WidgetTester tester,
  http.Client client, {
  bool withBackTarget = false,
  void Function(String route)? onNavigate,
}) async {
  await http.runWithClient(
    () async {
      await tester.pumpWidget(
        MaterialApp(
          onGenerateRoute: (settings) {
            if (settings.name == '/' || settings.name == null) {
              return MaterialPageRoute(
                builder: (context) => withBackTarget
                    ? Scaffold(
                        body: Center(
                          child: ElevatedButton(
                            onPressed: () => Navigator.push(
                              context,
                              MaterialPageRoute(builder: (_) => const CustomerServicesScreen()),
                            ),
                            child: const Text('Open Services'),
                          ),
                        ),
                      )
                    : const CustomerServicesScreen(),
              );
            }
            onNavigate?.call(settings.name!);
            return MaterialPageRoute(builder: (_) => const Scaffold());
          },
        ),
      );
      await _settle(tester);
    },
    () => client,
  );
}

void main() {
  group('CustomerServicesScreen', () {
    testWidgets('renders all real mocked active services, not capped', (tester) async {
      final services = [
        {'title': 'Light Duty Towing', 'description': 'Cars and small vehicles', 'image_url': null, 'category': 'a', 'availability_note': null},
        {'title': 'Medium Duty Towing', 'description': 'Vans and pickups', 'image_url': null, 'category': 'b', 'availability_note': null},
        {'title': 'Heavy Duty Towing', 'description': 'Trucks and buses', 'image_url': null, 'category': 'c', 'availability_note': null},
        {'title': 'Battery Jumpstart', 'description': 'Roadside battery service', 'image_url': null, 'category': 'd', 'availability_note': null},
        {'title': 'Flat Tire Assistance', 'description': 'On-site tire change', 'image_url': null, 'category': 'e', 'availability_note': null},
      ];
      final client = MockClient((request) async {
        if (request.url.path.endsWith('/v1/customer/content')) {
          return _json({'announcement': null, 'services': services});
        }
        return _json({'success': false}, status: 404);
      });

      await _pumpScreen(tester, client);

      expect(find.text('Light Duty Towing'), findsOneWidget);
      expect(find.text('Medium Duty Towing'), findsOneWidget);
      expect(find.text('Heavy Duty Towing'), findsOneWidget);
      expect(find.text('Battery Jumpstart'), findsOneWidget);
      expect(find.text('Flat Tire Assistance'), findsOneWidget);
      expect(find.text('Cars and small vehicles'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('shows a skeleton, not a spinner, while loading', (tester) async {
      final client = MockClient((request) async {
        await Future<void>.delayed(const Duration(seconds: 2));
        return _json({'announcement': null, 'services': []});
      });

      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: CustomerServicesScreen()));
          for (var i = 0; i < 3; i++) {
            await tester.pump(const Duration(milliseconds: 50));
          }
        },
        () => client,
      );

      expect(find.byType(SkeletonBox), findsWidgets);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(tester.takeException(), isNull);

      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('an API failure shows a retry state, not fabricated content', (tester) async {
      final client = MockClient((request) async => _json({}, status: 500));

      await _pumpScreen(tester, client);

      expect(find.text('Services are unavailable right now.'), findsOneWidget);
      expect(find.text('Try again'), findsOneWidget);
      expect(find.text('Light Duty Towing'), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('an empty-but-successful response shows a legitimate empty state, not the error state', (tester) async {
      final client = MockClient((request) async {
        if (request.url.path.endsWith('/v1/customer/content')) {
          return _json({'announcement': null, 'services': []});
        }
        return _json({'success': false}, status: 404);
      });

      await _pumpScreen(tester, client);

      expect(find.text('No services are currently listed.'), findsOneWidget);
      expect(find.text('Services are unavailable right now.'), findsNothing);
    });

    testWidgets('the Book Now CTA routes directly to /book-now, never an auth gate', (tester) async {
      final client = MockClient((request) async {
        if (request.url.path.endsWith('/v1/customer/content')) {
          return _json({'announcement': null, 'services': []});
        }
        return _json({'success': false}, status: 404);
      });

      String? capturedRoute;
      await _pumpScreen(tester, client, onNavigate: (route) => capturedRoute = route);

      await tester.tap(find.text('Book Now'));
      await _settle(tester);

      expect(capturedRoute, '/book-now');
      expect(find.text('Sign in to request towing'), findsNothing);
      expect(find.text('Create Account'), findsNothing);
    });

    test('never references the guest auth-gate sheet', () {
      final source = File('lib/screens/customer/customer_services_screen.dart').readAsStringSync();
      expect(source.contains('showAuthGateSheet'), isFalse);
      expect(source.contains('auth_gate_sheet.dart'), isFalse);
    });

    testWidgets('back navigation returns to the screen that opened it', (tester) async {
      final client = MockClient((request) async {
        if (request.url.path.endsWith('/v1/customer/content')) {
          return _json({'announcement': null, 'services': []});
        }
        return _json({'success': false}, status: 404);
      });

      await _pumpScreen(tester, client, withBackTarget: true);

      await tester.tap(find.text('Open Services'));
      await _settle(tester);
      expect(find.text('Services'), findsOneWidget);

      await tester.tap(find.byIcon(Icons.arrow_back_rounded));
      for (var i = 0; i < 20; i++) {
        await tester.pump(const Duration(milliseconds: 50));
      }

      expect(find.text('Open Services'), findsOneWidget);
      expect(find.text('Services'), findsNothing);
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

        final client = MockClient((request) async {
          if (request.url.path.endsWith('/v1/customer/content')) {
            return _json({
              'announcement': null,
              'services': [
                {
                  'title': 'Heavy Duty Towing For Large Commercial Trucks And Buses',
                  'description':
                      'A long real description that should wrap gracefully across multiple lines without overflowing the row.',
                  'image_url': null,
                  'category': 'a',
                  'availability_note': null,
                },
              ],
            });
          }
          return _json({'success': false}, status: 404);
        });

        await _pumpScreen(tester, client);

        expect(tester.takeException(), isNull);
      });
    }
  });
}
