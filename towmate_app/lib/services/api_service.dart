import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
import 'package:flutter/foundation.dart' show debugPrint, kDebugMode, kIsWeb;
import 'package:flutter_image_compress/flutter_image_compress.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;
import '../core/api_error.dart';
import '../core/api_transport.dart';
import 'package:http_parser/http_parser.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../core/session_coordinator.dart';
import '../models/booking_model.dart';
import '../models/quotation_model.dart';
import '../models/truck_type_model.dart';
import '../models/vehicle_category_model.dart';
import '../models/vehicle_type_model.dart';

class ApiService {
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://127.0.0.1:8000/api',
  );

  static const _secure = FlutterSecureStorage(
    aOptions: AndroidOptions(
      encryptedSharedPreferences: true,
      resetOnError: true,
    ),
  );

  static const _headers = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  };

  static String? _cachedToken;

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
    _cachedToken = token;
    SessionCoordinator.reset();

    try {
      await _secure.write(key: 'auth_token', value: token);
      if (email != null) await _secure.write(key: 'user_email', value: email);
      if (phone != null) await _secure.write(key: 'user_phone', value: phone);
    } catch (_) {}

    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('auth_token');
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
    _cachedToken = null;

    try {
      await _secure.delete(key: 'auth_token');
      await _secure.delete(key: 'user_email');
      await _secure.delete(key: 'user_phone');
    } catch (_) {}

    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('auth_token');
    await prefs.remove('user_role');
    await prefs.remove('user_name');
    await prefs.remove('user_first_name');
    await prefs.remove('user_middle_name');
    await prefs.remove('user_last_name');
    await prefs.remove('user_id');
    await prefs.remove('must_change_password');
    await prefs.remove('duty_class');
    await prefs.remove('user_auth_provider');

    unawaited(clearBookingDraft());
  }

  static const _draftKey = 'booking_draft_v1';

  static Future<void> saveBookingDraft(Map<String, dynamic> data) async {
    final prefs = await SharedPreferences.getInstance();
    final userId = prefs.getInt('user_id');
    if (userId == null) return;

    final payload = {
      ...data,
      'user_id': userId,
      'saved_at': DateTime.now().toIso8601String(),
    };

    try {
      await prefs.setString(_draftKey, jsonEncode(payload));
    } catch (_) {}
  }

  static Future<Map<String, dynamic>?> loadBookingDraft() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_draftKey);
    if (raw == null) return null;

    try {
      final decoded = jsonDecode(raw) as Map<String, dynamic>;
      final userId = prefs.getInt('user_id');
      if (userId == null || decoded['user_id'] != userId) {
        await clearBookingDraft();
        return null;
      }
      return decoded;
    } catch (_) {
      await clearBookingDraft();
      return null;
    }
  }

  static Future<void> clearBookingDraft() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_draftKey);
    await clearDraftPhotos();
  }

  static Future<Directory> _draftPhotosDirPath() async {
    final base = await getApplicationDocumentsDirectory();
    return Directory('${base.path}/booking_draft_photos');
  }

  static Future<String?> persistDraftImage(String sourcePath) async {
    try {
      final source = File(sourcePath);
      if (!await source.exists()) return null;

      final dir = await _draftPhotosDirPath();
      if (!await dir.exists()) await dir.create(recursive: true);

      final ext = sourcePath.contains('.') ? sourcePath.split('.').last : 'jpg';
      final target = File(
        '${dir.path}/${DateTime.now().microsecondsSinceEpoch}.$ext',
      );
      await source.copy(target.path);
      return target.path;
    } catch (_) {
      return null;
    }
  }

  static Future<void> clearDraftPhotos() async {
    try {
      final dir = await _draftPhotosDirPath();
      if (await dir.exists()) await dir.delete(recursive: true);
    } catch (_) {}
  }

  static Future<bool> getMustChangePassword() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool('must_change_password') ?? false;
  }

  static Future<String?> getToken() async {
    if (_cachedToken != null) return _cachedToken;

    try {
      final token = await _secure.read(key: 'auth_token');
      if (token != null) {
        _cachedToken = token;
        return token;
      }
    } catch (_) {}

    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('auth_token');
    if (token != null) _cachedToken = token;
    return token;
  }

  static Future<String?> getUserRole() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('user_role');
  }

  static Future<String?> getUserName() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('user_name');
  }

  static Future<String?> getUserFirstName() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('user_first_name');
  }

  static Future<String?> getUserMiddleName() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('user_middle_name');
  }

  static Future<String?> getUserLastName() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('user_last_name');
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

  static Future<Map<String, dynamic>> updateProfile({
    required String firstName,
    required String lastName,
    String? middleName,
    String? phone,
  }) async {
    try {
      final token = await getToken();
      final response = await apiClient
          .post(
            Uri.parse('$baseUrl/v1/profile/update'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
            body: jsonEncode({
              'first_name': firstName,
              'last_name': lastName,
              'middle_name': ?middleName,
              if (phone != null) 'phone': phone,
            }),
          )
          .timeout(const Duration(seconds: 15));

      final body = jsonDecode(response.body) as Map<String, dynamic>;
      if (response.statusCode == 200 && body['success'] == true) {
        final data = body['data'] as Map<String, dynamic>?;
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString('user_first_name', firstName);
        await prefs.setString('user_last_name', lastName);
        if (middleName != null) {
          if (middleName.trim().isEmpty) {
            await prefs.remove('user_middle_name');
          } else {
            await prefs.setString('user_middle_name', middleName.trim());
          }
        }
        if (data?['name'] != null) {
          await prefs.setString('user_name', data!['name'] as String);
        }

        if (phone != null) {
          await _secure.write(key: 'user_phone', value: phone);
        }

        return {'success': true};
      }
      return {
        'success': false,
        'message': ApiMessages.forResponse(response.statusCode, body, fallback: 'Failed to update name.'),
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Uint8List?> fetchProfileImage() async {
    try {
      final token = await getToken();
      if (token == null || token.isEmpty) return null;
      final response = await apiClient
          .get(
            Uri.parse('$baseUrl/v1/profile/image'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 15));
      if (response.statusCode == 200) return response.bodyBytes;
    } catch (_) {}
    return null;
  }

  static Future<Map<String, dynamic>> updateProfileImage(XFile image) async {
    try {
      final token = await getToken();
      if (token == null || token.isEmpty) {
        return {
          'success': false,
          'message': 'Your session has expired. Please sign in again.',
        };
      }
      final bytes = await image.readAsBytes();
      final extension = image.name.toLowerCase().split('.').last;
      final mediaType = switch (extension) {
        'jpg' || 'jpeg' => MediaType('image', 'jpeg'),
        'png' => MediaType('image', 'png'),
        'webp' => MediaType('image', 'webp'),
        _ => null,
      };
      if (mediaType == null) {
        return {
          'success': false,
          'message': 'Please choose a JPG, PNG, or WEBP image.',
        };
      }
      final request =
          http.MultipartRequest('POST', Uri.parse('$baseUrl/v1/profile/image'))
            ..headers['Accept'] = 'application/json'
            ..headers['Authorization'] = 'Bearer $token'
            ..files.add(
              http.MultipartFile.fromBytes(
                'profile_image',
                bytes,
                filename: image.name,
                contentType: mediaType,
              ),
            );
      final response = await apiClient.send(request).timeout(
        const Duration(seconds: 30),
      );
      final responseBody = await response.stream.bytesToString();
      Map<String, dynamic>? body;
      try {
        body = jsonDecode(responseBody) as Map<String, dynamic>;
      } on FormatException {
        body = null;
      }
      final succeeded = response.statusCode == 200 && body?['success'] == true;
      return {
        'success': succeeded,
        'message': succeeded
            ? (body?['message'] as String? ?? '')
            : _profileImageFailureMessage(response.statusCode, body),
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static String _profileImageFailureMessage(
    int status,
    Map<String, dynamic>? body,
  ) {
    if (status != 422) {
      return ApiMessages.forResponse(
        status,
        body,
        fallback: 'Could not update your profile photo.',
      );
    }
    final errors = body?['errors'];
    final imageErrors = errors is Map ? errors['profile_image'] : null;
    final detail = imageErrors is List && imageErrors.isNotEmpty
        ? imageErrors.first.toString().toLowerCase()
        : '';
    if (detail.contains('greater than') ||
        detail.contains('may not be') ||
        detail.contains('too large') ||
        detail.contains('kilobytes')) {
      return 'Please choose an image up to 5 MB.';
    }
    return ApiMessages.forResponse(status, body);
  }

  static Future<Map<String, dynamic>> requestEmailChangeOtp(
    String email,
  ) async {
    try {
      final token = await getToken();
      final response = await apiClient
          .post(
            Uri.parse('$baseUrl/v1/profile/email/request-otp'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
            body: jsonEncode({'email': email}),
          )
          .timeout(const Duration(seconds: 15));

      final body = jsonDecode(response.body) as Map<String, dynamic>;
      return {
        'success': response.statusCode == 200 && body['success'] == true,
        'message': ApiMessages.forResponse(response.statusCode, body),
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> confirmEmailChange(
    String email,
    String otp,
  ) async {
    try {
      final token = await getToken();
      final response = await apiClient
          .post(
            Uri.parse('$baseUrl/v1/profile/email/confirm'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
            body: jsonEncode({'email': email, 'otp': otp}),
          )
          .timeout(const Duration(seconds: 15));

      final body = jsonDecode(response.body) as Map<String, dynamic>;
      if (response.statusCode == 200 && body['success'] == true) {
        await _secure.write(key: 'user_email', value: email);
        return {'success': true};
      }
      return {
        'success': false,
        'message': ApiMessages.forResponse(response.statusCode, body, fallback: 'Failed to update email.'),
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> changePassword({
    required String currentPassword,
    required String newPassword,
  }) async {
    try {
      final token = await getToken();
      final response = await apiClient
          .post(
            Uri.parse('$baseUrl/v1/profile/change-password'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
            body: jsonEncode({
              'current_password': currentPassword,
              'new_password': newPassword,
              'new_password_confirmation': newPassword,
            }),
          )
          .timeout(const Duration(seconds: 15));

      final body = jsonDecode(response.body) as Map<String, dynamic>;
      return {
        'success': response.statusCode == 200 && body['success'] == true,
        'message': ApiMessages.forResponse(response.statusCode, body),
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> acceptTerms() async {
    try {
      final token = await getToken();
      final response = await apiClient
          .post(
            Uri.parse('$baseUrl/auth/accept-terms'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 15));

      final body = jsonDecode(response.body) as Map<String, dynamic>;
      final accepted = response.statusCode == 200 && body['success'] == true;
      return {
        'success': accepted,
        'message': accepted
            ? body['message'] as String?
            : ApiMessages.forResponse(response.statusCode, body),
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

  static Map<String, dynamic> _networkError(Object e) {
    return {
      'success': false,
      'transport_error': true,
      'message': ApiError.message(ApiError.classifyException(e)),
    };
  }

  static Future<Map<String, dynamic>> login(
    String email,
    String password,
    String csrfToken,
  ) async {
    try {
      final response = await apiClient
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
          'requires_terms_acceptance': body['requires_terms_acceptance'] == true,
        };
      }

      return {
        'success': false,
        // Server-side trouble (5xx / throttled) is not a wrong password and
        // must not count toward the client-side credential lockout.
        if (response.statusCode >= 500 || response.statusCode == 429)
          'transport_error': true,
        'message':
            ApiMessages.forResponse(response.statusCode, body, fallback: 'Login failed. Check your credentials.', authenticated: false),
      };
    } on TimeoutException {
      return {
        'success': false,
        'transport_error': true,
        'message': 'Request timed out. Please try again.',
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> loginWithGoogle(
    String idToken,
    String csrfToken,
  ) async {
    http.Response? response;
    try {
      response = await apiClient
          .post(
            Uri.parse('$baseUrl/auth/google'),
            headers: {..._headers, 'X-CSRF-Token': csrfToken},
            body: jsonEncode({'id_token': idToken}),
          )
          .timeout(const Duration(seconds: 15));

      final body = jsonDecode(response.body) as Map<String, dynamic>;

      if (response.statusCode == 200 && body['success'] == true) {
        final data = body['data'] as Map<String, dynamic>;
        final user = data['user'] as Map<String, dynamic>;

        await saveSession(
          token: data['token'] as String,
          role: user['role'] as String? ?? 'Customer',
          name: user['name'] as String? ?? '',
          userId: (user['id'] as num?)?.toInt() ?? 0,
          email: user['email'] as String?,
          phone: user['phone'] as String?,
          dutyClass: user['duty_class'] as String?,
        );

        return {
          'success': true,
          'needsPhone': false,
          'role': user['role'] as String? ?? 'Customer',
          'requires_terms_acceptance': body['requires_terms_acceptance'] == true,
        };
      }

      if (response.statusCode == 202 && body['needs_phone'] == true) {
        return {
          'success': true,
          'needsPhone': true,
          'completionToken': body['completion_token'] as String,
          'firstName': body['first_name'] as String? ?? '',
          'lastName': body['last_name'] as String? ?? '',
        };
      }

      return {
        'success': false,
        if (response.statusCode >= 500 || response.statusCode == 429)
          'transport_error': true,
        'message': ApiMessages.forResponse(response.statusCode, body, fallback: 'Google sign-in failed.', authenticated: false),
      };
    } on TimeoutException {
      return {
        'success': false,
        'transport_error': true,
        'message': 'Request timed out. Please try again.',
      };
    } catch (e) {
      if (kDebugMode) {
        debugPrint(
          '[GoogleAuth] loginWithGoogle failed: ${e.runtimeType}, '
          'idTokenPresent=${idToken.isNotEmpty}, '
          'httpStatus=${response?.statusCode ?? 'no response'}',
        );
      }
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> completeGoogleSignup({
    required String completionToken,
    required String firstName,
    String? middleName,
    required String lastName,
    required String phone,
    required String csrfToken,
    required bool acceptTerms,
  }) async {
    try {
      final response = await apiClient
          .post(
            Uri.parse('$baseUrl/auth/google/complete'),
            headers: {..._headers, 'X-CSRF-Token': csrfToken},
            body: jsonEncode({
              'completion_token': completionToken,
              'first_name': firstName,
              if (middleName != null && middleName.trim().isNotEmpty)
                'middle_name': middleName.trim(),
              'last_name': lastName,
              'phone': phone,
              'accept_terms': acceptTerms,
            }),
          )
          .timeout(const Duration(seconds: 15));

      final body = jsonDecode(response.body) as Map<String, dynamic>;

      if (response.statusCode == 201 && body['success'] == true) {
        final data = body['data'] as Map<String, dynamic>;
        final user = data['user'] as Map<String, dynamic>;

        await saveSession(
          token: data['token'] as String,
          role: user['role'] as String? ?? 'Customer',
          name: user['name'] as String? ?? '',
          userId: (user['id'] as num?)?.toInt() ?? 0,
          email: user['email'] as String?,
          phone: user['phone'] as String?,
          dutyClass: user['duty_class'] as String?,
        );

        return {'success': true, 'role': user['role'] as String? ?? 'Customer'};
      }

      return {
        'success': false,
        'message': ApiMessages.forResponse(response.statusCode, body, fallback: 'Could not complete sign-up.', authenticated: false),
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
    String? middleName,
    required String lastName,
    required String email,
    required String phone,
    required String password,
    required String confirmPassword,
    required String csrfToken,
    required bool acceptTerms,
  }) async {
    try {
      final response = await apiClient
          .post(
            Uri.parse('$baseUrl/register'),
            headers: {..._headers, 'X-CSRF-Token': csrfToken},
            body: jsonEncode({
              'first_name': firstName,
              if (middleName != null && middleName.trim().isNotEmpty)
                'middle_name': middleName.trim(),
              'last_name': lastName,
              'email': email.trim().toLowerCase(),
              'phone': phone,
              'password': password,
              'password_confirmation': confirmPassword,
              'accept_terms': acceptTerms,
            }),
          )
          .timeout(const Duration(seconds: 15));

      final body = jsonDecode(response.body) as Map<String, dynamic>;

      if ((response.statusCode == 200 || response.statusCode == 201) &&
          body['success'] == true) {
        final data = body['data'] as Map<String, dynamic>?;
        if (data != null) {
          final user = data['user'] as Map<String, dynamic>;
          await saveSession(
            token: data['token'] as String,
            role: user['role'] as String? ?? 'Customer',
            name: user['name'] as String? ?? '',
            userId: (user['id'] as num?)?.toInt() ?? 0,
            email: user['email'] as String?,
            phone: user['phone'] as String?,
          );
        }
        return {'success': true};
      }

      final errors = body['errors'] as Map<String, dynamic>?;
      if (errors != null && errors.isNotEmpty) {
        final firstList = errors.values.first;
        final msg = (firstList is List && firstList.isNotEmpty)
            ? firstList.first as String
            : ApiMessages.forResponse(response.statusCode, body, fallback: 'Registration failed.', authenticated: false);
        return {'success': false, 'message': msg};
      }

      return {
        'success': false,
        'message':
            ApiMessages.forResponse(response.statusCode, body, fallback: 'Registration failed. Please try again.', authenticated: false),
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

  static Future<Map<String, dynamic>> validateSession() async {
    try {
      final token = await getToken();
      final response = await apiClient
          .get(
            Uri.parse('$baseUrl/v1/profile'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 10));

      if (response.statusCode == 401) {
        await SessionCoordinator.handleUnauthenticated(token: token);
        return {'success': false, 'invalid_session': true};
      }

      if (response.statusCode == 200) {
        final body = jsonDecode(response.body) as Map<String, dynamic>;
        return {
          'success': true,
          'requires_terms_acceptance':
              body['requires_terms_acceptance'] == true,
        };
      }

      return {'success': false, 'transport_error': true};
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<void> fetchAndCacheProfile() async {
    try {
      final token = await getToken();
      final response = await apiClient
          .get(
            Uri.parse('$baseUrl/v1/profile'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 10));

      if (response.statusCode == 401) {
        await SessionCoordinator.handleUnauthenticated(token: token);
        return;
      }

      if (response.statusCode == 200) {
        final body = jsonDecode(response.body) as Map<String, dynamic>;
        final data = body['data'] as Map<String, dynamic>?;
        if (data != null) {
          final prefs = await SharedPreferences.getInstance();
          if (data['name'] != null) {
            await prefs.setString('user_name', data['name'] as String);
          }
          if (data['first_name'] != null) {
            await prefs.setString(
              'user_first_name',
              data['first_name'] as String,
            );
          }
          if (data.containsKey('middle_name')) {
            final middle = data['middle_name'] as String?;
            if (middle == null || middle.trim().isEmpty) {
              await prefs.remove('user_middle_name');
            } else {
              await prefs.setString('user_middle_name', middle);
            }
          }
          if (data['last_name'] != null) {
            await prefs.setString(
              'user_last_name',
              data['last_name'] as String,
            );
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
          if (data['auth_provider'] != null) {
            await prefs.setString(
              'user_auth_provider',
              data['auth_provider'] as String,
            );
          }
        }
      }
    } catch (_) {}
  }

  static Future<String?> getUserAuthProvider() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('user_auth_provider');
  }

  static Future<List<TruckTypeModel>> fetchTruckTypes() async {
    try {
      final token = await getToken();
      final response = await apiClient
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

  static Future<List<VehicleTypeModel>> fetchVehicleTypes() async {
    try {
      final token = await getToken();
      final response = await apiClient
          .get(
            Uri.parse('$baseUrl/v1/vehicle-types'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 15));

      if (response.statusCode == 200) {
        final list = jsonDecode(response.body) as List;
        return list
            .map((j) => VehicleTypeModel.fromJson(j as Map<String, dynamic>))
            .toList();
      }
      return [];
    } catch (_) {
      return [];
    }
  }

  static Future<List<VehicleCategoryModel>> fetchVehicleCategories() async {
    try {
      final token = await getToken();
      final response = await apiClient
          .get(
            Uri.parse('$baseUrl/v1/vehicle-categories'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 15));

      if (response.statusCode == 200) {
        final list = jsonDecode(response.body) as List;
        return list
            .map(
              (j) => VehicleCategoryModel.fromJson(j as Map<String, dynamic>),
            )
            .toList();
      }
      return [];
    } catch (_) {
      return [];
    }
  }

  static Future<Map<String, dynamic>?> fetchPricingPreview({
    required int vehicleTypeId,
    required double pickupLat,
    required double pickupLng,
    required double dropoffLat,
    required double dropoffLng,
    required String serviceType,
    List<Map<String, dynamic>> extraVehicles = const [],
  }) async {
    try {
      final token = await getToken();
      final response = await apiClient
          .post(
            Uri.parse('$baseUrl/v1/geo/pricing-preview'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
            body: jsonEncode({
              'vehicle_type_id': vehicleTypeId,
              'pickup_lat': pickupLat,
              'pickup_lng': pickupLng,
              'drop_lat': dropoffLat,
              'drop_lng': dropoffLng,
              'service_type': serviceType,
              if (extraVehicles.isNotEmpty) 'extra_vehicles': extraVehicles,
            }),
          )
          .timeout(const Duration(seconds: 15));
      if (response.statusCode == 200) {
        return jsonDecode(response.body) as Map<String, dynamic>;
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  static Future<Map<String, dynamic>?> fetchAvailability() async {
    try {
      final token = await getToken();
      final res = await apiClient
          .get(
            Uri.parse('$baseUrl/v1/availability'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 10));
      if (res.statusCode == 200) {
        return jsonDecode(res.body) as Map<String, dynamic>;
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  static Future<Map<String, dynamic>?> fetchCustomerContent() async {
    final uri = Uri.parse('$baseUrl/v1/customer/content');
    http.Response? res;
    try {
      res = await apiClient
          .get(uri, headers: _headers)
          .timeout(const Duration(seconds: 20));
      if (res.statusCode == 200) {
        return jsonDecode(res.body) as Map<String, dynamic>;
      }
      _debugFetchFailure('fetchCustomerContent', uri, null, res.statusCode);
      return null;
    } catch (e) {
      _debugFetchFailure('fetchCustomerContent', uri, e, res?.statusCode);
      return null;
    }
  }

  static Future<List<Map<String, dynamic>>> fetchVehicleTypesByCategory(
    String category,
  ) async {
    final uri = Uri.parse('$baseUrl/v1/vehicle-types/by-category/$category');
    http.Response? res;
    try {
      res = await apiClient
          .get(uri, headers: _headers)
          .timeout(const Duration(seconds: 20));
      if (res.statusCode == 200) {
        final data = jsonDecode(res.body) as Map<String, dynamic>;
        final list = data['vehicleTypes'] as List? ?? [];
        return list.cast<Map<String, dynamic>>();
      }
      _debugFetchFailure(
        'fetchVehicleTypesByCategory',
        uri,
        null,
        res.statusCode,
      );
      return [];
    } catch (e) {
      _debugFetchFailure(
        'fetchVehicleTypesByCategory',
        uri,
        e,
        res?.statusCode,
      );
      return [];
    }
  }

  static void _debugFetchFailure(
    String stage,
    Uri uri,
    Object? error,
    int? statusCode,
  ) {
    if (!kDebugMode) return;
    debugPrint(
      '[ApiService] $stage failed: '
      'host=${uri.host}:${uri.port}, path=${uri.path}, method=GET, '
      'responseReceived=${statusCode != null}, httpStatus=${statusCode ?? 'none'}, '
      'exceptionType=${error?.runtimeType ?? 'none'}',
    );
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

  static Future<List<Map<String, dynamic>>> autocompleteAddress(
    String query,
  ) async {
    try {
      final token = await getToken();
      final uri = Uri.parse(
        '$baseUrl/v1/geo/autocomplete',
      ).replace(queryParameters: {'q': query});
      final response = await apiClient
          .get(uri, headers: {..._headers, 'Authorization': 'Bearer $token'})
          .timeout(const Duration(seconds: 10));
      if (response.statusCode == 200) {
        final body = jsonDecode(response.body) as Map<String, dynamic>;
        return (body['suggestions'] as List? ?? [])
            .cast<Map<String, dynamic>>();
      }
      return [];
    } catch (_) {
      return [];
    }
  }

  static Future<Map<String, dynamic>?> resolvePlaceDetails(
    String placeId,
  ) async {
    try {
      final token = await getToken();
      final uri = Uri.parse(
        '$baseUrl/v1/geo/place-details',
      ).replace(queryParameters: {'place_id': placeId});
      final response = await apiClient
          .get(uri, headers: {..._headers, 'Authorization': 'Bearer $token'})
          .timeout(const Duration(seconds: 10));
      if (response.statusCode == 200) {
        return jsonDecode(response.body) as Map<String, dynamic>;
      }
      return null;
    } catch (_) {
      return null;
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
      final response = await apiClient
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

  static Future<Map<String, dynamic>> cancelBooking(String code) async {
    try {
      final token = await getToken();
      final response = await apiClient
          .post(
            Uri.parse('$baseUrl/v1/bookings/$code/cancel'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 15));
      final body = jsonDecode(response.body) as Map<String, dynamic>;
      return {
        'success': response.statusCode == 200 && body['success'] == true,
        'message': ApiMessages.forResponse(response.statusCode, body, fallback: ''),
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> cancelGroupBookings(
    String groupCode,
    List<String> bookingCodes, {
    String? reason,
  }) async {
    try {
      final token = await getToken();
      final response = await apiClient
          .post(
            Uri.parse('$baseUrl/v1/bookings/group/$groupCode/cancel'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
            body: jsonEncode({
              'booking_codes': bookingCodes,
              if (reason != null && reason.isNotEmpty) 'reason': reason,
            }),
          )
          .timeout(const Duration(seconds: 15));
      final body = jsonDecode(response.body) as Map<String, dynamic>;
      return {
        'success': response.statusCode == 200 && body['success'] == true,
        'message': ApiMessages.forResponse(response.statusCode, body, fallback: ''),
        'invalid_booking_codes': body['invalid_booking_codes'],
        'ineligible': body['ineligible'],
        'cancelled_booking_codes': body['cancelled_booking_codes'],
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<BookingModel?> fetchBookingDetail(String code) async {
    try {
      final token = await getToken();
      final response = await apiClient
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

  static Future<String?> fetchReceiptUrl(String code) async {
    try {
      final token = await getToken();
      final response = await apiClient
          .get(
            Uri.parse('$baseUrl/v1/bookings/$code/receipt'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 15));
      if (response.statusCode == 200) {
        final body = jsonDecode(response.body) as Map<String, dynamic>;
        if (body['success'] == true) {
          return (body['data'] as Map<String, dynamic>)['pdf_url'] as String?;
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
      final response = await apiClient
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

  static Future<String?> checkDuplicateActiveRoute({
    required double pickupLat,
    required double pickupLng,
    required double dropoffLat,
    required double dropoffLng,
    required String serviceType,
  }) async {
    try {
      final token = await getToken();
      final response = await apiClient
          .post(
            Uri.parse('$baseUrl/v1/bookings/check-duplicate-route'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
            body: jsonEncode({
              'pickup_lat': pickupLat,
              'pickup_lng': pickupLng,
              'dropoff_lat': dropoffLat,
              'dropoff_lng': dropoffLng,
              'service_type': serviceType,
            }),
          )
          .timeout(const Duration(seconds: 10));
      if (response.statusCode == 422) {
        final body = jsonDecode(response.body) as Map<String, dynamic>;
        return body['message'] as String?;
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
    Map<int, List<String>> extraVehicleImagePaths = const {},
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

      for (int i = 0; i < vehicleImagePaths.length; i++) {
        final path = vehicleImagePaths[i];
        List<int> bytes;
        if (kIsWeb) {
          bytes = await XFile(path).readAsBytes();
        } else {
          final compressed = await FlutterImageCompress.compressWithFile(
            path,
            quality: 70,
            minWidth: 1280,
            minHeight: 1280,
            keepExif: false,
          );
          bytes = compressed ?? await XFile(path).readAsBytes();
        }
        req.files.add(
          http.MultipartFile.fromBytes(
            'vehicle_images[]',
            bytes,
            filename: 'vehicle_$i.jpg',
            contentType: MediaType('image', 'jpeg'),
          ),
        );
      }

      for (final entry in extraVehicleImagePaths.entries) {
        final slotIndex = entry.key;
        final paths = entry.value;
        for (int i = 0; i < paths.length; i++) {
          final path = paths[i];
          List<int> bytes;
          if (kIsWeb) {
            bytes = await XFile(path).readAsBytes();
          } else {
            final compressed = await FlutterImageCompress.compressWithFile(
              path,
              quality: 70,
              minWidth: 1280,
              minHeight: 1280,
              keepExif: false,
            );
            bytes = compressed ?? await XFile(path).readAsBytes();
          }
          req.files.add(
            http.MultipartFile.fromBytes(
              'extra_vehicle_images[$slotIndex][]',
              bytes,
              filename: 'extra_${slotIndex}_$i.jpg',
              contentType: MediaType('image', 'jpeg'),
            ),
          );
        }
      }

      final streamed = await apiClient.send(req).timeout(const Duration(seconds: 30));
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
        final bookings =
            (body['bookings'] as List?)
                ?.map(
                  (e) =>
                      BookingGroupSibling.fromJson(e as Map<String, dynamic>),
                )
                .toList() ??
            const <BookingGroupSibling>[];
        return {
          'success': true,
          'booking_code': body['booking_code'],
          'group_code': body['group_code'],
          'bookings': bookings,
        };
      }
      return {
        'success': false,
        'message':
            ApiMessages.forResponse(response.statusCode, body, fallback: 'Booking failed. Please try again.'),
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
      final url =
          'https://router.project-osrm.org/route/v1/driving/$lng1,$lat1;$lng2,$lat2'
          '?overview=full&geometries=geojson';
      final osrmResp = await http
          .get(Uri.parse(url), headers: {'User-Agent': 'TowMate/1.0'})
          .timeout(const Duration(seconds: 12));

      if (osrmResp.statusCode == 200) {
        final body = jsonDecode(osrmResp.body) as Map<String, dynamic>;
        if (body['code'] == 'Ok') {
          final route = (body['routes'] as List).first as Map;
          final coords = (route['geometry']['coordinates'] as List)
              .map((c) => [(c[1] as num).toDouble(), (c[0] as num).toDouble()])
              .toList();
          if (coords.length >= 2) {
            return {
              'success': true,
              'distance_km': (route['distance'] as num).toDouble() / 1000.0,
              'duration_min': (route['duration'] as num).toDouble() / 60.0,
              'coordinates': coords,
            };
          }
        }
      }
    } catch (_) {}

    try {
      final token = await getToken();
      final response = await apiClient
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
        final body = jsonDecode(response.body) as Map<String, dynamic>;
        if (body['is_fallback'] == true) {
          return {'success': false};
        }
        return {...body, 'success': true};
      }
      return {'success': false};
    } on TimeoutException {
      return {'success': false};
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
      await apiClient
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
      final res = await apiClient
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
      final res = await apiClient
          .post(
            Uri.parse('$baseUrl/v1/quotations/$id/accept'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 15));
      final body = jsonDecode(res.body) as Map<String, dynamic>;
      return {
        'success': res.statusCode == 200 && body['success'] == true,
        'message': ApiMessages.forResponse(res.statusCode, body, fallback: ''),
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> rejectQuotation(
    int id, {
    String? reason,
  }) async {
    try {
      final token = await getToken();
      final res = await apiClient
          .post(
            Uri.parse('$baseUrl/v1/quotations/$id/reject'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
            body: jsonEncode({'reason': reason}),
          )
          .timeout(const Duration(seconds: 15));
      final body = jsonDecode(res.body) as Map<String, dynamic>;
      return {
        'success': res.statusCode == 200 && body['success'] == true,
        'message': ApiMessages.forResponse(res.statusCode, body, fallback: ''),
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> requestPriceReview(
    int id,
    String reason,
  ) async {
    try {
      final token = await getToken();
      final res = await apiClient
          .post(
            Uri.parse('$baseUrl/v1/quotations/$id/request-price-review'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
            body: jsonEncode({'reason': reason}),
          )
          .timeout(const Duration(seconds: 15));
      final body = jsonDecode(res.body) as Map<String, dynamic>;
      return {
        'success': res.statusCode == 200 && body['success'] == true,
        'message': ApiMessages.forResponse(res.statusCode, body, fallback: ''),
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> sendQuotationInquiry(
    int id,
    String message,
  ) async {
    try {
      final token = await getToken();
      final res = await apiClient
          .post(
            Uri.parse('$baseUrl/v1/quotations/$id/inquire'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
            body: jsonEncode({'message': message}),
          )
          .timeout(const Duration(seconds: 15));
      final body = jsonDecode(res.body) as Map<String, dynamic>;
      return {
        'success': res.statusCode == 200 && body['success'] == true,
        'message': ApiMessages.forResponse(res.statusCode, body, fallback: ''),
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> sendRegistrationOtp(String email) async {
    try {
      final res = await apiClient
          .post(
            Uri.parse('$baseUrl/register/send-otp'),
            headers: _headers,
            body: jsonEncode({'email': email}),
          )
          .timeout(const Duration(seconds: 45));
      final body = jsonDecode(res.body) as Map<String, dynamic>;
      return {
        'success': body['success'] == true,
        'message': ApiMessages.forResponse(res.statusCode, body, fallback: '', authenticated: false),
      };
    } on TimeoutException {
      return {
        'success': false,
        'message':
            'Request timed out. Please check your connection and try again.',
      };
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> verifyRegistrationOtp(
    String email,
    String otp,
  ) async {
    try {
      final res = await apiClient
          .post(
            Uri.parse('$baseUrl/register/verify-otp'),
            headers: _headers,
            body: jsonEncode({'email': email, 'otp': otp}),
          )
          .timeout(const Duration(seconds: 15));
      final body = jsonDecode(res.body) as Map<String, dynamic>;
      return {
        'success': body['success'] == true,
        'message': ApiMessages.forResponse(res.statusCode, body, fallback: '', authenticated: false),
      };
    } on TimeoutException {
      return {'success': false, 'message': 'Request timed out.'};
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> sendResetOtp(String email) async {
    try {
      final res = await apiClient
          .post(
            Uri.parse('$baseUrl/password/forgot'),
            headers: _headers,
            body: jsonEncode({'email': email}),
          )
          .timeout(const Duration(seconds: 45));
      final body = jsonDecode(res.body) as Map<String, dynamic>;
      return {
        'success': body['success'] == true,
        'message': ApiMessages.forResponse(res.statusCode, body, fallback: '', authenticated: false),
      };
    } on TimeoutException {
      return {'success': false, 'message': 'Request timed out.'};
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> verifyResetOtp(
    String email,
    String otp,
  ) async {
    try {
      final res = await apiClient
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
        'message': ApiMessages.forResponse(res.statusCode, body, fallback: '', authenticated: false),
      };
    } on TimeoutException {
      return {'success': false, 'message': 'Request timed out.'};
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> resetPassword({
    required String email,
    required String resetToken,
    required String password,
    required String passwordConfirmation,
  }) async {
    try {
      final res = await apiClient
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
        'message': ApiMessages.forResponse(res.statusCode, body, fallback: '', authenticated: false),
      };
    } on TimeoutException {
      return {'success': false, 'message': 'Request timed out.'};
    } catch (e) {
      return _networkError(e);
    }
  }

  static Future<Map<String, dynamic>> fetchNotifications() async {
    try {
      final token = await getToken();
      final res = await apiClient
          .get(
            Uri.parse('$baseUrl/v1/notifications'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 15));
      final body = jsonDecode(res.body) as Map<String, dynamic>;
      if (res.statusCode == 200 && body['success'] == true) {
        return {
          'success': true,
          'unread_count': (body['unread_count'] as num?)?.toInt() ?? 0,
          'notifications': body['data'] as List<dynamic>? ?? [],
        };
      }
      return {'success': false, 'unread_count': 0, 'notifications': []};
    } on TimeoutException {
      return {'success': false, 'unread_count': 0, 'notifications': []};
    } catch (_) {
      return {'success': false, 'unread_count': 0, 'notifications': []};
    }
  }

  static Future<void> markAllNotificationsRead() async {
    try {
      final token = await getToken();
      await apiClient
          .post(
            Uri.parse('$baseUrl/v1/notifications/mark-read'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 10));
    } catch (_) {}
  }

  static Future<void> markNotificationRead(int id) async {
    try {
      final token = await getToken();
      await apiClient
          .post(
            Uri.parse('$baseUrl/v1/notifications/$id/read'),
            headers: {..._headers, 'Authorization': 'Bearer $token'},
          )
          .timeout(const Duration(seconds: 10));
    } catch (_) {}
  }
}
