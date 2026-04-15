class MenuItemModel {
  final int id;
  final String name;
  final String category;
  final double price;
  final String? description;
  final String? imageUrl;

  MenuItemModel({
    required this.id,
    required this.name,
    required this.category,
    required this.price,
    this.description,
    this.imageUrl,
  });

  factory MenuItemModel.fromJson(Map<String, dynamic> json) {
    final rawPrice =
        json['price'] ?? json['amount'] ?? json['menu_price'] ?? json['cost'];
    final rawId = json['id'] ?? json['menu_item_id'] ?? json['menu_id'];
    final rawName = json['name'] ?? json['menu_name'] ?? json['title'];
    final rawCategory =
        json['category'] ?? json['menu_category'] ?? json['type'];
    final rawDescription =
        json['description'] ?? json['details'] ?? json['desc'];
    final rawImage = json['image_url'] ??
        json['image'] ??
        json['photo'] ??
        json['thumbnail'];

    final parsedPrice = rawPrice is num
        ? rawPrice.toDouble()
        : double.tryParse(rawPrice?.toString() ?? '') ?? 0;

    return MenuItemModel(
      id: int.tryParse(rawId?.toString() ?? '') ?? 0,
      name: (rawName ?? '').toString(),
      category: (rawCategory ?? '').toString(),
      price: parsedPrice,
      description: rawDescription?.toString(),
      imageUrl: rawImage?.toString(),
    );
  }
}

class CartItem {
  final MenuItemModel item;
  int quantity;

  CartItem({required this.item, this.quantity = 1});

  double get subtotal => item.price * quantity;
}
