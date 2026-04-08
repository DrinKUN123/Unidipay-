import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

class ApiService {
  // Override at run/build time for real devices:
  // flutter run --dart-define=UNIDIPAY_API_BASE=http://192.168.x.x/unidipaypro/php/api/mobile/
  static const String _definedBaseUrl = String.fromEnvironment(
    'UNIDIPAY_API_BASE',
    defaultValue: '',
  );

  static const String _usbReverseBaseUrl =
      'http://127.0.0.1:8080/unidipaypro/php/api/mobile/';
  static const Duration _requestTimeout = Duration(seconds: 6);
  static String? _activeBaseUrl;

  static String _ensureTrailingSlash(String value) {
    return value.endsWith('/') ? value : '$value/';
  }

  static String get configuredBaseUrl {
    final custom = _definedBaseUrl.trim();
    if (custom.isNotEmpty) {
      return _ensureTrailingSlash(custom);
    }
    return _activeBaseUrl ?? _usbReverseBaseUrl;
  }

  List<String> get _candidateBaseUrls {
    final custom = _definedBaseUrl.trim();
    if (custom.isNotEmpty) {
      return [_ensureTrailingSlash(custom)];
    }

    if (_activeBaseUrl != null) {
      return [_activeBaseUrl!];
    }

    return [_usbReverseBaseUrl];
  }

  Future<Map<String, dynamic>> get(
    String endpoint, {
    String? token,
    Map<String, String>? query,
  }) async {
    Exception? lastError;

    for (final base in _candidateBaseUrls) {
      final uri = _buildUri(base, endpoint, query);
      try {
        final response = await http
            .get(uri, headers: _headers(token))
            .timeout(_requestTimeout);
        _activeBaseUrl = base;
        return _decode(response, base);
      } on SocketException catch (e) {
        lastError = Exception('Cannot reach $base: ${e.message}');
      } on TimeoutException {
        lastError = Exception('Request timed out for $base');
      } on http.ClientException catch (e) {
        lastError = Exception('HTTP client error for $base: ${e.message}');
      }
    }

    throw lastError ?? Exception('Unable to reach API server.');
  }

  Future<Map<String, dynamic>> post(
    String endpoint,
    Map<String, dynamic> body, {
    String? token,
  }) async {
    Exception? lastError;

    for (final base in _candidateBaseUrls) {
      final uri = Uri.parse('$base$endpoint');
      try {
        final response = await http
            .post(
              uri,
              headers: _headers(token),
              body: jsonEncode(body),
            )
            .timeout(_requestTimeout);
        _activeBaseUrl = base;
        return _decode(response, base);
      } on SocketException catch (e) {
        lastError = Exception('Cannot reach $base: ${e.message}');
      } on TimeoutException {
        lastError = Exception('Request timed out for $base');
      } on http.ClientException catch (e) {
        lastError = Exception('HTTP client error for $base: ${e.message}');
      }
    }

    throw lastError ?? Exception('Unable to reach API server.');
  }

  Map<String, String> _headers(String? token) {
    final headers = <String, String>{
      'Content-Type': 'application/json',
    };
    if (token != null && token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }
    return headers;
  }

  Map<String, dynamic> _decode(http.Response response, String requestBaseUrl) {
    Map<String, dynamic> data;
    try {
      data = jsonDecode(response.body) as Map<String, dynamic>;
    } catch (_) {
      throw Exception('Invalid server response from $requestBaseUrl');
    }

    if (response.statusCode >= 400 || (data['error'] != null)) {
      throw Exception(data['error']?.toString() ?? 'Request failed');
    }
    return data;
  }

  Uri _buildUri(String base, String endpoint, Map<String, String>? query) {
    final uri = Uri.parse('$base$endpoint');
    if (query == null || query.isEmpty) {
      return uri;
    }

    final mergedQuery = <String, String>{
      ...uri.queryParameters,
      ...query,
    };

    return uri.replace(queryParameters: mergedQuery);
  }
}
