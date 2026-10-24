// lib/screens/buyer/return_screen.dart
//
// The buyer half of a return/refund request: describe the problem, attach one
// to five photos in the order they were chosen, and submit.
//
// ## Why the photos are not just thumbnails
//
// The workbook asks for evidence that arrives in the chosen order and can be
// removed before submission. The list is therefore append-only through the
// shared camera/gallery picker and index-stable until the buyer removes an
// entry; nothing sorts it. The same list is what the request stores, so the
// order on screen and the order in the record cannot disagree.
//
// ## What this screen does not pretend to do
//
// There is no seller dashboard in this app, so it cannot make a seller respond.
// The status card below renders a real rejection (reason and escalation) when
// the data source supplies one, but this mock build has no button that invents
// a seller decision. That gap is stated in the QA report rather than papered
// over with a fake "seller notified" toast.

import 'dart:io';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../data/api/api_exception.dart';
import '../../data/mock_data.dart';
import '../../state/order_state.dart';
import '../../state/return_state.dart';
import '../../theme/app_theme.dart';
import '../../utils/image_picker_helper.dart';
import '../../utils/timed_snackbar.dart';
import '../../widgets/editorial_shapes.dart';

typedef ReturnPhotoPicker = Future<File?> Function(BuildContext context);

class ReturnScreen extends StatefulWidget {
  const ReturnScreen({
    super.key,
    required this.order,
    this.deliveredAt,
    this.photoPicker,
  });

  final Order order;

  /// The delivery timestamp used for the seven-day check. Passed by the order
  /// detail screen because a freshly delivered order may have its timestamp in
  /// `CourierState` before it reaches the order row.
  final DateTime? deliveredAt;

  /// Injectable only so widget tests can exercise the ordered-photo flow
  /// without the platform picker. Production leaves this null and uses the
  /// shared camera/gallery helper.
  final ReturnPhotoPicker? photoPicker;

  @override
  State<ReturnScreen> createState() => _ReturnScreenState();
}

class _ReturnScreenState extends State<ReturnScreen> {
  final _description = TextEditingController();
  final List<String> _photos = [];
  bool _submitting = false;
  bool _submitted = false;
  String? _error;

  @override
  void dispose() {
    _description.dispose();
    super.dispose();
  }

  Future<void> _attachPhoto() async {
    if (_photos.length >= returnPhotoLimit) return;
    final picked = widget.photoPicker != null
        ? await widget.photoPicker!(context)
        : await pickAndSaveImage(
            context,
            filenamePrefix: 'return_${widget.order.id}',
          );
    if (picked == null || !mounted) return;
    setState(() => _photos.add(picked.path));
  }

  void _removePhoto(int index) {
    setState(() => _photos.removeAt(index));
  }

