// lib/screens/courier/delivery_tracker_screen.dart
//
// Per-job delivery lifecycle tracker — pushed from either dashboard
// (Pickup or Delivery) rather than living as a fixed shell tab, since
// a courier can now have several assigned jobs in flight at once (see
// state/courier_state.dart). Replaces the old active_delivery_screen.dart,
// which assumed exactly one active job — delete that file, it's fully
// superseded by this one.

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../../state/auth_state.dart';
import '../../state/courier_state.dart';
import '../../theme/app_theme.dart';
import '../shared/chat_screen.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/pressable_scale.dart';
import '../../widgets/staggered_reveal.dart';
import 'widgets/delivery_completion_view.dart';
import '../../utils/image_picker_helper.dart';
import '../../utils/open_maps.dart';

class DeliveryTrackerScreen extends StatefulWidget {
  const DeliveryTrackerScreen({super.key, required this.jobId});

  final String jobId;

  @override
  State<DeliveryTrackerScreen> createState() => _DeliveryTrackerScreenState();
}

class _DeliveryTrackerScreenState extends State<DeliveryTrackerScreen> {
  String? _proofImagePath;

  bool get _proofUploaded => _proofImagePath != null;

  /// Set when this job has just been completed, which swaps the whole screen
  /// for the confirmation below. State rather than a route because the
  /// completion *is* this screen's outcome, and because completing the job
  /// removes it from CourierState — see the note in build().
  bool _justCompleted = false;

  /// Whether this particular completion earns the animation. Decided once,
  /// at completion, by [_completeDelivery].
  bool _celebrate = false;

  /// True when the job being closed out was a return rather than a delivery
  /// (`returnToSender` is a final step too). Kept separate from `_celebrate`
  /// because the two are independent: a return is never celebrated, and the
  /// copy has to be honest about which event just happened.
  bool _returned = false;

  static const _steps = [
    (DeliveryMilestone.accepted,        'Assigned',           Icons.assignment_outlined),
    (DeliveryMilestone.arrivedAtSeller, 'At Seller',          Icons.storefront_outlined),
    (DeliveryMilestone.pickedUp,        'Picked Up',          Icons.inventory_2_outlined),
    (DeliveryMilestone.atSortingCenter, 'At Sorting',         Icons.warehouse_outlined),
    (DeliveryMilestone.sorted,          'Sorted',             Icons.check_box_outlined),
    (DeliveryMilestone.assignedToRider, 'To Rider',           Icons.person_pin_circle_outlined),
    (DeliveryMilestone.inTransit,       'In Transit',         Icons.local_shipping_outlined),
    (DeliveryMilestone.delivered,       'Delivered',          Icons.flag_outlined),
  ];

  int _currentIndex(DeliveryMilestone milestone) => _steps.indexWhere((s) => s.$1 == milestone);

  void _advance(CourierState courier, DeliveryMilestone current) {
    final next = _currentIndex(current) + 1;
    if (next >= _steps.length) return;
    final upcoming = _steps[next].$1;
    // The dispatch step is the first moment the buyer's tracker can answer
    // "who is bringing this", so that's where identity is recorded (and why
    // it's null on every earlier step — the rider who collects a parcel
    // isn't the rider who delivers it).
    final dispatch = upcoming == DeliveryMilestone.assignedToRider;
    final auth = context.read<AuthState>();
    courier.advanceMilestone(
      widget.jobId,
      upcoming,
      courierName: dispatch ? auth.name : null,
      // The rider's own profile photo, if they've set one. Passed from the
      // courier's session rather than looked up by the buyer — so the buyer's
      // app never has access to a rider's account, only to this one value
      // handed over with the assignment.
      courierPhotoPath: dispatch ? auth.avatarPath : null,
    );
  }

  /// Mark delivery failed — separate from _advance so it doesn't
  /// require the proof upload check and has its own action path.
  void _markFailed(CourierState courier) {
    courier.advanceMilestone(widget.jobId, DeliveryMilestone.failedDelivery);
  }

