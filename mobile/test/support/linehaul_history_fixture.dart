Map<String, Object?> historyFixture(List<Map<String, Object?>> trips) {
  final rows = trips
      .where(
        (trip) => const {
          'Completed',
          'Received with Issues',
          'Cancelled',
          'Declined',
        }.contains(trip['status']),
      )
      .map(
        (trip) => <String, Object?>{
          ...trip,
          'history_key': 'fixture:${trip['id']}',
          'recorded_at': DateTime.now().toUtc().toIso8601String(),
        },
      )
      .toList();
  return {
    'page': 1,
    'last_page': 1,
    'total': rows.length,
    'timezone': 'Asia/Manila',
    'trips': rows,
  };
}
