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
  static const String _definedLanFallbackBaseUrl = String.fromEnvironment(
    'UNIDIPAY_LAN_FALLBACK',
    defaultValue: 'http://192.168.1.17/unidipaypro/php/api/mobile/',
  );

  static const String _usbReverseBaseUrl =
      'http://127.0.0.1:8080/unidipaypro/php/api/mobile/';
  static const String _usbReverseBaseUrlPort80 =
      'http://127.0.0.1/unidipaypro/php/api/mobile/';
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
    return _activeBaseUrl ?? _defaultBaseUrls.first;
  }

  static List<String> get _defaultBaseUrls {
    final lanCandidates = _lanFallbackCandidates;

    if (Platform.isAndroid) {
      return [_usbReverseBaseUrl, _usbReverseBaseUrlPort80, ...lanCandidates];
    }
    return [_usbReverseBaseUrlPort80, _usbReverseBaseUrl, ...lanCandidates];
  }

  static List<String> get _lanFallbackCandidates {
    final lanFallback = _definedLanFallbackBaseUrl.trim();
    if (lanFallback.isEmpty) {
      return const <String>[];
    }

    final normalized = _ensureTrailingSlash(lanFallback);
    final uri = Uri.tryParse(normalized);
    if (uri == null || uri.host.isEmpty) {
      return [normalized];
    }

    final port = uri.hasPort ? uri.port : 80;
    if (port == 8080) {
      return [normalized, uri.replace(port: 80).toString()];
    }

    return [normalized, uri.replace(port: 8080).toString()];
  }

  List<String> get _candidateBaseUrls {
    final custom = _definedBaseUrl.trim();
    if (custom.isNotEmpty) {
      return [_ensureTrailingSlash(custom)];
    }

    final defaults = _defaultBaseUrls;
    if (_activeBaseUrl == null) {
      return defaults;
    }

    return [
      _activeBaseUrl!,
      ...defaults.where((base) => base != _activeBaseUrl)
    ];
  }

  Future<Map<String, dynamic>> get(
    String endpoint, {
    String? token,
    Map<String, String>? query,
  }) async {
    Exception? lastError;
    final attemptedBases = <String>[];

    for (final base in _candidateBaseUrls) {
      attemptedBases.add(base);
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

    throw Exception(_connectivityFailureMessage(attemptedBases, lastError));
  }

  Future<Map<String, dynamic>> post(
    String endpoint,
    Map<String, dynamic> body, {
    String? token,
  }) async {
    Exception? lastError;
    final attemptedBases = <String>[];

    for (final base in _candidateBaseUrls) {
      attemptedBases.add(base);
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

    throw Exception(_connectivityFailureMessage(attemptedBases, lastError));
  }

  String _connectivityFailureMessage(
    List<String> attemptedBases,
    Exception? lastError,
  ) {
    final attempted = attemptedBases.join(', ');
    final baseMessage = lastError?.toString() ?? 'Unable to reach API server.';

    if (Platform.isAndroid) {
      return '$baseMessage Tried: $attempted. For USB debugging with Apache on port 80, run "adb reverse tcp:8080 tcp:80". If Apache is on 8080, run "adb reverse tcp:8080 tcp:8080". For Wi-Fi, run with --dart-define=UNIDIPAY_API_BASE=http://<PC-LAN-IP>/unidipaypro/php/api/mobile/';
    }

    return '$baseMessage Tried: $attempted. Set UNIDIPAY_API_BASE to a reachable server URL if needed.';
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
