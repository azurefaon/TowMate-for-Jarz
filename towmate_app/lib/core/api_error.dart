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
