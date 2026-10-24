// lib/data/ph_regions.dart
//
// A small Region → City → Barangay fixture for the address book.
//
// The rest of the app still uses phAddressData (province → municipality →
// barangay) for registration and Edit Profile. The address book needs the
// level above that: the workbook's cascade starts at Region, and the
// unserviceable-area test is stated in terms of NCR versus Visayas. Keeping a
// separate map avoids changing the registration form's existing hierarchy while
// still giving the new form real, linked options.
//
// Placeholder data, like phAddressData: replace both with a PSGC lookup when the
// API for it exists. The structure, not the number of rows, is what the screen
// and the tests depend on.

const Map<String, Map<String, List<String>>> phRegionData = {
  'National Capital Region (NCR)': {
    'Quezon City': [
      'Batasan Hills',
      'Commonwealth',
      'Holy Spirit',
      'Poblacion',
    ],
    'Manila': ['Binondo', 'Ermita', 'Malate', 'Sampaloc'],
    'Makati City': ['Bel-Air', 'Poblacion', 'San Lorenzo'],
    'Pasig City': ['Kapitolyo', 'San Antonio', 'Ugong'],
  },
  'CALABARZON (Region IV-A)': {
    'Pila': ['San Antonio', 'Santa Clara Norte', 'Poblacion'],
    'Santa Cruz': ['Bubukal', 'Gatid', 'Poblacion I'],
    'Victoria': ['Bagumbayan', 'Poblacion', 'Tagumpay'],
    'Lipa City': ['Antipolo del Norte', 'Bulacnin', 'Marauoy'],
    'Batangas City': ['Alangilan', 'Kumintang Ilaya', 'Poblacion'],
  },
  'Central Visayas (Region VII)': {
    'Cebu City': ['Lahug', 'Mabolo', 'Talamban'],
    'Mandaue City': ['Bakilid', 'Centro', 'Tipolo'],
    'Lapu-Lapu City': ['Pajo', 'Poblacion', 'Punta Engano'],
  },
};

/// The region label a legacy profile address should be filed under.
///
/// Registration stores a province, not a region. Seeding the book from an
/// existing profile therefore has to translate that province once. Unknown
/// provinces are kept as-is rather than guessed into a region: a wrong label is
/// worse than an ungrouped one, and the buyer can correct it on the form.
String regionForProvince(String? province) {
  final value = (province ?? '').trim();
  if (value.isEmpty) return '';
  switch (value) {
    case 'Metro Manila':
    case 'NCR':
      return 'National Capital Region (NCR)';
    case 'Laguna':
    case 'Batangas':
    case 'Cavite':
    case 'Rizal':
    case 'Quezon':
      return 'CALABARZON (Region IV-A)';
    case 'Cebu':
    case 'Bohol':
    case 'Negros Oriental':
    case 'Siquijor':
      return 'Central Visayas (Region VII)';
  }
  return value;
}
