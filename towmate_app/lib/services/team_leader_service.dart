import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import '../core/api_transport.dart';
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../core/api_error.dart';
import 'api_service.dart';
import '../models/task_model.dart';

class TeamLeaderService {
  static const String _base = '${ApiService.baseUrl}/v1/team-leader';

  static const _baseHeaders = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  };

  static Future<Map<String, String>> _authHeaders() async {
    final token = await ApiService.getToken();
    return {..._baseHeaders, 'Authorization': 'Bearer $token'};
  }

  static Future<Map<String, dynamic>> changePassword({
    required String currentPassword,
    required String newPassword,
    required String confirmPassword,
  }) async {
    try {
      final response = await apiClient
          .post(
            Uri.parse(
              '${ApiService.baseUrl}/v1/team-leader/auth/change-password',
            ),
            headers: await _authHeaders(),
            body: jsonEncode({
              'current_password': currentPassword,
              'password': newPassword,
              'password_confirmation': confirmPassword,
            }),
          )
          .timeout(const Duration(seconds: 15));

      final body = jsonDecode(response.body) as Map<String, dynamic>;
      if (response.statusCode == 200 && body['success'] == true) {
        final prefs = await SharedPreferences.getInstance();
        await prefs.setBool('must_change_password', false);
        return {'success': true};
      }
      return {
        'success': false,
        'message': ApiMessages.forResponse(
          response.statusCode,
          body,
          fallback: 'Password change failed.',
        ),
      };
    } catch (e) {
      return _failure(e);
    }
  }

  @visibleForTesting
  static Duration retryDelay = const Duration(seconds: 2);

  static Future<TaskModel?> getCurrentTask() async {
    ApiException? lastError;
    for (var attempt = 0; attempt < 3; attempt++) {
      try {
        final response = await apiClient
            .get(Uri.parse('$_base/task'), headers: await _authHeaders())
            .timeout(const Duration(seconds: 15));

        if (response.statusCode != 200) {
          final kind = ApiError.classifyStatus(response.statusCode);
          final error = ApiException(
            kind,
            ApiMessages.forResponse(response.statusCode, _decodeOrNull(response.body)),
          );
          if (kind != ApiErrorKind.serverError5xx) throw error;
          lastError = error;
        } else {
          final body = jsonDecode(response.body) as Map<String, dynamic>;
          final data = body['data'];
          if (data == null) return null;
          return TaskModel.fromJson(data as Map<String, dynamic>);
        }
      } on ApiException {
        rethrow;
      } catch (e) {
        final kind = ApiError.classifyException(e);
        lastError = ApiException(kind, ApiError.message(kind));
        final transient =
            kind == ApiErrorKind.timeout || kind == ApiErrorKind.unreachable;
        if (!transient) throw lastError;
      }
      if (attempt < 2) await Future.delayed(retryDelay);
    }
    throw lastError!;
  }

  static Future<Map<String, dynamic>> getHistory({int page = 1}) async {
    try {
      final response = await apiClient
          .get(
            Uri.parse('$_base/history?page=$page'),
            headers: await _authHeaders(),
          )
          .timeout(const Duration(seconds: 15));

      if (response.statusCode == 200) {
        final body = jsonDecode(response.body) as Map<String, dynamic>;
        return {
          'success': true,
          'data': (body['data'] as List? ?? []).cast<Map<String, dynamic>>(),
          'current_page': body['current_page'] ?? 1,
          'last_page': body['last_page'] ?? 1,
        };
      }
      return {
        'success': false,
        'data': <Map<String, dynamic>>[],
        'message': ApiMessages.forResponse(
          response.statusCode,
          _decodeOrNull(response.body),
        ),
      };
    } catch (e) {
      return {..._failure(e), 'data': <Map<String, dynamic>>[]};
    }
  }

  static Future<Map<String, dynamic>> acceptTask(String bookingCode) async {
    return _post('$_base/task/$bookingCode/accept');
  }

  static Future<Map<String, dynamic>> updateStatus(
    String bookingCode,
    String status, {
    double? lat,
    double? lng,
  }) async {
    try {
      final response = await apiClient
          .patch(
            Uri.parse('$_base/task/$bookingCode/status'),
            headers: await _authHeaders(),
            body: jsonEncode({
              'status': status,
              if (lat != null) 'lat': lat,
              if (lng != null) 'lng': lng,
            }),
          )
          .timeout(const Duration(seconds: 15));
      return _parseResult(response);
    } catch (e) {
      return _failure(e);
    }
  }

  /// LOCAL/DEMO ONLY. Dedicated endpoint; the backend re-checks the
  /// environment, demo account and booking, and derives the target status
  /// itself. There is intentionally no status/coordinates/flag parameter.
  static Future<Map<String, dynamic>> simulateArrival(String bookingCode) async {
    return _post('$_base/demo/task/$bookingCode/simulate-arrival');
  }

  static Future<Map<String, dynamic>> returnTask(
    String bookingCode,
    String reason,
    String? notes,
  ) async {
    try {
      final response = await apiClient
          .post(
            Uri.parse('$_base/task/$bookingCode/return'),
            headers: await _authHeaders(),
            body: jsonEncode({
              'reason': reason,
              if (notes != null && notes.isNotEmpty) 'notes': notes,
            }),
          )
          .timeout(const Duration(seconds: 15));
      return _parseResult(response);
    } catch (e) {
      return _failure(e);
    }
  }

  static Future<Map<String, dynamic>> uploadPhoto(
    String bookingCode,
    XFile photo,
    String type,
  ) async {
    try {
      final token = await ApiService.getToken();
      final bytes = await photo.readAsBytes();
      final req =
          http.MultipartRequest('POST', Uri.parse('$_base/task/$bookingCode/photo'))
            ..headers['Authorization'] = 'Bearer $token'
            ..headers['Accept'] = 'application/json'
            ..fields['type'] = type
            ..files.add(http.MultipartFile.fromBytes('photo', bytes, filename: photo.name));

      final streamed = await apiClient.send(req).timeout(const Duration(seconds: 30));
      final response = await http.Response.fromStream(streamed);
      return _parseResult(response);
    } catch (e) {
      return _failure(e);
    }
  }

  static Future<Map<String, dynamic>> claimNextInGroup(String groupCode) async {
    try {
      final response = await apiClient
          .post(
            Uri.parse('$_base/group/$groupCode/claim-next'),
            headers: await _authHeaders(),
          )
          .timeout(const Duration(seconds: 15));
      return _parseResult(response);
    } catch (e) {
      return _failure(e);
    }
  }

  static Future<Map<String, dynamic>> completeTask(
    String bookingCode,
    Uint8List? signatureBytes,
    String paymentMethod, {
    String? cashReceived,
  }) async {
    try {
      final token = await ApiService.getToken();
      final req =
          http.MultipartRequest('POST', Uri.parse('$_base/task/$bookingCode/complete'))
            ..headers['Authorization'] = 'Bearer $token'
            ..headers['Accept'] = 'application/json'
            ..fields['payment_method'] = paymentMethod;

      if (cashReceived != null) {
        req.fields['cash_received'] = cashReceived;
      }

      if (signatureBytes != null) {
        req.files.add(
          http.MultipartFile.fromBytes('signature', signatureBytes, filename: 'signature.png'),
        );
      }

      final streamed = await apiClient.send(req).timeout(const Duration(seconds: 30));
      final response = await http.Response.fromStream(streamed);
      return _parseResult(response);
    } catch (e) {
      return _failure(e);
    }
  }


  static Future<void> pingPresence() async {
    try {
      final response = await apiClient
          .post(
            Uri.parse('$_base/presence/ping'),
            headers: await _authHeaders(),
          )
          .timeout(const Duration(seconds: 10));
      if (kDebugMode && (response.statusCode < 200 || response.statusCode >= 300)) {
        debugPrint('TL presence ping failed: HTTP ${response.statusCode}');
      }
    } catch (_) {}
  }

  static Future<void> goOffline() async {
    try {
      await apiClient
          .post(
            Uri.parse('$_base/presence/offline'),
            headers: await _authHeaders(),
          )
          .timeout(const Duration(seconds: 10));
    } catch (_) {}
  }

  static Future<void> markAway() async {
    try {
      await apiClient
          .post(
            Uri.parse('$_base/presence/away'),
            headers: await _authHeaders(),
          )
          .timeout(const Duration(seconds: 10));
    } catch (_) {}
  }


  static Future<void> updateLocation(
    double lat,
    double lng, {
    double? accuracy,
  }) async {
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return;
    try {
      await apiClient
          .put(
            Uri.parse('$_base/location'),
            headers: await _authHeaders(),
            body: jsonEncode({
              'lat': lat,
              'lng': lng,
              if (accuracy != null) 'accuracy': accuracy,
            }),
          )
          .timeout(const Duration(seconds: 10));
    } catch (_) {}
  }


  static Future<Map<String, dynamic>> _post(
    String url, [
    Map<String, dynamic>? body,
  ]) async {
    try {
      final response = await apiClient
          .post(
            Uri.parse(url),
            headers: await _authHeaders(),
            body: body != null ? jsonEncode(body) : null,
          )
          .timeout(const Duration(seconds: 15));
      return _parseResult(response);
    } catch (e) {
      return _failure(e);
    }
  }

  static Object? _decodeOrNull(String raw) {
    try {
      return jsonDecode(raw);
    } catch (_) {
      return null;
    }
  }

  static Map<String, dynamic> _failure(Object e) {
    return {
      'success': false,
      'transport_error': true,
      'message': ApiError.message(ApiError.classifyException(e)),
    };
  }

  static Map<String, dynamic> _parseResult(http.Response response) {
    final decoded = _decodeOrNull(response.body);
    final body = decoded is Map<String, dynamic> ? decoded : null;

    if ((response.statusCode == 200 || response.statusCode == 201) &&
        body?['success'] == true) {
      try {
        final data = body!['data'];
        return {
          'success': true,
          if (data != null)
            'task': TaskModel.fromJson(data as Map<String, dynamic>),
          if (body['message'] != null) 'message': body['message'],
        };
      } catch (e) {
        return _failure(e);
      }
    }

    final message = body == null
        ? ApiError.message(
            response.statusCode >= 200 && response.statusCode < 300
                ? ApiErrorKind.invalidResponse
                : ApiError.classifyStatus(response.statusCode),
          )
        : ApiMessages.forResponse(response.statusCode, body);

    return {
      'success': false,
      'message': message.isEmpty ? ApiErrorMessages.serverError : message,
    };
  }
}
