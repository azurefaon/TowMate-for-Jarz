import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:flutter/foundation.dart'
    show kIsWeb, defaultTargetPlatform, TargetPlatform;
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/booking_model.dart';
import '../models/quotation_model.dart';
import '../models/truck_type_model.dart';

class ApiService {
  // static String get baseUrl {
  //   if (kIsWeb) return 'http://127.0.0.1:8000/api';
  //   if (defaultTargetPlatform == TargetPlatform.android) {
  //     return 'http://10.0.2.2:8000/api';
  //   }
  //   return 'http://127.0.0.1:8000/api';
  // }

  // static const String baseUrl = 'http://127.0.0.1:8000/api';
  static const String baseUrl = 'https://jarztowing.up.railway.app/api';

  static const _secure = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );

  static const _headers = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  };

  static Future<void> saveSession({
    required String token,
    required String role,
    required String name,
    required int userId,
    String? email,
    String? phone,
    String? dutyClass,
    bool mustChangePassword = false,
  }) async {
    try {
      await _secure.write(key: 'auth_token', value: token);
      if (email != null) await _secure.write(key: 'user_email', value: email);
      if (phone != null) await _secure.write(key: 'user_phone', value: phone);
    } catch (_) {}

    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('auth_token', token);
    await prefs.setString('user_role', role);
    await prefs.setString('user_name', name);
    await prefs.setInt('user_id', userId);
    await prefs.setBool('must_change_password', mustChangePassword);
    if (dutyClass != null) {
      await prefs.setString('duty_class', dutyClass);
    } else {
      await prefs.remove('duty_class');
    }
  }

  static Future<void> clearSession() async {
    try {
      await _secure.delete(key: 'auth_token');
      await _secure.delete(key: 'user_email');
      await _secure.delete(key: 'user_phone');
    } catch (_) {}

    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('auth_token');
    await prefs.remove('user_role');
    await prefs.remove('user_name');
    await prefs.remove('user_id');
    await prefs.remove('must_change_password');
    await prefs.remove('duty_class');
  }

  static Future<bool> getMustChangePassword() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool('must_change_password') ?? false;
  }

  static Future<String?> getToken() async {
    try {
      final token = await _secure.read(key: 'auth_token');
      if (token != null) return token;
    } catch (_) {}

    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('auth_token');
  }

  static Future<String?> getUserRole() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('user_role');
  }

  static Future<String?> getUserName() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('user_name');
  }

  static Future<String?> getUserDutyClass() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('duty_class');
  }

  static Future<String?> getUserEmail() async {
    try {
      return await _secure.read(key: 'user_email');
    } catch (_) {
      return null;
    }
  }

  static Future<String?> getUserPhone() async {
    try {
      return await _secure.read(key: 'user_phone');
    } catch (_) {
      return null;
    }
  }

  static Future<bool> isLoggedIn() async {
    final token = await getToken();
    return token != null && token.isNotEmpty;
  }

  static Map<String, dynamic> _networkError(Object e) {
    final msg = e.toString().toLowerCase();
    if (e is SocketException ||
        msg.contains('connection refused') ||
        msg.contains('failed host lookup') ||
        msg.contains('network') ||
        msg.contains('os error')) {
      return {
        'success': false,
        'message': 'Cannot reach the server. Please check your connection.',
      };
    }
    if (e is TimeoutException || msg.contains('timeout')) {
      return {
        'success': false,
        'message': 'Request timed out. Please try again.',
      };
    }
    return {
      'success': false,
      'message': 'An unexpected error occurred. Please try again.',
    };
  }

  static Future<Map<String, dynamic>> login(
    String email,
    String password,
    String csrfToken,
  ) async {
    try {
      final response = await http
          .post(
            Uri.parse('$baseUrl/login'),
            headers: {..._headers, 'X-CSRF-Token': csrfToken},
            body: jsonEncode({
              'email': email.trim().toLowerCase(),
              'password': password,
            }),
          )
          .timeout(const Duration(seconds: 15));

      final body = jsonDecode(response.body) as Map<String, dynamic>;

      if (response.statusCode == 200 && body['success'] == true) {
        final data = body['data'] as Map<String, dynamic>;
        final user = data['user'] as Map<String, dynamic>;

        final mustChange = user['must_change_password'] == true;

        await saveSession(
          token: data['token'] as String,
          role: user['role'] as String? ?? 'Customer',
          name: user['name'] as String? ?? '',
          userId: (user['id'] as num?)?.toInt() ?? 0,
          email: user['email'] as String?,
          phone: user['phone'] as String?,
          dutyClass: user['duty_class'] as String?,
          mustChangePassword: mustChange,
        );

        return {
          'success': true,
          'role': user['role'] as String? ?? 'Customer',
          'name': user['name'] as String? ?? '',
          'must_change_password': mustChange,
        };
      }

      return {
        'success': false,
        'message':
            body['message'] as String? ??
            'Login failed. Check your credentials.',
      };
    } on TimeoutException {
      return {
        'success': false,
        'message': 'Request timed out. Please try again.',
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> signup({
    required String firstName,
    required String lastName,
    required String email,
    required String phone,
    required String password,
    required String confirmPassword,
    required String csrfToken,
  }) async {
    try {
      final response = await http
          .post(
            Uri.parse('$baseUrl/register'),
            headers: {..._headers, 'X-CSRF-Token': csrfToken},
            body: jsonEncode({
              'first_name': firstName,
              'last_name': lastName,
              'email': email.trim().toLowerCase(),
              'phone': phone,
              'password': password,
              'password_confirmation': confirmPassword,
            }),
          )
          .timeout(const Duration(seconds: 15));

      if (response.statusCode == 200 || response.statusCode == 201) {
        return {'success': true};
      }

      final body = jsonDecode(response.body) as Map<String, dynamic>;

      final errors = body['errors'] as Map<String, dynamic>?;
      if (errors != null && errors.isNotEmpty) {
        final firstList = errors.values.first;
        final msg = (firstList is List && firstList.isNotEmpty)
            ? firstList.first as String
            : body['message'] as String? ?? 'Registration failed.';
        return {'success': false, 'message': msg};
      }

      return {
        'success': false,
        'message':
            body['message'] as String? ??
            'Registration failed. Please try again.',
      };
    } on TimeoutException {
      return {
        'success': false,
        'message': 'Request timed out. Please try again.',
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<void> fetchAndCacheProfile() async {
    try {
      final token = await getToken();
      final response = await http
          .get(
            Uri.parse('$baseUrl/v1/profile'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final body = jsonDecode(response.body) as Map<String, dynamic>;
        final data = body['data'] as Map<String, dynamic>?;
        if (data != null) {
          final prefs = await SharedPreferences.getInstance();
          if (data['name'] != null) {
            await prefs.setString('user_name', data['name'] as String);
          }
          if (data['email'] != null) {
            await _secure.write(
              key: 'user_email',
              value: data['email'] as String,
            );
          }
          if (data['phone'] != null) {
            await _secure.write(
              key: 'user_phone',
              value: data['phone'] as String,
            );
          }
        }
      }
    } catch (_) {}
  }

  static Future<List<TruckTypeModel>> fetchTruckTypes() async {
    try {
      final token = await getToken();
      final response = await http
          .get(
            Uri.parse('$baseUrl/v1/truck-types'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 15));

      if (response.statusCode == 200) {
        final list = jsonDecode(response.body) as List;
        return list
            .map((j) => TruckTypeModel.fromJson(j as Map<String, dynamic>))
            .toList();
      }
      return [];
    } catch (_) {
      return [];
    }
  }

  static Future<Map<String, dynamic>> fetchAvailability() async {
    try {
      final token = await getToken();
      final res = await http
          .get(
            Uri.parse('$baseUrl/v1/availability'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 10));
      if (res.statusCode == 200) {
        return jsonDecode(res.body) as Map<String, dynamic>;
      }
      return {'book_now_enabled': true};
    } catch (_) {
      return {'book_now_enabled': true};
    }
  }

  static Future<List<Map<String, dynamic>>> searchAddress(String query) async {
    try {
      final uri = Uri.https('nominatim.openstreetmap.org', '/search', {
        'q': query,
        'format': 'json',
        'countrycodes': 'ph',
        'limit': '5',
        'addressdetails': '0',
      });
      final response = await http
          .get(
            uri,
            headers: {
              'Accept': 'application/json',
              'User-Agent': 'TowMate/1.0',
            },
          )
          .timeout(const Duration(seconds: 10));
      if (response.statusCode == 200) {
        final results = jsonDecode(response.body) as List? ?? [];
        return results
            .cast<Map<String, dynamic>>()
            .map(
              (place) => {
                'label': place['display_name'] as String? ?? '',
                'coordinates': [
                  double.tryParse(place['lon'] as String? ?? '') ?? 0.0,
                  double.tryParse(place['lat'] as String? ?? '') ?? 0.0,
                ],
              },
            )
            .toList();
      }
      return [];
    } catch (_) {
      return [];
    }
  }

  static Future<Map<String, dynamic>> fetchBookingHistory({
    int page = 1,
  }) async {
    try {
      final token = await getToken();
      final uri = Uri.parse(
        '$baseUrl/v1/bookings/history',
      ).replace(queryParameters: {'page': page.toString()});
      final response = await http
          .get(uri, headers: {..._headers, 'Authorization': 'Bearer $token'})
          .timeout(const Duration(seconds: 15));
      if (response.statusCode == 200) {
        final body = jsonDecode(response.body) as Map<String, dynamic>;
        final items = (body['data'] as List? ?? [])
            .map((j) => BookingModel.fromJson(j as Map<String, dynamic>))
            .toList();
        final meta = body['meta'] as Map<String, dynamic>?;
        final lastPage = (meta?['last_page'] as num?)?.toInt() ?? 1;
        return {'success': true, 'bookings': items, 'hasMore': page < lastPage};
      }
      return {'success': false, 'bookings': <BookingModel>[], 'hasMore': false};
    } catch (_) {
      return {'success': false, 'bookings': <BookingModel>[], 'hasMore': false};
    }
  }

  static Future<BookingModel?> fetchBookingDetail(String code) async {
    try {
      final token = await getToken();
      final response = await http
          .get(
            Uri.parse('$baseUrl/v1/bookings/$code/detail'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 15));
      if (response.statusCode == 200) {
        final body = jsonDecode(response.body) as Map<String, dynamic>;
        if (body['success'] == true) {
          return BookingModel.fromJson(body['data'] as Map<String, dynamic>);
        }
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  static Future<BookingModel?> fetchCurrentBooking() async {
    try {
      final token = await getToken();
      final response = await http
          .get(
            Uri.parse('$baseUrl/v1/bookings/current'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 15));

      if (response.statusCode == 200) {
        final body = jsonDecode(response.body) as Map<String, dynamic>;
        final data = body['data'];
        if (data == null) return null;
        return BookingModel.fromJson(data as Map<String, dynamic>);
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  static Future<Map<String, dynamic>> createBooking({
    required int truckTypeId,
    required int vehicleTypeId,
    required String pickupAddress,
    required double pickupLat,
    required double pickupLng,
    required String dropoffAddress,
    required double dropoffLat,
    required double dropoffLng,
    required double distanceKm,
    String serviceType = 'book_now',
    String? notes,
    String? scheduledDate,
    String? scheduledTime,
    List<String> vehicleImagePaths = const [],
    List<Map<String, dynamic>> extraVehicles = const [],
  }) async {
    try {
      final token = await getToken();
      final req =
          http.MultipartRequest('POST', Uri.parse('$baseUrl/v1/bookings'))
            ..headers['Authorization'] = 'Bearer $token'
            ..headers['Accept'] = 'application/json'
            ..fields['truck_type_id'] = truckTypeId.toString()
            ..fields['vehicle_type_id'] = vehicleTypeId.toString()
            ..fields['pickup_address'] = pickupAddress
            ..fields['pickup_lat'] = pickupLat.toString()
            ..fields['pickup_lng'] = pickupLng.toString()
            ..fields['dropoff_address'] = dropoffAddress
            ..fields['dropoff_lat'] = dropoffLat.toString()
            ..fields['dropoff_lng'] = dropoffLng.toString()
            ..fields['distance_km'] = distanceKm.toString()
            ..fields['service_type'] = serviceType;

      if (notes != null && notes.isNotEmpty) req.fields['notes'] = notes;
      if (scheduledDate != null) req.fields['scheduled_date'] = scheduledDate;
      if (scheduledTime != null) req.fields['scheduled_time'] = scheduledTime;
      if (extraVehicles.isNotEmpty) {
        req.fields['extra_vehicles'] = jsonEncode(extraVehicles);
      }

      for (final path in vehicleImagePaths) {
        final xfile = XFile(path);
        final bytes = await xfile.readAsBytes();
        final filename = kIsWeb
            ? 'photo_${vehicleImagePaths.indexOf(path)}.jpg'
            : path.split(Platform.pathSeparator).last;
        req.files.add(
          http.MultipartFile.fromBytes(
            'vehicle_images[]',
            bytes,
            filename: filename,
          ),
        );
      }

      final streamed = await req.send().timeout(const Duration(seconds: 30));
      final response = await http.Response.fromStream(streamed);

      Map<String, dynamic> body;
      try {
        body = jsonDecode(response.body) as Map<String, dynamic>;
      } catch (_) {
        return {
          'success': false,
          'message': 'Request could not be completed. Please try again.',
        };
      }

      if (response.statusCode == 201 && body['success'] == true) {
        return {'success': true, 'booking_code': body['booking_code']};
      }
      return {
        'success': false,
        'message':
            body['message'] as String? ?? 'Booking failed. Please try again.',
      };
    } on TimeoutException {
      return {
        'success': false,
        'message': 'Request timed out. Please try again.',
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> calculateRoute(
    double lat1,
    double lng1,
    double lat2,
    double lng2,
  ) async {
    try {
      final token = await getToken();
      final response = await http
          .post(
            Uri.parse('$baseUrl/v1/geo/route'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
            body: jsonEncode({
              'pickup_lat': lat1,
              'pickup_lng': lng1,
              'drop_lat': lat2,
              'drop_lng': lng2,
            }),
          )
          .timeout(const Duration(seconds: 20));

      if (response.statusCode == 200) {
        return {
          ...jsonDecode(response.body) as Map<String, dynamic>,
          'success': true,
        };
      }
      return {'success': false, 'message': 'Could not calculate route.'};
    } on TimeoutException {
      return {'success': false, 'message': 'Route calculation timed out.'};
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<String> reverseGeocode(double lat, double lng) async {
    try {
      final uri = Uri.https('nominatim.openstreetmap.org', '/reverse', {
        'lat': lat.toString(),
        'lon': lng.toString(),
        'format': 'json',
        'zoom': '18',
      });
      final response = await http
          .get(
            uri,
            headers: {
              'Accept': 'application/json',
              'User-Agent': 'TowMate/1.0',
            },
          )
          .timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final body = jsonDecode(response.body) as Map<String, dynamic>;
        final name = body['display_name'] as String?;
        if (name != null && name.isNotEmpty) return name;
      }
    } catch (_) {}
    return 'Unknown location';
  }

  static Future<Map<String, dynamic>> logout(String csrfToken) async {
    try {
      final token = await getToken();
      await http
          .post(
            Uri.parse('$baseUrl/logout'),
            headers: {
              ..._headers,
              'Authorization': 'Bearer $token',
              'X-CSRF-Token': csrfToken,
            },
          )
          .timeout(const Duration(seconds: 15));
    } catch (_) {
    } finally {
      await clearSession();
    }
    return {'success': true};
  }

  static Future<QuotationModel?> fetchPendingQuotation() async {
    try {
      final token = await getToken();
      final res = await http
          .get(
            Uri.parse('$baseUrl/v1/quotations/pending'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 15));
      if (res.statusCode == 200) {
        final data = (jsonDecode(res.body) as Map<String, dynamic>)['data'];
        if (data == null) return null;
        return QuotationModel.fromJson(data as Map<String, dynamic>);
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  static Future<Map<String, dynamic>> acceptQuotation(int id) async {
    try {
      final token = await getToken();
      final res = await http
          .post(
            Uri.parse('$baseUrl/v1/quotations/$id/accept'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 15));
      final body = jsonDecode(res.body) as Map<String, dynamic>;
      return {
        'success': res.statusCode == 200 && body['success'] == true,
        'message': body['message'] ?? '',
      };
    } catch (_) {
      return {'success': false, 'message': 'Network error. Please try again.'};
    }
  }

  static Future<Map<String, dynamic>> rejectQuotation(
    int id, {
    String? reason,
  }) async {
    try {
      final token = await getToken();
      final res = await http
          .post(
            Uri.parse('$baseUrl/v1/quotations/$id/reject'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
            body: jsonEncode({'reason': reason}),
          )
          .timeout(const Duration(seconds: 15));
      final body = jsonDecode(res.body) as Map<String, dynamic>;
      return {
        'success': res.statusCode == 200 && body['success'] == true,
        'message': body['message'] ?? '',
      };
    } catch (_) {
      return {'success': false, 'message': 'Network error. Please try again.'};
    }
  }

  // ── Password Reset ─────────────────────────────────────────────────────────

  static Future<Map<String, dynamic>> sendResetOtp(String email) async {
    try {
      final res = await http
          .post(
            Uri.parse('$baseUrl/password/forgot'),
            headers: _headers,
            body: jsonEncode({'email': email}),
          )
          .timeout(const Duration(seconds: 15));
      final body = jsonDecode(res.body) as Map<String, dynamic>;
      return {
        'success': body['success'] == true,
        'message': body['message'] as String? ?? '',
      };
    } on TimeoutException {
      return {'success': false, 'message': 'Request timed out.'};
    } catch (_) {
      return {'success': false, 'message': 'Network error. Please try again.'};
    }
  }

  static Future<Map<String, dynamic>> verifyResetOtp(String email, String otp) async {
    try {
      final res = await http
          .post(
            Uri.parse('$baseUrl/password/verify-otp'),
            headers: _headers,
            body: jsonEncode({'email': email, 'otp': otp}),
          )
          .timeout(const Duration(seconds: 15));
      final body = jsonDecode(res.body) as Map<String, dynamic>;
      return {
        'success': body['success'] == true,
        'reset_token': body['reset_token'] as String?,
        'message': body['message'] as String? ?? '',
      };
    } on TimeoutException {
      return {'success': false, 'message': 'Request timed out.'};
    } catch (_) {
      return {'success': false, 'message': 'Network error. Please try again.'};
    }
  }

  static Future<Map<String, dynamic>> resetPassword({
    required String email,
    required String resetToken,
    required String password,
    required String passwordConfirmation,
  }) async {
    try {
      final res = await http
          .post(
            Uri.parse('$baseUrl/password/reset'),
            headers: _headers,
            body: jsonEncode({
              'email': email,
              'reset_token': resetToken,
              'password': password,
              'password_confirmation': passwordConfirmation,
            }),
          )
          .timeout(const Duration(seconds: 15));
      final body = jsonDecode(res.body) as Map<String, dynamic>;
      return {
        'success': body['success'] == true,
        'message': body['message'] as String? ?? '',
      };
    } on TimeoutException {
      return {'success': false, 'message': 'Request timed out.'};
    } catch (_) {
      return {'success': false, 'message': 'Network error. Please try again.'};
    }
  }
}
