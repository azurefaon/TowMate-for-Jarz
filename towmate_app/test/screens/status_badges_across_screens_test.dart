import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/core/status_style.dart';
import 'package:towmate_app/core/theme.dart';
import 'package:towmate_app/screens/customer/my_bookings_screen.dart';
import 'package:towmate_app/screens/team_leader/tl_history_screen.dart';
import 'package:towmate_app/screens/team_leader/tl_home_screen.dart';
import 'package:towmate_app/services/tl_presence_controller.dart';
import 'package:towmate_app/widgets/status_badge.dart';

double _lin(double v) => v <= 0.03928 ? v / 12.92 : math.pow((v + 0.055) / 1.055, 2.4).toDouble();
double _lum(Color c) => 0.2126 * _lin(c.r) + 0.7152 * _lin(c.g) + 0.0722 * _lin(c.b);
double _contrast(Color a, Color b) {
  final x = _lum(a), y = _lum(b);
  return (math.max(x, y) + 0.05) / (math.min(x, y) + 0.05);
}

http.Response _json(Object body, [int code = 200]) =>
    http.Response(jsonEncode(body), code, headers: {'content-type': 'application/json'});

/// The rendered fill and label colour of a StatusBadge.
({Color bg, Color fg}) _colors(WidgetTester t, Finder badge) {
  final container = t.widget<Container>(find.descendant(of: badge, matching: find.byType(Container)).first);
  final bg = (container.decoration as BoxDecoration).color!;
  final fg = t.widget<Text>(find.descendant(of: badge, matching: find.byType(Text))).style!.color!;
  return (bg: bg, fg: fg);
}

Finder _badge(String status) => find.byWidgetPredicate((w) => w is StatusBadge && w.status == status);

