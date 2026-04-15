import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';
import 'package:provider/provider.dart';

import '../models/order.dart';
import '../services/app_state.dart';

class OrdersScreen extends StatefulWidget {
  const OrdersScreen({super.key});

  @override
  State<OrdersScreen> createState() => _OrdersScreenState();
}

class _OrdersScreenState extends State<OrdersScreen> {
  late Future<List<OrderModel>> _future;

  String _normalizeStatus(String status) {
    return status.trim().toLowerCase();
  }

  String _statusLabel(String status) {
    final normalized = _normalizeStatus(status);
    if (_isCancelledStatus(normalized)) return 'cancelled';
    if (normalized.isEmpty) return 'unknown';
    return status.trim();
  }

  bool _isCancelledStatus(String status) {
    final normalized = _normalizeStatus(status);
    return normalized == 'cancelled' ||
        normalized == 'canceled' ||
        normalized == 'cancel' ||
        normalized == 'void' ||
        normalized == 'voided' ||
        normalized.isEmpty;
  }

  Color _statusBgColor(ColorScheme colorScheme, String status) {
    final normalized = _normalizeStatus(status);
    if (_isCancelledStatus(normalized)) {
      return colorScheme.errorContainer.withValues(alpha: 0.8);
    }
    if (normalized == 'processing') {
      return colorScheme.tertiaryContainer.withValues(alpha: 0.85);
    }
    if (normalized == 'completed' || normalized == 'paid') {
      return colorScheme.secondaryContainer.withValues(alpha: 0.85);
    }
    return colorScheme.primaryContainer.withValues(alpha: 0.75);
  }

  Color _statusFgColor(ColorScheme colorScheme, String status) {
    final normalized = _normalizeStatus(status);
    if (_isCancelledStatus(normalized)) {
      return colorScheme.onErrorContainer;
    }
    if (normalized == 'processing') {
      return colorScheme.onTertiaryContainer;
    }
    if (normalized == 'completed' || normalized == 'paid') {
      return colorScheme.onSecondaryContainer;
    }
    return colorScheme.onPrimaryContainer;
  }

