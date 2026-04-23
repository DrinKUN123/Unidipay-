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
    defaultValue: '',
  );

  static const String _usbReverseBaseUrl =
      'http://127.0.0.1:8080/unidipaypro/php/api/mobile/';
  static const String _usbReverseBaseUrlPort80 =
      'http://127.0.0.1/unidipaypro/php/api/mobile/';
    static const String _androidEmulatorBaseUrl =
      'http://10.0.2.2:8080/unidipaypro/php/api/mobile/';
    static const String _androidEmulatorBaseUrlPort80 =
      'http://10.0.2.2/unidipaypro/php/api/mobile/';
    static const String _genymotionBaseUrl =
      'http://10.0.3.2:8080/unidipaypro/php/api/mobile/';
    static const String _genymotionBaseUrlPort80 =
      'http://10.0.3.2/unidipaypro/php/api/mobile/';
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
      final androidCandidates = <String>[
        _usbReverseBaseUrl,
        _usbReverseBaseUrlPort80,
        _androidEmulatorBaseUrl,
        _androidEmulatorBaseUrlPort80,
        _genymotionBaseUrl,
        _genymotionBaseUrlPort80,
        ...lanCandidates,
      ];
      return androidCandidates.toSet().toList();
    }
    final defaults = <String>[
      _usbReverseBaseUrlPort80,
      _usbReverseBaseUrl,
      ...lanCandidates,
    ];
    return defaults.toSet().toList();
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
        return _decode(response, uri.toString());
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
        return _decode(response, uri.toString());
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
      return '$baseMessage Tried: $attempted. Android checks USB reverse (127.0.0.1), emulator host (10.0.2.2), and Genymotion host (10.0.3.2). For USB debugging with Apache on port 80, run "adb reverse tcp:8080 tcp:80". If Apache is on 8080, run "adb reverse tcp:8080 tcp:8080". For Wi-Fi/real devices, run with --dart-define=UNIDIPAY_API_BASE=http://<PC-LAN-IP>/unidipaypro/php/api/mobile/';
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

  String _responsePreview(String rawBody) {
    final normalized = rawBody.replaceAll(RegExp(r'\s+'), ' ').trim();
    if (normalized.isEmpty) {
      return '(empty response body)';
    }

    const maxLen = 220;
    if (normalized.length <= maxLen) {
      return normalized;
    }

    return '${normalized.substring(0, maxLen)}...';
  }

  Map<String, dynamic> _decode(http.Response response, String requestUrl) {
    Map<String, dynamic> data;
    try {
      data = jsonDecode(response.body) as Map<String, dynamic>;
    } catch (_) {
      final contentType = response.headers['content-type'] ?? 'unknown';
      final preview = _responsePreview(response.body);
      throw Exception(
        'Invalid server response from $requestUrl '
        '(HTTP ${response.statusCode}, content-type: $contentType). '
        'Response preview: $preview',
      );
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
