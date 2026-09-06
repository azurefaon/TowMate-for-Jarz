abstract final class Validators {
  static String? email(String? v) {
    if (v == null || v.trim().isEmpty) return 'Email is required';
    final re = RegExp(r'^[\w.+-]+@[\w-]+\.[a-zA-Z]{2,}$');
    if (!re.hasMatch(v.trim())) return 'Enter a valid email address';
    return null;
  }

  static String? password(String? v) {
    if (v == null || v.isEmpty) return 'Password is required';
    if (v.length < 12) return 'At least 12 characters required';
    if (!RegExp(r'[A-Z]').hasMatch(v)) return 'Must contain an uppercase letter';
    if (!RegExp(r'[a-z]').hasMatch(v)) return 'Must contain a lowercase letter';
    if (!RegExp(r'[0-9]').hasMatch(v)) return 'Must contain a number';
    if (!RegExp(r'[^A-Za-z0-9]').hasMatch(v)) return 'Must contain a special character';
    return null;
  }

  static String? confirmPassword(String? v, String original) {
    if (v == null || v.isEmpty) return 'Please confirm your password';
    if (v != original) return 'Passwords do not match';
    return null;
  }

  static String? name(String? v, [String fieldName = 'Name']) {
    if (v == null || v.trim().isEmpty) return '$fieldName is required';
    if (v.trim().length < 2) return '$fieldName must be at least 2 characters';
    return null;
  }

  static String? phone(String? v) {
    if (v == null || v.trim().isEmpty) return 'Phone number is required';
    final clean = v.trim();
    final re = RegExp(r'^(09\d{9}|(\+63)9\d{9})$');
    if (!re.hasMatch(clean)) return 'Enter a valid PH mobile number (e.g. 09171234567)';
    return null;
  }
}
