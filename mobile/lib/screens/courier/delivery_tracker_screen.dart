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
import 'package:url_launcher/url_launcher.dart';
import '../../config/dev_flags.dart' show FeatureFlags;
import '../../data/api/api_exception.dart'
    show ApiException, ValidationException;
import '../../services/media_picker.dart'
    show mediaProblemMessage, mediaProblemOf;
import '../../state/auth_state.dart';
import '../../state/courier_state.dart';
import '../../data/mock_data.dart' show DeliveryJob;
import '../../theme/app_theme.dart';
import '../../utils/money_format.dart';
import '../../utils/motion.dart';
import '../../utils/timed_snackbar.dart';
import '../shared/chat_screen.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/pressable_scale.dart';
import '../../widgets/staggered_reveal.dart';
import 'widgets/delivery_completion_view.dart';
import '../../utils/image_picker_helper.dart';
import '../../utils/open_maps.dart';
import 'courier_qr_scanner.dart';

class DeliveryTrackerScreen extends StatefulWidget {
  const DeliveryTrackerScreen({super.key, required this.jobId});

  final String jobId;

  @override
  State<DeliveryTrackerScreen> createState() => _DeliveryTrackerScreenState();
}

class _DeliveryTrackerScreenState extends State<DeliveryTrackerScreen> {
  String? _proofImagePath;
  bool _proofPicking = false;

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

  /// True while proof of delivery is on its way to the server (live mode).
  /// Blocks a second submission from a double tap or an impatient retry.
  bool _submitting = false;

  static const _steps = [
    (DeliveryMilestone.accepted, 'Assigned', Icons.assignment_outlined),
    (DeliveryMilestone.arrivedAtSeller, 'At Seller', Icons.storefront_outlined),
    (DeliveryMilestone.pickedUp, 'Picked Up', Icons.inventory_2_outlined),
    (DeliveryMilestone.atSortingCenter, 'At Sorting', Icons.warehouse_outlined),
    (DeliveryMilestone.sorted, 'Sorted', Icons.check_box_outlined),
    (
      DeliveryMilestone.assignedToRider,
      'To Rider',
      Icons.person_pin_circle_outlined,
    ),
    (DeliveryMilestone.inTransit, 'In Transit', Icons.local_shipping_outlined),
    (DeliveryMilestone.delivered, 'Delivered', Icons.flag_outlined),
  ];

  int _currentIndex(DeliveryMilestone milestone) =>
      _steps.indexWhere((s) => s.$1 == milestone);

  void _advance(
    CourierState courier,
    DeliveryMilestone current, {
    String? trackingNumber,
  }) {
    final next = _currentIndex(current) + 1;
    if (next >= _steps.length) return;
    final upcoming = _steps[next].$1;
    // The dispatch step is the first moment the buyer's tracker can answer
    // "who is bringing this", so that's where identity is recorded (and why
    // it's null on every earlier step - the rider who collects a parcel
    // isn't the rider who delivers it).
    final dispatch = upcoming == DeliveryMilestone.assignedToRider;
    final auth = context.read<AuthState>();
    courier.advanceMilestone(
      widget.jobId,
      upcoming,
      courierName: dispatch ? auth.name : null,
      // The rider's own profile photo, if they've set one. Passed from the
      // courier's session rather than looked up by the buyer - so the buyer's
      // app never has access to a rider's account, only to this one value
      // handed over with the assignment.
      courierPhotoPath: dispatch ? auth.avatarPath : null,
      // The typed waybill is forwarded on pickup so the server can compare
      // it with the number it issued. The local dialog already matched it;
      // this is the second, authoritative check.
      trackingNumber: trackingNumber,
    );
  }