  void _retryDelivery(CourierState courier) {
    courier.advanceMilestone(widget.jobId, DeliveryMilestone.inTransit);
  }

  bool _isToday(DateTime when) {
    final now = DateTime.now();
    return when.year == now.year && when.month == now.month && when.day == now.day;
  }

  /// The moment this whole app exists to produce — so it gets a real
  /// confirmation instead of a SnackBar.
  ///
  /// What it replaced: `showSnackBar('Delivery complete — ₱X added...')` +
  /// `pop()`, i.e. a message that the *next* completion wipes
  /// (`clearSnackBars`), describing a number that lives on a different tab,
  /// over a screen the courier had already left. Three things had to become
  /// true instead: the fee visible, the day's total visible, and nothing to
  /// wait for.
  ///
  /// ## Tempo, and why this is NOT Checkout's flourish
  ///
  /// A buyer completes a purchase once, so that screen can afford ~1.5s of
  /// monogram-trace-then-checkmark. A courier completes 20-40 deliveries a
  /// shift, standing up, in daylight, holding a parcel — putting a
  /// multi-second animation between every one of those would tax the exact
  /// person whose time this app exists to save, and the next critique would
  /// (correctly) be "the courier flow is sluggish."
  ///
  /// So the rule for this screen is **confirm the many, celebrate the few**:
  /// an instant confirmation always (haptic + a static mark, no animation to
  /// sit through), and the app's celebration asset spent only on the rare
  /// beats — the first delivery of the day, and every fifth delivery overall
  /// (5th, 10th, 15th…). That's the mirror of Checkout's "celebrate the
  /// once", and it's the version of caring that doesn't fight the work.
  void _completeDelivery(CourierState courier, {required DeliveryMilestone outcome}) {
    final returned = outcome == DeliveryMilestone.returnToSender;
    final firstToday = !courier.completedJobs.any((c) => _isToday(c.completedAt));
    final completionNumber = courier.completedJobs.length + 1;

    courier.completeJob(widget.jobId, returned: returned);

    // Haptics: the one confirmation that works one-handed, through a phone
    // case, without the courier looking up. Fired here rather than in the
    // flourish because the fast path has no flourish to fire it for it.
    final celebrating = !returned && (firstToday || completionNumber % 5 == 0);
    if (!celebrating) HapticFeedback.mediumImpact();

    setState(() {
      _justCompleted = true;
      _returned = returned;
      // A return is never celebrated. `returnToSender` is a final step like
      // `delivered`, so without this the app would throw confetti at a
      // failed delivery — which is the kind of thing that only becomes
      // obvious once the moment is loud enough to matter.
      _celebrate = celebrating;
    });
  }

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    final theme = Theme.of(context);
    final ext = theme.extension<AppThemeExtension>()!;
    final courier = context.watch<CourierState>();
    final job = courier.jobById(widget.jobId);

    // Checked before the null branch below, and the order is load-bearing:
    // completing a job removes it from CourierState, so `job` is null by the
    // very next frame — without this, the courier's confirmation would
    // render as "This job is no longer available."
    if (_justCompleted) return _buildCompletionScreen(context, courier);

    if (job == null) {
      // Shouldn't normally happen — only reachable if the job was
      // somehow completed/removed while this screen was still open.
      return Scaffold(
        appBar: AppBar(title: const Text('Delivery')),
        body: const Center(child: Text('This job is no longer available.')),
      );
    }

    final milestone = courier.milestoneFor(widget.jobId);

