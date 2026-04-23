import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../models/menu_item.dart';
import '../models/order.dart';
import '../models/student.dart';
import 'api_service.dart';

class AppState extends ChangeNotifier {
  final ApiService _api;

  AppState(this._api);

  String? _token;
  Student? _student;
  bool _isLoading = false;
  String? _authError;
  bool _requiresPasswordSetup = false;
  final List<CartItem> _cart = [];

  String? get token => _token;
  Student? get student => _student;
  bool get isAuthenticated =>
      _token != null && _student != null && !_requiresPasswordSetup;
  bool get hasPendingSetup =>
      _token != null && _student != null && _requiresPasswordSetup;
  bool get isLoading => _isLoading;
  String? get authError => _authError;
  bool get requiresPasswordSetup => _requiresPasswordSetup;
  List<CartItem> get cart => List.unmodifiable(_cart);

  double get cartTotal =>
      _cart.fold<double>(0, (sum, entry) => sum + entry.subtotal);

  Future<void> initialize() async {
    _isLoading = true;
    _authError = null;
    notifyListeners();

    final prefs = await SharedPreferences.getInstance();
    _token = prefs.getString('student_token');

    if (_token != null) {
      try {
        final data = await _api.get('auth.php?action=me', token: _token);
        _student = Student.fromJson(data['student'] as Map<String, dynamic>);
        _requiresPasswordSetup = data['requires_password_setup'] == true;
      } catch (e) {
        _authError = e.toString();
        _token = null;
        _student = null;
        _requiresPasswordSetup = false;
        await prefs.remove('student_token');
      }
    }

    _isLoading = false;
    notifyListeners();
  }

  Future<void> login({
    required String studentId,
    required String nfcCardId,
  }) async {
    await loginWithRfid(studentId: studentId, nfcCardId: nfcCardId);
  }

  Future<void> loginWithRfid({
    required String studentId,
    required String nfcCardId,
  }) async {
    await _performLogin({
      'student_id': studentId,
      'nfc_card_id': nfcCardId,
      'device_name': 'flutter-mobile',
    });
  }

  Future<void> loginWithEmail({
    required String email,
    required String password,
  }) async {
    await _performLogin({
      'identifier': email,
      'password': password,
      'device_name': 'flutter-mobile',
    });
  }

