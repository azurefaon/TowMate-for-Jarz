import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/models/quotation_model.dart';
import 'package:towmate_app/screens/customer/notifications_screen.dart';
import 'package:towmate_app/widgets/skeleton_box.dart';
import 'package:towmate_app/widgets/tm_bottom_nav.dart';

http.Response _json(Object body, {int status = 200}) {
  return http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});
}

final _notificationsFixture = [
  {
    'id': 1,
    'type': 'booking_update',
    'title': 'Your tow is on the way',
    'body': 'The driver is heading to your pickup location.',
    'created_at': DateTime.now().toIso8601String(),
    'is_read': false,
    'booking_code': 'TM-0001',
  },
  {
    'id': 2,
    'type': 'quotation_sent',
    'title': 'New quotation received',
    'body': 'Review your quotation for booking TM-0002.',
    'created_at': DateTime.now().toIso8601String(),
    'is_read': true,
    'booking_code': 'TM-0002',
  },
];

final _pendingQuotationFixture = {
  'id': 55,
  'quotation_number': 'QT-0002',
  'status': 'sent',
  'estimated_price': 2217.6,
  'distance_km': 12.0,
  'pickup_address': 'Pasay',
  'dropoff_address': 'Makati',
  'truck_type_name': 'Light Duty',
};

http.Client _buildClient({
  List<Map<String, dynamic>>? notifications,
  bool fail = false,
  bool slow = false,
  void Function(int id)? onMarkRead,
  Object? pendingQuotation,
}) {
  return MockClient((request) async {
    final path = request.url.path;
    if (path.endsWith('/v1/notifications')) {
      if (slow) await Future<void>.delayed(const Duration(seconds: 2));
      if (fail) return _json({'success': false}, status: 500);
      return _json({
        'success': true,
        'unread_count': (notifications ?? _notificationsFixture)
            .where((n) => n['is_read'] == false)
            .length,
        'data': notifications ?? _notificationsFixture,
      });
    }
    if (path.endsWith('/v1/notifications/mark-read')) {
      return _json({'success': true});
    }
    final readMatch = RegExp(r'/v1/notifications/(\d+)/read').firstMatch(path);
    if (readMatch != null) {
      onMarkRead?.call(int.parse(readMatch.group(1)!));
      return _json({'success': true});
    }
    if (path.endsWith('/v1/quotations/pending')) {
      return _json({'data': pendingQuotation});
    }
    return _json({'success': false}, status: 404);
  });
}

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 10; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

