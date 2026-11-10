import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/theme.dart';
import '../../services/tl_expense_service.dart';
import 'tl_expenses_screen.dart' show formatPeso;

/// Team Leader > Expenses > New expense. The unit is always the Team Leader's own (decided by the server).
/// Submitting sends the expense to the Dispatcher for verification; nothing is approved here.
class TlExpenseFormScreen extends StatefulWidget {
  const TlExpenseFormScreen({super.key, this.picker});

  /// Injectable for tests.
  final ImagePicker? picker;

  @override
  State<TlExpenseFormScreen> createState() => _TlExpenseFormScreenState();
}

class _TlExpenseFormScreenState extends State<TlExpenseFormScreen> {
  static const _maxReceipts = 5;
  final _form = GlobalKey<FormState>();
  final _amount = TextEditingController();
  final _description = TextEditingController();
  final _booking = TextEditingController();
  late final String _idempotencyKey = TlExpenseService.newIdempotencyKey();

  TlExpenseOptions? _options;
  String? _loadError;
  bool _loading = true;
  bool _submitting = false;
  String? _submitError;
  String? _receiptError;

  String _scope = 'job';
  String? _category;
  String? _funding;
  DateTime _date = DateTime.now();
  DateTime _month = DateTime(DateTime.now().year, DateTime.now().month);
  final List<XFile> _receipts = [];

  @override
  void initState() {
    super.initState();
    _loadOptions();
  }

  @override
  void dispose() {
    _amount.dispose();
    _description.dispose();
    _booking.dispose();
    super.dispose();
  }

  Future<void> _loadOptions() async {
    setState(() {
      _loading = true;
      _loadError = null;
    });
    final res = await TlExpenseService.fetchOptions();
    if (!mounted) return;
    setState(() {
      _loading = false;
      _options = res.options;
      _loadError = res.error;
      _funding ??= res.options?.fundingSources.isNotEmpty == true ? res.options!.fundingSources.first.key : null;
    });
  }

  String _ymd(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
  String _ym(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}';

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(context: context, initialDate: _date, firstDate: DateTime(now.year - 1), lastDate: now);
    if (picked != null) setState(() => _date = picked);
  }

  Future<void> _addReceipt(ImageSource source) async {
    if (_receipts.length >= _maxReceipts) return;
    final picker = widget.picker ?? ImagePicker();
    final file = await picker.pickImage(source: source, imageQuality: 80, maxWidth: 2000);
    if (file == null || !mounted) return;
    final kb = (await file.readAsBytes()).length / 1024;
    if (kb > (_options?.maxKilobytes ?? 8192)) {
      setState(() => _receiptError = 'That photo is too large. Each receipt must be under ${((_options?.maxKilobytes ?? 8192) / 1024).round()} MB.');
      return;
    }
    setState(() {
      _receipts.add(file);
      _receiptError = null;
    });
  }

  static final _amountPattern = RegExp(r'^\d{1,10}(\.\d{1,2})?$');

  String? _validateAmount(String? v) {
    final s = (v ?? '').replaceAll(',', '').trim();
    if (s.isEmpty) return 'Enter the amount paid.';
    if (!_amountPattern.hasMatch(s)) return 'Enter a valid amount with at most two decimal places, for example 2950 or 2950.50.';
    if (RegExp(r'^0+(\.0+)?$').hasMatch(s)) return 'The amount must be more than zero.';
    return null;
  }

