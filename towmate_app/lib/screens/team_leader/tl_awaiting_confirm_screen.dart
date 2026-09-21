import 'dart:io';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart';
import 'package:signature/signature.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/team_leader_service.dart';
import '../../widgets/quotation_price_cards.dart' show formatPeso;

class _DecimalInputFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    final text = newValue.text;
    if (text.isEmpty) return newValue;
    if (!RegExp(r'^\d*\.?\d{0,2}$').hasMatch(text)) return oldValue;
    if ('.'.allMatches(text).length > 1) return oldValue;
    return newValue;
  }
}

class TlAwaitingConfirmScreen extends StatefulWidget {
  const TlAwaitingConfirmScreen(
      {super.key, required this.task, required this.onUpdate});
  final TaskModel task;
  final void Function(TaskModel) onUpdate;

  @override
  State<TlAwaitingConfirmScreen> createState() =>
      _TlAwaitingConfirmScreenState();
}

class _TlAwaitingConfirmScreenState extends State<TlAwaitingConfirmScreen> {
  final _sigCtrl = SignatureController(
    penStrokeWidth: 2,
    penColor: Colors.black,
    exportBackgroundColor: Colors.white,
  );

  bool _hasSig = false;
  bool _loading = false;
  String? _error;
  String? _selectedPayment;
  final _cashReceivedCtrl = TextEditingController();
  final _cashFocusNode = FocusNode();

  XFile? _paymentProof;
  bool _proofUploaded = false;
  bool _uploadingProof = false;

  final Set<int> _expandedVehicles = {};

  static const _paymentOptions = [
    (value: 'cash',          label: 'Cash',         icon: Icons.payments_rounded),
    (value: 'gcash',         label: 'GCash',        icon: Icons.account_balance_wallet_rounded),
    (value: 'bank_transfer', label: 'Bank Transfer', icon: Icons.account_balance_rounded),
  ];

  bool get _needsProof => _selectedPayment != null;

  double get _amountDue =>
      widget.task.isGroupBooking && widget.task.groupTotal != null
          ? widget.task.groupTotal!
          : widget.task.finalTotal;

  String get _rawCashValue => _cashReceivedCtrl.text.replaceAll(',', '');

  bool get _cashCovers {
    final val = double.tryParse(_rawCashValue);
    return val != null && val >= _amountDue;
  }

  void _onCashFocusChange() {
    final raw = _rawCashValue.trim();
    if (raw.isEmpty) return;
    final val = double.tryParse(raw);
    if (val == null) return;

    final formatted = _cashFocusNode.hasFocus
        ? raw
        : NumberFormat('#,##0.00', 'en_PH').format(val);

    _cashReceivedCtrl.value = TextEditingValue(
      text: formatted,
      selection: TextSelection.collapsed(offset: formatted.length),
    );
  }

  bool get _canSubmit {
    if (!_hasSig) return false;
    if (_selectedPayment == null) return false;
    if (_needsProof && !_proofUploaded) return false;
    if (_selectedPayment == 'cash' && !_cashCovers) return false;
    return true;
  }

  @override
  void initState() {
    super.initState();
    _sigCtrl.addListener(() {
      if (_sigCtrl.isNotEmpty && !_hasSig) {
        setState(() => _hasSig = true);
      }
    });
    _cashReceivedCtrl.addListener(() => setState(() {}));
    _cashFocusNode.addListener(_onCashFocusChange);
  }

  @override
  void dispose() {
    _sigCtrl.dispose();
    _cashReceivedCtrl.dispose();
    _cashFocusNode.dispose();
    super.dispose();
  }

  Future<Uint8List?> _exportSignature() async {
    if (_sigCtrl.isEmpty) return null;
    return _sigCtrl.toPngBytes();
  }

