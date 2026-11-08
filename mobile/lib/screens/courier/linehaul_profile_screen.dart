import 'package:flutter/material.dart';
import '../../data/api/api_exception.dart';
import '../../data/repositories/linehaul_repository.dart';
import '../../theme/app_theme.dart';
import '../../widgets/editorial_header.dart';
import '../../widgets/editorial_shapes.dart';
import '../../widgets/empty_state.dart';
import '../../widgets/zefanya_mark.dart';

class LinehaulProfileScreen extends StatefulWidget {
  const LinehaulProfileScreen({super.key, required this.repository});
  final LinehaulRepository repository;

  @override
  State<LinehaulProfileScreen> createState() => _LinehaulProfileScreenState();
}

class _LinehaulProfileScreenState extends State<LinehaulProfileScreen> {
  late Future<LinehaulProfile> _profile;

  @override
  void initState() {
    super.initState();
    _profile = widget.repository.profile();
  }

  void _reload() {
    setState(() {
      _profile = widget.repository.profile();
    });
  }

  Widget _section(String title, IconData icon, List<Widget> children) => Card(
    color: AppColors.surface,
    elevation: 0,
    margin: const EdgeInsets.only(bottom: 16),
    shape: RoundedRectangleBorder(
      borderRadius: editorialRadius(0, ShapeTier.card),
      side: BorderSide(color: AppColors.divider),
    ),
    child: Padding(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Icon(icon, color: AppColors.primary, size: 28),
          const SizedBox(height: 12),
          Text(title, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 16),
          ...children,
        ],
      ),
    ),
  );

  Widget _detail(String label, String value) => Padding(
    padding: const EdgeInsets.only(bottom: 16),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: Theme.of(context).textTheme.labelMedium),
        const SizedBox(height: 4),
        SelectableText(value, style: Theme.of(context).textTheme.bodyLarge),
      ],
    ),
  );

  @override
  Widget build(BuildContext context) {
    AppColors.watch(context);
    return Scaffold(
      backgroundColor: Colors.transparent,
      appBar: AppBar(title: const Text('Courier profile')),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 720),
          child: FutureBuilder<LinehaulProfile>(
            future: _profile,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const Center(child: ZefanyaLoader(size: 48));
              }
              if (snapshot.hasError) {
                return SingleChildScrollView(
                  child: EmptyState(
                    icon: Icons.cloud_off_outlined,
                    title: 'Your profile could not be loaded',
                    subtitle: snapshot.error is ApiException
                        ? (snapshot.error as ApiException).message
                        : 'Please try again.',
                    action: OutlinedButton(
                      onPressed: _reload,
                      child: const Text('Retry'),
                    ),
                  ),
                );
              }
              final profile = snapshot.requireData;
              return RefreshIndicator(
                onRefresh: () async {
                  _reload();
                  try {
                    await _profile;
                  } on ApiException {
                    // The error view replaces stale profile details.
                  }
                },
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
                  children: [
                    const EditorialHeader(
                      eyebrow: 'YOUR ZEFANYA',
                      title: 'Made for\nyour journey.',
                      padding: EdgeInsets.only(bottom: 24),
                    ),
                    _section('Your details', Icons.badge_outlined, [
                      _detail('Name', profile.name),
                      _detail('Email', profile.email),
                      _detail('Account status', profile.status),
                      _detail(
                        'Email verification',
                        profile.emailVerified ? 'Verified' : 'Not verified',
                      ),
                    ]),
                    _section('Your company', Icons.local_shipping_outlined, [
                      _detail('Logistics company', profile.company),
                      _detail('Home hub', profile.homeHub),
                      const Text(
                        'These details come from your company account. Contact Logistics if they need correcting.',
                      ),
                    ]),
                    _section('Vehicle & documents', Icons.folder_outlined, [
                      const Text(
                        'Vehicle, license and document records are not available in this version. Uploads and company review are still being added.',
                      ),
                    ]),
                  ],
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}