    return Scaffold(
      backgroundColor: Colors.transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      appBar: AppBar(
        // Job ID is a reference code, not a human label. Show it as
        // a subtitle so the screen reads as "Delivery", not "JOB-0001".
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'DELIVERY',
              style: AppType.eyebrow.copyWith(fontSize: 9, letterSpacing: 2.0),
            ),
            Text(job.id, style: Theme.of(context).textTheme.titleSmall),
          ],
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 120),
        children: [
          StaggeredReveal(index: 0, child: _buildStepper(theme, milestone)),
          const SizedBox(height: 24),
          StaggeredReveal(
            index: 1,
            child: _buildLocationCard(
              theme,
              icon: Icons.storefront_outlined,
              title: job.shopName,
              subtitle: job.pickupAddress,
              roleLabel: 'Seller',
              index: 0,
            ),
          ),
          const SizedBox(height: 12),
          StaggeredReveal(
            index: 2,
            child: _buildLocationCard(
              theme,
              icon: Icons.person_outline,
              title: job.buyerName,
              subtitle: job.deliveryArea,
              roleLabel: 'Buyer',
              index: 1,
            ),
          ),
          const SizedBox(height: 20),
          StaggeredReveal(index: 3, child: _buildOrderSummary(theme, job)),
          if (milestone == DeliveryMilestone.inTransit) ...[
            const SizedBox(height: 20),
            StaggeredReveal(index: 4, child: _buildProofUpload(theme, ext)),
          ],
        ],
      ),
      bottomNavigationBar: _buildActionBar(theme, courier, milestone),
    );
  }

  Widget _buildStepper(ThemeData theme, DeliveryMilestone milestone) {
    final currentIndex = _currentIndex(milestone);
    return Row(
      children: List.generate(_steps.length, (i) {
        final (_, label, icon) = _steps[i];
        final done = i < currentIndex;
        final active = i == currentIndex;
        final color = done || active ? AppColors.primaryDark : AppColors.divider;

        return Expanded(
          child: Column(
            children: [
              Row(
                children: [
                  if (i > 0)
                    Expanded(
                      child: Container(height: 2, color: i <= currentIndex ? AppColors.primaryDark : AppColors.divider),
                    ),
                  Container(
                    width: 30,
                    height: 30,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: done || active ? AppColors.primaryDark : AppColors.surface,
                      border: Border.all(color: color, width: 1.5),
                    ),
                    child: Icon(
                      done ? Icons.check : icon,
                      size: 15,
                      color: done || active ? AppColors.textOnDark : AppColors.textSecondary,
                    ),
                  ),
                  if (i < _steps.length - 1)
                    Expanded(
                      child: Container(height: 2, color: i < currentIndex ? AppColors.primaryDark : AppColors.divider),
                    ),
                ],
              ),
              const SizedBox(height: 6),
              Text(
                label,
                textAlign: TextAlign.center,
                style: theme.textTheme.labelSmall?.copyWith(
                  color: active ? AppColors.primaryDark : AppColors.textSecondary,
                  fontWeight: active ? FontWeight.w700 : FontWeight.w500,
                ),
              ),
            ],
          ),
        );
      }),
    );
  }

  Widget _buildLocationCard(
    ThemeData theme, {
    required IconData icon,
    required String title,
    required String subtitle,
    required String roleLabel,
    required int index,
  }) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(index, ShapeTier.card),
      ),
      child: Row(
        children: [
          CircleAvatar(
            radius: 20,
            backgroundColor: AppColors.tertiary,
            child: Icon(icon, color: AppColors.textOnLight, size: 18),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(roleLabel, style: theme.textTheme.labelSmall),
                Text(title, style: theme.textTheme.titleSmall),
                Text(subtitle, style: theme.textTheme.bodySmall, maxLines: 2, overflow: TextOverflow.ellipsis),
              ],
            ),
          ),
          _circleAction(
            Icons.call_outlined,
            // clearSnackBars() first — same reasoning as chat_screen's
            // call button: no cooldown on this icon, so repeated taps
            // queued instead of replacing.
            () => ScaffoldMessenger.of(context)
              ..clearSnackBars()
              ..showSnackBar(SnackBar(content: Text('Calling $title...'))),
            semanticLabel: 'Call $title',
          ),
          const SizedBox(width: 8),
          _circleAction(
            Icons.chat_bubble_outline,
            () => Navigator.of(context).push(
              AppPageRoute(builder: (_) => ChatScreen(contactName: title, contactRole: roleLabel)),
            ),
            semanticLabel: 'Message $title',
          ),
          // Open in Maps: hands the address to the device's maps app.
          // Address only — no buyer phone is shown or passed anywhere
          // here (see OPEN QUESTIONS: call-buyer privacy).
          if (roleLabel == 'Buyer') ...[
            const SizedBox(width: 8),
            _circleAction(
              Icons.map_outlined,
              () => openAddressInMaps(context, subtitle),
              semanticLabel: 'Open delivery address in Maps',
            ),
          ],
        ],
      ),
    );
  }

  Widget _circleAction(IconData icon, VoidCallback onTap, {String? semanticLabel}) {
    return Semantics(
      button: true,
      label: semanticLabel,
      excludeSemantics: semanticLabel != null,
      child: PressableScale(
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(
            color: AppColors.surface,
            shape: BoxShape.circle,
          ),
          child: Icon(icon, size: 16, color: AppColors.textPrimary),
        ),
      ),
    );
  }

  Widget _buildOrderSummary(ThemeData theme, job) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(2, ShapeTier.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Order Details', style: theme.textTheme.titleSmall),
          const SizedBox(height: 10),
          _summaryLine(theme, 'Request ID', job.id),
          _summaryLine(theme, 'Package', job.packageSize),
          _summaryLine(theme, 'Payment Method', 'Cash on Delivery'),
          const Divider(height: 20),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text('YOUR DELIVERY FEE', style: AppType.eyebrow),
              // The fee is what the courier is actually here for on this
              // screen — same numeral treatment as the job card and the
              // earnings summary, so the three places a fee appears agree.
              Text('₱${job.fee.toStringAsFixed(0)}', style: AppType.priceNumeral(28)),
            ],
          ),
        ],
      ),
    );
  }

  Widget _summaryLine(ThemeData theme, String label, String value, {bool isBold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Expanded(child: Text(label, style: theme.textTheme.bodySmall)),
          const SizedBox(width: 12),
          Text(
            value,
            style: (isBold ? theme.textTheme.titleSmall : theme.textTheme.bodyMedium)?.copyWith(
              fontWeight: isBold ? FontWeight.w700 : FontWeight.w600,
              color: isBold ? AppColors.primaryDark : AppColors.textPrimary,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildProofUpload(ThemeData theme, AppThemeExtension ext) {
    // editorialRadius(1) so this card leans opposite to the location
    // cards above it — consistent with the alternating-lean pattern
    // everywhere else in the app.
    final radius = editorialRadius(1, ShapeTier.card);
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        // Sage tint: this is a completion action, so it uses the
        // success/done color rather than the neutral surfaceMuted.
        color: AppColors.tertiary.withValues(alpha: 0.14),
        borderRadius: radius,
        border: Border.all(color: AppColors.tertiary.withValues(alpha: 0.35)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.camera_alt_outlined, size: 16, color: AppColors.primaryDark),
              const SizedBox(width: 8),
              Text('Proof of Delivery', style: theme.textTheme.titleSmall),
            ],
          ),
          const SizedBox(height: 10),
          InkWell(
            onTap: () async {
              final picked = await pickAndSaveImage(context, filenamePrefix: 'proof_${widget.jobId}');
              if (picked != null && mounted) {
                setState(() => _proofImagePath = picked.path);
              }
            },
            borderRadius: editorialRadius(0, ShapeTier.accent),
            child: Container(
              height: 110,
              width: double.infinity,
              decoration: BoxDecoration(
                color: _proofUploaded
                    ? AppColors.tertiary.withValues(alpha: 0.22)
                    : AppColors.surface,
                borderRadius: editorialRadius(0, ShapeTier.accent),
                border: Border.all(
                  color: _proofUploaded ? AppColors.tertiary : AppColors.divider,
                  width: _proofUploaded ? 1.5 : 1,
                ),
              ),
              child: _proofUploaded
                  ? Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.check_circle_outline, color: AppColors.successText, size: 30),
                        const SizedBox(height: 8),
                        Text('Photo attached', style: theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w600,
                          color: AppColors.successText,
                        )),
                        const SizedBox(height: 2),
                        Text('Tap to retake', style: theme.textTheme.labelSmall),
                      ],
                    )
                  : Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.add_a_photo_outlined, color: AppColors.primaryDark.withValues(alpha: 0.55), size: 30),
                        const SizedBox(height: 8),
                        Text('Tap to attach a photo', style: theme.textTheme.bodySmall),
                      ],
                    ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildActionBar(ThemeData theme, CourierState courier, DeliveryMilestone milestone) {
    final (label, enabled, isFinalStep) = switch (milestone) {
      DeliveryMilestone.accepted        => ('Confirm Arrived at Seller', true, false),
      DeliveryMilestone.arrivedAtSeller => ('Confirm Pickup from Seller', true, false),
      DeliveryMilestone.pickedUp        => ('Drop at Sorting Center', true, false),
      DeliveryMilestone.atSortingCenter => ('Confirm Package Sorted', true, false),
      DeliveryMilestone.sorted          => ('Mark Assigned to Rider', true, false),
      DeliveryMilestone.assignedToRider => ('Start Delivery Transit', true, false),
      DeliveryMilestone.inTransit       => ('Confirm Order Delivered', _proofUploaded, true),
      DeliveryMilestone.delivered       => ('Delivery Complete', false, true),
      DeliveryMilestone.failedDelivery  => ('Retry Delivery', true, false),
      DeliveryMilestone.returnToSender  => ('Return Complete', true, true),
    };

    return Container(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        boxShadow: [
          BoxShadow(color: AppColors.neutral.withValues(alpha: 0.12), blurRadius: 16, offset: const Offset(0, -4)),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ElevatedButton(
              onPressed: !enabled
                  ? null
                  : isFinalStep
                      ? () => _completeDelivery(courier, outcome: milestone)
                      : milestone == DeliveryMilestone.failedDelivery
                          ? () => _retryDelivery(courier)
                          : () => _advance(courier, milestone),
              child: Text(label),
            ),
            // "Mark as Failed" only shown while in transit — the one
            // stage where real delivery attempts happen.
            if (milestone == DeliveryMilestone.inTransit) ...[
              const SizedBox(height: 8),
              OutlinedButton(
                onPressed: () => _markFailed(courier),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AppColors.danger,
                  side: BorderSide(color: AppColors.danger),
                ),
                child: const Text('Mark Delivery Failed'),
              ),
            ],
          ],
        ),
      ),
    );
  }

  /// Hands the completion off to [DeliveryCompletionView], which owns the
  /// layout — and the tempo rule, and the return copy (see that file). The
  /// numbers are gathered here because this class is the one already
  /// watching CourierState.
  Widget _buildCompletionScreen(BuildContext context, CourierState courier) {
    final today = courier.completedJobs.where((c) => _isToday(c.completedAt)).toList();

    CompletedJob? completed;
    for (final c in courier.completedJobs) {
      if (c.job.id == widget.jobId) completed = c;
    }

    // A return credits a share of the fee (kReturnFeeShare), not the full
    // one — the payout is read from CompletedJob rather than from the job's
    // posted fee, so this screen, the earnings totals and the profit log all
    // agree on the number.
    return DeliveryCompletionView(
      fee: completed?.payout ?? 0,
      todayTotal: today.fold<double>(0, (sum, c) => sum + c.payout),
      todayCount: today.length,
      returned: _returned,
      celebrate: _celebrate,
      onDone: () => Navigator.of(context).pop(),
    );
  }
}
