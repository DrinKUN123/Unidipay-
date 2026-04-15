import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';
import 'package:provider/provider.dart';

import '../services/app_state.dart';
import 'cart_screen.dart';
import 'menu_screen.dart';
import 'orders_screen.dart';
import 'wallet_screen.dart';

class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;

  final _pages = const [
    MenuScreen(),
    CartScreen(),
    WalletScreen(),
    OrdersScreen(),
  ];

  @override
  Widget build(BuildContext context) {
    final student = context.watch<AppState>().student;
    final colorScheme = Theme.of(context).colorScheme;
    final textTheme = Theme.of(context).textTheme;

    return LayoutBuilder(
      builder: (context, constraints) {
        final isWide = constraints.maxWidth >= 980;
        final isCompactTopBar = constraints.maxWidth < 460;
        final balanceText = isCompactTopBar
            ? 'PHP ${(student?.balance ?? 0).toStringAsFixed(0)}'
            : 'PHP ${(student?.balance ?? 0).toStringAsFixed(2)}';

        return Scaffold(
          backgroundColor: colorScheme.surface,
          appBar: AppBar(
            elevation: 0,
            toolbarHeight: isCompactTopBar ? 70 : 78,
            titleSpacing: 12,
            title: Row(
              children: [
                Container(
                  width: isCompactTopBar ? 44 : 52,
                  height: isCompactTopBar ? 44 : 52,
                  padding: const EdgeInsets.all(6),
                  decoration: BoxDecoration(
                    color: colorScheme.surface,
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(
                      color: colorScheme.outlineVariant.withValues(alpha: 0.5),
                    ),
                  ),
                  child: Image.asset(
                    'assets/images/unidipay_logo.png',
                    fit: BoxFit.contain,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'UNIDIPAY',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: (isCompactTopBar
                                ? textTheme.labelLarge
                                : textTheme.titleSmall)
                            ?.copyWith(
                          color: colorScheme.primary,
                          fontWeight: FontWeight.w900,
                          letterSpacing: 0.7,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        student?.name ?? 'Student portal',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: textTheme.bodyMedium?.copyWith(
                          color: colorScheme.onSurfaceVariant,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            actions: [
              Padding(
                padding: const EdgeInsets.only(right: 6),
                child: Center(
                  child: Container(
                    padding: EdgeInsets.symmetric(
                      horizontal: isCompactTopBar ? 10 : 14,
                      vertical: isCompactTopBar ? 7 : 9,
                    ),
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        colors: [
                          colorScheme.primaryContainer,
                          colorScheme.secondaryContainer,
                        ],
                      ),
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: Text(
                      balanceText,
                      style: TextStyle(
                        color: colorScheme.onPrimaryContainer,
                        fontWeight: FontWeight.w700,
                        fontSize: isCompactTopBar ? 12 : 14,
                      ),
                    ),
                  ),
                ),
              ),
              if (isCompactTopBar)
                PopupMenuButton<String>(
                  tooltip: 'More actions',
                  onSelected: (value) {
                    if (value == 'refresh') {
                      context.read<AppState>().refreshProfile();
                    } else if (value == 'logout') {
                      context.read<AppState>().logout();
                    }
                  },
                  itemBuilder: (context) => const [
                    PopupMenuItem<String>(
                      value: 'refresh',
                      child: Row(
                        children: [
                          FaIcon(FontAwesomeIcons.arrowsRotate, size: 14),
                          SizedBox(width: 10),
                          Text('Refresh profile'),
                        ],
                      ),
                    ),
                    PopupMenuItem<String>(
                      value: 'logout',
                      child: Row(
                        children: [
                          FaIcon(FontAwesomeIcons.rightFromBracket, size: 14),
                          SizedBox(width: 10),
                          Text('Logout'),
                        ],
                      ),
                    ),
                  ],
                  child: const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 8),
                    child: Icon(Icons.more_vert),
                  ),
                )
              else ...[
                IconButton(
                  tooltip: 'Refresh profile',
                  onPressed: () => context.read<AppState>().refreshProfile(),
                  icon: const FaIcon(FontAwesomeIcons.arrowsRotate, size: 17),
                ),
                IconButton(
                  tooltip: 'Logout',
                  onPressed: () => context.read<AppState>().logout(),
                  icon:
                      const FaIcon(FontAwesomeIcons.rightFromBracket, size: 17),
                ),
                const SizedBox(width: 8),
              ],
            ],
          ),
          body: Container(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  colorScheme.primaryContainer.withValues(alpha: 0.22),
                  colorScheme.surface,
                  colorScheme.tertiaryContainer.withValues(alpha: 0.14),
                ],
              ),
            ),
            child: isWide
                ? Row(
                    children: [
                      NavigationRail(
                        selectedIndex: _index,
                        onDestinationSelected: (i) =>
                            setState(() => _index = i),
                        labelType: NavigationRailLabelType.all,
                        destinations: const [
                          NavigationRailDestination(
                            icon: FaIcon(FontAwesomeIcons.utensils, size: 18),
                            label: Text('Menu'),
                          ),
                          NavigationRailDestination(
                            icon:
                                FaIcon(FontAwesomeIcons.cartShopping, size: 18),
                            label: Text('Cart'),
                          ),
                          NavigationRailDestination(
                            icon: FaIcon(FontAwesomeIcons.wallet, size: 18),
                            label: Text('Wallet'),
                          ),
                          NavigationRailDestination(
                            icon: FaIcon(FontAwesomeIcons.receipt, size: 18),
                            label: Text('Orders'),
                          ),
                        ],
                      ),
                      const VerticalDivider(width: 1),
                      Expanded(
                        child: Align(
                          alignment: Alignment.topCenter,
                          child: ConstrainedBox(
                            constraints: const BoxConstraints(maxWidth: 1180),
                            child: _pages[_index],
                          ),
                        ),
                      ),
                    ],
                  )
                : _pages[_index],
          ),
          bottomNavigationBar: isWide
              ? null
              : NavigationBar(
                  selectedIndex: _index,
                  onDestinationSelected: (i) => setState(() => _index = i),
                  height: 72,
                  destinations: const [
                    NavigationDestination(
                      icon: FaIcon(FontAwesomeIcons.utensils, size: 18),
                      label: 'Menu',
                    ),
                    NavigationDestination(
                      icon: FaIcon(FontAwesomeIcons.cartShopping, size: 18),
                      label: 'Cart',
                    ),
                    NavigationDestination(
                      icon: FaIcon(FontAwesomeIcons.wallet, size: 18),
                      label: 'Wallet',
                    ),
                    NavigationDestination(
                      icon: FaIcon(FontAwesomeIcons.receipt, size: 18),
                      label: 'Orders',
                    ),
                  ],
                ),
        );
      },
    );
  }
}
