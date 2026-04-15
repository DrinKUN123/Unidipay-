import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';
import 'package:provider/provider.dart';

import '../models/menu_item.dart';
import '../services/api_service.dart';
import '../services/app_state.dart';

class MenuScreen extends StatefulWidget {
  const MenuScreen({super.key});

  @override
  State<MenuScreen> createState() => _MenuScreenState();
}

class _MenuScreenState extends State<MenuScreen> {
  static const categories = ['Meals', 'Drinks', 'Snacks', 'Desserts'];
  String _selectedCategory = 'Meals';
  late Future<List<MenuItemModel>> _future;

  @override
  void initState() {
    super.initState();
    _future = context.read<AppState>().fetchMenu();
  }

  String? _resolveImageUrl(String? rawUrl) {
    if (rawUrl == null || rawUrl.trim().isEmpty) return null;

    final value = rawUrl.trim();
    final uri = Uri.tryParse(value);
    if (uri != null && uri.hasScheme) return value;

    final base = Uri.parse(ApiService.configuredBaseUrl);
    if (value.startsWith('uploads/') || value.startsWith('images/')) {
      final appRoot = Uri(
        scheme: base.scheme,
        host: base.host,
        port: base.hasPort ? base.port : null,
        path: '/unidipaypro/',
      );
      return appRoot.resolve(value).toString();
    }

    if (value.startsWith('/')) {
      final root = Uri(
        scheme: base.scheme,
        host: base.host,
        port: base.hasPort ? base.port : null,
      );
      return root.resolve(value).toString();
    }

    return base.resolve(value).toString();
  }

  bool _matchesCategory(MenuItemModel item, String selectedCategory) {
    final category = item.category.trim().toLowerCase();
    if (category.isEmpty) return selectedCategory == 'Meals';

    switch (selectedCategory) {
      case 'Meals':
        return category.contains('meal') ||
            category.contains('main') ||
            category.contains('rice') ||
            category.contains('ulam') ||
            category.contains('viand');
      case 'Drinks':
        return category.contains('drink') ||
            category.contains('beverage') ||
            category.contains('coffee') ||
            category.contains('juice') ||
            category.contains('tea');
      case 'Snacks':
        return category.contains('snack') ||
            category.contains('finger') ||
            category.contains('appetizer');
      case 'Desserts':
        return category.contains('dessert') ||
            category.contains('sweet') ||
            category.contains('cake');
      default:
        return true;
    }
  }

