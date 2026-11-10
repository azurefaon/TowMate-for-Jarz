import 'dart:convert';
import 'dart:math';

import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';

import '../core/api_error.dart';
import '../core/api_transport.dart';
import 'api_service.dart';

/// One expense as returned by GET/POST /api/v1/team-leader/expenses.
class TlExpense {
  const TlExpense({
    required this.id,
    required this.reference,
    required this.status,
    required this.statusLabel,
    required this.scope,
    required this.categoryLabel,
    required this.description,
    required this.amount,
    required this.expenseDate,
    required this.evidenceCount,
    required this.fundings,
    this.bookingReference,
    this.coverageMonth,
    this.rejectionReason,
    this.unitFundShortfall = '0.00',
  });

  final int id;
  final String reference;
  final String status;
  final String statusLabel;
  final String scope;
  final String categoryLabel;
  final String description;
  final String amount;
  final String expenseDate;
  final int evidenceCount;
  final List<Map<String, dynamic>> fundings;
  final String? bookingReference;
  final String? coverageMonth;
  final String? rejectionReason;
  final String unitFundShortfall;

  factory TlExpense.fromJson(Map<String, dynamic> j) => TlExpense(
        id: (j['id'] as num).toInt(),
        reference: '${j['reference'] ?? ''}',
        status: '${j['status'] ?? ''}',
        statusLabel: '${j['status_label'] ?? j['status'] ?? ''}',
        scope: '${j['expense_scope'] ?? 'job'}',
        categoryLabel: '${j['category_label'] ?? j['category'] ?? ''}',
        description: '${j['description'] ?? ''}',
        amount: '${j['amount'] ?? '0.00'}',
        expenseDate: '${j['expense_date'] ?? ''}',
        evidenceCount: (j['evidence_count'] as num?)?.toInt() ?? 0,
        fundings: (j['fundings'] as List? ?? const []).cast<Map<String, dynamic>>(),
        bookingReference: j['booking_reference'] as String?,
        coverageMonth: j['coverage_month'] as String?,
        rejectionReason: j['rejection_reason'] as String?,
        unitFundShortfall: '${j['unit_fund_shortfall'] ?? '0.00'}',
      );
}

/// Choices for the expense form, from GET /api/v1/team-leader/expenses/options (server config, never hard-coded).
class TlExpenseOptions {
  const TlExpenseOptions({
    required this.unitName,
    required this.availableUnitFunds,
    required this.categories,
    required this.fundingSources,
    required this.scopes,
    required this.maxKilobytes,
  });

  final String? unitName;
  final String? availableUnitFunds;
  final List<MapEntry<String, String>> categories;
  final List<MapEntry<String, String>> fundingSources;
  final List<MapEntry<String, String>> scopes;
  final int maxKilobytes;

  bool get hasUnit => unitName != null;

  static List<MapEntry<String, String>> _pairs(dynamic list) => (list as List? ?? const [])
      .map((e) => MapEntry('${(e as Map)['key']}', '${e['label']}'))
      .toList();

  factory TlExpenseOptions.fromJson(Map<String, dynamic> j) {
    final unit = j['unit'] as Map<String, dynamic>?;
    return TlExpenseOptions(
      unitName: unit?['name'] as String?,
      availableUnitFunds: unit?['available_unit_funds'] as String?,
      categories: _pairs(j['categories']),
      fundingSources: _pairs(j['funding_sources']),
      scopes: _pairs(j['expense_scopes']),
      maxKilobytes: ((j['evidence'] as Map?)?['max_kilobytes'] as num?)?.toInt() ?? 8192,
    );
  }
}

/// Team Leader expense submission over the existing Phase 3 API. The server decides the unit (the Team Leader's own),
/// re-validates everything, and starts the Team Leader → Dispatcher → Owner review. This class never approves anything.
class TlExpenseService {
  static const String _base = '${ApiService.baseUrl}/v1/team-leader/expenses';

  static Future<Map<String, String>> _headers() async => {
        'Accept': 'application/json',
        'Authorization': 'Bearer ${await ApiService.getToken()}',
      };