const _themes = {'light': (ThemeMode.light), 'dark': (ThemeMode.dark)};
ThemeData _themeFor(String mode) => mode == 'dark' ? AppTheme.dark : AppTheme.light;
Brightness _brightnessFor(String mode) => mode == 'dark' ? Brightness.dark : Brightness.light;
Color _surfaceFor(String mode) => mode == 'dark' ? TmColors.dark800 : TmColors.white;

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(
    const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
    (c) async => null,
  );
  for (final ch in ['flutter.baseflow.com/geolocator', 'flutter.baseflow.com/geolocator_android']) {
    binding.defaultBinaryMessenger.setMockMethodCallHandler(
      MethodChannel(ch),
      (c) async => c.method == 'checkPermission' ? 2 : null,
    );
  }

  Future<void> settle(WidgetTester t) async {
    for (var i = 0; i < 12; i++) {
      await t.pump(const Duration(milliseconds: 50));
    }
  }

  void bigView(WidgetTester t) {
    t.view.physicalSize = const Size(400, 1800);
    t.view.devicePixelRatio = 1.0;
    addTearDown(t.view.reset);
  }

  group('StatusBadge widget, both themes', () {
    for (final mode in _themes.keys) {
      testWidgets('renders every known status solid + readable in $mode mode', (t) async {
        bigView(t);
        final statuses = StatusStyle.knownStatuses.toList();
        await t.pumpWidget(MaterialApp(
          theme: _themeFor(mode),
          themeMode: _themes[mode],
          home: Scaffold(
            body: ListView(children: [for (final s in statuses) StatusBadge(status: s, label: s)]),
          ),
        ));

        for (final s in statuses) {
          final c = _colors(t, _badge(s));
          final style = StatusStyle.of(s);
          final b = _brightnessFor(mode);
          expect(c.bg, style.backgroundFor(b), reason: '$s bg');
          expect(c.fg, style.foregroundFor(b), reason: '$s fg');
          expect(c.bg.a, 1.0, reason: '$s must be a solid fill');
          expect(_contrast(c.bg, c.fg), greaterThanOrEqualTo(4.5), reason: '$s label contrast in $mode');
          expect(c.bg, isNot(_surfaceFor(mode)), reason: '$s must differ from the surrounding surface');
        }
      });

      testWidgets('unknown status renders a readable neutral badge in $mode mode', (t) async {
        await t.pumpWidget(MaterialApp(
          theme: _themeFor(mode),
          themeMode: _themes[mode],
          home: const Scaffold(body: Center(child: StatusBadge(status: 'some_new_status'))),
        ));

        expect(find.text('Some New Status'), findsOneWidget);
        final c = _colors(t, find.byType(StatusBadge));
        expect(c.bg, StatusStyle.neutral.backgroundFor(_brightnessFor(mode)));
        expect(_contrast(c.bg, c.fg), greaterThanOrEqualTo(4.5));
        for (final known in StatusStyle.knownStatuses) {
          expect(c.bg, isNot(StatusStyle.of(known).backgroundFor(_brightnessFor(mode))), reason: known);
        }
      });
    }

    testWidgets('an explicit label overrides the default wording', (t) async {
      await t.pumpWidget(const MaterialApp(
        home: Scaffold(body: StatusBadge(status: 'on_the_way', label: 'En Route')),
      ));
      expect(find.text('En Route'), findsOneWidget);
      expect(find.text('On The Way'), findsNothing);
    });

    testWidgets('the neutral constructor is never a lifecycle colour', (t) async {
      await t.pumpWidget(const MaterialApp(
        home: Scaffold(body: StatusBadge.neutral(label: '2 active')),
      ));
      expect(_colors(t, find.byType(StatusBadge)).bg, StatusStyle.neutral.background);
    });
  });

  group('Team Leader History', () {
    final jobs = [
      {
        'booking_code': 'TM-0777', 'status': 'completed', 'customer_name': 'Maria Santos',
        'pickup_address': 'Quezon City', 'dropoff_address': 'Mandaluyong',
        'final_total': 1250.0, 'completed_at': '2026-08-01T10:00:00Z',
      },
      {
        'booking_code': 'TM-0778', 'status': 'returned', 'customer_name': 'Jose Cruz',
        'pickup_address': 'Pasig', 'dropoff_address': 'Taguig',
        'final_total': 0.0, 'completed_at': '2026-08-02T10:00:00Z',
      },
    ];

    Future<void> pumpHistory(WidgetTester t, String mode) async {
      bigView(t);
      SharedPreferences.setMockInitialValues({'auth_token': 'x'});
      final client = MockClient((r) async => r.url.path.endsWith('/v1/team-leader/history')
          ? _json({'success': true, 'data': jobs, 'current_page': 1, 'last_page': 1})
          : _json({'success': false}, 404));
      await http.runWithClient(() async {
        await t.pumpWidget(MaterialApp(theme: _themeFor(mode), themeMode: _themes[mode], home: const TlHistoryScreen()));
        await settle(t);
      }, () => client);
    }

    for (final mode in _themes.keys) {
      testWidgets('Completed is a solid green badge with high-contrast text ($mode)', (t) async {
        await pumpHistory(t, mode);

        final c = _colors(t, _badge('completed'));
        final b = _brightnessFor(mode);
        expect(find.text('Completed'), findsOneWidget);
        expect(c.bg, StatusStyle.green.backgroundFor(b));
        expect(c.bg.a, 1.0, reason: 'no translucent/tinted fill');
        expect(c.bg.g, greaterThan(c.bg.r));
        expect(c.bg.g, greaterThan(c.bg.b));
        expect(c.fg.toARGB32(), 0xFFFFFFFF, reason: 'white label');
        expect(_contrast(c.bg, c.fg), greaterThanOrEqualTo(4.5));
        // The old pale look is gone: label colour is not the badge's own hue.
        expect(c.fg, isNot(TmColors.success));
      });

      testWidgets('Returned is a solid red badge ($mode)', (t) async {
        await pumpHistory(t, mode);

        final c = _colors(t, _badge('returned'));
        expect(find.text('Returned'), findsOneWidget);
        expect(c.bg, StatusStyle.red.backgroundFor(_brightnessFor(mode)));
        expect(c.bg.a, 1.0);
        expect(c.bg.r, greaterThan(c.bg.g * 3));
        expect(_contrast(c.bg, c.fg), greaterThanOrEqualTo(4.5));
      });
    }
  });

  group('same status, same styling across screens', () {
    Map<String, dynamic> booking(String code, String status) => {
          'id': int.parse(code.replaceAll(RegExp(r'[^0-9]'), '')),
          'booking_code': code,
          'status': status,
          'pickup_address': 'Pasig',
          'dropoff_address': 'QC',
          'truck_type_name': 'Light Duty',
          'created_at': '2026-09-01 10:00:00',
          'group_booking_code': code,
          'service_type': 'book_now',
        };

    Future<void> pumpMyBookings(WidgetTester t, String mode, List<Map<String, dynamic>> bookings) async {
      bigView(t);
      SharedPreferences.setMockInitialValues({'auth_token': 't', 'user_role': 'Customer', 'user_name': 'C'});
      final client = MockClient((r) async {
        if (r.url.path.endsWith('/v1/bookings/history')) {
          return _json({'data': bookings, 'meta': {'last_page': 1}});
        }
        if (r.url.path.endsWith('/v1/quotations/pending')) return _json({'data': null});
        return _json({'success': false}, 404);
      });
      await http.runWithClient(() async {
        await t.pumpWidget(MaterialApp(theme: _themeFor(mode), themeMode: _themes[mode], home: const MyBookingsScreen()));
        await settle(t);
      }, () => client);
    }

    for (final mode in _themes.keys) {
      testWidgets('customer History uses the very same completed/cancelled colours ($mode)', (t) async {
        await pumpMyBookings(t, mode, [
          booking('TM-00003', 'completed'),
          booking('TM-00004', 'cancelled'),
          booking('TM-00005', 'rejected'),
          booking('TM-00006', 'not_responding'),
        ]);
        await t.tap(find.text('History'));
        await settle(t);

        final b = _brightnessFor(mode);
        for (final s in ['completed', 'cancelled', 'rejected', 'not_responding']) {
          final c = _colors(t, _badge(s));
          expect(c.bg, StatusStyle.of(s).backgroundFor(b), reason: s);
          expect(c.bg.a, 1.0, reason: s);
          expect(_contrast(c.bg, c.fg), greaterThanOrEqualTo(4.5), reason: s);
        }
        expect(_colors(t, _badge('completed')).bg, StatusStyle.green.backgroundFor(b));
      });

      testWidgets('customer Active tab badges are solid + readable ($mode)', (t) async {
        await pumpMyBookings(t, mode, [
          booking('TM-00001', 'requested'),
          booking('TM-00002', 'on_the_way'),
          booking('TM-00007', 'waiting_verification'),
        ]);

        final b = _brightnessFor(mode);
        for (final s in ['requested', 'on_the_way', 'waiting_verification']) {
          final c = _colors(t, _badge(s));
          expect(c.bg, StatusStyle.of(s).backgroundFor(b), reason: s);
          expect(_contrast(c.bg, c.fg), greaterThanOrEqualTo(4.5), reason: s);
        }
      });

      testWidgets('TL History Completed == customer History Completed ($mode)', (t) async {
        await pumpMyBookings(t, mode, [booking('TM-00003', 'completed')]);
        await t.tap(find.text('History'));
        await settle(t);
        final customer = _colors(t, _badge('completed'));

        await t.pumpWidget(const SizedBox());
        SharedPreferences.setMockInitialValues({'auth_token': 'x'});
        final client = MockClient((r) async => r.url.path.endsWith('/v1/team-leader/history')
            ? _json({
                'success': true,
                'data': [
                  {'booking_code': 'TM-0777', 'status': 'completed', 'customer_name': 'M', 'pickup_address': 'a',
                   'dropoff_address': 'b', 'final_total': 1.0, 'completed_at': '2026-08-01T10:00:00Z'},
                ],
                'current_page': 1, 'last_page': 1,
              })
            : _json({'success': false}, 404));
        await http.runWithClient(() async {
          await t.pumpWidget(MaterialApp(theme: _themeFor(mode), themeMode: _themes[mode], home: const TlHistoryScreen()));
          await settle(t);
        }, () => client);
        final tl = _colors(t, _badge('completed'));

        expect(tl.bg, customer.bg);
        expect(tl.fg, customer.fg);
      });
    }
  });

  group('Team Leader Home', () {
    for (final mode in _themes.keys) {
      testWidgets('assigned is a solid amber badge with near-black text ($mode)', (t) async {
        bigView(t);
        SharedPreferences.setMockInitialValues({'auth_token': 'x', 'user_name': 'TL', 'duty_class': 'heavy'});
        final client = MockClient((r) async {
          if (r.url.path.endsWith('/v1/team-leader/task') && r.method == 'GET') {
            return _json({
              'success': true,
              'data': {
                'id': 1, 'booking_code': 'TM-1', 'status': 'assigned', 'customer_name': 'J',
                'pickup_address': 'a', 'dropoff_address': 'b', 'truck_type_name': 'x', 'service_type': 'book_now',
              },
            });
          }
          return _json({'success': true});
        });
        try {
          await http.runWithClient(() async {
            await t.pumpWidget(MaterialApp(theme: _themeFor(mode), themeMode: _themes[mode], home: const TlHomeScreen()));
            await settle(t);
          }, () => client);

          final c = _colors(t, _badge('assigned'));
          expect(find.text('Assigned'), findsOneWidget);
          expect(c.bg, StatusStyle.amber.backgroundFor(_brightnessFor(mode)));
          expect(c.fg.toARGB32(), 0xFF171717);
          expect(_contrast(c.bg, c.fg), greaterThan(10));
          expect(c.bg, isNot(_surfaceFor(mode)));
        } finally {
          TlPresenceController.stop();
        }
      });
    }
  });
}
