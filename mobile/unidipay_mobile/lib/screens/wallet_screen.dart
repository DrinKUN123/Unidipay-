import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../services/app_state.dart';

class WalletScreen extends StatefulWidget {
  const WalletScreen({super.key});

  @override
  State<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends State<WalletScreen> {
  final _amountController = TextEditingController();
  bool _submitting = false;
  bool _syncing = false;
  String? _pendingInvoiceId;
  String? _pendingCheckoutUrl;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _syncPendingPayments(showSnackBarWhenCredited: true);
    });
  }

  @override
  void dispose() {
    _amountController.dispose();
    super.dispose();
  }

  Future<void> _startGcashPayment() async {
    final amount = double.tryParse(_amountController.text.trim());

    if (amount == null || amount <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Enter a valid amount')),
      );
      return;
    }

    setState(() => _submitting = true);
    try {
      final data = await context.read<AppState>().createGcashTopupInvoice(
            amount: amount,
          );

      final invoiceId = (data['invoice_id'] ?? '').toString();
      final checkoutUrl = (data['invoice_url'] ?? '').toString();
      if (invoiceId.isEmpty || checkoutUrl.isEmpty) {
        throw Exception('Failed to create GCash checkout session.');
      }

      setState(() {
        _pendingInvoiceId = invoiceId;
        _pendingCheckoutUrl = checkoutUrl;
      });

      final uri = Uri.parse(checkoutUrl);
      final launched = await launchUrl(uri, mode: LaunchMode.externalApplication);
      if (!launched) {
        throw Exception('Could not open GCash checkout URL.');
      }

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Checkout opened. Complete payment, then tap Verify Payment.')),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Top-up start failed: $e')),
      );
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _verifyPayment() async {
    final invoiceId = _pendingInvoiceId;
    if (invoiceId == null || invoiceId.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('No pending payment to verify.')),
      );
      return;
    }

    setState(() => _submitting = true);
    try {
      final data = await context
          .read<AppState>()
          .verifyGcashTopupInvoice(invoiceId: invoiceId);

      final paid = data['paid'] == true;
      if (!mounted) return;

      if (paid) {
        final newBalance = (data['new_balance'] as num?)?.toDouble() ?? 0;
        _amountController.clear();
        setState(() {
          _pendingInvoiceId = null;
          _pendingCheckoutUrl = null;
        });
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Top-up complete. New balance: PHP ${newBalance.toStringAsFixed(2)}')),
        );
      } else {
        final status = (data['status'] ?? 'PENDING').toString();
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Payment not completed yet. Status: $status')),
        );
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Payment verify failed: $e')),
      );
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  Future<void> _syncPendingPayments({bool showSnackBarWhenCredited = false}) async {
    if (_syncing) return;
    setState(() => _syncing = true);
    try {
      final data = await context.read<AppState>().syncPendingXenditTopups();
      if (!mounted) return;

      final creditedCount = (data['credited_count'] as num?)?.toInt() ?? 0;
      if (showSnackBarWhenCredited && creditedCount > 0) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Synced $creditedCount paid top-up(s). Balance updated.')),
        );
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Sync failed: $e')),
      );
    } finally {
      if (mounted) setState(() => _syncing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final student = context.watch<AppState>().student;
    final colorScheme = Theme.of(context).colorScheme;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
      children: [
        Text(
          'Wallet Load via GCash',
          style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
        ),
        const SizedBox(height: 4),
        Text(
          'Top up your balance without leaving the app',
          style: Theme.of(context).textTheme.bodySmall?.copyWith(color: colorScheme.onSurfaceVariant),
        ),
        const SizedBox(height: 14),
        Card(
          elevation: 1.5,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
          child: ListTile(
            contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            title: const Text('Current Balance', style: TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text('NFC: ${student?.nfcCardId ?? '-'}'),
            leading: CircleAvatar(
              backgroundColor: colorScheme.primaryContainer,
              child: Icon(Icons.account_balance_wallet_rounded, color: colorScheme.onPrimaryContainer),
            ),
            trailing: Text(
              'PHP ${(student?.balance ?? 0).toStringAsFixed(2)}',
              style: TextStyle(fontWeight: FontWeight.w800, color: colorScheme.primary),
            ),
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _amountController,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(
            labelText: 'Amount',
            hintText: 'e.g. 150',
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(18)),
          ),
        ),
        const SizedBox(height: 10),
        if (_pendingInvoiceId != null) ...[
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: colorScheme.primaryContainer.withOpacity(0.3),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  'Pending GCash Payment',
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    color: colorScheme.onPrimaryContainer,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  'Invoice: $_pendingInvoiceId',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(height: 10),
                OutlinedButton.icon(
                  onPressed: _submitting
                      ? null
                      : () async {
                          final url = _pendingCheckoutUrl;
                          if (url == null || url.isEmpty) return;
                          await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
                        },
                  icon: const Icon(Icons.open_in_new),
                  label: const Text('Open Checkout Again'),
                ),
              ],
            ),
          ),
          const SizedBox(height: 10),
        ],
        FilledButton.icon(
          onPressed: _submitting ? null : _startGcashPayment,
          icon: _submitting
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Icon(Icons.account_balance_wallet_rounded),
          label: Text(_submitting ? 'Processing...' : 'Pay with GCash'),
        ),
        const SizedBox(height: 10),
        OutlinedButton.icon(
          onPressed: _submitting || _pendingInvoiceId == null ? null : _verifyPayment,
          icon: const Icon(Icons.verified_rounded),
          label: const Text('Verify Payment'),
        ),
        const SizedBox(height: 8),
        OutlinedButton.icon(
          onPressed: _submitting || _syncing
              ? null
              : () => _syncPendingPayments(showSnackBarWhenCredited: true),
          icon: _syncing
              ? const SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Icon(Icons.sync_rounded),
          label: Text(_syncing ? 'Syncing...' : 'Sync Pending Top-ups'),
        ),
      ],
    );
  }
}