  Future<void> _performLogin(Map<String, dynamic> payload) async {
    _isLoading = true;
    _authError = null;
    notifyListeners();

    try {
      final data = await _api.post('auth.php?action=login', payload);

      final newToken = data['token']?.toString();
      if (newToken == null || newToken.isEmpty) {
        throw Exception(
          'Login response did not include a session token. Check mobile endpoint and UNIDIPAY_API_BASE: ${ApiService.configuredBaseUrl}',
        );
      }

      final studentData = data['student'];
      if (studentData is! Map<String, dynamic>) {
        throw Exception('Login response did not include student profile data.');
      }

      final newStudent = Student.fromJson(studentData);

      _token = newToken;
      _student = newStudent;
      _requiresPasswordSetup = data['requires_password_setup'] == true;

      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('student_token', newToken);
    } catch (e) {
      _authError = e.toString();
      _token = null;
      _student = null;
      _requiresPasswordSetup = false;
      rethrow;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  Future<void> setInitialCredentials({
    required String email,
    required String password,
    required String confirmPassword,
  }) async {
    if (_token == null) {
      throw Exception(
          'You need to log in first using Student ID and RFID card.');
    }

    _isLoading = true;
    _authError = null;
    notifyListeners();

    try {
      await _api.post(
        'auth.php?action=set_initial_credentials',
        {
          'email': email,
          'password': password,
          'confirm_password': confirmPassword,
        },
        token: _token,
      );

      final me = await _api.get('auth.php?action=me', token: _token);
      _student = Student.fromJson(me['student'] as Map<String, dynamic>);
      _requiresPasswordSetup = me['requires_password_setup'] == true;
    } catch (e) {
      _authError = e.toString();
      rethrow;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  Future<void> requestPasswordReset({required String email}) async {
    await _api.post('auth.php?action=request_password_reset', {'email': email});
  }

  Future<Map<String, dynamic>> validateResetToken(
      {required String token}) async {
    final data = await _api.get(
      'auth.php?action=validate_reset_token',
      query: {'token': token},
    );
    return data;
  }

  Future<void> resetPassword({
    required String token,
    required String newPassword,
    required String confirmPassword,
  }) async {
    await _api.post('auth.php?action=reset_password', {
      'token': token,
      'new_password': newPassword,
      'confirm_password': confirmPassword,
    });
  }

  Future<void> logout() async {
    if (_token != null) {
      try {
        await _api.post('auth.php?action=logout', {}, token: _token);
      } catch (_) {
        // Ignore server logout failure and clear local session.
      }
    }

    _token = null;
    _student = null;
    _authError = null;
    _requiresPasswordSetup = false;
    _cart.clear();

    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('student_token');
    notifyListeners();
  }

  Future<void> refreshProfile() async {
    if (_token == null) return;
    final data = await _api.get('auth.php?action=me', token: _token);
    _student = Student.fromJson(data['student'] as Map<String, dynamic>);
    _requiresPasswordSetup = data['requires_password_setup'] == true;
    notifyListeners();
  }

  Future<double> loadViaGcash({
    required double amount,
    required String gcashReference,
  }) async {
    final data = await _api.post(
      'wallet.php?action=gcash_topup',
      {
        'amount': amount,
        'gcash_reference': gcashReference,
      },
      token: _token,
    );

    final newBalance =
        (data['new_balance'] as num?)?.toDouble() ?? (_student?.balance ?? 0);

    if (_student != null) {
      _student = _student!.copyWith(balance: newBalance);
      notifyListeners();
    }

    return newBalance;
  }

  Future<Map<String, dynamic>> createGcashTopupInvoice({
    required double amount,
  }) async {
    return _api.post(
      'wallet.php?action=xendit_create_invoice',
      {'amount': amount},
      token: _token,
    );
  }

  Future<Map<String, dynamic>> verifyGcashTopupInvoice({
    required String invoiceId,
  }) async {
    final data = await _api.get(
      'wallet.php?action=xendit_check_invoice',
      token: _token,
      query: {'invoice_id': invoiceId},
    );

    final paid = data['paid'] == true;
    if (paid) {
      final newBalance = (data['new_balance'] as num?)?.toDouble();
      if (newBalance != null && _student != null) {
        _student = _student!.copyWith(balance: newBalance);
        notifyListeners();
      }
    }

    return data;
  }

  Future<Map<String, dynamic>> syncPendingXenditTopups() async {
    final data = await _api.get(
      'wallet.php?action=xendit_sync_pending',
      token: _token,
    );

    final newBalance = (data['new_balance'] as num?)?.toDouble();
    if (newBalance != null && _student != null) {
      _student = _student!.copyWith(balance: newBalance);
      notifyListeners();
    }

    return data;
  }

  Future<List<Map<String, dynamic>>> fetchTopupHistory() async {
    final data =
        await _api.get('wallet.php?action=topup_history', token: _token);
    final rows =
        (data['topups'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
    return rows;
  }

  List<Map<String, dynamic>> _extractMenuRows(dynamic payload) {
    if (payload is List) {
      return payload.whereType<Map<String, dynamic>>().toList();
    }

    if (payload is Map<String, dynamic>) {
      const candidateKeys = [
        'items',
        'menu',
        'menus',
        'data',
        'rows',
        'result',
      ];

      for (final key in candidateKeys) {
        final value = payload[key];
        if (value is List) {
          return value.whereType<Map<String, dynamic>>().toList();
        }
      }

      for (final value in payload.values) {
        final nested = _extractMenuRows(value);
        if (nested.isNotEmpty) return nested;
      }
    }

    return const [];
  }

  Future<List<MenuItemModel>> fetchMenu({String? category}) async {
    final queries = <Map<String, String>>[];
    Object? lastError;

    if (category != null) {
      queries.add({'action': 'category', 'category': category});
      queries.add({'action': 'category', 'category': category.toLowerCase()});
      queries.add({'action': 'all'});
    } else {
      queries.add({'action': 'all'});
      queries.add({'action': 'list'});
      queries.add(const {});
    }

    for (final query in queries) {
      try {
        final data = await _api.get(
          'menu.php',
          token: _token,
          query: query.isEmpty ? null : query,
        );

        final rows = _extractMenuRows(data);
        if (rows.isEmpty) continue;

        return rows
            .map(MenuItemModel.fromJson)
            .where((item) => item.name.trim().isNotEmpty)
            .toList();
      } catch (e) {
        lastError = e;
        continue;
      }
    }

    if (lastError != null) {
      throw Exception('Unable to load menu: $lastError');
    }

    return const [];
  }

  void addToCart(MenuItemModel item) {
    final existing =
        _cart.where((entry) => entry.item.id == item.id).firstOrNull;
    if (existing != null) {
      existing.quantity += 1;
    } else {
      _cart.add(CartItem(item: item, quantity: 1));
    }
    notifyListeners();
  }

  void updateCartQuantity(int itemId, int delta) {
    final index = _cart.indexWhere((entry) => entry.item.id == itemId);
    if (index < 0) return;

    _cart[index].quantity += delta;
    if (_cart[index].quantity <= 0) {
      _cart.removeAt(index);
    }
    notifyListeners();
  }

  void clearCart() {
    _cart.clear();
    notifyListeners();
  }

  Future<Map<String, dynamic>> placeOrder({required String orderType}) async {
    final payload = {
      'order_type': orderType,
      'total': cartTotal,
      'items': _cart
          .map((entry) => {
                'menu_item_id': entry.item.id,
                'name': entry.item.name,
                'price': entry.item.price,
                'quantity': entry.quantity,
                'subtotal': entry.subtotal,
              })
          .toList(),
    };

    final data =
        await _api.post('orders.php?action=create', payload, token: _token);

    final newBalance = (data['new_balance'] as num?)?.toDouble();
    if (newBalance != null && _student != null) {
      _student = _student!.copyWith(balance: newBalance);
    }

    _cart.clear();
    notifyListeners();

    return data;
  }

  Future<List<OrderModel>> fetchOrders() async {
    final data = await _api.get('orders.php?action=history', token: _token);
    final rows = (data['orders'] as List<dynamic>? ?? [])
        .map((e) => OrderModel.fromJson(e as Map<String, dynamic>))
        .toList();
    return rows;
  }

  Future<Map<String, dynamic>> cancelOrder({required int orderId}) async {
    final data = await _api.post(
      'orders.php?action=cancel',
      {'order_id': orderId},
      token: _token,
    );

    final newBalance = (data['new_balance'] as num?)?.toDouble();
    if (newBalance != null && _student != null) {
      _student = _student!.copyWith(balance: newBalance);
      notifyListeners();
    }

    return data;
  }
}

extension<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
