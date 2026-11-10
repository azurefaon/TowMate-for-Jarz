import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:towmate_app/screens/team_leader/tl_expense_form_screen.dart';
import 'package:towmate_app/screens/team_leader/tl_expenses_screen.dart';
import 'package:towmate_app/services/tl_expense_service.dart';

// Phase 8: Team Leader expense submission over the existing /api/v1/team-leader/expenses endpoints.

class _FakePicker extends ImagePicker {
  int calls = 0;

  @override
  Future<XFile?> pickImage({
    required ImageSource source,
    double? maxWidth,
    double? maxHeight,
    int? imageQuality,
    CameraDevice preferredCameraDevice = CameraDevice.rear,
    bool requestFullMetadata = true,
  }) async {
    calls++;
    return XFile.fromData(Uint8List.fromList(List.filled(64, 7)), path: 'receipt-$calls.jpg', mimeType: 'image/jpeg');
  }
}

Map<String, dynamic> _options({bool withUnit = true}) => {
      'success': true,
      'data': {
        'unit': withUnit ? {'id': 4, 'name': 'Unit Alpha', 'available_unit_funds': '3000.00'} : null,
        'categories': [
          {'key': 'fuel_diesel', 'label': 'Fuel / Diesel'},
          {'key': 'toll', 'label': 'Toll'},
        ],
        'funding_sources': [
          {'key': 'unit_fund', 'label': 'Issued unit funds'},
          {'key': 'company_direct', 'label': 'Direct company payment'},
          {'key': 'personal_advance', 'label': 'Personal advance'},
        ],
        'expense_scopes': [
          {'key': 'job', 'label': 'Job expense'},
          {'key': 'unit', 'label': 'Unit / monthly expense'},
        ],
        'evidence': {'max_kilobytes': 8192, 'types': ['jpg', 'png', 'pdf']},
      },
    };

Map<String, dynamic> _expense({String status = 'submitted', String label = 'Awaiting Dispatcher verification', String? rejection}) => {
      'id': 11,
      'reference': 'EXP-20261110-ABC123',
      'status': status,
      'status_label': label,
      'expense_scope': 'job',
      'booking_reference': '1234567',
      'expense_date': '2026-11-09',
      'category': 'fuel_diesel',
      'category_label': 'Fuel / Diesel',
      'description': 'Diesel for the Bulacan tow',
      'amount': '2950.50',
      'fundings': [
        {'source': 'unit_fund', 'label': 'Issued unit funds', 'amount': '2950.50'}
      ],
      'unit_fund_shortfall': '0.00',
      'evidence_count': 1,
      'rejection_reason': rejection,
      'submitted_at': '2026-11-09T10:00:00+08:00',
    };

