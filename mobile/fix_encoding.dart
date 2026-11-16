// ignore_for_file: avoid_print
// fix_encoding.dart
//
// One-shot: normalize every lib/**/*.dart to UTF-8 without BOM and
// repair mojibake sequences (UTF-8 bytes misread as Latin-1, e.g.
// "â€”" for the em dash). Reads/writes BYTES as UTF-8 — never the
// editor tooling that caused the damage.
//
// Run: dart fix_encoding.dart (from mobile/)
// Verifies: exits nonzero if any mojibake marker remains.

import 'dart:io';
import 'dart:convert';

/// Byte-pair repairs: misdecoded text -> correct text. Ordered longest
/// first so multi-byte sequences match before their prefixes.
const _repairs = <String, String>{
  'â€”': '—', // em dash
  'â€“': '–', // en dash
  'â€˜': '‘', // left single quote
  'â€™': '’', // right single quote
  'â€œ': '“', // left double quote
  'â€\x9d': '”', // right double quote
  'â€¦': '…', // ellipsis
  'â€': '”', // leftover fragment (checked last among â€* forms)
  'Ã©': 'é',
  'Ã¨': 'è',
  'Ã±': 'ñ',
};

void main() {
  final lib = Directory('lib');
  if (!lib.existsSync()) {
    stderr.writeln('run from mobile/');
    exit(2);
  }
  var fixed = 0;
  var bomStripped = 0;
  final files = lib
      .listSync(recursive: true)
      .whereType<File>()
      .where((f) => f.path.endsWith('.dart'))
      .toList()
    ..sort((a, b) => a.path.compareTo(b.path));

  for (final file in files) {
    var bytes = file.readAsBytesSync();
    // Strip UTF-8 BOM if present.
    if (bytes.length >= 3 &&
        bytes[0] == 0xEF &&
        bytes[1] == 0xBB &&
        bytes[2] == 0xBF) {
      bytes = bytes.sublist(3);
      bomStripped++;
    }
    var text = utf8.decode(bytes, allowMalformed: true);
    var out = text;
    for (final entry in _repairs.entries) {
      out = out.replaceAll(entry.key, entry.value);
    }
    if (out != text) {
      fixed++;
      print('fixed ${file.path}');
    }
    file.writeAsBytesSync(utf8.encode(out), flush: true);
  }

  // Verify: zero mojibake markers remain.
  var hits = 0;
  for (final file in files) {
    final text = file.readAsStringSync(encoding: utf8);
    if (text.contains('â€') || text.contains('Ã©')) {
      print('REMAINS: ${file.path}');
      hits++;
    }
  }
  print('files fixed: $fixed, BOMs stripped: $bomStripped, remains: $hits');
  if (hits > 0) exit(1);
}