  Future<void> _cancelOrder(OrderModel order, BuildContext sheetContext) async {
    final colorScheme = Theme.of(context).colorScheme;

    final shouldCancel = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        backgroundColor: colorScheme.surface,
        titleTextStyle: Theme.of(context).textTheme.headlineSmall?.copyWith(
              color: colorScheme.onSurface,
              fontWeight: FontWeight.w800,
            ),
        contentTextStyle: Theme.of(context).textTheme.bodyLarge?.copyWith(
              color: colorScheme.onSurface,
              height: 1.35,
            ),
        title: const Text('Cancel Order'),
        content: Text(
          'Cancel Order #${order.id}? This will refund PHP ${order.total.toStringAsFixed(2)} to your wallet.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogContext).pop(false),
            child: Text(
              'No',
              style: TextStyle(
                color: colorScheme.primary,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
          FilledButton(
            onPressed: () => Navigator.of(dialogContext).pop(true),
            child: const Text('Yes, cancel'),
          ),
        ],
      ),
    );

    if (shouldCancel != true) return;

    try {
      await context.read<AppState>().cancelOrder(orderId: order.id);
      if (!mounted) return;

      Navigator.of(sheetContext).pop();
      setState(() {
        _future = context.read<AppState>().fetchOrders();
      });

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Order #${order.id} cancelled.')),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Cancel failed: $e')),
      );
    }
  }

  void _showOrderDetails(OrderModel order) {
    final colorScheme = Theme.of(context).colorScheme;
    final canCancel = _normalizeStatus(order.status) == 'pending';
    final isCancelled = _isCancelledStatus(order.status);
    final statusBg = _statusBgColor(colorScheme, order.status);
    final statusFg = _statusFgColor(colorScheme, order.status);
    final statusLabel = _statusLabel(order.status);

    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: colorScheme.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (sheetContext) {
        final items = order.items;

        return DraggableScrollableSheet(
          expand: false,
          initialChildSize: 0.66,
          minChildSize: 0.45,
          maxChildSize: 0.9,
          builder: (context, controller) {
            return Padding(
              padding: const EdgeInsets.fromLTRB(18, 14, 18, 18),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Center(
                    child: Container(
                      width: 44,
                      height: 4,
                      decoration: BoxDecoration(
                        color: colorScheme.outlineVariant,
                        borderRadius: BorderRadius.circular(999),
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    'Order #${order.id}',
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.w800,
                          color: colorScheme.onSurface,
                        ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    order.createdAt,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: colorScheme.onSurfaceVariant,
                        ),
                  ),
                  if (isCancelled) ...[
                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 6),
                      decoration: BoxDecoration(
                        color: colorScheme.errorContainer,
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            Icons.block,
                            size: 14,
                            color: colorScheme.onErrorContainer,
                          ),
                          const SizedBox(width: 6),
                          Text(
                            'Cancelled',
                            style: TextStyle(
                              color: colorScheme.onErrorContainer,
                              fontWeight: FontWeight.w800,
                              fontSize: 12,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                  const SizedBox(height: 14),
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 10, vertical: 5),
                        decoration: BoxDecoration(
                          color: statusBg,
                          borderRadius: BorderRadius.circular(999),
                        ),
                        child: Text(
                          statusLabel,
                          style: TextStyle(
                            color: statusFg,
                            fontWeight: FontWeight.w700,
                            fontSize: 12,
                          ),
                        ),
                      ),
                      const Spacer(),
                      Text(
                        'PHP ${order.total.toStringAsFixed(2)}',
                        style:
                            Theme.of(context).textTheme.titleMedium?.copyWith(
                                  fontWeight: FontWeight.w800,
                                  color: colorScheme.onSurface,
                                ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),
                  if (canCancel) ...[
                    SizedBox(
                      width: double.infinity,
                      child: OutlinedButton.icon(
                        onPressed: () => _cancelOrder(order, sheetContext),
                        icon: const Icon(Icons.cancel_outlined),
                        label: const Text('Cancel Order'),
                      ),
                    ),
                    const SizedBox(height: 12),
                  ],
                  const Divider(height: 1),
                  const SizedBox(height: 12),
                  Text(
                    'Items',
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w800,
                          color: colorScheme.onSurface,
                        ),
                  ),
                  const SizedBox(height: 8),
                  Expanded(
                    child: items.isEmpty
                        ? Center(
                            child: Text(
                              'No item details found for this order.',
                              style: Theme.of(context)
                                  .textTheme
                                  .bodyMedium
                                  ?.copyWith(
                                    color: colorScheme.onSurfaceVariant,
                                  ),
                              textAlign: TextAlign.center,
                            ),
                          )
                        : ListView.separated(
                            controller: controller,
                            itemCount: items.length,
                            separatorBuilder: (_, __) =>
                                const SizedBox(height: 8),
                            itemBuilder: (context, index) {
                              final item = items[index];
                              final itemSubtotal = item.subtotal > 0
                                  ? item.subtotal
                                  : item.price * item.quantity;

                              return Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 12, vertical: 10),
                                decoration: BoxDecoration(
                                  color: colorScheme.surfaceContainerLowest,
                                  borderRadius: BorderRadius.circular(14),
                                  border: Border.all(
                                    color: colorScheme.outlineVariant
                                        .withValues(alpha: 0.35),
                                  ),
                                ),
                                child: Row(
                                  children: [
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        children: [
                                          Text(
                                            item.name,
                                            style: Theme.of(context)
                                                .textTheme
                                                .bodyLarge
                                                ?.copyWith(
                                                  fontWeight: FontWeight.w700,
                                                ),
                                          ),
                                          const SizedBox(height: 2),
                                          Text(
                                            'PHP ${item.price.toStringAsFixed(2)} x ${item.quantity}',
                                            style: Theme.of(context)
                                                .textTheme
                                                .bodySmall
                                                ?.copyWith(
                                                  color: colorScheme
                                                      .onSurfaceVariant,
                                                ),
                                          ),
                                        ],
                                      ),
                                    ),
                                    const SizedBox(width: 10),
                                    Text(
                                      'PHP ${itemSubtotal.toStringAsFixed(2)}',
                                      style: Theme.of(context)
                                          .textTheme
                                          .bodyLarge
                                          ?.copyWith(
                                            fontWeight: FontWeight.w800,
                                          ),
                                    ),
                                  ],
                                ),
                              );
                            },
                          ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }

  @override
  void initState() {
    super.initState();
    _future = context.read<AppState>().fetchOrders();
  }

  @override
  Widget build(BuildContext context) {
    final colorScheme = Theme.of(context).colorScheme;

    return LayoutBuilder(
      builder: (context, constraints) {
        final isWide = constraints.maxWidth >= 900;

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
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                FaIcon(
                                  FontAwesomeIcons.clockRotateLeft,
                                  size: 16,
                                  color: colorScheme.primary,
                                ),
                                const SizedBox(width: 8),
                                Text(
                                  'Order History',
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
                            const SizedBox(height: 2),
                            Text(
                              'View your previous purchases and payment status',
                              style: Theme.of(context)
                                  .textTheme
                                  .bodySmall
                                  ?.copyWith(
                                    color: colorScheme.onSurface,
                                  ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                Expanded(
                  child: FutureBuilder<List<OrderModel>>(
                    future: _future,
                    builder: (context, snapshot) {
                      return AnimatedSwitcher(
                        duration: const Duration(milliseconds: 250),
                        child: snapshot.connectionState ==
                                ConnectionState.waiting
                            ? const Center(
                                key: ValueKey('orders-loading'),
                                child: CircularProgressIndicator(),
                              )
                            : snapshot.hasError
                                ? Center(
                                    key: const ValueKey('orders-error'),
                                    child: Padding(
                                      padding: const EdgeInsets.all(24),
                                      child: Text(
                                        'Failed to load orders:\n${snapshot.error}',
                                        textAlign: TextAlign.center,
                                      ),
                                    ),
                                  )
                                : (snapshot.data ?? []).isEmpty
                                    ? Center(
                                        key: const ValueKey('orders-empty'),
                                        child: Column(
                                          mainAxisSize: MainAxisSize.min,
                                          children: [
                                            FaIcon(FontAwesomeIcons.receipt,
                                                size: 52,
                                                color: colorScheme.primary),
                                            const SizedBox(height: 10),
                                            Text(
                                              'No orders found',
                                              style: Theme.of(context)
                                                  .textTheme
                                                  .titleMedium
                                                  ?.copyWith(
                                                      fontWeight:
                                                          FontWeight.w700),
                                            ),
                                          ],
                                        ),
                                      )
                                    : RefreshIndicator(
                                        key: const ValueKey('orders-list'),
                                        onRefresh: () async {
                                          setState(() {
                                            _future = context
                                                .read<AppState>()
                                                .fetchOrders();
                                          });
                                          await _future;
                                        },
                                        child: GridView.builder(
                                          padding: const EdgeInsets.fromLTRB(
                                              16, 0, 16, 16),
                                          itemCount:
                                              (snapshot.data ?? []).length,
                                          gridDelegate:
                                              SliverGridDelegateWithFixedCrossAxisCount(
                                            crossAxisCount: isWide ? 2 : 1,
                                            mainAxisExtent: 128,
                                            crossAxisSpacing: 10,
                                            mainAxisSpacing: 10,
                                          ),
                                          itemBuilder: (context, index) {
                                            final order =
                                                (snapshot.data ?? [])[index];
                                            final isCancelled =
                                                _isCancelledStatus(
                                                    order.status);
                                            final statusLabel =
                                                _statusLabel(order.status);
                                            final statusBg = _statusBgColor(
                                                colorScheme, order.status);
                                            final statusFg = _statusFgColor(
                                                colorScheme, order.status);
                                            return TweenAnimationBuilder<
                                                double>(
                                              tween: Tween(begin: 0, end: 1),
                                              duration: Duration(
                                                  milliseconds:
                                                      200 + (index * 25)),
                                              curve: Curves.easeOutCubic,
                                              child: Card(
                                                elevation: 1.5,
                                                shape: RoundedRectangleBorder(
                                                  borderRadius:
                                                      BorderRadius.circular(20),
                                                ),
                                                child: ListTile(
                                                  enabled: !isCancelled,
                                                  onTap: isCancelled
                                                      ? null
                                                      : () => _showOrderDetails(
                                                          order),
                                                  contentPadding:
                                                      const EdgeInsets
                                                          .symmetric(
                                                          horizontal: 16,
                                                          vertical: 8),
                                                  leading: CircleAvatar(
                                                    backgroundColor: colorScheme
                                                        .primaryContainer,
                                                    child: FaIcon(
                                                      FontAwesomeIcons
                                                          .clipboardCheck,
                                                      size: 14,
                                                      color: colorScheme
                                                          .onPrimaryContainer,
                                                    ),
                                                  ),
                                                  title: Text(
                                                    'Order #${order.id}',
                                                    style: const TextStyle(
                                                        fontWeight:
                                                            FontWeight.w700),
                                                  ),
                                                  subtitle:
                                                      Text(order.createdAt),
                                                  trailing: Column(
                                                    mainAxisAlignment:
                                                        MainAxisAlignment
                                                            .center,
                                                    crossAxisAlignment:
                                                        CrossAxisAlignment.end,
                                                    children: [
                                                      Text(
                                                        'PHP ${order.total.toStringAsFixed(2)}',
                                                        style: TextStyle(
                                                          fontWeight:
                                                              FontWeight.w800,
                                                          color: isCancelled
                                                              ? colorScheme
                                                                  .onSurfaceVariant
                                                              : null,
                                                        ),
                                                      ),
                                                      const SizedBox(height: 4),
                                                      isCancelled
                                                          ? Container(
                                                              padding:
                                                                  const EdgeInsets
                                                                      .symmetric(
                                                                      horizontal:
                                                                          8,
                                                                      vertical:
                                                                          3),
                                                              decoration:
                                                                  BoxDecoration(
                                                                color: colorScheme
                                                                    .errorContainer,
                                                                borderRadius:
                                                                    BorderRadius
                                                                        .circular(
                                                                            999),
                                                              ),
                                                              child: Row(
                                                                mainAxisSize:
                                                                    MainAxisSize
                                                                        .min,
                                                                children: [
                                                                  Icon(
                                                                    Icons.block,
                                                                    size: 11,
                                                                    color: colorScheme
                                                                        .onErrorContainer,
                                                                  ),
                                                                  const SizedBox(
                                                                      width: 4),
                                                                  Text(
                                                                    'Cancelled',
                                                                    style:
                                                                        TextStyle(
                                                                      color: colorScheme
                                                                          .onErrorContainer,
                                                                      fontWeight:
                                                                          FontWeight
                                                                              .w800,
                                                                      fontSize:
                                                                          11,
                                                                    ),
                                                                  ),
                                                                ],
                                                              ),
                                                            )
                                                          : Container(
                                                              padding:
                                                                  const EdgeInsets
                                                                      .symmetric(
                                                                      horizontal:
                                                                          8,
                                                                      vertical:
                                                                          3),
                                                              decoration:
                                                                  BoxDecoration(
                                                                color: statusBg,
                                                                borderRadius:
                                                                    BorderRadius
                                                                        .circular(
                                                                            999),
                                                              ),
                                                              child: Text(
                                                                statusLabel,
                                                                style:
                                                                    TextStyle(
                                                                  color:
                                                                      statusFg,
                                                                  fontWeight:
                                                                      FontWeight
                                                                          .w700,
                                                                  fontSize: 11,
                                                                ),
                                                              ),
                                                            ),
                                                    ],
                                                  ),
                                                ),
                                              ),
                                              builder:
                                                  (context, value, child) =>
                                                      Opacity(
                                                opacity: value,
                                                child: Transform.translate(
                                                  offset: Offset(
                                                      0, (1 - value) * 10),
                                                  child: child,
                                                ),
                                              ),
                                            );
                                          },
                                        ),
                                      ),
                      );
                    },
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}