  /// Random UUID v4, generated once per form so a retried submission cannot create a duplicate expense.
  static String newIdempotencyKey([Random? random]) {
    final r = random ?? Random.secure();
    final b = List<int>.generate(16, (_) => r.nextInt(256));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    final h = b.map((x) => x.toRadixString(16).padLeft(2, '0')).join();
    return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-${h.substring(16, 20)}-${h.substring(20)}';
  }

  static Future<({TlExpenseOptions? options, String? error})> fetchOptions() async {
    try {
      final res = await apiClient.get(Uri.parse('$_base/options'), headers: await _headers()).timeout(const Duration(seconds: 15));
      final body = _decode(res.body);
      if (res.statusCode == 200 && body?['success'] == true) {
        return (options: TlExpenseOptions.fromJson(body!['data'] as Map<String, dynamic>), error: null);
      }
      return (options: null, error: _message(res.statusCode, body));
    } catch (e) {
      return (options: null, error: ApiError.message(ApiError.classifyException(e)));
    }
  }

  static Future<({List<TlExpense> items, String? error})> list() async {
    try {
      final res = await apiClient.get(Uri.parse(_base), headers: await _headers()).timeout(const Duration(seconds: 15));
      final body = _decode(res.body);
      if (res.statusCode == 200 && body?['success'] == true) {
        final data = (body!['data'] as List? ?? const []).cast<Map<String, dynamic>>();
        return (items: data.map(TlExpense.fromJson).toList(), error: null);
      }
      return (items: const <TlExpense>[], error: _message(res.statusCode, body));
    } catch (e) {
      return (items: const <TlExpense>[], error: ApiError.message(ApiError.classifyException(e)));
    }
  }

  /// Submits one expense with its receipts. [fundingSource] pays the whole amount (mixed funding is corrected on the web).
  static Future<Map<String, dynamic>> submit({
    required String idempotencyKey,
    required String scope,
    required String expenseDate,
    required String category,
    required String description,
    required String amount,
    required String fundingSource,
    required List<XFile> receipts,
    String? bookingRef,
    String? coverageMonth,
  }) async {
    try {
      final req = http.MultipartRequest('POST', Uri.parse(_base))
        ..headers.addAll(await _headers())
        ..fields['idempotency_key'] = idempotencyKey
        ..fields['expense_scope'] = scope
        ..fields['expense_date'] = expenseDate
        ..fields['category'] = category
        ..fields['description'] = description
        ..fields['amount'] = amount
        ..fields['fundings[$fundingSource]'] = amount;
      if (scope == 'job' && bookingRef != null) req.fields['booking_ref'] = bookingRef;
      if (scope == 'unit' && coverageMonth != null) req.fields['coverage_month'] = coverageMonth;
      for (final file in receipts) {
        req.files.add(http.MultipartFile.fromBytes('receipts[]', await file.readAsBytes(), filename: file.name));
      }

      final streamed = await apiClient.send(req).timeout(const Duration(seconds: 60));
      final res = await http.Response.fromStream(streamed);
      final body = _decode(res.body);
      if ((res.statusCode == 201 || res.statusCode == 200) && body?['success'] == true) {
        return {
          'success': true,
          'duplicate': res.statusCode == 200,
          'message': body!['message'] ?? 'Expense submitted for Dispatcher review.',
          'warning': body['warning'],
          'expense': TlExpense.fromJson(body['data'] as Map<String, dynamic>),
        };
      }
      return {'success': false, 'message': _message(res.statusCode, body)};
    } catch (e) {
      return {'success': false, 'message': ApiError.message(ApiError.classifyException(e))};
    }
  }

  static Map<String, dynamic>? _decode(String raw) {
    try {
      final v = jsonDecode(raw);
      return v is Map<String, dynamic> ? v : null;
    } catch (_) {
      return null;
    }
  }

  static String _message(int status, Map<String, dynamic>? body) {
    final errors = body?['errors'];
    if (errors is Map && errors.isNotEmpty) {
      final first = errors.values.first;
      if (first is List && first.isNotEmpty) return '${first.first}';
    }
    final m = body == null ? ApiError.message(ApiError.classifyStatus(status)) : ApiMessages.forResponse(status, body);
    return m.isEmpty ? 'Something went wrong. Please try again.' : m;
  }
}
