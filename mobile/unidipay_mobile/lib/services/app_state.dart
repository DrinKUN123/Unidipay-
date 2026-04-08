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
  final List<CartItem> _cart = [];

  String? get token => _token;
  Student? get student => _student;
  bool get isAuthenticated => _token != null && _student != null;
  bool get isLoading => _isLoading;
  String? get authError => _authError;
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
      } catch (e) {
        _authError = e.toString();
        _token = null;
        _student = null;
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
    _isLoading = true;
    _authError = null;
    notifyListeners();

    try {
      final data = await _api.post('auth.php?action=login', {
        'student_id': studentId,
        'nfc_card_id': nfcCardId,
        'device_name': 'flutter-mobile',
      });

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

      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('student_token', newToken);
    } catch (e) {
      _authError = e.toString();
      _token = null;
      _student = null;
      rethrow;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
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
    _cart.clear();

    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('student_token');
    notifyListeners();
  }

  Future<void> refreshProfile() async {
    if (_token == null) return;
    final data = await _api.get('auth.php?action=me', token: _token);
    _student = Student.fromJson(data['student'] as Map<String, dynamic>);
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

    final newBalance = (data['new_balance'] as num?)?.toDouble() ??
        (_student?.balance ?? 0);

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
    final data = await _api.get('wallet.php?action=topup_history', token: _token);
    final rows = (data['topups'] as List<dynamic>? ?? [])
        .cast<Map<String, dynamic>>();
    return rows;
  }

  Future<List<MenuItemModel>> fetchMenu({String? category}) async {
    final query = <String, String>{'action': category == null ? 'all' : 'category'};
    if (category != null) {
      query['category'] = category;
    }

    final data = await _api.get('menu.php', token: _token, query: query);
    final items = (data['items'] as List<dynamic>? ?? [])
        .map((e) => MenuItemModel.fromJson(e as Map<String, dynamic>))
        .toList();
    return items;
  }

  void addToCart(MenuItemModel item) {
    final existing = _cart.where((entry) => entry.item.id == item.id).firstOrNull;
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

    final data = await _api.post('orders.php?action=create', payload, token: _token);

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
}

extension<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
