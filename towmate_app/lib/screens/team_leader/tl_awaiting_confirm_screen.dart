import 'dart:io';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:signature/signature.dart';
import '../../core/theme.dart';
import '../../models/task_model.dart';
import '../../services/team_leader_service.dart';
import '../../widgets/tl_checklist_item.dart';

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

  XFile? _paymentProof;
  bool _proofUploaded = false;
  bool _uploadingProof = false;

  static const _paymentOptions = [
    (value: 'cash',          label: 'Cash',         icon: Icons.payments_rounded),
    (value: 'gcash',         label: 'GCash',        icon: Icons.account_balance_wallet_rounded),
    (value: 'bank_transfer', label: 'Bank Transfer', icon: Icons.account_balance_rounded),
  ];

  bool get _needsProof =>
      _selectedPayment == 'gcash' || _selectedPayment == 'bank_transfer';

  bool get _canSubmit {
    if (!_hasSig) return false;
    if (_selectedPayment == null) return false;
    if (_needsProof && !_proofUploaded) return false;
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
  }

  @override
  void dispose() {
    _sigCtrl.dispose();
    super.dispose();
  }

  Future<File?> _exportSignature() async {
    if (_sigCtrl.isEmpty) return null;
    final bytes = await _sigCtrl.toPngBytes();
    if (bytes == null) return null;
    final dir = await getTemporaryDirectory();
    final file = File('${dir.path}/sig_${DateTime.now().millisecondsSinceEpoch}.png');
    await file.writeAsBytes(bytes);
    return file;
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

    setState(() { _loading = true; _error = null; });

    final sigFile = await _exportSignature();

    final res = await TeamLeaderService.completeTask(
        widget.task.bookingCode, sigFile, _selectedPayment!);
    if (!mounted) return;
    if (res['success'] == true) {
      widget.onUpdate(widget.task.copyWith(status: 'completed'));
    } else {
      setState(() {
        _loading = false;
        _error = res['message'] as String? ?? 'Completion failed.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _header(),
            const SizedBox(height: 24),

            // ── Verification checklist ──────────────────────────────
            Text('Verification Checklist',
                style: GoogleFonts.inter(
                    color: TmColors.black, fontSize: 15, letterSpacing: -0.2)),
            const SizedBox(height: 12),
            TlChecklistItem(
              label: 'Customer signature',
              sublabel: _hasSig ? 'Captured' : 'Sign below',
              checked: _hasSig,
            ),
            TlChecklistItem(
              label: 'Payment method',
              sublabel: _selectedPayment != null
                  ? _paymentOptions
                      .firstWhere((p) => p.value == _selectedPayment)
                      .label
                  : 'Select below',
              checked: _selectedPayment != null,
            ),
            if (_needsProof)
              TlChecklistItem(
                label: 'Payment proof',
                sublabel: _proofUploaded ? 'Uploaded' : 'Upload below',
                checked: _proofUploaded,
              ),
            const SizedBox(height: 24),

            // ── Payment method ──────────────────────────────────────
            Text('Payment Method',
                style: GoogleFonts.inter(color: TmColors.black, fontSize: 13)),
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
                      }
                    }),
                    child: Container(
                      margin: EdgeInsets.only(
                        right: option.value == 'bank_transfer' ? 0 : 8,
                      ),
                      padding: const EdgeInsets.symmetric(
                          vertical: 14, horizontal: 8),
                      decoration: BoxDecoration(
                        color: selected
                            ? TmColors.yellow.withValues(alpha: 0.1)
                            : TmColors.white,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(
                          color:
                              selected ? TmColors.yellow : TmColors.grey300,
                          width: selected ? 1.5 : 1,
                        ),
                      ),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(option.icon,
                              color: selected
                                  ? TmColors.black
                                  : TmColors.grey500,
                              size: 22),
                          const SizedBox(height: 6),
                          Text(
                            option.label,
                            style: GoogleFonts.inter(
                              color: selected
                                  ? TmColors.black
                                  : TmColors.grey500,
                              fontSize: 11,
                            ),
                            textAlign: TextAlign.center,
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              }).toList(),
            ),

            // ── Payment proof (GCash / Bank Transfer only) ──────────
            if (_needsProof) ...[
              const SizedBox(height: 20),
              Text('Payment Proof',
                  style:
                      GoogleFonts.inter(color: TmColors.black, fontSize: 13)),
              const SizedBox(height: 8),
              GestureDetector(
                onTap: _uploadingProof ? null : _showProofSource,
                child: Container(
                  height: 140,
                  width: double.infinity,
                  decoration: BoxDecoration(
                    color: TmColors.grey100,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(
                      color: _proofUploaded
                          ? TmColors.success
                          : TmColors.grey300,
                      width: _proofUploaded ? 1.5 : 1,
                    ),
                  ),
                  child: _uploadingProof
                      ? const Center(
                          child: CircularProgressIndicator(
                              color: TmColors.yellow, strokeWidth: 2))
                      : _paymentProof != null
                          ? ClipRRect(
                              borderRadius: BorderRadius.circular(12),
                              child: kIsWeb
                                  ? Image.network(_paymentProof!.path,
                                      height: 140,
                                      width: double.infinity,
                                      fit: BoxFit.cover)
                                  : Image.file(File(_paymentProof!.path),
                                      height: 140,
                                      width: double.infinity,
                                      fit: BoxFit.cover),
                            )
                          : Center(
                              child: Text('Tap to upload proof',
                                  style: GoogleFonts.inter(
                                      color: TmColors.grey500, fontSize: 13)),
                            ),
                ),
              ),
            ],
            const SizedBox(height: 24),

            // ── Signature pad ───────────────────────────────────────
            Text('Customer Signature',
                style: GoogleFonts.inter(color: TmColors.black, fontSize: 13)),
            const SizedBox(height: 4),
            Text('Required for task completion',
                style:
                    GoogleFonts.inter(color: TmColors.grey500, fontSize: 11)),
            const SizedBox(height: 8),
            Container(
              height: 160,
              decoration: BoxDecoration(
                color: TmColors.white,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(
                  color: _hasSig ? TmColors.yellow : TmColors.grey300,
                  width: _hasSig ? 1.5 : 1,
                ),
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Signature(
                  controller: _sigCtrl,
                  backgroundColor: TmColors.white,
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
                child: Text('Clear',
                    style: GoogleFonts.inter(
                        color: TmColors.grey500, fontSize: 12)),
              ),
            ),

            if (_error != null) ...[
              const SizedBox(height: 4),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: TmColors.error.withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(10),
                  border: const Border(
                      left: BorderSide(color: TmColors.error, width: 3)),
                ),
                child: Text(_error!,
                    style: GoogleFonts.inter(
                        color: TmColors.error, fontSize: 13)),
              ),
              const SizedBox(height: 12),
            ],

            const SizedBox(height: 8),
            SizedBox(
              width: double.infinity,
              height: 52,
              child: ElevatedButton(
                onPressed: (_loading || !_canSubmit) ? null : _submit,
                style: ElevatedButton.styleFrom(
                  backgroundColor: TmColors.yellow,
                  foregroundColor: TmColors.black,
                  disabledBackgroundColor:
                      TmColors.yellow.withValues(alpha: 0.5),
                  shape: const StadiumBorder(),
                  elevation: 0,
                ),
                child: _loading
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                            color: TmColors.black, strokeWidth: 2))
                    : Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          const Icon(Icons.check_circle_rounded,
                              color: TmColors.black, size: 20),
                          const SizedBox(width: 8),
                          Text('Complete Task',
                              style: GoogleFonts.inter(
                                  color: TmColors.black, fontSize: 15)),
                        ],
                      ),
              ),
            ),
            const SizedBox(height: 40),
          ],
        ),
      ),
    );
  }

  Widget _header() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: TmColors.black,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Row(
        children: [
          const Icon(Icons.verified_outlined,
              color: TmColors.yellow, size: 22),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Awaiting Customer Verification',
                    style: GoogleFonts.inter(
                        color: TmColors.white, fontSize: 14)),
                Text('Collect signature and confirm payment to complete.',
                    style: GoogleFonts.inter(
                        color: TmColors.grey500, fontSize: 12)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