  Future<void> _submit() async {
    if (_submitting) return;
    setState(() {
      _submitting = true;
      _error = null;
    });

    String? error;
    try {
      error = await context.read<OrderState>().returns.submit(
            order: widget.order,
            description: _description.text,
            photoPaths: List.of(_photos),
            deliveredAt: widget.deliveredAt,
          );
    } on ApiException catch (failure) {
      error = failure.message;
    } catch (_) {
      error = 'Your return request could not be sent. Try again.';
    }

    if (!mounted) return;
    setState(() {
      _submitting = false;
      _error = error;
      _submitted = error == null;
    });
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(),
      body: SafeArea(
        child: _submitted ? _confirmation(context) : _form(context),
      ),
    );
  }

  Widget _form(BuildContext context) {
    final theme = Theme.of(context);
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 4, 20, 32),
      children: [
        Text('${widget.order.shopName} — ${widget.order.id}',
            style: AppType.eyebrow),
        const SizedBox(height: 8),
        Text('Return or refund', style: AppType.sectionDisplay),
        const SizedBox(height: 8),
        Text(
          'Tell the seller what went wrong and attach photos of the item. '
          'Requests can be made for 7 days after delivery.',
          style: theme.textTheme.bodyMedium
              ?.copyWith(color: AppColors.textSecondary),
        ),
        const SizedBox(height: 22),
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: AppColors.surface,
            borderRadius: editorialRadius(0, ShapeTier.card),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('PHOTOS', style: AppType.eyebrow),
              const SizedBox(height: 6),
              Text(
                'Attach 1 to $returnPhotoLimit photos. They are kept in the '
                'order you add them.',
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: AppColors.textSecondary),
              ),
              const SizedBox(height: 12),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (var i = 0; i < _photos.length; i++)
                    _ReturnPhotoThumb(
                      key: ValueKey('return-photo-$i'),
                      path: _photos[i],
                      index: i,
                      onRemove: () => _removePhoto(i),
                      removeKey: ValueKey('return-photo-remove-$i'),
                    ),
                  if (_photos.length < returnPhotoLimit)
                    _AddPhotoButton(
                      key: const ValueKey('return-photo-add'),
                      count: _photos.length,
                      onTap: _attachPhoto,
                    ),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        TextField(
          key: const ValueKey('return-description'),
          controller: _description,
          minLines: 4,
          maxLines: 6,
          maxLength: 500,
          textCapitalization: TextCapitalization.sentences,
          decoration: InputDecoration(
            labelText: 'What is wrong?',
            hintText: 'At least 10 characters',
            alignLabelWithHint: true,
            helperText: '$returnDescriptionMinLength+ characters required',
          ),
        ),
        if (_error != null) ...[
          const SizedBox(height: 4),
          Text(
            _error!,
            key: const ValueKey('return-error'),
            style: theme.textTheme.bodySmall?.copyWith(color: AppColors.danger),
          ),
        ],
        const SizedBox(height: 12),
        ElevatedButton(
          key: const ValueKey('return-submit'),
          onPressed: _submitting ? null : _submit,
          child: Text(_submitting ? 'Submitting...' : 'Submit Return Request'),
        ),
      ],
    );
  }

  Widget _confirmation(BuildContext context) {
    final theme = Theme.of(context);
    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 12, 24, 32),
      children: [
        Center(
          child: Container(
            width: 88,
            height: 88,
            decoration: BoxDecoration(
              color: AppColors.secondary.withValues(alpha: 0.20),
              shape: BoxShape.circle,
            ),
            child: Icon(
              Icons.assignment_turned_in_outlined,
              size: 40,
              color: AppColors.primaryDark,
            ),
          ),
        ),
        const SizedBox(height: 22),
        Text(
          'Return request submitted',
          key: const ValueKey('return-submitted'),
          textAlign: TextAlign.center,
          style: AppType.sectionDisplay,
        ),
        const SizedBox(height: 10),
        Text(
          'Your request for ${widget.order.id} is open. The seller\'s '
          'response will appear on this order.',
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyMedium
              ?.copyWith(color: AppColors.textSecondary),
        ),
        const SizedBox(height: 24),
        ElevatedButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Back to order'),
        ),
      ],
    );
  }
}