  @override
  Widget build(BuildContext context) {
    final cartCount = context.watch<AppState>().cart.length;
    final colorScheme = Theme.of(context).colorScheme;

    return LayoutBuilder(
      builder: (context, constraints) {
        final contentMaxWidth =
            constraints.maxWidth >= 900 ? 1120.0 : constraints.maxWidth;

        return Align(
          alignment: Alignment.topCenter,
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: contentMaxWidth),
            child: FutureBuilder<List<MenuItemModel>>(
              future: _future,
              builder: (context, snapshot) {
                final allItems = snapshot.data ?? const <MenuItemModel>[];
                final filteredItems = allItems
                    .where((item) => _matchesCategory(item, _selectedCategory))
                    .toList();
                final items = filteredItems.isEmpty ? allItems : filteredItems;

                return RefreshIndicator(
                  onRefresh: () async {
                    setState(() {
                      _future = context.read<AppState>().fetchMenu();
                    });
                    await _future;
                  },
                  child: SingleChildScrollView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 14, 16, 20),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    children: [
                                      FaIcon(
                                        FontAwesomeIcons.utensils,
                                        size: 16,
                                        color: colorScheme.primary,
                                      ),
                                      const SizedBox(width: 8),
                                      Text(
                                        'Menu & Ordering',
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
                                    'Browse meals and add items to your cart',
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
                            Container(
                              padding: const EdgeInsets.symmetric(
                                  horizontal: 12, vertical: 8),
                              decoration: BoxDecoration(
                                color: colorScheme.primaryContainer
                                    .withValues(alpha: 0.7),
                                borderRadius: BorderRadius.circular(999),
                              ),
                              child: Row(
                                children: [
                                  FaIcon(
                                    FontAwesomeIcons.cartShopping,
                                    size: 14,
                                    color: colorScheme.onPrimaryContainer,
                                  ),
                                  const SizedBox(width: 6),
                                  Text(
                                    'Cart: $cartCount',
                                    style: TextStyle(
                                      color: colorScheme.onPrimaryContainer,
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 10),
                        SingleChildScrollView(
                          scrollDirection: Axis.horizontal,
                          child: Row(
                            children: categories.map((cat) {
                              final selected = cat == _selectedCategory;
                              final icon = switch (cat) {
                                'Meals' => FontAwesomeIcons.utensils,
                                'Drinks' => FontAwesomeIcons.glassWater,
                                'Snacks' => FontAwesomeIcons.cookieBite,
                                'Desserts' => FontAwesomeIcons.iceCream,
                                _ => FontAwesomeIcons.tag,
                              };

                              return Padding(
                                padding: const EdgeInsets.only(right: 10),
                                child: ChoiceChip(
                                  label: Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      FaIcon(
                                        icon,
                                        size: 13,
                                        color: selected
                                            ? colorScheme.onPrimaryContainer
                                            : colorScheme.onSurfaceVariant,
                                      ),
                                      const SizedBox(width: 6),
                                      Text(cat),
                                    ],
                                  ),
                                  selected: selected,
                                  showCheckmark: false,
                                  labelStyle: TextStyle(
                                    fontWeight: FontWeight.w700,
                                    color: selected
                                        ? colorScheme.onPrimaryContainer
                                        : colorScheme.onSurfaceVariant,
                                  ),
                                  backgroundColor: colorScheme
                                      .surfaceContainerHighest
                                      .withValues(alpha: 0.55),
                                  selectedColor: colorScheme.primaryContainer,
                                  side: BorderSide(
                                    color: colorScheme.outlineVariant
                                        .withValues(alpha: 0.45),
                                  ),
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(999),
                                  ),
                                  onSelected: (_) {
                                    setState(() {
                                      _selectedCategory = cat;
                                    });
                                  },
                                ),
                              );
                            }).toList(),
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          '${items.length} item(s) available',
                          style:
                              Theme.of(context).textTheme.bodySmall?.copyWith(
                                    color: colorScheme.onSurfaceVariant,
                                    fontWeight: FontWeight.w600,
                                  ),
                        ),
                        const SizedBox(height: 12),
                        AnimatedSwitcher(
                          duration: const Duration(milliseconds: 250),
                          switchInCurve: Curves.easeOut,
                          switchOutCurve: Curves.easeIn,
                          child:
                              snapshot.connectionState ==
                                      ConnectionState.waiting
                                  ? const Padding(
                                      key: ValueKey('menu-loading'),
                                      padding: EdgeInsets.only(top: 40),
                                      child: Center(
                                          child: CircularProgressIndicator()),
                                    )
                                  : snapshot.hasError
                                      ? Padding(
                                          key: const ValueKey('menu-error'),
                                          padding: const EdgeInsets.all(8),
                                          child: Text(
                                            'Failed to load menu:\n${snapshot.error}',
                                            textAlign: TextAlign.center,
                                          ),
                                        )
                                      : items.isEmpty
                                          ? Padding(
                                              key: const ValueKey('menu-empty'),
                                              padding: const EdgeInsets.all(8),
                                              child: Column(
                                                children: [
                                                  const Text(
                                                    'No food items returned by the server.',
                                                    textAlign: TextAlign.center,
                                                  ),
                                                  const SizedBox(height: 8),
                                                  Text(
                                                    'API: ${ApiService.configuredBaseUrl}',
                                                    textAlign: TextAlign.center,
                                                    style: Theme.of(context)
                                                        .textTheme
                                                        .bodySmall,
                                                  ),
                                                  const SizedBox(height: 10),
                                                  OutlinedButton.icon(
                                                    onPressed: () {
                                                      setState(() {
                                                        _future = context
                                                            .read<AppState>()
                                                            .fetchMenu();
                                                      });
                                                    },
                                                    icon: const Icon(
                                                        Icons.refresh),
                                                    label: const Text(
                                                        'Reload Menu'),
                                                  ),
                                                ],
                                              ),
                                            )
                                          : Column(
                                              key: const ValueKey('menu-items'),
                                              children: [
                                                ...items
                                                    .asMap()
                                                    .entries
                                                    .map((entry) {
                                                  final index = entry.key;
                                                  final item = entry.value;
                                                  final imageUrl =
                                                      _resolveImageUrl(
                                                          item.imageUrl);
                                                  final initial =
                                                      item.name.isNotEmpty
                                                          ? item.name.characters
                                                              .first
                                                              .toUpperCase()
                                                          : '?';
                                                  return TweenAnimationBuilder<
                                                      double>(
                                                    tween:
                                                        Tween(begin: 0, end: 1),
                                                    duration: Duration(
                                                        milliseconds:
                                                            220 + (index * 30)),
                                                    curve: Curves.easeOutCubic,
                                                    child: Container(
                                                      margin:
                                                          const EdgeInsets.only(
                                                              bottom: 10),
                                                      height: 176,
                                                      decoration: BoxDecoration(
                                                        borderRadius:
                                                            BorderRadius
                                                                .circular(18),
                                                        border: Border.all(
                                                          color: colorScheme
                                                              .outlineVariant
                                                              .withValues(
                                                                  alpha: 0.35),
                                                        ),
                                                        boxShadow: [
                                                          BoxShadow(
                                                            color: colorScheme
                                                                .shadow
                                                                .withValues(
                                                                    alpha:
                                                                        0.05),
                                                            blurRadius: 10,
                                                            offset:
                                                                const Offset(
                                                                    0, 4),
                                                          ),
                                                        ],
                                                      ),
                                                      child: ClipRRect(
                                                        borderRadius:
                                                            BorderRadius
                                                                .circular(18),
                                                        child: Stack(
                                                          fit: StackFit.expand,
                                                          children: [
                                                            if (imageUrl !=
                                                                null)
                                                              Image.network(
                                                                imageUrl,
                                                                fit: BoxFit
                                                                    .cover,
                                                                filterQuality:
                                                                    FilterQuality
                                                                        .high,
                                                                errorBuilder: (_,
                                                                        __,
                                                                        ___) =>
                                                                    Container(
                                                                  color: colorScheme
                                                                      .primaryContainer
                                                                      .withValues(
                                                                          alpha:
                                                                              0.55),
                                                                  alignment:
                                                                      Alignment
                                                                          .center,
                                                                  child: Text(
                                                                    initial,
                                                                    style:
                                                                        TextStyle(
                                                                      color: colorScheme
                                                                          .onPrimaryContainer,
                                                                      fontWeight:
                                                                          FontWeight
                                                                              .w800,
                                                                      fontSize:
                                                                          28,
                                                                    ),
                                                                  ),
                                                                ),
                                                              )
                                                            else
                                                              Container(
                                                                decoration:
                                                                    BoxDecoration(
                                                                  gradient:
                                                                      LinearGradient(
                                                                    begin: Alignment
                                                                        .topLeft,
                                                                    end: Alignment
                                                                        .bottomRight,
                                                                    colors: [
                                                                      colorScheme
                                                                          .primaryContainer
                                                                          .withValues(
                                                                              alpha: 0.75),
                                                                      colorScheme
                                                                          .secondaryContainer
                                                                          .withValues(
                                                                              alpha: 0.45),
                                                                    ],
                                                                  ),
                                                                ),
                                                                alignment:
                                                                    Alignment
                                                                        .center,
                                                                child: Text(
                                                                  initial,
                                                                  style:
                                                                      TextStyle(
                                                                    color: colorScheme
                                                                        .onPrimaryContainer,
                                                                    fontWeight:
                                                                        FontWeight
                                                                            .w800,
                                                                    fontSize:
                                                                        32,
                                                                  ),
                                                                ),
                                                              ),
                                                            Container(
                                                              decoration:
                                                                  BoxDecoration(
                                                                gradient:
                                                                    LinearGradient(
                                                                  begin: Alignment
                                                                      .topCenter,
                                                                  end: Alignment
                                                                      .bottomCenter,
                                                                  colors: [
                                                                    Colors.black
                                                                        .withValues(
                                                                            alpha:
                                                                                0.0),
                                                                    Colors.black
                                                                        .withValues(
                                                                            alpha:
                                                                                0.22),
                                                                  ],
                                                                ),
                                                              ),
                                                            ),
                                                            Align(
                                                              alignment: Alignment
                                                                  .bottomCenter,
                                                              child: Container(
                                                                width: double
                                                                    .infinity,
                                                                padding:
                                                                    const EdgeInsets
                                                                        .fromLTRB(
                                                                        14,
                                                                        14,
                                                                        14,
                                                                        10),
                                                                decoration:
                                                                    BoxDecoration(
                                                                  color: Colors
                                                                      .black
                                                                      .withValues(
                                                                          alpha:
                                                                              0.38),
                                                                  border:
                                                                      Border(
                                                                    top:
                                                                        BorderSide(
                                                                      color: Colors
                                                                          .white
                                                                          .withValues(
                                                                              alpha: 0.18),
                                                                    ),
                                                                  ),
                                                                ),
                                                                child: Column(
                                                                  mainAxisSize:
                                                                      MainAxisSize
                                                                          .min,
                                                                  crossAxisAlignment:
                                                                      CrossAxisAlignment
                                                                          .start,
                                                                  children: [
                                                                    Text(
                                                                      item.name,
                                                                      style:
                                                                          const TextStyle(
                                                                        fontSize:
                                                                            16,
                                                                        fontWeight:
                                                                            FontWeight.w800,
                                                                        color: Colors
                                                                            .white,
                                                                        shadows: [
                                                                          Shadow(
                                                                            color:
                                                                                Colors.black54,
                                                                            blurRadius:
                                                                                8,
                                                                          ),
                                                                        ],
                                                                      ),
                                                                      maxLines:
                                                                          1,
                                                                      overflow:
                                                                          TextOverflow
                                                                              .ellipsis,
                                                                    ),
                                                                    const SizedBox(
                                                                        height:
                                                                            2),
                                                                    Text(
                                                                      item.description ??
                                                                          item.category,
                                                                      maxLines:
                                                                          1,
                                                                      overflow:
                                                                          TextOverflow
                                                                              .ellipsis,
                                                                      style: Theme.of(
                                                                              context)
                                                                          .textTheme
                                                                          .bodySmall
                                                                          ?.copyWith(
                                                                            color:
                                                                                Colors.white.withValues(alpha: 0.95),
                                                                            height:
                                                                                1.2,
                                                                          ),
                                                                    ),
                                                                    const SizedBox(
                                                                        height:
                                                                            8),
                                                                    Row(
                                                                      children: [
                                                                        Text(
                                                                          'PHP ${item.price.toStringAsFixed(2)}',
                                                                          style:
                                                                              const TextStyle(
                                                                            fontWeight:
                                                                                FontWeight.w800,
                                                                            color:
                                                                                Colors.white,
                                                                            fontSize:
                                                                                16,
                                                                          ),
                                                                        ),
                                                                        const Spacer(),
                                                                        ConstrainedBox(
                                                                          constraints:
                                                                              const BoxConstraints(
                                                                            minWidth:
                                                                                66,
                                                                            maxWidth:
                                                                                88,
                                                                            minHeight:
                                                                                30,
                                                                            maxHeight:
                                                                                30,
                                                                          ),
                                                                          child:
                                                                              FilledButton.tonalIcon(
                                                                            style:
                                                                                FilledButton.styleFrom(
                                                                              minimumSize: const Size(66, 30),
                                                                              maximumSize: const Size(88, 30),
                                                                              backgroundColor: colorScheme.primary,
                                                                              foregroundColor: colorScheme.onPrimary,
                                                                              padding: const EdgeInsets.symmetric(
                                                                                horizontal: 8,
                                                                                vertical: 0,
                                                                              ),
                                                                              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                                                                            ),
                                                                            onPressed:
                                                                                () {
                                                                              context.read<AppState>().addToCart(item);
                                                                              ScaffoldMessenger.of(context).showSnackBar(
                                                                                SnackBar(
                                                                                  content: Text('${item.name} added to cart'),
                                                                                ),
                                                                              );
                                                                            },
                                                                            icon:
                                                                                const FaIcon(FontAwesomeIcons.plus, size: 11),
                                                                            label:
                                                                                const Text(
                                                                              'Add',
                                                                              style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
                                                                            ),
                                                                          ),
                                                                        ),
                                                                      ],
                                                                    ),
                                                                  ],
                                                                ),
                                                              ),
                                                            ),
                                                          ],
                                                        ),
                                                      ),
                                                    ),
                                                    builder: (context, value,
                                                        child) {
                                                      return Opacity(
                                                        opacity: value,
                                                        child:
                                                            Transform.translate(
                                                          offset: Offset(0,
                                                              (1 - value) * 12),
                                                          child: child,
                                                        ),
                                                      );
                                                    },
                                                  );
                                                }),
                                              ],
                                            ),
                        ),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
        );
      },
    );
  }
}
