import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';
import 'package:provider/provider.dart';

import '../services/app_state.dart';

class CartScreen extends StatefulWidget {
  const CartScreen({super.key});

  @override
  State<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends State<CartScreen> {
  String? _orderType;
  bool _placing = false;

  Future<void> _placeOrder() async {
    final app = context.read<AppState>();
    if (app.cart.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Cart is empty')),
      );
      return;
    }

    if (_orderType == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please select order type')),
      );
      return;
    }

    setState(() => _placing = true);
    try {
      final data = await app.placeOrder(orderType: _orderType!);
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
    final colorScheme = Theme.of(context).colorScheme;
    final cartCount = app.cart.fold<int>(0, (sum, e) => sum + e.quantity);

    return LayoutBuilder(
      builder: (context, constraints) {
        final isWide = constraints.maxWidth >= 960;

        final cartList = Expanded(
          child: AnimatedSwitcher(
            duration: const Duration(milliseconds: 250),
            child: app.cart.isEmpty
                ? Center(
                    key: const ValueKey('cart-empty'),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        FaIcon(FontAwesomeIcons.cartShopping,
                            size: 42, color: colorScheme.primary),
                        const SizedBox(height: 10),
                        const Text('Cart is empty'),
                      ],
                    ),
                  )
                : ListView.builder(
                    key: const ValueKey('cart-list'),
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                    itemCount: app.cart.length,
                    itemBuilder: (context, index) {
                      final entry = app.cart[index];
                      return TweenAnimationBuilder<double>(
                        tween: Tween(begin: 0, end: 1),
                        duration: Duration(milliseconds: 200 + (index * 25)),
                        curve: Curves.easeOutCubic,
                        child: Card(
                          margin: const EdgeInsets.only(bottom: 10),
                          child: ListTile(
                            title: Text(entry.item.name),
                            subtitle: Text(
                                'PHP ${entry.item.price.toStringAsFixed(2)} x ${entry.quantity}'),
                            trailing: SizedBox(
                              width: 120,
                              child: Row(
                                mainAxisAlignment: MainAxisAlignment.end,
                                children: [
                                  IconButton(
                                    onPressed: () => app.updateCartQuantity(
                                        entry.item.id, -1),
                                    icon:
                                        const Icon(Icons.remove_circle_outline),
                                  ),
                                  Text('${entry.quantity}'),
                                  IconButton(
                                    onPressed: () => app.updateCartQuantity(
                                        entry.item.id, 1),
                                    icon: const Icon(Icons.add_circle_outline),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                        builder: (context, value, child) => Opacity(
                          opacity: value,
                          child: Transform.translate(
                            offset: Offset(0, (1 - value) * 10),
                            child: child,
                          ),
                        ),
                      );
                    },
                  ),
          ),
        );

        final checkoutPanel = Card(
          margin: const EdgeInsets.fromLTRB(16, 0, 16, 16),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  'Select Order Type',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: colorScheme.onSurface,
                      ),
                ),
                const SizedBox(height: 8),
                DropdownButtonFormField<String>(
                  initialValue: _orderType,
                  style: TextStyle(
                    color: colorScheme.onSurface,
                    fontWeight: FontWeight.w700,
                    fontSize: 16,
                  ),
                  decoration: InputDecoration(
                    hintText: 'Select order type',
                    prefixIcon:
                        const Icon(Icons.room_service_outlined, size: 18),
                  ),
                  hint: Text(
                    'Select order type',
                    style: TextStyle(
                      color: colorScheme.onSurfaceVariant,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  items: [
                    DropdownMenuItem(
                      value: 'dine-in',
                      child: Row(
                        children: [
                          Icon(
                            Icons.chair_outlined,
                            size: 18,
                            color: colorScheme.onSurface,
                          ),
                          SizedBox(width: 8),
                          Text(
                            'Dine In',
                            style: TextStyle(
                              color: colorScheme.onSurface,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ],
                      ),
                    ),
                    DropdownMenuItem(
                      value: 'take-out',
                      child: Row(
                        children: [
                          Icon(
                            Icons.shopping_bag_outlined,
                            size: 18,
                            color: colorScheme.onSurface,
                          ),
                          const SizedBox(width: 8),
                          Text(
                            'Take Out',
                            style: TextStyle(
                              color: colorScheme.onSurface,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                  onChanged: (v) => setState(() => _orderType = v),
                ),
                const SizedBox(height: 10),
                Text(
                  'Total: PHP ${app.cartTotal.toStringAsFixed(2)}',
                  style: TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.w800,
                    color: colorScheme.primary,
                  ),
                  textAlign: TextAlign.right,
                ),
                const SizedBox(height: 10),
                FilledButton.icon(
                  onPressed: _placing ? null : _placeOrder,
                  icon: _placing
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const FaIcon(FontAwesomeIcons.creditCard, size: 16),
                  label: Text(
                      _placing ? 'Processing...' : 'Pay Using RFID Balance'),
                ),
              ],
            ),
          ),
        );

        return Align(
          alignment: Alignment.topCenter,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 1120),
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
                  child: Row(
                    children: [
                      Expanded(
                        child: Row(
                          children: [
                            FaIcon(
                              FontAwesomeIcons.cartFlatbedSuitcase,
                              size: 16,
                              color: colorScheme.primary,
                            ),
                            const SizedBox(width: 8),
                            Text(
                              'Cart & Checkout',
                              style: Theme.of(context)
                                  .textTheme
                                  .titleLarge
                                  ?.copyWith(
                                    fontWeight: FontWeight.w800,
                                    color: colorScheme.primary,
                                  ),
                            ),
                          ],
                        ),
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 7),
                        decoration: BoxDecoration(
                          color: colorScheme.primaryContainer
                              .withValues(alpha: 0.7),
                          borderRadius: BorderRadius.circular(999),
                        ),
                        child: Text(
                          '$cartCount item(s)',
                          style: TextStyle(
                            color: colorScheme.onPrimaryContainer,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                if (isWide)
                  Expanded(
                    child: Row(
                      children: [
                        Expanded(child: cartList),
                        SizedBox(
                          width: 360,
                          child: Align(
                            alignment: Alignment.topCenter,
                            child: checkoutPanel,
                          ),
                        ),
                      ],
                    ),
                  )
                else ...[
                  cartList,
                  checkoutPanel,
                ],
              ],
            ),
          ),
        );
      },
    );
  }
}