class _AddPhotoButton extends StatelessWidget {
  const _AddPhotoButton({
    super.key,
    required this.count,
    required this.onTap,
  });

  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    return Semantics(
      button: true,
      label: 'Add photo ($count of $returnPhotoLimit attached)',
      child: InkWell(
        onTap: onTap,
        borderRadius: editorialRadius(0, ShapeTier.accent),
        child: Container(
          width: 76,
          height: 76,
          decoration: BoxDecoration(
            color: AppColors.surfaceMuted,
            borderRadius: editorialRadius(0, ShapeTier.accent),
            border: Border.all(color: AppColors.divider),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(
                Icons.add_a_photo_outlined,
                color: AppColors.primaryDark.withValues(alpha: 0.55),
                size: 22,
              ),
              const SizedBox(height: 3),
              Text(
                '$count/$returnPhotoLimit',
                style: theme.textTheme.labelSmall
                    ?.copyWith(color: AppColors.textSecondary),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ReturnPhotoThumb extends StatelessWidget {
  const _ReturnPhotoThumb({
    super.key,
    required this.path,
    required this.index,
    this.onRemove,
    this.removeKey,
  });

  final String path;
  final int index;
  final VoidCallback? onRemove;
  final Key? removeKey;

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return SizedBox(
      width: 76,
      height: 76,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          ClipRRect(
            borderRadius: editorialRadius(0, ShapeTier.accent),
            child: Image.file(
              File(path),
              width: 76,
              height: 76,
              fit: BoxFit.cover,
              // A path can outlive the file (cleared storage). Say so rather
              // than drawing a broken-image glyph.
              errorBuilder: (context, error, stack) => Container(
                width: 76,
                height: 76,
                color: AppColors.surfaceMuted,
                child: const Icon(Icons.image_not_supported_outlined),
              ),
            ),
          ),
          Positioned(
            left: 3,
            bottom: 3,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
              decoration: BoxDecoration(
                color: AppColors.surface.withValues(alpha: 0.88),
                borderRadius: radiusOf(ShapeTier.pill),
              ),
              child: Text(
                '${index + 1}',
                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
              ),
            ),
          ),
          if (onRemove != null)
            Positioned(
              top: -6,
              right: -6,
              child: Semantics(
                button: true,
                label: 'Remove photo ${index + 1}',
                child: InkWell(
                  key: removeKey,
                  onTap: onRemove,
                  customBorder: const CircleBorder(),
                  child: Container(
                    padding: const EdgeInsets.all(3),
                    decoration: BoxDecoration(
                      color: AppColors.surface,
                      shape: BoxShape.circle,
                      border: Border.all(color: AppColors.divider),
                    ),
                    child: const Icon(Icons.close, size: 14),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// The request's state on the order detail screen: open, rejected with the
/// seller's exact words, or escalated.
class ReturnStatusCard extends StatelessWidget {
  const ReturnStatusCard({super.key, required this.request});

  final ReturnRequest request;

  Color get _statusColor => switch (request.status) {
        ReturnStatus.submitted => AppColors.warning,
        ReturnStatus.rejected => AppColors.danger,
        ReturnStatus.escalated => AppColors.primaryDark,
        ReturnStatus.refunded => AppColors.tertiary,
      };

  Future<void> _escalate(BuildContext context) async {
    try {
      final error =
          await context.read<OrderState>().returns.escalate(request);
      if (!context.mounted) return;
      showTimedSnackBar(
        context,
        SnackBar(
          content: Text(error ?? 'Return escalated for another review.'),
        ),
      );
    } on ApiException catch (error) {
      if (!context.mounted) return;
      showTimedSnackBar(context, SnackBar(content: Text(error.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final statusColor = _statusColor;
    final textColor = Color.lerp(statusColor, Colors.black, 0.40)!;

    return Container(
      key: const ValueKey('return-status'),
      margin: const EdgeInsets.only(bottom: 20),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(1, ShapeTier.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: Text('RETURN / REFUND', style: AppType.eyebrow)),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: statusColor.withValues(alpha: 0.15),
                  borderRadius: radiusOf(ShapeTier.pill),
                ),
                child: Text(
                  request.orderLabel,
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: textColor,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(_title, style: theme.textTheme.titleSmall),
          const SizedBox(height: 6),
          Text(
            request.description,
            style: theme.textTheme.bodyMedium,
          ),
          if (request.photoPaths.isNotEmpty) ...[
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (var i = 0; i < request.photoPaths.length; i++)
                  _ReturnPhotoThumb(
                    key: ValueKey('return-status-photo-$i'),
                    path: request.photoPaths[i],
                    index: i,
                  ),
              ],
            ),
          ],
          if (request.status == ReturnStatus.rejected) ...[
            const SizedBox(height: 14),
            Text("SELLER'S REASON", style: AppType.eyebrow),
            const SizedBox(height: 4),
            Text(
              request.sellerReason?.trim().isNotEmpty == true
                  ? request.sellerReason!
                  : 'No reason provided.',
              key: const ValueKey('return-seller-reason'),
              style: theme.textTheme.bodyMedium,
            ),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              key: const ValueKey('return-escalate'),
              onPressed: () => _escalate(context),
              icon: const Icon(Icons.campaign_outlined, size: 16),
              label: const Text('Escalate'),
            ),
          ] else if (request.status == ReturnStatus.submitted) ...[
            const SizedBox(height: 12),
            Text(
              'This request is open. Order Received is unavailable until the '
              'seller responds.',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: AppColors.textSecondary),
            ),
          ] else if (request.status == ReturnStatus.escalated) ...[
            const SizedBox(height: 12),
            Text(
              'This request has been escalated for another review.',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: AppColors.textSecondary),
            ),
          ] else ...[
            const SizedBox(height: 12),
            Text(
              'The refund for this order has been processed.',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: AppColors.textSecondary),
            ),
          ],
        ],
      ),
    );
  }

  String get _title => switch (request.status) {
        ReturnStatus.submitted => 'Waiting for the seller',
        ReturnStatus.rejected => 'Request rejected',
        ReturnStatus.escalated => 'Escalated',
        ReturnStatus.refunded => 'Refunded',
      };
}