Future<void> _pumpNotifications(
  WidgetTester tester, {
  List<Map<String, dynamic>>? notifications,
  bool fail = false,
  ThemeData? theme,
  ThemeMode? themeMode,
  void Function(String route, Object? args)? onNavigate,
  void Function(int id)? onMarkRead,
  Object? pendingQuotation,
}) async {
  SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
  await http.runWithClient(
    () async {
      await tester.pumpWidget(
        MaterialApp(
          theme: theme,
          themeMode: themeMode,
          onGenerateRoute: (settings) {
            if (settings.name == '/' || settings.name == null) {
              return MaterialPageRoute(builder: (_) => const NotificationsScreen());
            }
            onNavigate?.call(settings.name!, settings.arguments);
            return MaterialPageRoute(builder: (_) => const Scaffold());
          },
        ),
      );
      await _settle(tester);
    },
    () => _buildClient(
      notifications: notifications,
      fail: fail,
      onMarkRead: onMarkRead,
      pendingQuotation: pendingQuotation,
    ),
  );
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (call) async => null,
  );

  group('NotificationsScreen', () {
    testWidgets('renders a loading skeleton, not a spinner, before content arrives', (tester) async {
      SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: NotificationsScreen()));
          await tester.pump();
        },
        () => _buildClient(slow: true),
      );

      expect(find.byType(SkeletonBox), findsWidgets);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(tester.takeException(), isNull);

      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('header and bottom nav remain visible during loading', (tester) async {
      SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(const MaterialApp(home: NotificationsScreen()));
          await tester.pump();
        },
        () => _buildClient(slow: true),
      );

      expect(find.text('Notifications'), findsOneWidget);
      expect(find.byIcon(Icons.arrow_back_rounded), findsNothing);
      expect(find.byType(TmBottomNav), findsOneWidget);

      await tester.pump(const Duration(seconds: 3));
    });

    testWidgets('loaded notifications render normally and hide the skeleton', (tester) async {
      await _pumpNotifications(tester);

      expect(find.text('Your tow is on the way'), findsOneWidget);
      expect(find.text('New quotation received'), findsOneWidget);
      expect(find.byType(SkeletonBox), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('empty state still works', (tester) async {
      await _pumpNotifications(tester, notifications: []);

      expect(find.text('No notifications yet'), findsOneWidget);
      expect(find.byIcon(Icons.notifications_none_rounded), findsOneWidget);
      expect(find.byType(SkeletonBox), findsNothing);
    });

    testWidgets('error state shows the fallback snackbar without a crash', (tester) async {
      await _pumpNotifications(tester, fail: true);

      await tester.pump();
      expect(find.text('Could not load notifications. Check your connection.'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('unread notification shows a dot and Mark all read action', (tester) async {
      await _pumpNotifications(tester);

      expect(find.text('Mark all read'), findsOneWidget);
    });

    testWidgets('tapping Mark all read clears the unread action', (tester) async {
      await _pumpNotifications(tester);

      expect(find.text('Mark all read'), findsOneWidget);
      await tester.tap(find.text('Mark all read'));
      await _settle(tester);

      expect(find.text('Mark all read'), findsNothing);
    });

    testWidgets('tapping a notification with a booking code navigates to booking detail', (tester) async {
      String? capturedRoute;
      Object? capturedArgs;

      await _pumpNotifications(
        tester,
        onNavigate: (route, args) {
          capturedRoute = route;
          capturedArgs = args;
        },
      );

      await tester.tap(find.text('Your tow is on the way'));
      await _settle(tester);

      expect(capturedRoute, '/booking-detail');
      expect(capturedArgs, 'TM-0001');
    });

    testWidgets('tapping a new-quotation notification opens the quotation review screen, not booking detail', (
      tester,
    ) async {
      String? capturedRoute;
      Object? capturedArgs;

      SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(
            MaterialApp(
              onGenerateRoute: (settings) {
                if (settings.name == '/' || settings.name == null) {
                  return MaterialPageRoute(builder: (_) => const NotificationsScreen());
                }
                capturedRoute = settings.name;
                capturedArgs = settings.arguments;
                return MaterialPageRoute(builder: (_) => const Scaffold());
              },
            ),
          );
          await _settle(tester);

          await tester.tap(find.text('New quotation received'));
          await _settle(tester);
        },
        () => _buildClient(pendingQuotation: _pendingQuotationFixture),
      );

      expect(capturedRoute, '/quotation');
      expect(capturedArgs, isA<QuotationModel>());
      expect((capturedArgs as QuotationModel).quotationNumber, 'QT-0002');
    });

    testWidgets('shows a clear unavailable message instead of opening booking detail when the quotation is no longer pending', (
      tester,
    ) async {
      String? capturedRoute;
      Object? capturedArgs;

      SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(
            MaterialApp(
              onGenerateRoute: (settings) {
                if (settings.name == '/' || settings.name == null) {
                  return MaterialPageRoute(builder: (_) => const NotificationsScreen());
                }
                capturedRoute = settings.name;
                capturedArgs = settings.arguments;
                return MaterialPageRoute(builder: (_) => const Scaffold());
              },
            ),
          );
          await _settle(tester);

          await tester.tap(find.text('New quotation received'));
          await _settle(tester);
        },
        () => _buildClient(pendingQuotation: null),
      );

      expect(capturedRoute, isNull);
      expect(capturedArgs, isNull);
      expect(
        find.text('This quotation is no longer available. It may have already been responded to or expired.'),
        findsOneWidget,
      );
    });

    testWidgets('tapping a notification with no booking code does not navigate', (tester) async {
      final navigated = <String>[];

      await _pumpNotifications(
        tester,
        notifications: [
          {
            'id': 3,
            'type': 'quotation_expired',
            'title': 'Quotation expired',
            'body': 'Your quotation is no longer valid.',
            'created_at': DateTime.now().toIso8601String(),
            'is_read': false,
            'booking_code': null,
          },
        ],
        onNavigate: (route, args) => navigated.add(route),
      );

      await tester.tap(find.text('Quotation expired'));
      await _settle(tester);

      expect(navigated, isEmpty);
    });

    testWidgets('tapping an unread notification persists the read state via the real per-notification endpoint', (
      tester,
    ) async {
      final markedRead = <int>[];

      SharedPreferences.setMockInitialValues({'auth_token': 'test-token'});
      await http.runWithClient(
        () async {
          await tester.pumpWidget(
            MaterialApp(
              onGenerateRoute: (settings) {
                if (settings.name == '/' || settings.name == null) {
                  return MaterialPageRoute(builder: (_) => const NotificationsScreen());
                }
                return MaterialPageRoute(builder: (_) => const Scaffold());
              },
            ),
          );
          await _settle(tester);

          await tester.tap(find.text('Your tow is on the way'));
          await _settle(tester);
        },
        () => _buildClient(onMarkRead: markedRead.add),
      );

      expect(markedRead, contains(1));
    });

    testWidgets('does not show any notification type icon or icon background', (tester) async {
      await _pumpNotifications(tester);

      expect(find.byIcon(Icons.receipt_long_rounded), findsNothing);
      expect(find.byIcon(Icons.local_shipping_rounded), findsNothing);
      expect(find.byIcon(Icons.notifications_rounded), findsNothing);
    });

    testWidgets('groups notifications into Today, Yesterday, and Earlier sections', (tester) async {
      final now = DateTime.now();
      final today = DateTime(now.year, now.month, now.day, 9);
      final yesterday = today.subtract(const Duration(days: 1));
      final earlier = today.subtract(const Duration(days: 10));

      await _pumpNotifications(
        tester,
        notifications: [
          {
            'id': 10,
            'type': 'booking_update',
            'title': 'Today notification',
            'body': 'Body',
            'created_at': today.toIso8601String(),
            'is_read': true,
            'booking_code': 'TM-0010',
          },
          {
            'id': 11,
            'type': 'booking_update',
            'title': 'Yesterday notification',
            'body': 'Body',
            'created_at': yesterday.toIso8601String(),
            'is_read': true,
            'booking_code': 'TM-0011',
          },
          {
            'id': 12,
            'type': 'booking_update',
            'title': 'Earlier notification',
            'body': 'Body',
            'created_at': earlier.toIso8601String(),
            'is_read': true,
            'booking_code': 'TM-0012',
          },
        ],
      );

      expect(find.text('Today'), findsOneWidget);
      expect(find.text('Yesterday'), findsOneWidget);
      expect(find.text('Earlier'), findsOneWidget);

      final todayHeaderTop = tester.getTopLeft(find.text('Today')).dy;
      final todayItemTop = tester.getTopLeft(find.text('Today notification')).dy;
      final yesterdayHeaderTop = tester.getTopLeft(find.text('Yesterday')).dy;
      expect(todayItemTop, greaterThan(todayHeaderTop));
      expect(yesterdayHeaderTop, greaterThan(todayItemTop));
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

        await _pumpNotifications(tester);

        expect(tester.takeException(), isNull);
        expect(find.byType(TmBottomNav), findsOneWidget);
      });
    }

    testWidgets('renders correctly in light mode', (tester) async {
      await _pumpNotifications(tester, theme: AppTheme.light, themeMode: ThemeMode.light);

      expect(tester.takeException(), isNull);
      expect(find.text('Your tow is on the way'), findsOneWidget);
    });

    testWidgets('renders correctly in dark mode', (tester) async {
      await _pumpNotifications(tester, theme: AppTheme.dark, themeMode: ThemeMode.dark);

      expect(tester.takeException(), isNull);
      expect(find.text('Your tow is on the way'), findsOneWidget);
    });
  });
}