  /// Mark delivery failed — separate from _advance so it doesn't
  /// require the proof upload check and has its own action path.
  ///
  /// Live mode records the attempt and leaves the next action to Logistics.
  Future<void> _markFailed(CourierState courier) async {
    final live = courier.isServerAuthoritative;
    var notes = '';
    String? notesError;
    final reason = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: live,
      backgroundColor: AppColors.surface,
      shape: editorialShape(0, ShapeTier.card),
      builder: (sheetContext) {
        final theme = Theme.of(sheetContext);
        return StatefulBuilder(
          builder: (context, setSheetState) {
            final choices = Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 20, 20, 6),
                  child: Text(
                    'Why did delivery fail?',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                if (live)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(20, 6, 20, 4),
                    child: TextField(
                      maxLength: 970,
                      maxLines: 2,
                      onChanged: (value) {
                        notes = value;
                        if (notesError != null) {
                          setSheetState(() => notesError = null);
                        }
                      },
                      decoration: InputDecoration(
                        labelText: 'Notes',
                        helperText: 'Required for Other',
                        errorText: notesError,
                      ),
                    ),
                  ),
                for (final option in [
                  'Buyer not available',
                  'Buyer refused',
                  'Wrong address',
                  if (!live) 'Payment not ready',
                  if (live && courier.jobById(widget.jobId)?.hasCod == true)
                    'Insufficient COD payment',
                  'Other',
                ])
                  ListTile(
                    title: Text(option),
                    onTap: () {
                      if (live && option == 'Other' && notes.trim().isEmpty) {
                        setSheetState(
                          () => notesError = 'Enter the failure details.',
                        );
                        return;
                      }
                      Navigator.of(sheetContext).pop(option);
                    },
                  ),
                const SizedBox(height: 8),
              ],
            );
            return SafeArea(
              top: false,
              child: live
                  ? Padding(
                      padding: EdgeInsets.only(
                        bottom: MediaQuery.viewInsetsOf(context).bottom,
                      ),
                      child: SingleChildScrollView(child: choices),
                    )
                  : choices,
            );
          },
        );
      },
    );
    if (reason == null || !mounted) return;

    if (live) {
      await _submitParcelAction(
        courier,
        pickup: false,
        failureReason: reason,
        failureNotes:
            reason == 'Insufficient COD payment' && notes.trim().isNotEmpty
            ? 'Insufficient COD payment. ${notes.trim()}'
            : notes,
      );
      return;
    }

    if (reason == 'Buyer refused') {
      courier.advanceMilestone(
        widget.jobId,
        DeliveryMilestone.returnToSender,
        failureReason: reason,
      );
      showTimedSnackBar(
        context,
        const SnackBar(content: Text('Marked for return to seller.')),
      );
      return;
    }

    courier.advanceMilestone(
      widget.jobId,
      DeliveryMilestone.failedDelivery,
      failureReason: reason,
    );
    if (!mounted) return;
    final attempt = courier.attemptsFor(widget.jobId);
    showTimedSnackBar(
      context,
      SnackBar(
        content: Text(
          attempt >= 3
              ? 'Third failed attempt — returning to seller.'
              : 'Delivery attempt $attempt of 3 failed: $reason',
        ),
      ),
    );
  }

  Future<void> _submitParcelAction(
    CourierState courier, {
    required bool pickup,
    String? trackingNumber,
    String? failureReason,
    String? failureNotes,
  }) async {
    try {
      final confirmed = await courier.confirmParcelAction(
        widget.jobId,
        pickup: pickup,
        trackingNumber: trackingNumber,
        failureReason: failureReason,
        failureNotes: failureNotes,
      );
      if (!mounted || !confirmed) return;
      showTimedSnackBar(
        context,
        SnackBar(
          content: Text(
            pickup
                ? 'Pickup confirmed.'
                : 'Delivery attempt recorded as failed.',
          ),
        ),
      );
    } on ApiException catch (error) {
      if (!mounted) return;
      showTimedSnackBar(
        context,
        SnackBar(content: Text(_serverMessage(error))),
      );
    }
  }

  /// Reports a lost or damaged parcel to WebZefanya (live mode).
  ///
  /// The server decides everything that follows: it accepts a report only
  /// after a verified pickup, refuses a second open report, requires a photo
  /// for damage, and blocks the parcel's operations until Admin resolves it.
  Future<void> _reportIncident(CourierState courier) async {
    final report = await showModalBottomSheet<_IncidentReport>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.surface,
      shape: editorialShape(0, ShapeTier.card),
      builder: (_) => _IncidentSheet(jobId: widget.jobId),
    );
    if (report == null || !mounted) return;

    try {
      await courier.reportIncident(
        widget.jobId,
        type: report.type,
        reason: report.reason,
        description: report.description,
        photoPath: report.photoPath,
      );
      if (!mounted) return;
      showTimedSnackBar(
        context,
        const SnackBar(
          content: Text(
            'Incident reported. This parcel is on hold until Admin resolves it.',
          ),
        ),
      );
    } on ApiException catch (error) {
      if (!mounted) return;
      final message =
          error is ValidationException && error.fieldErrors.isNotEmpty
          ? error.fieldErrors.values.first
          : error.message;
      showTimedSnackBar(context, SnackBar(content: Text(message)));
    }
  }

  void _retryDelivery(CourierState courier) {
    courier.advanceMilestone(widget.jobId, DeliveryMilestone.inTransit);
  }

  Future<void> _confirmDelivery(CourierState courier) async {
    if (_submitting ||
        courier.isDeliveryCompleting(widget.jobId) ||
        ModalRoute.isCurrentOf(context) == false) {
      return;
    }
    if (courier.isDeliveryCompletionUnconfirmed(widget.jobId)) {
      await _checkCompletion(courier);
      return;
    }
    final job = courier.jobById(widget.jobId);
    final photo = _proofImagePath;
    if (job == null || photo == null) return;

    var category = 'buyer';
    var name = '';
    var relationship = '';
    var handedOver = false;
    var accepted = false;
    var collected = false;
    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      sheetAnimationStyle: reduceMotion(context)
          ? AnimationStyle.noAnimation
          : null,
      backgroundColor: AppColors.surface,
      shape: editorialShape(0, ShapeTier.card),
      builder: (sheetContext) => StatefulBuilder(
        builder: (context, update) => SafeArea(
          top: false,
          child: SingleChildScrollView(
            padding: EdgeInsets.fromLTRB(
              20,
              20,
              20,
              20 + MediaQuery.viewInsetsOf(context).bottom,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('CONFIRM HANDOVER', style: AppType.eyebrow),
                const SizedBox(height: 8),
                const Text(
                  'Use a photo of the parcel at handover. Keep faces, IDs and private documents out of the photo.',
                ),
                CheckboxListTile(
                  key: const ValueKey('physical-handover'),
                  contentPadding: EdgeInsets.zero,
                  title: const Text('I physically handed over the parcel'),
                  value: handedOver,
                  onChanged: (value) =>
                      update(() => handedOver = value == true),
                ),
                DropdownButtonFormField<String>(
                  key: const ValueKey('recipient-category'),
                  initialValue: category,
                  isExpanded: true,
                  decoration: const InputDecoration(labelText: 'Received by'),
                  items: const [
                    DropdownMenuItem(value: 'buyer', child: Text('Buyer')),
                    DropdownMenuItem(
                      value: 'representative',
                      child: Text('Authorized representative'),
                    ),
                  ],
                  onChanged: (value) =>
                      update(() => category = value ?? 'buyer'),
                ),
                if (category == 'representative') ...[
                  const SizedBox(height: 12),
                  const Text(
                    'Confirm the buyer authorized this person before handing over the parcel.',
                  ),
                  TextFormField(
                    key: const ValueKey('recipient-name'),
                    initialValue: name,
                    maxLength: 120,
                    decoration: const InputDecoration(
                      labelText: 'Representative name',
                    ),
                    onChanged: (value) => update(() => name = value.trim()),
                  ),
                  TextFormField(
                    key: const ValueKey('recipient-relationship'),
                    initialValue: relationship,
                    maxLength: 120,
                    decoration: const InputDecoration(
                      labelText: 'Relationship to buyer',
                    ),
                    onChanged: (value) =>
                        update(() => relationship = value.trim()),
                  ),
                ],
                CheckboxListTile(
                  key: const ValueKey('recipient-accepted'),
                  contentPadding: EdgeInsets.zero,
                  title: const Text('The recipient accepted the parcel'),
                  value: accepted,
                  onChanged: (value) => update(() => accepted = value == true),
                ),
                if (job.hasCod)
                  CheckboxListTile(
                    key: const ValueKey('cod-collected'),
                    contentPadding: EdgeInsets.zero,
                    title: Text(
                      'I collected exactly ${formatPeso(job.codAmount)}',
                    ),
                    value: collected,
                    onChanged: (value) =>
                        update(() => collected = value == true),
                  ),
                const SizedBox(height: 12),
                ElevatedButton(
                  key: const ValueKey('confirm-delivery-submit'),
                  onPressed:
                      handedOver &&
                          accepted &&
                          (!job.hasCod || collected) &&
                          (category == 'buyer' ||
                              (name.isNotEmpty && relationship.isNotEmpty))
                      ? () => Navigator.of(sheetContext).pop(true)
                      : null,
                  child: const Text('Complete Delivery'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
    if (confirmed != true || !mounted || _submitting) return;
    if (courier.isServerAuthoritative) {
      setState(() => _submitting = true);
      try {
        await courier.submitDelivery(
          widget.jobId,
          proofPhotoPath: photo,
          recipientCategory: category,
          receivedBy: category == 'representative' ? name : null,
          recipientRelationship: category == 'representative'
              ? relationship
              : null,
          codAmount: job.hasCod ? job.codAmount : null,
        );
      } on ApiException catch (error) {
        if (!mounted) return;
        if (courier.isDeliveryCompletionUnconfirmed(widget.jobId)) {
          await _checkCompletion(courier);
        } else {
          showTimedSnackBar(
            context,
            SnackBar(content: Text(_serverMessage(error))),
          );
        }
        return;
      } finally {
        if (mounted) setState(() => _submitting = false);
      }
    }
    if (!mounted) return;
    _completeDelivery(courier, outcome: DeliveryMilestone.delivered);
  }

  Future<void> _checkCompletion(CourierState courier) async {
    setState(() => _submitting = true);
    bool? delivered;
    try {
      delivered = await courier.reconcileDeliveryCompletion(widget.jobId);
    } on ApiException {
      // Keep the outcome unknown until an authorized server read confirms it.
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
    if (!mounted) return;
    if (delivered == true) {
      showTimedSnackBar(
        context,
        const SnackBar(content: Text('Delivery confirmed by the server.')),
      );
      Navigator.of(context).maybePop();
      return;
    }
    showTimedSnackBar(
      context,
      SnackBar(
        content: Text(
          delivered == false
              ? 'Delivery is still open. Review the handover before trying again.'
              : 'Delivery status is unconfirmed. Check status before trying again.',
        ),
      ),
    );
  }

  /// The server's reason, with field errors (wrong code, wrong cash) first.
  String _serverMessage(ApiException error) {
    if (error is ValidationException && error.fieldErrors.isNotEmpty) {
      return error.fieldErrors.values.first;
    }
    return error.message;
  }

  bool _isToday(DateTime when) {
    final now = DateTime.now();
    return when.year == now.year &&
        when.month == now.month &&
        when.day == now.day;
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
  void _completeDelivery(
    CourierState courier, {
    required DeliveryMilestone outcome,
  }) {
    final returned = outcome == DeliveryMilestone.returnToSender;

    courier.advanceMilestone(widget.jobId, outcome);

    final firstToday = !courier.completedJobs.any(
      (c) => _isToday(c.completedAt),
    );
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
        body: Center(
          child: courier.isDeliveryCompletionUnconfirmed(widget.jobId)
              ? TextButton(
                  onPressed: _submitting
                      ? null
                      : () => _checkCompletion(courier),
                  child: Text(
                    _submitting
                        ? 'Checking delivery…'
                        : 'Check delivery status',
                  ),
                )
              : const Text('This job is no longer available.'),
        ),
      );
    }

    final milestone = courier.milestoneFor(widget.jobId);
    final sellerFirst = switch (milestone) {
      DeliveryMilestone.assignedToRider ||
      DeliveryMilestone.inTransit ||
      DeliveryMilestone.delivered ||
      DeliveryMilestone.failedDelivery => false,
      _ => true,
    };
    final seller = _buildLocationCard(
      theme,
      icon: Icons.storefront_outlined,
      title: job.shopName,
      subtitle: job.pickupAddress,
      roleLabel: 'Seller',
      contactPhone: job.sellerPhone,
      index: 0,
    );
    final buyer = _buildLocationCard(
      theme,
      icon: Icons.person_outline,
      title: job.buyerName,
      subtitle: job.deliveryArea,
      roleLabel: 'Buyer',
      contactPhone: job.buyerPhone,
      index: 1,
    );

    // A background handoff the server refused: say so once, then clear it.
    // The jobs were already re-read, so the stepper shows the server's status.
    final syncFailure = courier.syncFailure;
    if (syncFailure != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted || courier.syncFailure == null) return;
        courier.clearSyncFailure();
        showTimedSnackBar(
          context,
          SnackBar(content: Text(_serverMessage(syncFailure))),
        );
      });
    }

    return Scaffold(
      backgroundColor: Colors
          .transparent, // was AppColors.background — the app-wide gradient in main.dart now paints this
      appBar: AppBar(
        toolbarHeight: (MediaQuery.textScalerOf(context).scale(14) * 3.2).clamp(
          56.0,
          100.0,
        ),
        // Job ID is a reference code, not a human label. Show it as
        // a subtitle so the screen reads as "Delivery", not "JOB-0001".
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              milestone == DeliveryMilestone.returnToSender
                  ? 'RETURN'
                  : sellerFirst
                  ? 'PICKUP'
                  : 'DELIVERY',
              style: AppType.eyebrow.copyWith(letterSpacing: 1.2),
            ),
            Text(
              job.id,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.titleSmall,
            ),
          ],
        ),
        actions: [
          // Live mode: WebZefanya's incident report (lost / damaged parcel).
          if (courier.isServerAuthoritative)
            IconButton(
              key: const ValueKey('report-incident'),
              tooltip: 'Report a problem with this parcel',
              icon: const Icon(Icons.report_problem_outlined),
              onPressed: () => _reportIncident(courier),
            ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 24),
        children: [
          StaggeredReveal(index: 0, child: _buildStepper(theme, milestone)),
          const SizedBox(height: 12),
          StaggeredReveal(index: 1, child: sellerFirst ? seller : buyer),
          if (milestone == DeliveryMilestone.inTransit) ...[
            if (job.hasCod)
              _summaryLine(
                theme,
                'COD amount',
                formatPeso(job.codAmount),
                isBold: true,
              ),
            const SizedBox(height: 12),
            _buildProofUpload(theme),
          ],
          const SizedBox(height: 12),
          StaggeredReveal(
            index: 2,
            child: _buildOrderSummary(
              theme,
              job,
              showCod: milestone != DeliveryMilestone.inTransit || !job.hasCod,
            ),
          ),
          const SizedBox(height: 12),
          StaggeredReveal(index: 3, child: sellerFirst ? buyer : seller),
        ],
      ),
      bottomNavigationBar: _buildActionBar(theme, courier, milestone),
    );
  }

  Widget _buildStepper(ThemeData theme, DeliveryMilestone milestone) {
    final currentIndex = _currentIndex(milestone);
    final current = currentIndex >= 0 ? _steps[currentIndex] : null;
    final isException = current == null;
    final currentLabel = switch (milestone) {
      DeliveryMilestone.failedDelivery => 'Delivery failed',
      DeliveryMilestone.returnToSender => 'Returning to seller',
      _ => current!.$2,
    };
    final currentIcon = switch (milestone) {
      DeliveryMilestone.failedDelivery => Icons.error_outline,
      DeliveryMilestone.returnToSender => Icons.undo,
      _ => current!.$3,
    };
    final stepNumber = isException ? null : currentIndex + 1;
    final progress = isException ? 0.0 : stepNumber! / _steps.length;
    final next = !isException && currentIndex + 1 < _steps.length
        ? _steps[currentIndex + 1]
        : null;
    final accent = isException ? AppColors.danger : AppColors.primaryDark;

    // A compact current-step indicator instead of eight equal columns.
    // Eight labels in one row on a phone can only fit by shrinking or
    // splitting words ("Assign / ed", "Deliv / ery"). The current step is
    // the information a rider actually needs at a glance; the full
    // sequence is carried by the progress bar and the next-step line.
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(0, ShapeTier.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: isException
                      ? AppColors.danger.withValues(alpha: 0.12)
                      : AppColors.primaryDark,
                ),
                child: Icon(
                  currentIcon,
                  size: 20,
                  color: isException ? AppColors.danger : AppColors.textOnDark,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      isException
                          ? 'DELIVERY UPDATE'
                          : 'STEP $stepNumber OF ${_steps.length}',
                      style: AppType.eyebrow.copyWith(color: accent),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      currentLabel,
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          ClipRRect(
            borderRadius: radiusOf(ShapeTier.pill),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 6,
              backgroundColor: AppColors.divider,
              color: accent,
            ),
          ),
          if (next != null) ...[
            const SizedBox(height: 10),
            Text(
              'Next: ${next.$2}',
              style: theme.textTheme.bodySmall?.copyWith(
                color: AppColors.textSecondary,
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildLocationCard(
    ThemeData theme, {
    required IconData icon,
    required String title,
    required String subtitle,
    required String roleLabel,
    required int index,
    String? contactPhone,
  }) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surfaceMuted,
        borderRadius: editorialRadius(index, ShapeTier.card),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
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
                    Text(subtitle, style: theme.textTheme.bodySmall),
                    if (contactPhone != null && contactPhone.isNotEmpty)
                      Text(
                        contactPhone,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: AppColors.textSecondary,
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Wrap(
            spacing: 8,
            runSpacing: 4,
            children: [
              _circleAction(
                Icons.call_outlined,
                () => _callNumber(contactPhone, title),
                semanticLabel: 'Call $title',
              ),
              if (FeatureFlags.chatLive)
                _circleAction(
                  Icons.chat_bubble_outline,
                  () => Navigator.of(context).push(
                    AppPageRoute(
                      builder: (_) => ChatScreen(
                        contactName: title,
                        contactRole: roleLabel,
                      ),
                    ),
                  ),
                  semanticLabel: 'Message $title',
                ),
              // Open in Maps: hands the address to the device's maps app.
              // Address only — no buyer phone is shown or passed anywhere
              // here (see OPEN QUESTIONS: call-buyer privacy).
              if (roleLabel == 'Buyer') ...[
                _circleAction(
                  Icons.map_outlined,
                  () {
                    if (context.read<CourierState>().isServerAuthoritative) {
                      openAddressInMaps(context, subtitle);
                    } else {
                      _showDemoContactNotice();
                    }
                  },
                  semanticLabel: 'Open delivery address in Maps',
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }

  /// Opens the device dialer for the number the server shared with this
  /// job. If no number was shared, says so instead of pretending to call.
  Future<void> _callNumber(String? phone, String name) async {
    if (!context.read<CourierState>().isServerAuthoritative) {
      _showDemoContactNotice();
      return;
    }
    if (phone == null || phone.isEmpty) {
      showTimedSnackBar(
        context,
        SnackBar(content: Text('No contact number was shared for $name.')),
      );
      return;
    }
    final uri = Uri(scheme: 'tel', path: phone);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri);
      return;
    }
    if (!mounted) return;
    showTimedSnackBar(
      context,
      SnackBar(content: Text('Could not open the dialer for $phone.')),
    );
  }

  void _showDemoContactNotice() => showTimedSnackBar(
    context,
    const SnackBar(content: Text('Calls and Maps are disabled in demo mode.')),
  );

  Widget _circleAction(
    IconData icon,
    VoidCallback onTap, {
    String? semanticLabel,
  }) {
    return Semantics(
      button: true,
      label: semanticLabel,
      excludeSemantics: semanticLabel != null,
      child: PressableScale(
        onTap: onTap,
        child: Container(
          width: 48,
          height: 48,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: AppColors.surface,
            shape: BoxShape.circle,
          ),
          child: Icon(icon, size: 16, color: AppColors.textPrimary),
        ),
      ),
    );
  }

  Widget _buildOrderSummary(
    ThemeData theme,
    DeliveryJob job, {
    required bool showCod,
  }) {
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
          _summaryLine(theme, 'Tracking number', job.trackingNumber ?? job.id),
          _summaryLine(theme, 'Package', job.packageSize),
          _summaryLine(
            theme,
            job.parcelCount == 1 ? 'Parcel' : 'Parcels',
            '${job.parcelCount}',
          ),
          if (job.sellerPhone != null && job.sellerPhone!.isNotEmpty)
            _summaryLine(theme, 'Seller contact', job.sellerPhone!),
          if (job.buyerPhone != null && job.buyerPhone!.isNotEmpty)
            _summaryLine(theme, 'Buyer contact', job.buyerPhone!),
          _summaryLine(theme, 'Payment method', job.paymentLabel),
          if (showCod)
            _summaryLine(
              theme,
              'COD amount',
              job.prepaid ? '₱0.00 · Paid' : formatPeso(job.codAmount),
            ),
          const Divider(height: 20),
          Wrap(
            spacing: 10,
            runSpacing: 4,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Text('YOUR DELIVERY FEE', style: AppType.eyebrow),
              // The fee is what the courier is actually here for on this
              // screen — same numeral treatment as the job card and the
              // earnings summary, so the three places a fee appears agree.
              Text(formatPeso(job.fee), style: AppType.priceNumeral(28)),
            ],
          ),
        ],
      ),
    );
  }

  Widget _summaryLine(
    ThemeData theme,
    String label,
    String value, {
    bool isBold = false,
  }) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Expanded(child: Text(label, style: theme.textTheme.bodySmall)),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              value,
              textAlign: TextAlign.end,
              style:
                  (isBold
                          ? theme.textTheme.titleSmall
                          : theme.textTheme.bodyMedium)
                      ?.copyWith(
                        fontWeight: isBold ? FontWeight.w700 : FontWeight.w600,
                        color: isBold
                            ? AppColors.primaryDark
                            : AppColors.textPrimary,
                      ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _captureProof() async {
    if (_proofPicking) return;
    setState(() => _proofPicking = true);
    try {
      final picked = await pickAndSaveImage(
        context,
        filenamePrefix: 'proof_${widget.jobId}',
        cameraOnly: true,
      );
      if (picked != null && mounted) _proofImagePath = picked.path;
    } on Exception catch (error) {
      if (mounted) {
        showTimedSnackBar(
          context,
          SnackBar(content: Text(mediaProblemMessage(mediaProblemOf(error)))),
        );
      }
    } finally {
      if (mounted) setState(() => _proofPicking = false);
    }
  }

  Widget _buildProofUpload(ThemeData theme) {
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
              Icon(
                Icons.camera_alt_outlined,
                size: 16,
                color: AppColors.primaryDark,
              ),
              const SizedBox(width: 8),
              Flexible(
                child: Text(
                  'Proof of Delivery',
                  style: theme.textTheme.titleSmall,
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          InkWell(
            onTap: _proofPicking ? null : _captureProof,
            borderRadius: editorialRadius(0, ShapeTier.accent),
            child: Container(
              constraints: const BoxConstraints(minHeight: 90),
              padding: const EdgeInsets.all(12),
              width: double.infinity,
              decoration: BoxDecoration(
                color: _proofUploaded
                    ? AppColors.tertiary.withValues(alpha: 0.22)
                    : AppColors.surface,
                borderRadius: editorialRadius(0, ShapeTier.accent),
                border: Border.all(
                  color: _proofUploaded
                      ? AppColors.tertiary
                      : AppColors.divider,
                  width: _proofUploaded ? 1.5 : 1,
                ),
              ),
              child: _proofUploaded
                  ? Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(
                          Icons.check_circle_outline,
                          color: AppColors.successText,
                          size: 30,
                        ),
                        const SizedBox(height: 8),
                        Text(
                          'Photo attached',
                          style: theme.textTheme.bodyMedium?.copyWith(
                            fontWeight: FontWeight.w600,
                            color: AppColors.successText,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          'Tap to retake',
                          style: theme.textTheme.labelSmall,
                        ),
                      ],
                    )
                  : Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(
                          Icons.add_a_photo_outlined,
                          color: AppColors.primaryDark.withValues(alpha: 0.55),
                          size: 30,
                        ),
                        const SizedBox(height: 8),
                        Text(
                          'Tap to attach a photo',
                          style: theme.textTheme.bodySmall,
                        ),
                      ],
                    ),
            ),
          ),
        ],
      ),
    );
  }

  /// Pickup is the one handover where the courier and the parcel meet for the
  /// first time. The tracking number is the parcel's identity, so confirming it
  /// here (rather than a bare "picked up" tap) is what stops the wrong parcel
  /// being carried away. The number is already visible in Order Details for a
  /// manual cross-check; the field accepts it typed or pasted, which also covers
  /// a damaged barcode that a scanner cannot read.
  Future<void> _confirmPickup(CourierState courier) async {
    final job = courier.jobById(widget.jobId);
    if (job == null) return;
    final expected = (job.trackingNumber ?? job.id).trim().toUpperCase();
    // A plain value rather than a TextEditingController: the field is disposed
    // by the dialog route, and disposing a controller here races the dialog's
    // exit animation, which still reads it for one frame.
    var enteredText = '';
    String? error;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (dialogContext, setDialogState) {
          final theme = Theme.of(dialogContext);
          return AlertDialog(
            title: const Text('Verify the parcel'),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Enter the tracking number on the waybill before taking '
                  'the parcel from the seller.',
                  style: theme.textTheme.bodySmall,
                ),
                const SizedBox(height: 12),
                TextField(
                  key: const ValueKey('pickup-tracking'),
                  autofocus: true,
                  textCapitalization: TextCapitalization.characters,
                  onChanged: (value) => enteredText = value,
                  decoration: const InputDecoration(
                    labelText: 'Tracking number',
                    hintText: 'e.g. ZEF-10246-01',
                  ),
                ),
                if (expected.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    onPressed: () async {
                      final result = await Navigator.of(context).push<String>(
                        MaterialPageRoute(
                          builder: (_) => CourierQrScanner(
                            key: ValueKey('qr-scanner-$expected'),
                            expectedTrackingNumber: expected,
                            riderTaskLabel: 'Scan the seller’s parcel label',
                          ),
                        ),
                      );
                      if (mounted && result != null) {
                        setDialogState(() => enteredText = result);
                      }
                    },
                    icon: const Icon(Icons.qr_code_scanner),
                    label: const Text('Scan QR'),
                  ),
                ],
                if (error != null) ...[
                  const SizedBox(height: 10),
                  Text(
                    error!,
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: AppColors.danger,
                    ),
                  ),
                ],
              ],
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.of(dialogContext).pop(false),
                child: const Text('Cancel'),
              ),
              FilledButton(
                onPressed: () {
                  final entered = enteredText.trim().toUpperCase();
                  if (entered.isEmpty) {
                    setDialogState(
                      () => error = 'Enter the tracking number first.',
                    );
                    return;
                  }
                  if (entered != expected) {
                    setDialogState(
                      () => error =
                          'That tracking number does not match this parcel.',
                    );
                    return;
                  }
                  Navigator.of(dialogContext).pop(true);
                },
                child: const Text('Confirm Pickup'),
              ),
            ],
          );
        },
      ),
    );
    if (confirmed != true || !mounted) return;
    if (courier.isServerAuthoritative) {
      await _submitParcelAction(
        courier,
        pickup: true,
        trackingNumber: enteredText.trim(),
      );
      return;
    }
    _advance(
      courier,
      DeliveryMilestone.arrivedAtSeller,
      trackingNumber: enteredText.trim(),
    );
  }

  Widget _buildActionBar(
    ThemeData theme,
    CourierState courier,
    DeliveryMilestone milestone,
  ) {
    final live = courier.isServerAuthoritative;
    final parcelActionPending = courier.isParcelActionPending(widget.jobId);
    final parcelActionUnconfirmed = courier.isParcelActionUnconfirmed(
      widget.jobId,
    );
    final logisticsStep = courier.isServerAuthoritative
        ? switch (milestone) {
            DeliveryMilestone.pickedUp =>
              'Hand the parcel to the sorting center. Logistics will confirm receipt.',
            DeliveryMilestone.atSortingCenter =>
              'Waiting for Logistics to sort the parcel.',
            DeliveryMilestone.sorted =>
              'Waiting for Logistics to assign a delivery rider.',
            DeliveryMilestone.failedDelivery =>
              'Return the parcel to the station. Logistics will confirm receipt and arrange the next step.',
            _ => null,
          }
        : null;
    final (label, enabled, isFinalStep) = switch (milestone) {
      DeliveryMilestone.accepted => ('Confirm Arrived at Seller', true, false),
      DeliveryMilestone.arrivedAtSeller => (
        'Confirm Pickup from Seller',
        true,
        false,
      ),
      DeliveryMilestone.pickedUp => ('Drop at Sorting Center', true, false),
      DeliveryMilestone.atSortingCenter => (
        'Confirm Package Sorted',
        true,
        false,
      ),
      DeliveryMilestone.sorted => ('Mark Assigned to Rider', true, false),
      DeliveryMilestone.assignedToRider => (
        'Start Delivery Transit',
        true,
        false,
      ),
      DeliveryMilestone.inTransit => (
        'Confirm Order Delivered',
        _proofUploaded,
        true,
      ),
      DeliveryMilestone.delivered => ('Delivery Complete', false, true),
      DeliveryMilestone.failedDelivery => ('Retry Delivery', true, false),
      DeliveryMilestone.returnToSender => ('Return Complete', true, true),
    };

    return Container(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        boxShadow: [
          BoxShadow(
            color: AppColors.neutral.withValues(alpha: 0.12),
            blurRadius: 16,
            offset: const Offset(0, -4),
          ),
        ],
      ),
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (!live &&
                milestone == DeliveryMilestone.inTransit &&
                courier.attemptsFor(widget.jobId) > 0)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  'Delivery attempt ${courier.attemptsFor(widget.jobId) + 1} of 3',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
                ),
              ),
            if (!live && milestone == DeliveryMilestone.failedDelivery)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  'Attempt ${courier.attemptsFor(widget.jobId)} of 3 failed'
                  '${courier.failureReasonFor(widget.jobId) == null ? '' : ' — ${courier.failureReasonFor(widget.jobId)}'}',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: AppColors.danger,
                  ),
                ),
              ),
            // The stepper above already names the return; this line only adds
            // the reason, so "Returning to seller" is not stated twice.
            if (milestone == DeliveryMilestone.returnToSender &&
                courier.failureReasonFor(widget.jobId) != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  'Reason: ${courier.failureReasonFor(widget.jobId)}',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
                ),
              ),
            if (!enabled && milestone == DeliveryMilestone.inTransit)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: TextButton.icon(
                  onPressed: _proofPicking ? null : _captureProof,
                  icon: const Icon(Icons.camera_alt_outlined, size: 18),
                  label: const Text('Take proof photo to continue'),
                ),
              ),
            if (parcelActionPending)
              Text(
                milestone == DeliveryMilestone.inTransit
                    ? 'Recording delivery attempt…'
                    : 'Confirming pickup…',
                textAlign: TextAlign.center,
                semanticsLabel: 'Waiting for server confirmation',
              )
            else if (parcelActionUnconfirmed ||
                courier.isDeliveryStartUnconfirmed(widget.jobId))
              TextButton(
                onPressed: courier.loadingJobs
                    ? null
                    : () async {
                        try {
                          await courier.refreshJobs();
                        } on ApiException {
                          // The queue retains the error for the next retry.
                        }
                      },
                child: Text(
                  parcelActionUnconfirmed
                      ? 'Parcel update unconfirmed. Refresh status'
                      : 'Delivery start unconfirmed. Refresh status',
                ),
              )
            else if (logisticsStep != null)
              Text(logisticsStep, textAlign: TextAlign.center)
            else
              ElevatedButton(
                onPressed:
                    _submitting ||
                        parcelActionPending ||
                        parcelActionUnconfirmed ||
                        courier.isDeliveryCompleting(widget.jobId) ||
                        (!enabled &&
                            !courier.isDeliveryCompletionUnconfirmed(
                              widget.jobId,
                            )) ||
                        courier.isStartingDelivery(widget.jobId)
                    ? null
                    : isFinalStep
                    ? milestone == DeliveryMilestone.inTransit
                          ? () => _confirmDelivery(courier)
                          : () => _completeDelivery(courier, outcome: milestone)
                    : milestone == DeliveryMilestone.arrivedAtSeller
                    ? () => _confirmPickup(courier)
                    : milestone == DeliveryMilestone.failedDelivery
                    ? () => _retryDelivery(courier)
                    : () => _advance(courier, milestone),
                child: Text(
                  _submitting || courier.isDeliveryCompleting(widget.jobId)
                      ? 'Submitting delivery…'
                      : courier.isDeliveryCompletionUnconfirmed(widget.jobId)
                      ? 'Check delivery status'
                      : courier.isStartingDelivery(widget.jobId)
                      ? 'Starting delivery…'
                      : label,
                ),
              ),
            // "Mark as Failed" only shown while in transit — the one
            // stage where real delivery attempts happen.
            if (milestone == DeliveryMilestone.inTransit) ...[
              const SizedBox(height: 8),
              OutlinedButton(
                onPressed:
                    _submitting ||
                        parcelActionPending ||
                        parcelActionUnconfirmed ||
                        courier.isDeliveryCompleting(widget.jobId) ||
                        courier.isDeliveryCompletionUnconfirmed(widget.jobId)
                    ? null
                    : () => _markFailed(courier),
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
    final today = courier.completedJobs
        .where((c) => _isToday(c.completedAt))
        .toList();

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

/// What the rider filled in on the incident sheet.
typedef _IncidentReport = ({
  String type,
  String reason,
  String description,
  String? photoPath,
});

/// The incident form: the fields WebZefanya's `ParcelIncidentService::reportRules`
/// asks for (type lost|damaged, reason ≤255, description ≤1000, photo for
/// damage). The limits are mirrored only so the form can refuse early; the
/// server still validates every one.
class _IncidentSheet extends StatefulWidget {
  const _IncidentSheet({required this.jobId});

  final String jobId;

  @override
  State<_IncidentSheet> createState() => _IncidentSheetState();
}

class _IncidentSheetState extends State<_IncidentSheet> {
  final _formKey = GlobalKey<FormState>();
  final _reason = TextEditingController();
  final _description = TextEditingController();
  String _type = 'lost';
  String? _photoPath;
  bool _photoMissing = false;

  @override
  void dispose() {
    _reason.dispose();
    _description.dispose();
    super.dispose();
  }

  void _submit() {
    final needsPhoto = _type == 'damaged' && _photoPath == null;
    setState(() => _photoMissing = needsPhoto);
    if (!(_formKey.currentState?.validate() ?? false) || needsPhoto) return;
    Navigator.of(context).pop<_IncidentReport>((
      type: _type,
      reason: _reason.text.trim(),
      description: _description.text.trim(),
      photoPath: _type == 'damaged' ? _photoPath : null,
    ));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return SafeArea(
      top: false,
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          20,
          20,
          20,
          16 + MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: Form(
          key: _formKey,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('Report a problem', style: theme.textTheme.titleMedium),
                const SizedBox(height: 4),
                Text(
                  'The parcel is put on hold until Admin reviews the report.',
                  style: theme.textTheme.bodySmall,
                ),
                const SizedBox(height: 12),
                SegmentedButton<String>(
                  segments: const [
                    ButtonSegment(value: 'lost', label: Text('Lost')),
                    ButtonSegment(value: 'damaged', label: Text('Damaged')),
                  ],
                  selected: {_type},
                  onSelectionChanged: (s) => setState(() => _type = s.first),
                ),
                const SizedBox(height: 12),
                TextFormField(
                  key: const ValueKey('incident-reason'),
                  controller: _reason,
                  maxLength: 255,
                  decoration: const InputDecoration(labelText: 'Reason'),
                  validator: (v) =>
                      (v ?? '').trim().isEmpty ? 'Enter a reason' : null,
                ),
                TextFormField(
                  key: const ValueKey('incident-description'),
                  controller: _description,
                  maxLength: 1000,
                  minLines: 2,
                  maxLines: 5,
                  decoration: const InputDecoration(
                    labelText: 'What happened?',
                  ),
                  validator: (v) => (v ?? '').trim().isEmpty
                      ? 'Describe what happened'
                      : null,
                ),
                if (_type == 'damaged') ...[
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    key: const ValueKey('incident-photo'),
                    onPressed: () async {
                      final picked = await pickAndSaveImage(
                        context,
                        filenamePrefix: 'incident_${widget.jobId}',
                      );
                      if (picked != null && mounted) {
                        setState(() {
                          _photoPath = picked.path;
                          _photoMissing = false;
                        });
                      }
                    },
                    icon: Icon(
                      _photoPath == null
                          ? Icons.add_a_photo_outlined
                          : Icons.check_circle_outline,
                    ),
                    label: Text(
                      _photoPath == null
                          ? 'Add a photo of the damage'
                          : 'Photo added',
                    ),
                  ),
                  if (_photoMissing)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Text(
                        'Attach a photo when reporting a damaged parcel.',
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: AppColors.danger,
                        ),
                      ),
                    ),
                ],
                const SizedBox(height: 16),
                FilledButton(
                  key: const ValueKey('incident-submit'),
                  onPressed: _submit,
                  child: const Text('Submit report'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
