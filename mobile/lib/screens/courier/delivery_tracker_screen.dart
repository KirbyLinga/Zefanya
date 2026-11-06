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
import '../../state/auth_state.dart';
import '../../state/courier_state.dart';
import '../../data/mock_data.dart' show DeliveryJob;
import '../../theme/app_theme.dart';
import '../../utils/money_format.dart';
import '../../utils/timed_snackbar.dart';
import '../shared/chat_screen.dart';
import '../../widgets/app_page_route.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/pressable_scale.dart';
import '../../widgets/staggered_reveal.dart';
import 'widgets/delivery_completion_view.dart';
import '../../utils/image_picker_helper.dart';
import '../../utils/open_maps.dart';
import '../../utils/delivery_pass.dart';
import 'courier_qr_scanner.dart';

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
  /// The reason is required: a failed attempt without one tells the buyer
  /// and Logistics nothing they can act on. "Buyer refused" is the one
  /// reason that ends the attempt chain immediately (RID-FAIL-003); every
  /// other reason is retried, up to CourierState's three-attempt limit.
  Future<void> _markFailed(CourierState courier) async {
    final reason = await showModalBottomSheet<String>(
      context: context,
      backgroundColor: AppColors.surface,
      shape: editorialShape(0, ShapeTier.card),
      builder: (sheetContext) {
        final theme = Theme.of(sheetContext);
        return SafeArea(
          top: false,
          child: Column(
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
              for (final option in const [
                'Buyer not available',
                'Buyer refused',
                'Wrong address',
                'Payment not ready',
                'Other',
              ])
                ListTile(
                  title: Text(option),
                  onTap: () => Navigator.of(sheetContext).pop(option),
                ),
              const SizedBox(height: 8),
            ],
          ),
        );
      },
    );
    if (reason == null || !mounted) return;

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

  void _retryDelivery(CourierState courier) {
    courier.advanceMilestone(widget.jobId, DeliveryMilestone.inTransit);
  }

  /// The handover check before a delivery can be completed.
  ///
  /// Two independent facts are verified at the door: the code the buyer reads
  /// out, and — for a COD parcel — the exact cash amount. The sheet stays open
  /// on a wrong value so the rider can correct it without losing the photo or
  /// the typed code. After five wrong codes [CourierState] locks entry for 15
  /// minutes; the sheet says so rather than letting the button look broken.
  Future<void> _confirmDelivery(CourierState courier) async {
    final job = courier.jobById(widget.jobId);
    if (job == null) return;

    final otpController = TextEditingController();
    final amountController = TextEditingController();
    String? error;

    final confirmed = await showModalBottomSheet<({String otp, double? cash})>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.surface,
      shape: editorialShape(0, ShapeTier.card),
      builder: (sheetContext) {
        return StatefulBuilder(
          builder: (context, setSheetState) {
            final sheetTheme = Theme.of(context);
            final locked = courier.otpLockRemaining(widget.jobId);
            return Padding(
              padding: EdgeInsets.fromLTRB(
                20,
                20,
                20,
                20 + MediaQuery.of(context).viewInsets.bottom,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text('CONFIRM HANDOVER', style: AppType.eyebrow),
                  const SizedBox(height: 8),
                  Text(
                    "Scan the buyer's delivery pass or ask for the 6-digit "
                    'code.',
                    style: sheetTheme.textTheme.bodyMedium,
                  ),
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    key: const ValueKey('delivery-pass-scan'),
                    onPressed: locked != null
                        ? null
                        : () async {
                            final scanned = await Navigator.of(context)
                                .push<String>(
                                  MaterialPageRoute(
                                    builder: (_) => CourierQrScanner(
                                      key: ValueKey(
                                        'delivery-pass-scanner-${job.id}',
                                      ),
                                      expectedTrackingNumber: job.id,
                                      manualEntryLabel: 'delivery code',
                                      manualHint: '6 digits',
                                    ),
                                  ),
                                );
                            if (scanned == null || !context.mounted) return;

                            final result = readDeliveryPassScan(
                              scanned,
                              jobId: job.id,
                            );
                            if (result.error != null) {
                              setSheetState(() => error = result.error);
                              return;
                            }
                            otpController.text = result.code!;
                            setSheetState(() => error = null);
                          },
                    icon: const Icon(Icons.qr_code_scanner),
                    label: const Text("Scan buyer's QR"),
                  ),
                  if (courier.isServerAuthoritative) ...[
                    const SizedBox(height: 8),
                    TextButton.icon(
                      key: const ValueKey('delivery-code-send'),
                      onPressed: () => _requestCode(courier),
                      icon: const Icon(Icons.sms_outlined),
                      label: const Text('Send code to buyer'),
                    ),
                  ],
                  const SizedBox(height: 16),
                  TextField(
                    key: const ValueKey('delivery-otp-field'),
                    controller: otpController,
                    enabled: locked == null,
                    keyboardType: TextInputType.number,
                    maxLength: 6,
                    decoration: const InputDecoration(
                      labelText: 'Delivery OTP',
                      hintText: '6 digits',
                      counterText: '',
                    ),
                  ),
                  if (job.hasCod) ...[
                    const SizedBox(height: 12),
                    TextField(
                      key: const ValueKey('cod-amount-field'),
                      controller: amountController,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      decoration: InputDecoration(
                        labelText: 'Cash collected',
                        hintText: formatPeso(job.codAmount),
                        prefixText: '₱ ',
                      ),
                    ),
                  ],
                  if (locked != null) ...[
                    const SizedBox(height: 12),
                    Text(
                      'Entry is locked. Try again in '
                      '${(locked.inSeconds / 60).ceil()} minutes.',
                      style: sheetTheme.textTheme.bodySmall?.copyWith(
                        color: AppColors.danger,
                      ),
                    ),
                  ],
                  if (error != null) ...[
                    const SizedBox(height: 12),
                    Text(
                      error!,
                      key: const ValueKey('handover-error'),
                      style: sheetTheme.textTheme.bodySmall?.copyWith(
                        color: AppColors.danger,
                      ),
                    ),
                  ],
                  const SizedBox(height: 20),
                  ElevatedButton(
                    key: const ValueKey('confirm-delivery-submit'),
                    onPressed: () {
                      final otpProblem = courier.validateDeliveryOtp(
                        widget.jobId,
                        otpController.text,
                      );
                      final codProblem = otpProblem != null
                          ? null
                          : courier.validateCodAmount(
                              widget.jobId,
                              amountController.text,
                            );
                      final problem = otpProblem ?? codProblem;
                      if (problem != null) {
                        setSheetState(() => error = problem);
                        return;
                      }
                      Navigator.of(sheetContext).pop((
                        otp: otpController.text.trim(),
                        cash: job.hasCod
                            ? _parseCash(amountController.text)
                            : null,
                      ));
                    },
                    child: const Text('Complete Delivery'),
                  ),
                ],
              ),
            );
          },
        );
      },
    );

    otpController.dispose();
    amountController.dispose();
    if (confirmed == null || !mounted) return;

    // Live mode: WebZefanya checks the photo, the buyer's code and the cash,
    // and records the delivery once. Only its answer completes the job here.
    if (courier.isServerAuthoritative) {
      final photo = _proofImagePath;
      if (_submitting || photo == null) return;
      setState(() => _submitting = true);
      try {
        await courier.submitDelivery(
          widget.jobId,
          proofPhotoPath: photo,
          deliveryOtp: confirmed.otp,
          codAmount: confirmed.cash,
        );
      } on ApiException catch (error) {
        if (!mounted) return;
        setState(() => _submitting = false);
        showTimedSnackBar(context, SnackBar(content: Text(_serverMessage(error))));
        return;
      }
      if (!mounted) return;
      setState(() => _submitting = false);
    }

    _completeDelivery(
      courier,
      outcome: DeliveryMilestone.delivered,
      deliveryOtp: confirmed.otp,
      codAmount: confirmed.cash,
    );
  }

  /// The server's reason, with field errors (wrong code, wrong cash) first.
  String _serverMessage(ApiException error) {
    if (error is ValidationException && error.fieldErrors.isNotEmpty) {
      return error.fieldErrors.values.first;
    }
    return error.message;
  }

  /// Live mode: asks WebZefanya to send the buyer a new delivery code.
  Future<void> _requestCode(CourierState courier) async {
    try {
      await courier.requestDeliveryCode(widget.jobId);
      if (!mounted) return;
      showTimedSnackBar(
        context,
        const SnackBar(content: Text('Delivery code sent to the buyer.')),
      );
    } on ApiException catch (error) {
      if (!mounted) return;
      showTimedSnackBar(context, SnackBar(content: Text(_serverMessage(error))));
    }
  }

  /// The cash figure typed at the door, as a number.
  ///
  /// The field is numeric but a rider may paste "₱700.00" or "700,00"; the
  /// validation in [CourierState] already rejected anything that is not the
  /// exact amount, so this only needs to recover the number it accepted.
  double? _parseCash(String input) {
    final normalized = input.replaceAll(RegExp(r'[^0-9.]'), '');
    return double.tryParse(normalized);
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
    String? deliveryOtp,
    double? codAmount,
  }) {
    final returned = outcome == DeliveryMilestone.returnToSender;

    // Write the handover to the server before the local job disappears.
    // DELIVERED carries the code and cash the rider just checked; a RETURNED
    // outcome already reached this state through _markFailed, whose terminal
    // guard makes this call a no-op. Without the write, the courier's screen
    // would say "delivered" while the buyer's tracker stayed at Out for
    // Delivery.
    courier.advanceMilestone(
      widget.jobId,
      outcome,
      deliveryOtp: deliveryOtp,
      codAmount: codAmount,
    );

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
        // Job ID is a reference code, not a human label. Show it as
        // a subtitle so the screen reads as "Delivery", not "JOB-0001".
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'DELIVERY',
              style: AppType.eyebrow.copyWith(letterSpacing: 1.2),
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
              contactPhone: job.sellerPhone,
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
              contactPhone: job.buyerPhone,
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
                Text(
                  subtitle,
                  style: theme.textTheme.bodySmall,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
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
          _circleAction(
            Icons.call_outlined,
            () => _callNumber(contactPhone, title),
            semanticLabel: 'Call $title',
          ),
          const SizedBox(width: 8),
          if (FeatureFlags.chatLive)
            _circleAction(
              Icons.chat_bubble_outline,
              () => Navigator.of(context).push(
                AppPageRoute(
                  builder: (_) =>
                      ChatScreen(contactName: title, contactRole: roleLabel),
                ),
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

  /// Opens the device dialer for the number the server shared with this
  /// job. If no number was shared, says so instead of pretending to call.
  Future<void> _callNumber(String? phone, String name) async {
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

  Widget _buildOrderSummary(ThemeData theme, DeliveryJob job) {
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
          _summaryLine(
            theme,
            'COD amount',
            job.prepaid ? '₱0.00 · Paid' : formatPeso(job.codAmount),
          ),
          const Divider(height: 20),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            crossAxisAlignment: CrossAxisAlignment.end,
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
          Text(
            value,
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
              Icon(
                Icons.camera_alt_outlined,
                size: 16,
                color: AppColors.primaryDark,
              ),
              const SizedBox(width: 8),
              Text('Proof of Delivery', style: theme.textTheme.titleSmall),
            ],
          ),
          const SizedBox(height: 10),
          InkWell(
            onTap: () async {
              final picked = await pickAndSaveImage(
                context,
                filenamePrefix: 'proof_${widget.jobId}',
                // RID-DLV-008: proof must be taken live, not chosen from
                // the gallery, so the photo proves the parcel was there.
                cameraOnly: true,
              );
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
            if (milestone == DeliveryMilestone.inTransit &&
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
            if (milestone == DeliveryMilestone.failedDelivery)
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
                child: Text(
                  'Attach a live proof-of-delivery photo to continue.',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: AppColors.textSecondary,
                  ),
                ),
              ),
            ElevatedButton(
              onPressed: !enabled
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
