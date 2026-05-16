import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';
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

  // ── Password ───────────────────────────────────────────────────────────────

  static Future<Map<String, dynamic>> changePassword({
    required String currentPassword,
    required String newPassword,
    required String confirmPassword,
  }) async {
    try {
      final response = await http
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
        'message': body['message'] as String? ?? 'Password change failed.',
      };
    } on TimeoutException {
      return {'success': false, 'message': 'Request timed out.'};
    } catch (_) {
      return {'success': false, 'message': 'Network error. Please try again.'};
    }
  }

  // ── Task ───────────────────────────────────────────────────────────────────

  static Future<TaskModel?> getCurrentTask() async {
    try {
      final response = await http
          .get(Uri.parse('$_base/task'), headers: await _authHeaders())
          .timeout(const Duration(seconds: 15));

      if (response.statusCode == 200) {
        final body = jsonDecode(response.body) as Map<String, dynamic>;
        final data = body['data'];
        if (data == null) return null;
        return TaskModel.fromJson(data as Map<String, dynamic>);
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  static Future<Map<String, dynamic>> acceptTask(String bookingCode) async {
    return _post('$_base/task/$bookingCode/accept');
  }

  static Future<Map<String, dynamic>> updateStatus(
    String bookingCode,
    String status,
  ) async {
    try {
      final response = await http
          .patch(
            Uri.parse('$_base/task/$bookingCode/status'),
            headers: await _authHeaders(),
            body: jsonEncode({'status': status}),
          )
          .timeout(const Duration(seconds: 15));
      return _parseResult(response);
    } on TimeoutException {
      return {'success': false, 'message': 'Request timed out.'};
    } catch (_) {
      return {'success': false, 'message': 'Network error.'};
    }
  }

  static Future<Map<String, dynamic>> returnTask(
    String bookingCode,
    String reason,
    String? notes,
  ) async {
    try {
      final response = await http
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
    } on TimeoutException {
      return {'success': false, 'message': 'Request timed out.'};
    } catch (_) {
      return {'success': false, 'message': 'Network error.'};
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

      final streamed = await req.send().timeout(const Duration(seconds: 30));
      final response = await http.Response.fromStream(streamed);
      return _parseResult(response);
    } on TimeoutException {
      return {'success': false, 'message': 'Upload timed out.'};
    } catch (_) {
      return {'success': false, 'message': 'Upload failed.'};
    }
  }

  static Future<Map<String, dynamic>> completeTask(
    String bookingCode,
    File? signature,
    String paymentMethod,
  ) async {
    try {
      final token = await ApiService.getToken();
      final req =
          http.MultipartRequest('POST', Uri.parse('$_base/task/$bookingCode/complete'))
            ..headers['Authorization'] = 'Bearer $token'
            ..headers['Accept'] = 'application/json'
            ..fields['payment_method'] = paymentMethod;

      if (signature != null) {
        req.files.add(
          await http.MultipartFile.fromPath('signature', signature.path),
        );
      }

      final streamed = await req.send().timeout(const Duration(seconds: 30));
      final response = await http.Response.fromStream(streamed);
      return _parseResult(response);
    } on TimeoutException {
      return {'success': false, 'message': 'Request timed out.'};
    } catch (_) {
      return {'success': false, 'message': 'Network error.'};
    }
  }

  // ── Presence ───────────────────────────────────────────────────────────────

  static Future<void> pingPresence() async {
    try {
      await http
          .post(
            Uri.parse('$_base/presence/ping'),
            headers: await _authHeaders(),
          )
          .timeout(const Duration(seconds: 10));
    } catch (_) {}
  }

  static Future<void> goOffline() async {
    try {
      await http
          .post(
            Uri.parse('$_base/presence/offline'),
            headers: await _authHeaders(),
          )
          .timeout(const Duration(seconds: 10));
    } catch (_) {}
  }

  // ── Location ───────────────────────────────────────────────────────────────

  static Future<void> updateLocation(double lat, double lng) async {
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return;
    try {
      await http
          .put(
            Uri.parse('$_base/location'),
            headers: await _authHeaders(),
            body: jsonEncode({'lat': lat, 'lng': lng}),
          )
          .timeout(const Duration(seconds: 10));
    } catch (_) {}
  }

  // ── Helpers ────────────────────────────────────────────────────────────────

  static Future<Map<String, dynamic>> _post(
    String url, [
    Map<String, dynamic>? body,
  ]) async {
    try {
      final response = await http
          .post(
            Uri.parse(url),
            headers: await _authHeaders(),
            body: body != null ? jsonEncode(body) : null,
          )
          .timeout(const Duration(seconds: 15));
      return _parseResult(response);
    } on TimeoutException {
      return {'success': false, 'message': 'Request timed out.'};
    } catch (_) {
      return {'success': false, 'message': 'Network error.'};
    }
  }

  static Map<String, dynamic> _parseResult(http.Response response) {
    try {
      final body = jsonDecode(response.body) as Map<String, dynamic>;
      if ((response.statusCode == 200 || response.statusCode == 201) &&
          body['success'] == true) {
        final data = body['data'];
        return {
          'success': true,
          if (data != null)
            'task': TaskModel.fromJson(data as Map<String, dynamic>),
          if (body['message'] != null) 'message': body['message'],
        };
      }
      return {
        'success': false,
        'message': body['message'] as String? ?? 'Something went wrong.',
      };
    } catch (_) {
      return {
        'success': false,
        'message': 'Server error (${response.statusCode}).',
      };
    }
  }
}
