import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../services/app_state.dart';

class CartScreen extends StatefulWidget {
  const CartScreen({super.key});

  @override
  State<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends State<CartScreen> {
  String _orderType = 'dine-in';
  bool _placing = false;

  Future<void> _placeOrder() async {
    final app = context.read<AppState>();
    if (app.cart.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Cart is empty')),
      );
      return;
    }

    setState(() => _placing = true);
    try {
      final data = await app.placeOrder(orderType: _orderType);
      if (!mounted) return;
      final orderNo = data['order_number']?.toString() ?? 'N/A';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Order placed: $orderNo')),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Order failed: $e')),
      );
    } finally {
      if (mounted) setState(() => _placing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();

    return Column(
      children: [
        const Padding(
          padding: EdgeInsets.fromLTRB(12, 10, 12, 4),
          child: Row(
            children: [
              Text('Cart & Checkout', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            ],
          ),
        ),
        Expanded(
          child: app.cart.isEmpty
              ? const Center(child: Text('Cart is empty'))
              : ListView.builder(
                  itemCount: app.cart.length,
                  itemBuilder: (context, index) {
                    final entry = app.cart[index];
                    return ListTile(
                      title: Text(entry.item.name),
                      subtitle: Text('PHP ${entry.item.price.toStringAsFixed(2)} x ${entry.quantity}'),
                      trailing: SizedBox(
                        width: 120,
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.end,
                          children: [
                            IconButton(
                              onPressed: () => app.updateCartQuantity(entry.item.id, -1),
                              icon: const Icon(Icons.remove_circle_outline),
                            ),
                            Text('${entry.quantity}'),
                            IconButton(
                              onPressed: () => app.updateCartQuantity(entry.item.id, 1),
                              icon: const Icon(Icons.add_circle_outline),
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
        ),
        Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              DropdownButtonFormField<String>(
                initialValue: _orderType,
                decoration: const InputDecoration(
                  labelText: 'Order Type',
                  border: OutlineInputBorder(),
                ),
                items: const [
                  DropdownMenuItem(value: 'dine-in', child: Text('Dine In')),
                  DropdownMenuItem(value: 'take-out', child: Text('Take Out')),
                ],
                onChanged: (v) => setState(() => _orderType = v ?? 'dine-in'),
              ),
              const SizedBox(height: 10),
              Text(
                'Total: PHP ${app.cartTotal.toStringAsFixed(2)}',
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                textAlign: TextAlign.right,
              ),
              const SizedBox(height: 10),
              ElevatedButton(
                onPressed: _placing ? null : _placeOrder,
                child: Text(_placing ? 'Processing...' : 'Pay Using NFC Balance'),
              ),
            ],
          ),
        )
      ],
    );
  }
}