  void _showProofSource() {
    showModalBottomSheet(
      context: context,
      backgroundColor: TmColors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 8),
            ListTile(
              title: Text('Take Photo',
                  style: GoogleFonts.inter(color: TmColors.black, fontSize: 14)),
              onTap: () {
                Navigator.pop(context);
                _pickAndUploadProof(ImageSource.camera);
              },
            ),
            ListTile(
              title: Text('Choose from Gallery',
                  style: GoogleFonts.inter(color: TmColors.black, fontSize: 14)),
              onTap: () {
                Navigator.pop(context);
                _pickAndUploadProof(ImageSource.gallery);
              },
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }

  Future<void> _pickAndUploadProof(ImageSource source) async {
    final file = await ImagePicker().pickImage(source: source, imageQuality: 85);
    if (file == null || !mounted) return;
    setState(() {
      _paymentProof = file;
      _uploadingProof = true;
    });
    final res = await TeamLeaderService.uploadPhoto(
        widget.task.bookingCode, file, 'payment_proof');
    if (!mounted) return;
    setState(() {
      _uploadingProof = false;
      _proofUploaded = res['success'] == true;
      if (!_proofUploaded) _paymentProof = null;
    });
    if (!_proofUploaded) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(res['message'] as String? ?? 'Upload failed.'),
        backgroundColor: TmColors.error,
      ));
    }
  }

  Future<void> _back() async {
    if (widget.task.status != 'waiting_verification') {
      Navigator.of(context).pop();
      return;
    }
    setState(() => _loading = true);
    final res = await TeamLeaderService.updateStatus(
        widget.task.bookingCode, 'arrived_dropoff');
    if (!mounted) return;
    if (res['success'] == true) {
      widget.onUpdate(widget.task.copyWith(status: 'arrived_dropoff'));
    } else {
      setState(() => _loading = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(res['message'] as String? ?? 'Failed.'),
          backgroundColor: TmColors.error));
    }
  }

  Future<void> _submit() async {
    if (!_hasSig) {
      setState(() => _error = 'Customer signature is required.');
      return;
    }
    if (_selectedPayment == null) {
      setState(() => _error = 'Please select a payment method.');
      return;
    }
    if (_needsProof && !_proofUploaded) {
      setState(() => _error = 'Please upload payment proof.');
      return;
    }
    if (_selectedPayment == 'cash' && !_cashCovers) {
      setState(() => _error =
          'Cash received must cover the final total of ${formatPeso(_amountDue)}.');
      return;
    }

    setState(() { _loading = true; _error = null; });

    try {
      final sigBytes = await _exportSignature();

      final res = await TeamLeaderService.completeTask(
          widget.task.bookingCode, sigBytes, _selectedPayment!,
          cashReceived: _selectedPayment == 'cash' ? _rawCashValue : null);
      if (!mounted) return;
      if (res['success'] == true) {
        final updated = res['task'] as TaskModel? ??
            widget.task.copyWith(
                status: 'waiting_verification', paymentMethod: _selectedPayment);
        widget.onUpdate(updated);
        if (widget.task.status != 'waiting_verification' &&
            Navigator.of(context).canPop()) {
          Navigator.of(context).pop();
        }
      } else {
        setState(() {
          _loading = false;
          _error = res['message'] as String? ?? 'Completion failed.';
        });
      }
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = 'Something went wrong while submitting. Please try again.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final task = widget.task;
    final showGroupBreakdown = task.isGroupBooking && task.groupTotal != null;

    return Container(
      width: double.infinity,
      height: double.infinity,
      color: context.bg,
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 28),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _stepHeader(context),
              const SizedBox(height: 18),
              showGroupBreakdown ? _groupPaymentCard(context, task) : _amountDueCard(context),
              const SizedBox(height: 14),
              _paymentMethodCard(context),
              const SizedBox(height: 14),
              _signatureCard(context),
              if (_error != null) ...[
                const SizedBox(height: 14),
                _errorBox(context, _error!),
              ],
              const SizedBox(height: 16),
              _primaryBtn(context, (_loading || !_canSubmit) ? null : _submit),
              const SizedBox(height: 12),
              _secondaryBtn(context, 'Back', _loading ? null : _back),
            ],
          ),
        ),
      ),
    );
  }

  Widget _stepHeader(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Customer Verification',
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 26,
            fontWeight: FontWeight.w800,
            letterSpacing: -0.5,
            height: 1.15,
          ),
        ),
        const SizedBox(height: 14),
        Row(
          children: [
            Text(
              'Step 5 of 6',
              style: GoogleFonts.inter(
                color: context.textTertiary,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
            const Spacer(),
            Text(
              '5 / 6',
              style: GoogleFonts.inter(
                color: context.textTertiary,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: 5 / 6,
            minHeight: 6,
            backgroundColor: context.divider,
            valueColor: const AlwaysStoppedAnimation(TmColors.yellow),
          ),
        ),
      ],
    );
  }

  BoxDecoration _cardDecoration(BuildContext context) {
    return BoxDecoration(
      color: context.card,
      borderRadius: BorderRadius.circular(23),
      border: Border.all(color: context.divider),
      boxShadow: [
        BoxShadow(
          color: Colors.black.withValues(alpha: context.isDark ? 0.24 : 0.06),
          blurRadius: 20,
          offset: const Offset(0, 8),
        ),
      ],
    );
  }

  Widget _cardTitle(BuildContext context, String text) {
    return Text(
      text,
      style: GoogleFonts.inter(
        color: context.textPrimary,
        fontSize: 16,
        fontWeight: FontWeight.w800,
      ),
    );
  }

  Widget _amountDueCard(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
      decoration: _cardDecoration(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _cardTitle(context, 'Payment'),
          const SizedBox(height: 10),
          Text(
            'Amount Due',
            style: GoogleFonts.inter(
              color: context.textTertiary,
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 2),
          Text(
            formatPeso(_amountDue),
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 30,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.5,
            ),
          ),
        ],
      ),
    );
  }

  Widget _groupPaymentCard(BuildContext context, TaskModel task) {
    final vehicles = task.groupVehicleTotals;
    final breakdown = task.groupVehicleBreakdown;
    final hasBreakdown = breakdown != null && breakdown.isNotEmpty;
    final adjustment = task.groupAdjustment ?? 0;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
      decoration: _cardDecoration(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _cardTitle(context, 'Group Payment'),
          const SizedBox(height: 3),
          Text(
            '${task.groupVehicleCount} vehicles',
            style: GoogleFonts.inter(
              color: context.textTertiary,
              fontSize: 13,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 10),
          if (hasBreakdown)
            for (var i = 0; i < breakdown.length; i++) ...[
              if (i > 0) Divider(height: 1, thickness: 1, color: context.divider),
              _vehicleBreakdownSection(context, i, breakdown[i]),
            ]
          else
            for (var i = 0; i < vehicles.length; i++)
              _breakdownRow(context, 'Vehicle ${i + 1}', vehicles[i]),
          if (adjustment != 0) ...[
            const SizedBox(height: 6),
            _breakdownRow(context, 'Adjustment', adjustment),
          ],
          const SizedBox(height: 6),
          Divider(height: 1, thickness: 1, color: context.divider),
          const SizedBox(height: 6),
          _breakdownRow(context, 'Total', _amountDue, emphasize: true),
        ],
      ),
    );
  }

  Widget _vehicleBreakdownSection(BuildContext context, int index, GroupVehiclePricing pricing) {
    final expanded = _expandedVehicles.contains(index);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTap: () => setState(() {
            if (expanded) {
              _expandedVehicles.remove(index);
            } else {
              _expandedVehicles.add(index);
            }
          }),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    'Vehicle ${index + 1}',
                    style: GoogleFonts.inter(
                      color: context.textTertiary,
                      fontSize: 14,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ),
                if (!expanded) ...[
                  Text(
                    formatPeso(pricing.finalTotal),
                    style: GoogleFonts.inter(
                      color: context.textPrimary,
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(width: 6),
                ],
                Icon(
                  expanded ? Icons.keyboard_arrow_up_rounded : Icons.keyboard_arrow_down_rounded,
                  size: 18,
                  color: context.textTertiary,
                ),
              ],
            ),
          ),
        ),
        if (expanded) ...[
          _pricingComponentRow(context, 'Base Rate', pricing.baseRate),
          _pricingComponentRow(context, 'Distance Fee', pricing.distanceFee),
          _pricingComponentRow(context, 'VAT (12%)', pricing.vatAmount),
          const SizedBox(height: 2),
          _breakdownRow(context, 'Vehicle Total', pricing.finalTotal, emphasize: true, strong: false),
          const SizedBox(height: 4),
        ],
      ],
    );
  }

  Widget _pricingComponentRow(BuildContext context, String label, double amount) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: GoogleFonts.inter(
                color: context.textTertiary,
                fontSize: 13,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
          Text(
            formatPeso(amount),
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: 13,
              fontWeight: FontWeight.w500,
            ),
          ),
        ],
      ),
    );
  }

  Widget _breakdownRow(BuildContext context, String label, double amount, {bool emphasize = false, bool strong = true}) {
    final labelSize = emphasize ? (strong ? 15.0 : 14.0) : 14.0;
    final labelWeight = emphasize ? FontWeight.w700 : FontWeight.w500;
    final valueSize = emphasize ? (strong ? 16.0 : 15.0) : 14.0;
    final valueWeight = emphasize ? (strong ? FontWeight.w800 : FontWeight.w700) : FontWeight.w600;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: GoogleFonts.inter(
                color: emphasize ? context.textPrimary : context.textTertiary,
                fontSize: labelSize,
                fontWeight: labelWeight,
              ),
            ),
          ),
          Text(
            formatPeso(amount),
            style: GoogleFonts.inter(
              color: context.textPrimary,
              fontSize: valueSize,
              fontWeight: valueWeight,
            ),
          ),
        ],
      ),
    );
  }

  Widget _paymentMethodCard(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
      decoration: _cardDecoration(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _cardTitle(context, 'Payment Method'),
          const SizedBox(height: 10),
          Row(
            children: _paymentOptions.map((option) {
              final selected = _selectedPayment == option.value;
              return Expanded(
                child: GestureDetector(
                  onTap: () => setState(() {
                    _selectedPayment = option.value;
                    if (option.value == 'cash') {
                      _paymentProof = null;
                      _proofUploaded = false;
                    } else {
                      _cashReceivedCtrl.clear();
                    }
                  }),
                  child: Container(
                    margin: EdgeInsets.only(
                      right: option.value == 'bank_transfer' ? 0 : 8,
                    ),
                    padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
                    decoration: BoxDecoration(
                      color: selected ? TmColors.yellow : context.surface,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(
                        color: selected ? TmColors.yellow : context.divider,
                        width: selected ? 1.5 : 1,
                      ),
                    ),
                    child: Text(
                      option.label,
                      style: GoogleFonts.inter(
                        color: selected ? TmColors.black : context.textPrimary,
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                      ),
                      textAlign: TextAlign.center,
                    ),
                  ),
                ),
              );
            }).toList(),
          ),
          if (_selectedPayment == 'cash') ...[
            const SizedBox(height: 14),
            Divider(height: 1, thickness: 1, color: context.divider),
            const SizedBox(height: 12),
            _cashSection(context),
          ],
          if (_needsProof) ...[
            const SizedBox(height: 14),
            Divider(height: 1, thickness: 1, color: context.divider),
            const SizedBox(height: 12),
            _proofSection(context),
          ],
        ],
      ),
    );
  }

  Widget _cashSection(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Cash Received (₱)',
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 13,
            fontWeight: FontWeight.w700,
          ),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _cashReceivedCtrl,
          focusNode: _cashFocusNode,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          inputFormatters: [_DecimalInputFormatter()],
          style: GoogleFonts.inter(color: context.textPrimary, fontSize: 15),
          decoration: InputDecoration(
            hintText: '0.00',
            hintStyle: GoogleFonts.inter(color: context.textTertiary, fontSize: 15),
            filled: true,
            fillColor: context.surface,
            contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(12),
              borderSide: BorderSide(color: context.divider),
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(12),
              borderSide: BorderSide(color: context.divider),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(12),
              borderSide: const BorderSide(color: TmColors.yellow, width: 1.5),
            ),
          ),
        ),
        const SizedBox(height: 5),
        Text(
          'Must be at least ${formatPeso(_amountDue)}.',
          style: GoogleFonts.inter(
            color: (_cashReceivedCtrl.text.isNotEmpty && !_cashCovers)
                ? TmColors.error
                : context.textTertiary,
            fontSize: 12,
          ),
        ),
      ],
    );
  }

  Widget _proofSection(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Flexible(
              child: Text(
                'Payment Proof',
                style: GoogleFonts.inter(
                  color: context.textPrimary,
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
            const SizedBox(width: 8),
            Text(
              'Required',
              style: GoogleFonts.inter(
                color: TmColors.error,
                fontSize: 12,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        _proofUploadBox(
          context,
          height: 110,
          uploading: _uploadingProof,
          uploaded: _proofUploaded,
          file: _paymentProof,
          placeholder: 'Tap to upload proof',
          onTap: _uploadingProof ? null : _showProofSource,
        ),
      ],
    );
  }

  Widget _proofUploadBox(
    BuildContext context, {
    required double height,
    required bool uploading,
    required bool uploaded,
    required XFile? file,
    required String placeholder,
    required VoidCallback? onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        height: height,
        width: double.infinity,
        decoration: BoxDecoration(
          color: context.surface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: uploaded ? TmColors.success : context.divider,
            width: uploaded ? 1.5 : 1,
          ),
        ),
        child: uploading
            ? const Center(
                child: CircularProgressIndicator(color: TmColors.yellow, strokeWidth: 2),
              )
            : file != null
                ? Stack(
                    fit: StackFit.expand,
                    children: [
                      ClipRRect(
                        borderRadius: BorderRadius.circular(12),
                        child: kIsWeb
                            ? Image.network(file.path, height: height, width: double.infinity, fit: BoxFit.cover)
                            : Image.file(File(file.path), height: height, width: double.infinity, fit: BoxFit.cover),
                      ),
                      if (uploaded)
                        Positioned(
                          left: 0,
                          right: 0,
                          bottom: 0,
                          child: ClipRRect(
                            borderRadius: const BorderRadius.vertical(bottom: Radius.circular(12)),
                            child: Container(
                              color: Colors.black.withValues(alpha: 0.55),
                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                              child: Row(
                                children: [
                                  const Icon(Icons.check_circle_rounded, color: TmColors.success, size: 16),
                                  const SizedBox(width: 6),
                                  Expanded(
                                    child: Text(
                                      'Proof uploaded',
                                      style: GoogleFonts.inter(
                                        color: Colors.white,
                                        fontSize: 12,
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                  ),
                                  Text(
                                    'Replace',
                                    style: GoogleFonts.inter(
                                      color: TmColors.yellow,
                                      fontSize: 12,
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ),
                    ],
                  )
                : Center(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.upload_rounded, color: context.textTertiary, size: 22),
                        const SizedBox(height: 6),
                        Text(
                          placeholder,
                          style: GoogleFonts.inter(color: context.textTertiary, fontSize: 12),
                        ),
                      ],
                    ),
                  ),
      ),
    );
  }

  Widget _signatureCard(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
      decoration: _cardDecoration(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _cardTitle(context, 'Customer Signature'),
          const SizedBox(height: 3),
          Text(
            'Required to complete this request',
            style: GoogleFonts.inter(color: context.textTertiary, fontSize: 13),
          ),
          const SizedBox(height: 8),
          Container(
            height: 130,
            decoration: BoxDecoration(
              color: context.surface,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(
                color: _hasSig ? TmColors.yellow : context.divider,
                width: _hasSig ? 1.5 : 1,
              ),
            ),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(12),
              child: Signature(
                controller: _sigCtrl,
                backgroundColor: context.surface,
              ),
            ),
          ),
          Align(
            alignment: Alignment.centerRight,
            child: TextButton(
              onPressed: () {
                _sigCtrl.clear();
                setState(() => _hasSig = false);
              },
              child: Text(
                'Clear',
                style: GoogleFonts.inter(color: context.textTertiary, fontSize: 12),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _errorBox(BuildContext context, String message) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: TmColors.error.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(10),
        border: const Border(left: BorderSide(color: TmColors.error, width: 3)),
      ),
      child: Text(
        message,
        style: GoogleFonts.inter(color: TmColors.error, fontSize: 13),
      ),
    );
  }

  Widget _primaryBtn(BuildContext context, VoidCallback? onTap) {
    return SizedBox(
      width: double.infinity,
      height: 56,
      child: ElevatedButton(
        onPressed: onTap,
        style: ElevatedButton.styleFrom(
          backgroundColor: TmColors.yellow,
          foregroundColor: TmColors.black,
          disabledBackgroundColor: TmColors.yellow.withValues(alpha: 0.6),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          elevation: 0,
        ),
        child: _loading
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(color: TmColors.black, strokeWidth: 2),
              )
            : Text(
                'Complete Task',
                style: GoogleFonts.inter(
                  color: TmColors.black,
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                ),
              ),
      ),
    );
  }

  Widget _secondaryBtn(BuildContext context, String label, VoidCallback? onTap) {
    return SizedBox(
      width: double.infinity,
      height: 56,
      child: OutlinedButton(
        onPressed: onTap,
        style: OutlinedButton.styleFrom(
          backgroundColor: context.card,
          side: BorderSide(color: context.divider),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
        child: Text(
          label,
          style: GoogleFonts.inter(
            color: context.textPrimary,
            fontSize: 15,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }
}
