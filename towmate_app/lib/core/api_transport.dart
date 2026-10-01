import 'package:http/http.dart' as http;
import 'session_coordinator.dart';

class SessionAwareClient extends http.BaseClient {
  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final inner = http.Client();
    final http.StreamedResponse streamed;
    final List<int> bytes;
    try {
      streamed = await inner.send(request);
      bytes = await streamed.stream.toBytes();
    } finally {
      inner.close();
    }

    if (streamed.statusCode == 401) {
      final token = bearerTokenOf(request);
      if (token != null) {
        await SessionCoordinator.handleUnauthenticated(token: token);
      }
    }

    return http.StreamedResponse(
      Stream<List<int>>.value(bytes),
      streamed.statusCode,
      contentLength: bytes.length,
      request: streamed.request,
      headers: streamed.headers,
      isRedirect: streamed.isRedirect,
      persistentConnection: streamed.persistentConnection,
      reasonPhrase: streamed.reasonPhrase,
    );
  }

  static String? bearerTokenOf(http.BaseRequest request) {
    final header = request.headers['Authorization'];
    if (header == null || !header.startsWith('Bearer ')) return null;
    final token = header.substring(7).trim();
    if (token.isEmpty || token == 'null') return null;
    return token;
  }
}

final http.Client apiClient = SessionAwareClient();
