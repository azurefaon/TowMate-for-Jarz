import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../core/theme.dart';
import '../../services/tl_expense_service.dart';
import '../../widgets/tl_bottom_nav.dart';
import 'tl_expense_form_screen.dart';

/// Formats a decimal string such as "2950.5" as "₱2,950.50" without converting to floating point.
String formatPeso(String amount) {
  final parts = amount.trim().split('.');
  final whole = parts[0].replaceAll(RegExp(r'[^0-9]'), '');
  final cents = (parts.length > 1 ? parts[1] : '').padRight(2, '0').substring(0, 2);
  final grouped = whole.isEmpty ? '0' : whole.replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');
  return '₱$grouped.$cents';
}

/// Team Leader > Expenses: the Team Leader's submitted expenses and their review status, plus "New expense".
class TlExpensesScreen extends StatefulWidget {
  const TlExpensesScreen({super.key});

  @override
  State<TlExpensesScreen> createState() => _TlExpensesScreenState();
}

class _TlExpensesScreenState extends State<TlExpensesScreen> {
  List<TlExpense> _items = const [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    final res = await TlExpenseService.list();
    if (!mounted) return;
    setState(() {
      _loading = false;
      _items = res.items;
      _error = res.error;
    });
  }

  Future<void> _openForm() async {
    final submitted = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => const TlExpenseFormScreen()));
    if (submitted == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      appBar: AppBar(
        title: Text('Expenses', style: GoogleFonts.inter(fontWeight: FontWeight.w700)),
        backgroundColor: context.bg,
        foregroundColor: context.textPrimary,
        elevation: 0,
      ),
      floatingActionButton: FloatingActionButton.extended(
        key: const Key('tlExpenseNew'),
        onPressed: _openForm,
        backgroundColor: TmColors.yellow,
        foregroundColor: TmColors.black,
        icon: const Icon(Icons.add),
        label: Text('New expense', style: GoogleFonts.inter(fontWeight: FontWeight.w700)),
      ),
      bottomNavigationBar: const TlBottomNav(currentRoute: '/tl-expenses'),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _body(),
      ),
    );
  }

  Widget _body() {
    if (_loading) {
      return ListView(children: const [SizedBox(height: 160), Center(child: CircularProgressIndicator())]);
    }
    if (_error != null && _items.isEmpty) {
      return ListView(padding: const EdgeInsets.all(24), children: [
        const SizedBox(height: 80),
        Icon(Icons.cloud_off_rounded, size: 40, color: context.textTertiary),
        const SizedBox(height: 12),
        Text(_error!, key: const Key('tlExpenseError'), textAlign: TextAlign.center, style: GoogleFonts.inter(color: context.textSecondary)),
        const SizedBox(height: 16),
        Center(child: OutlinedButton(onPressed: _load, child: const Text('Try again'))),
      ]);
    }
    if (_items.isEmpty) {
      return ListView(padding: const EdgeInsets.all(24), children: [
        const SizedBox(height: 80),
        Icon(Icons.receipt_long_outlined, size: 40, color: context.textTertiary),
        const SizedBox(height: 12),
        Text('No expenses yet. Tap "New expense" to record fuel, tolls, parking and other costs for your unit.',
            key: const Key('tlExpenseEmpty'), textAlign: TextAlign.center, style: GoogleFonts.inter(color: context.textSecondary)),
      ]);
    }
    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
      itemCount: _items.length,
      separatorBuilder: (_, _) => const SizedBox(height: 10),
      itemBuilder: (_, i) => _ExpenseCard(expense: _items[i]),
    );
  }
}

class _ExpenseCard extends StatelessWidget {
  const _ExpenseCard({required this.expense});

  final TlExpense expense;

  (Color, Color) _statusColors() => switch (expense.status) {
        'approved' => (const Color(0xFFDCFCE7), const Color(0xFF166534)),
        'rejected' => (const Color(0xFFFEE2E2), const Color(0xFF991B1B)),
        'voided' => (const Color(0xFFF5F5F5), const Color(0xFF525252)),
        'verified' => (const Color(0xFFDBEAFE), const Color(0xFF1E40AF)),
        _ => (const Color(0xFFFEF3C7), const Color(0xFF92400E)),
      };

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = _statusColors();
    final e = expense;
    return Container(
      key: Key('tlExpense-${e.id}'),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: context.card, borderRadius: BorderRadius.circular(14), border: Border.all(color: context.divider)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text(e.categoryLabel, style: GoogleFonts.inter(fontWeight: FontWeight.w700, fontSize: 15, color: context.textPrimary))),
          Text(formatPeso(e.amount), style: GoogleFonts.inter(fontWeight: FontWeight.w800, fontSize: 15, color: context.textPrimary)),
        ]),
        const SizedBox(height: 4),
        Text(
          '${e.reference} · ${e.expenseDate}${e.bookingReference != null ? ' · Job ${e.bookingReference}' : ''}${e.coverageMonth != null ? ' · Month ${e.coverageMonth}' : ''}',
          style: GoogleFonts.inter(fontSize: 12, color: context.textTertiary),
        ),
        const SizedBox(height: 6),
        Text(e.description, maxLines: 2, overflow: TextOverflow.ellipsis, style: GoogleFonts.inter(fontSize: 13, color: context.textSecondary)),
        const SizedBox(height: 10),
        Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
            decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(999)),
            child: Text(e.statusLabel, style: GoogleFonts.inter(fontSize: 12, fontWeight: FontWeight.w700, color: fg)),
          ),
          Text('${e.evidenceCount} receipt(s)', style: GoogleFonts.inter(fontSize: 12, color: e.evidenceCount == 0 ? TmColors.destructive : context.textTertiary)),
          ...e.fundings.map((f) => Text('${f['label']}: ${formatPeso('${f['amount']}')}', style: GoogleFonts.inter(fontSize: 12, color: context.textTertiary))),
        ]),
        if (e.rejectionReason != null && e.rejectionReason!.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text('Rejected: ${e.rejectionReason}', style: GoogleFonts.inter(fontSize: 12, color: TmColors.destructive)),
        ],
      ]),
    );
  }
}
