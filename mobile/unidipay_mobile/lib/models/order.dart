class OrderModel {
  final int id;
  final double total;
  final String status;
  final String createdAt;
  final List<OrderItemModel> items;

  OrderModel({
    required this.id,
    required this.total,
    required this.status,
    required this.createdAt,
    required this.items,
  });

  factory OrderModel.fromJson(Map<String, dynamic> json) {
    final rawTotal = json['total'];
    final parsedTotal = rawTotal is num
        ? rawTotal.toDouble()
        : double.tryParse(rawTotal?.toString() ?? '') ?? 0;
    final rawItems = json['items'];
    final parsedItems = rawItems is List
        ? rawItems
            .whereType<Map<String, dynamic>>()
            .map(OrderItemModel.fromJson)
            .toList()
        : <OrderItemModel>[];

    return OrderModel(
      id: int.tryParse(json['id'].toString()) ?? 0,
      total: parsedTotal,
      status: (json['status'] ?? '').toString(),
      createdAt: (json['created_at'] ?? '').toString(),
      items: parsedItems,
    );
  }
}

class OrderItemModel {
  final int menuItemId;
  final String name;
  final double price;
  final int quantity;
  final double subtotal;

  OrderItemModel({
    required this.menuItemId,
    required this.name,
    required this.price,
    required this.quantity,
    required this.subtotal,
  });

  factory OrderItemModel.fromJson(Map<String, dynamic> json) {
    final rawPrice = json['price'];
    final rawSubtotal = json['subtotal'];

    return OrderItemModel(
      menuItemId: int.tryParse((json['menu_item_id'] ?? '').toString()) ?? 0,
      name: (json['name'] ?? '').toString(),
      price: rawPrice is num
          ? rawPrice.toDouble()
          : double.tryParse(rawPrice?.toString() ?? '') ?? 0,
      quantity: int.tryParse((json['quantity'] ?? '').toString()) ?? 0,
      subtotal: rawSubtotal is num
          ? rawSubtotal.toDouble()
          : double.tryParse(rawSubtotal?.toString() ?? '') ?? 0,
    );
  }
}