Future<void> _settle(WidgetTester tester) async {
  for (var i = 0; i < 12; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  binding.defaultBinaryMessenger.setMockMethodCallHandler(const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'), (call) async => null);

  setUp(() {
    SharedPreferences.setMockInitialValues({'auth_token': 'tl-token', 'user_role': 'Team Leader'});
  });

  group('formatPeso', () {
    test('formats exact decimal strings with separators', () {
      expect(formatPeso('2950.5'), '₱2,950.50');
      expect(formatPeso('1234567.89'), '₱1,234,567.89');
      expect(formatPeso('0'), '₱0.00');
    });
  });

  group('TlExpenseService', () {
    test('creates RFC 4122 version 4 idempotency keys', () {
      final key = TlExpenseService.newIdempotencyKey();
      expect(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$').hasMatch(key), isTrue);
      expect(TlExpenseService.newIdempotencyKey(), isNot(key));
    });

    test('reads the server validation message on a 422', () async {
      final client = MockClient((r) async => http.Response(jsonEncode({'message': 'x', 'errors': {'expense_date': ['Expenses cannot be dated in the future.']}}), 422));
      final res = await http.runWithClient(
        () => TlExpenseService.submit(idempotencyKey: 'k', scope: 'job', expenseDate: '2026-11-09', category: 'toll', description: 'Toll', amount: '120', fundingSource: 'unit_fund', receipts: const [], bookingRef: '1'),
        () => client,
      );
      expect(res['success'], isFalse);
      expect(res['message'], 'Expenses cannot be dated in the future.');
    });
  });

  group('TlExpensesScreen', () {
    testWidgets('lists submitted expenses with amount, status and rejection reason', (tester) async {
      final client = MockClient((r) async => http.Response(
          jsonEncode({'success': true, 'data': [_expense(), _expense(status: 'rejected', label: 'Rejected', rejection: 'Receipt is unreadable')..['id'] = 12]}), 200,
          headers: {'content-type': 'application/json; charset=utf-8'}));
      await http.runWithClient(() async {
        await tester.pumpWidget(const MaterialApp(home: TlExpensesScreen()));
        await _settle(tester);

        expect(find.text('Fuel / Diesel'), findsNWidgets(2));
        expect(find.text('₱2,950.50'), findsNWidgets(2));
        expect(find.text('Awaiting Dispatcher verification'), findsOneWidget);
        expect(find.text('Rejected'), findsOneWidget);
        expect(find.text('Rejected: Receipt is unreadable'), findsOneWidget);
        expect(find.textContaining('Job 1234567'), findsNWidgets(2));
        expect(find.byKey(const Key('tlExpenseNew')), findsOneWidget);
      }, () => client);
    });

    testWidgets('shows an empty state and a retryable error state', (tester) async {
      var fail = true;
      final client = MockClient((r) async => fail ? http.Response('{}', 500) : http.Response(jsonEncode({'success': true, 'data': []}), 200));
      await http.runWithClient(() async {
        await tester.pumpWidget(const MaterialApp(home: TlExpensesScreen()));
        await _settle(tester);
        expect(find.byKey(const Key('tlExpenseError')), findsOneWidget);

        fail = false;
        await tester.tap(find.text('Try again'));
        await _settle(tester);
        expect(find.byKey(const Key('tlExpenseEmpty')), findsOneWidget);
      }, () => client);
    });
  });

  group('TlExpenseFormScreen', () {
    Future<void> pumpForm(WidgetTester tester, _FakePicker picker) async {
      tester.view.physicalSize = const Size(420, 2400);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(MaterialApp(home: TlExpenseFormScreen(picker: picker)));
      await _settle(tester);
    }

    testWidgets('validates required fields and a receipt before sending anything', (tester) async {
      var posts = 0;
      final client = MockClient((r) async {
        if (r.method == 'POST') posts++;
        return http.Response(jsonEncode(_options()), 200);
      });
      await http.runWithClient(() async {
        await pumpForm(tester, _FakePicker());
        expect(find.textContaining('Unit: Unit Alpha'), findsOneWidget);
        expect(find.textContaining('₱3,000.00'), findsOneWidget);

        await tester.enterText(find.byKey(const Key('tlExpenseAmount')), '12.345');
        await tester.tap(find.byKey(const Key('tlExpenseSubmit')));
        await _settle(tester);

        expect(find.text('A job expense needs the booking reference.'), findsOneWidget);
        expect(find.text('Choose a category.'), findsOneWidget);
        expect(find.textContaining('at most two decimal places'), findsOneWidget);
        expect(find.text('Describe the expense.'), findsOneWidget);
        expect(find.text('Attach at least one receipt photo.'), findsOneWidget);
        expect(posts, 0);
      }, () => client);
    });

    testWidgets('submits the expense with its receipt, funding source and a stable idempotency key', (tester) async {
      final bodies = <String>[];
      var reply = 422;
      final client = MockClient((r) async {
        if (r.method == 'GET') return http.Response(jsonEncode(_options()), 200);
        bodies.add(utf8.decode(r.bodyBytes, allowMalformed: true));
        expect(r.url.path, endsWith('/v1/team-leader/expenses'));
        expect(r.headers['Authorization'], 'Bearer tl-token');
        return reply == 422
            ? http.Response(jsonEncode({'success': false, 'message': 'The booking reference does not belong to your unit.'}), 422)
            : http.Response(jsonEncode({'success': true, 'message': 'Expense submitted for Dispatcher review.', 'warning': null, 'data': _expense()}), 201);
      });
      final picker = _FakePicker();
      await http.runWithClient(() async {
        await pumpForm(tester, picker);
        await tester.enterText(find.byKey(const Key('tlExpenseBooking')), '1234567');
        await tester.tap(find.byKey(const Key('tlExpenseCategory')));
        await _settle(tester);
        await tester.tap(find.text('Toll').last);
        await _settle(tester);
        await tester.enterText(find.byKey(const Key('tlExpenseAmount')), '1,250.50');
        await tester.tap(find.byKey(const Key('tlExpenseFunding')));
        await _settle(tester);
        await tester.tap(find.text('Direct company payment').last);
        await _settle(tester);
        await tester.enterText(find.byKey(const Key('tlExpenseDescription')), 'NLEX toll, both ways');
        await tester.ensureVisible(find.byKey(const Key('tlExpenseGallery')));
        await tester.tap(find.byKey(const Key('tlExpenseGallery')));
        await _settle(tester);
        expect(picker.calls, 1, reason: 'picker opened');
        expect(find.text('receipt-1.jpg'), findsOneWidget);

        // First attempt: the server refuses; the error is shown and the form stays usable.
        await tester.ensureVisible(find.byKey(const Key('tlExpenseSubmit')));
        await tester.tap(find.byKey(const Key('tlExpenseSubmit')));
        await _settle(tester);
        expect(find.text('The booking reference does not belong to your unit.'), findsOneWidget);

        // Retry succeeds and reuses the same idempotency key.
        reply = 201;
        await tester.tap(find.byKey(const Key('tlExpenseSubmit')));
        await _settle(tester);
      }, () => client);

      expect(bodies, hasLength(2));
      final key1 = RegExp(r'name="idempotency_key"\r\n\r\n([0-9a-f-]{36})').firstMatch(bodies[0])!.group(1);
      final key2 = RegExp(r'name="idempotency_key"\r\n\r\n([0-9a-f-]{36})').firstMatch(bodies[1])!.group(1);
      expect(key1, key2);
      final body = bodies[1];
      expect(body, contains('name="expense_scope"\r\n\r\njob'));
      expect(body, contains('name="booking_ref"\r\n\r\n1234567'));
      expect(body, contains('name="category"\r\n\r\ntoll'));
      expect(body, contains('name="amount"\r\n\r\n1250.50'));
      expect(body, contains('name="fundings[company_direct]"\r\n\r\n1250.50'));
      expect(body, contains('name="description"\r\n\r\nNLEX toll, both ways'));
      expect(body, contains('name="receipts[]"; filename="receipt-1.jpg"'));
      expect(body, isNot(contains('unit_id')));   // the server always uses the Team Leader's own unit
    });

    testWidgets('explains when the Team Leader has no assigned unit', (tester) async {
      final client = MockClient((r) async => http.Response(jsonEncode(_options(withUnit: false)), 200));
      await http.runWithClient(() async {
        await pumpForm(tester, _FakePicker());
        expect(find.byKey(const Key('tlExpenseNoUnit')), findsOneWidget);
        expect(find.byKey(const Key('tlExpenseSubmit')), findsNothing);
      }, () => client);
    });
  });
}
