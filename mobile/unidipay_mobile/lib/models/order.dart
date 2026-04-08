class OrderModel {
  final int id;
  final double total;
  final String status;
  final String createdAt;

  OrderModel({
    required this.id,
    required this.total,
    required this.status,
    required this.createdAt,
  });

  factory OrderModel.fromJson(Map<String, dynamic> json) {
    final rawTotal = json['total'];
    final parsedTotal = rawTotal is num
        ? rawTotal.toDouble()
        : double.tryParse(rawTotal?.toString() ?? '') ?? 0;

    return OrderModel(
      id: int.tryParse(json['id'].toString()) ?? 0,
      total: parsedTotal,
      status: (json['status'] ?? '').toString(),
      createdAt: (json['created_at'] ?? '').toString(),
    );
  }
}
