import 'dart:async';
import 'dart:io';
import 'package:http/http.dart' as http;

enum ApiErrorKind {
  authenticated401,
  login401,
  forbidden403,
  notFound404,
  conflict409,
  validation422,
  rateLimited429,
  serverError5xx,
  timeout,
  unreachable,
  invalidResponse,
  unknown,
}

class ApiErrorMessages {
  ApiErrorMessages._();

  static const sessionEnded = 'Your session has ended. Please sign in again.';
  static const forbidden = "You don't have permission to do this.";
  static const validation = 'Please check the information you entered.';
  static const rateLimited =
      'Too many attempts. Please wait a moment and try again.';
  static const serverError =
      "We couldn't complete your request right now. Please try again.";
  static const timeout = 'The request took too long. Please try again.';
  static const unreachable =
      'Unable to connect. Check your internet connection and try again.';
  static const invalidResponse = serverError;
  static const incorrectCredentials = 'Incorrect email or password.';
}

class ApiError {
  ApiError._();

  static ApiErrorKind classifyStatus(int status, {bool authenticated = true}) {
    if (status == 401) {
      return authenticated
          ? ApiErrorKind.authenticated401
          : ApiErrorKind.login401;
    }
    if (status == 403) return ApiErrorKind.forbidden403;
    if (status == 404) return ApiErrorKind.notFound404;
    if (status == 409) return ApiErrorKind.conflict409;
    if (status == 422) return ApiErrorKind.validation422;
    if (status == 429) return ApiErrorKind.rateLimited429;
    if (status >= 500 && status < 600) return ApiErrorKind.serverError5xx;
    return ApiErrorKind.unknown;
  }

  static ApiErrorKind classifyException(Object error) {
    if (error is TimeoutException) return ApiErrorKind.timeout;
    if (error is SocketException) return ApiErrorKind.unreachable;
    if (error is http.ClientException) {
      final text = error.message.toLowerCase();
      if (text.contains('timed out') || text.contains('timeout')) {
        return ApiErrorKind.timeout;
      }
      return ApiErrorKind.unreachable;
    }
    if (error is FormatException || error is TypeError) {
      return ApiErrorKind.invalidResponse;
    }
    final text = error.toString().toLowerCase();
    if (text.contains('timed out') || text.contains('timeout')) {
      return ApiErrorKind.timeout;
    }
    if (text.contains('connection refused') ||
        text.contains('connection reset') ||
        text.contains('connection closed') ||
        text.contains('failed host lookup') ||
        text.contains('failed to fetch') ||
        text.contains('network') ||
        text.contains('os error')) {
      return ApiErrorKind.unreachable;
    }
    return ApiErrorKind.unknown;
  }

  static bool endsSession(ApiErrorKind kind) =>
      kind == ApiErrorKind.authenticated401;

  static String message(ApiErrorKind kind) {
    switch (kind) {
      case ApiErrorKind.authenticated401:
        return ApiErrorMessages.sessionEnded;
      case ApiErrorKind.login401:
        return ApiErrorMessages.incorrectCredentials;
      case ApiErrorKind.forbidden403:
        return ApiErrorMessages.forbidden;
      case ApiErrorKind.validation422:
        return ApiErrorMessages.validation;
      case ApiErrorKind.rateLimited429:
        return ApiErrorMessages.rateLimited;
      case ApiErrorKind.timeout:
        return ApiErrorMessages.timeout;
      case ApiErrorKind.unreachable:
        return ApiErrorMessages.unreachable;
      case ApiErrorKind.notFound404:
      case ApiErrorKind.conflict409:
      case ApiErrorKind.serverError5xx:
      case ApiErrorKind.invalidResponse:
      case ApiErrorKind.unknown:
        return ApiErrorMessages.serverError;
    }
  }
}

class ApiException implements Exception {
  ApiException(this.kind, this.message);

  final ApiErrorKind kind;
  final String message;

  @override
  String toString() => message;
}

class ApiMessages {
  ApiMessages._();

  static const _frameworkDefaults = {
    'unauthenticated',
    'unauthorized',
    'this action is unauthorized',
    'forbidden',
    'not found',
    'server error',
    'internal server error',
    'bad request',
    'bad gateway',
    'service unavailable',
    'gateway timeout',
    'too many attempts',
    'too many requests',
    'method not allowed',
    'page expired',
    'the given data was invalid',
    'error',
    'failed',
    'something went wrong',
  };

  static final _technical = RegExp(
    r'(<\s*/?\s*(html|body|!doctype|div|pre|head)|exception|stack trace|sqlstate|\.php|vendor/|illuminate\|#\d+\s)',
    caseSensitive: false,
  );

  static final _statusWording = RegExp(
    r'\b(error|status|http)\b[^.]{0,20}\b[1-5]\d{2}\b|\b[1-5]\d{2}\b[^.]{0,20}\b(error|status)\b',
    caseSensitive: false,
  );

  static bool isSafe(Object? raw) {
    if (raw is! String) return false;
    final text = raw.trim();
    if (text.isEmpty || text.length > 240) return false;
    if (text.startsWith('{') || text.startsWith('[')) return false;
    final normalized = text.toLowerCase().replaceAll(RegExp(r'[.!\s]+$'), '');
    if (_frameworkDefaults.contains(normalized)) return false;
    if (_technical.hasMatch(text)) return false;
    if (_statusWording.hasMatch(text)) return false;
    return true;
  }

  static String? firstValidationError(Object? errors) {
    if (errors is! Map) return null;
    for (final value in errors.values) {
      if (value is List && value.isNotEmpty && isSafe(value.first)) {
        return (value.first as String).trim();
      }
      if (isSafe(value)) return (value as String).trim();
    }
    return null;
  }

  static String forResponse(
    int status,
    Object? body, {
    String? fallback,
    bool authenticated = true,
  }) {
    final map = body is Map ? body : const {};
    final backend = map['message'];

    if (status >= 200 && status < 300) {
      return backend is String ? backend : (fallback ?? '');
    }

    final kind = ApiError.classifyStatus(status, authenticated: authenticated);
    final safe = isSafe(backend) ? (backend as String).trim() : null;

    switch (kind) {
      case ApiErrorKind.authenticated401:
        return ApiErrorMessages.sessionEnded;
      case ApiErrorKind.rateLimited429:
      case ApiErrorKind.serverError5xx:
        return ApiError.message(kind);
      case ApiErrorKind.validation422:
        return safe ??
            firstValidationError(map['errors']) ??
            fallback ??
            ApiErrorMessages.validation;
      default:
        return safe ?? fallback ?? ApiError.message(kind);
    }
  }
}
