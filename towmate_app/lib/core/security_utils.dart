import 'dart:math';

class CsrfTokenService {
  static String generate() {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    final rng = Random.secure();
    return List.generate(32, (_) => chars[rng.nextInt(chars.length)]).join();
  }
}

class InputSanitizer {
  static String sanitize(String input) => input.trim();
}

class RateLimiter {
  static const int _maxAttempts = 3;
  static const Duration _cooldown = Duration(seconds: 30);

  static int _attempts = 0;
  static DateTime? _lockedAt;

  static bool get isLocked {
    if (_lockedAt == null) return false;
    if (DateTime.now().difference(_lockedAt!) >= _cooldown) {
      reset();
      return false;
    }
    return true;
  }

  static Duration get remainingCooldown {
    if (_lockedAt == null) return Duration.zero;
    final elapsed = DateTime.now().difference(_lockedAt!);
    if (elapsed >= _cooldown) return Duration.zero;
    return _cooldown - elapsed;
  }

  static void recordFailure() {
    _attempts++;
    if (_attempts >= _maxAttempts) {
      _lockedAt = DateTime.now();
    }
  }

  static void reset() {
    _attempts = 0;
    _lockedAt = null;
  }
}