  Future<void> _submit() async {
    setState(() {
      _submitError = null;
      _receiptError = _receipts.isEmpty ? 'Attach at least one receipt photo.' : null;
    });
    final valid = _form.currentState?.validate() ?? false;
    if (!valid || _receipts.isEmpty || _category == null || _funding == null) return;

    setState(() => _submitting = true);
    final res = await TlExpenseService.submit(
      idempotencyKey: _idempotencyKey,
      scope: _scope,
      expenseDate: _ymd(_date),
      category: _category!,
      description: _description.text.trim(),
      amount: _amount.text.replaceAll(',', '').trim(),
      fundingSource: _funding!,
      receipts: List.of(_receipts),
      bookingRef: _scope == 'job' ? _booking.text.trim() : null,
      coverageMonth: _scope == 'unit' ? _ym(_month) : null,
    );
    if (!mounted) return;
    setState(() => _submitting = false);

    if (res['success'] == true) {
      final warning = res['warning'] as String?;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(warning == null ? '${res['message']}' : '${res['message']} $warning'),
        duration: Duration(seconds: warning == null ? 3 : 6),
      ));
      Navigator.of(context).pop(true);
    } else {
      setState(() => _submitError = '${res['message']}');
    }
  }

  InputDecoration _dec(String label, {String? hint}) => InputDecoration(
        labelText: label,
        hintText: hint,
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: TmColors.black, width: 1.5)),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: context.bg,
      appBar: AppBar(
        title: Text('New expense', style: GoogleFonts.inter(fontWeight: FontWeight.w700)),
        backgroundColor: context.bg,
        foregroundColor: context.textPrimary,
        elevation: 0,
      ),
      body: SafeArea(child: _content()),
    );
  }

  Widget _content() {
    if (_loading) return const Center(child: CircularProgressIndicator());
    final o = _options;
    if (o == null) {
      return Center(child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(_loadError ?? 'Could not load the expense form.', key: const Key('tlExpenseFormError'), textAlign: TextAlign.center),
          const SizedBox(height: 12),
          OutlinedButton(onPressed: _loadOptions, child: const Text('Try again')),
        ]),
      ));
    }
    if (!o.hasUnit) {
      return const Center(child: Padding(
        padding: EdgeInsets.all(24),
        child: Text('You are not assigned to a unit, so you cannot record expenses yet. Ask the Dispatcher to assign you.',
            key: Key('tlExpenseNoUnit'), textAlign: TextAlign.center),
      ));
    }

    return Form(
      key: _form,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        children: [
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: context.surface, borderRadius: BorderRadius.circular(12)),
            child: Text(
              'Unit: ${o.unitName}${o.availableUnitFunds != null ? ' · Unit funds available: ${formatPeso(o.availableUnitFunds!)}' : ''}',
              key: const Key('tlExpenseUnit'),
              style: GoogleFonts.inter(fontWeight: FontWeight.w600, color: context.textPrimary),
            ),
          ),
          const SizedBox(height: 16),
          SegmentedButton<String>(
            key: const Key('tlExpenseScope'),
            segments: o.scopes.map((s) => ButtonSegment(value: s.key, label: Text(s.value))).toList(),
            selected: {_scope},
            onSelectionChanged: _submitting ? null : (s) => setState(() => _scope = s.first),
          ),
          const SizedBox(height: 16),
          if (_scope == 'job')
            TextFormField(
              key: const Key('tlExpenseBooking'),
              controller: _booking,
              enabled: !_submitting,
              decoration: _dec('Booking reference', hint: 'For example 1234567'),
              validator: (v) => (v ?? '').trim().isEmpty ? 'A job expense needs the booking reference.' : null,
            )
          else
            ListTile(
              key: const Key('tlExpenseMonth'),
              contentPadding: EdgeInsets.zero,
              title: const Text('Month covered'),
              subtitle: Text(_ym(_month)),
              trailing: const Icon(Icons.calendar_month),
              onTap: _submitting
                  ? null
                  : () async {
                      final picked = await showDatePicker(context: context, initialDate: _month, firstDate: DateTime(DateTime.now().year - 1), lastDate: DateTime.now());
                      if (picked != null) setState(() => _month = DateTime(picked.year, picked.month));
                    },
            ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: const Key('tlExpenseCategory'),
            initialValue: _category,
            isExpanded: true,
            decoration: _dec('Category'),
            items: o.categories.map((c) => DropdownMenuItem(value: c.key, child: Text(c.value))).toList(),
            onChanged: _submitting ? null : (v) => setState(() => _category = v),
            validator: (v) => v == null ? 'Choose a category.' : null,
          ),
          const SizedBox(height: 12),
          TextFormField(
            key: const Key('tlExpenseAmount'),
            controller: _amount,
            enabled: !_submitting,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: _dec('Amount paid (₱)', hint: '2950.00'),
            validator: _validateAmount,
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: const Key('tlExpenseFunding'),
            initialValue: _funding,
            isExpanded: true,
            decoration: _dec('Paid from'),
            items: o.fundingSources.map((f) => DropdownMenuItem(value: f.key, child: Text(f.value))).toList(),
            onChanged: _submitting ? null : (v) => setState(() => _funding = v),
            validator: (v) => v == null ? 'Choose how it was paid.' : null,
          ),
          const SizedBox(height: 12),
          ListTile(
            key: const Key('tlExpenseDate'),
            contentPadding: EdgeInsets.zero,
            title: const Text('Date paid'),
            subtitle: Text(_ymd(_date)),
            trailing: const Icon(Icons.event),
            onTap: _submitting ? null : _pickDate,
          ),
          TextFormField(
            key: const Key('tlExpenseDescription'),
            controller: _description,
            enabled: !_submitting,
            maxLines: 3,
            maxLength: 2000,
            decoration: _dec('Description', hint: 'What was paid for, and where'),
            validator: (v) => (v ?? '').trim().isEmpty ? 'Describe the expense.' : null,
          ),
          const SizedBox(height: 8),
          Text('Receipts (${_receipts.length}/$_maxReceipts)', style: GoogleFonts.inter(fontWeight: FontWeight.w700, color: context.textPrimary)),
          const SizedBox(height: 8),
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (var i = 0; i < _receipts.length; i++)
              InputChip(
                key: Key('tlExpenseReceipt-$i'),
                label: Text(_receipts[i].name, overflow: TextOverflow.ellipsis),
                onDeleted: _submitting ? null : () => setState(() => _receipts.removeAt(i)),
              ),
            if (_receipts.length < _maxReceipts) ...[
              OutlinedButton.icon(
                key: const Key('tlExpenseCamera'),
                onPressed: _submitting ? null : () => _addReceipt(ImageSource.camera),
                icon: const Icon(Icons.photo_camera_outlined),
                label: const Text('Take photo'),
              ),
              OutlinedButton.icon(
                key: const Key('tlExpenseGallery'),
                onPressed: _submitting ? null : () => _addReceipt(ImageSource.gallery),
                icon: const Icon(Icons.photo_library_outlined),
                label: const Text('Choose photo'),
              ),
            ],
          ]),
          if (_receiptError != null)
            Padding(padding: const EdgeInsets.only(top: 6), child: Text(_receiptError!, key: const Key('tlExpenseReceiptError'), style: const TextStyle(color: TmColors.destructive))),
          if (_submitError != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(_submitError!, key: const Key('tlExpenseSubmitError'), style: const TextStyle(color: TmColors.destructive, fontWeight: FontWeight.w600)),
            ),
          const SizedBox(height: 20),
          SizedBox(
            height: 52,
            child: FilledButton(
              key: const Key('tlExpenseSubmit'),
              style: FilledButton.styleFrom(backgroundColor: TmColors.yellow, foregroundColor: TmColors.black, disabledBackgroundColor: TmColors.grey300),
              onPressed: _submitting ? null : _submit,
              child: _submitting
                  ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: TmColors.black))
                  : Text('Submit for Dispatcher review', style: GoogleFonts.inter(fontWeight: FontWeight.w700)),
            ),
          ),
          const SizedBox(height: 8),
          Text('The Dispatcher verifies the receipt, then the Owner approves it. To split one expense across funding sources, ask the Owner to correct it.',
              style: GoogleFonts.inter(fontSize: 12, color: context.textTertiary)),
        ],
      ),
    );
  }
}
