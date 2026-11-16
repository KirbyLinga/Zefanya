// mobile/tool/generate_ph_psgc.js
//
// Builds the app's offline Philippine address catalogue from the MIT-licensed
// @aivangogh/ph-address package (PSGC as of 30 June 2026).
//
// The package embeds its CSV tables as gzip+base64 strings inside dist/index.js.
// This script inflates them, resolves the PSGC quirks the app's cascade has to
// hide, and writes:
//
//   assets/data/ph_psgc.json          the hierarchy + postal codes
//   lib/data/ph_province_regions.dart a synchronous province -> region map for
//                                     seeding an address book before the JSON
//                                     asset has finished loading
//
// Usage:
//   npm pack @aivangogh/ph-address@2026.2.4
//   tar -xzf aivangogh-ph-address-2026.2.4.tgz
//   set PH_ADDRESS_INDEX=package\dist\index.js
//   node tool/generate_ph_psgc.js
//
// or pass the index.js path as the first argument.

const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const indexPath = process.env.PH_ADDRESS_INDEX || process.argv[2];
if (!indexPath) {
  console.error(
    'Set PH_ADDRESS_INDEX to the extracted package dist/index.js, or pass it as argv[2].',
  );
  process.exit(1);
}

const source = fs.readFileSync(indexPath, 'utf8');

/** Parse one RFC-4180-ish CSV line (the package's tables quote commas). */
function parseCsvLine(line) {
  const fields = [];
  let value = '';
  let quoted = false;
  for (let i = 0; i < line.length; i += 1) {
    const char = line[i];
    if (quoted) {
      if (char === '"') {
        if (line[i + 1] === '"') {
          value += '"';
          i += 1;
        } else {
          quoted = false;
        }
      } else {
        value += char;
      }
    } else if (char === '"') {
      quoted = true;
    } else if (char === ',') {
      fields.push(value);
      value = '';
    } else {
      value += char;
    }
  }
  fields.push(value);
  return fields;
}

function readTable(name) {
  const match = source.match(new RegExp(`var ${name} = "([^"]+)"`));
  if (!match) throw new Error(`Could not find the ${name} table in ${indexPath}`);
  const csv = zlib.inflateSync(Buffer.from(match[1], 'base64')).toString('utf8');
  return csv
    .split(/\r?\n/)
    .slice(1)
    .filter((line) => line.trim().length > 0)
    .map(parseCsvLine);
}

const regionRows = readTable('regions');
const provinceRows = readTable('provinces');
const municipalityRows = readTable('municipalities');
const barangayRows = readTable('barangays');
const postalRows = readTable('postalCodes');

// The app keeps the labels the rest of the UI already uses. The package's
// `designation` column is the short form (NCR, CAR, BARMM, ...), so the long
// label is assembled here rather than shipped as a second table.
const REGION_DISPLAY = {
  '0100000000': 'Ilocos Region (Region I)',
  '0200000000': 'Cagayan Valley (Region II)',
  '0300000000': 'Central Luzon (Region III)',
  '0400000000': 'CALABARZON (Region IV-A)',
  '0500000000': 'Bicol Region (Region V)',
  '0600000000': 'Western Visayas (Region VI)',
  '0700000000': 'Central Visayas (Region VII)',
  '0800000000': 'Eastern Visayas (Region VIII)',
  '0900000000': 'Zamboanga Peninsula (Region IX)',
  '1000000000': 'Northern Mindanao (Region X)',
  '1100000000': 'Davao Region (Region XI)',
  '1200000000': 'SOCCSKSARGEN (Region XII)',
  '1300000000': 'National Capital Region (NCR)',
  '1400000000': 'Cordillera Administrative Region (CAR)',
  '1600000000': 'Caraga (Region XIII)',
  '1700000000': 'MIMAROPA Region',
  '1800000000': 'Negros Island Region (NIR)',
  '1900000000': 'Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)',
};

const NCR_CODE = '1300000000';
const MANILA_CITY_CODE = '1380600000';
const ISABELA_CITY_CODE = '0990101000';
const ISABELA_PARENT_CODE = '0990100000';
const BASILAN_PROVINCE_CODE = '1900700000';
const SGA_PARENT_CODE = '1999900000';
const SGA_PROVINCE_CODE = '1999900000';

const regions = regionRows.map(([officialName, code, designation]) => ({
  code,
  officialName,
  shortName: designation || '',
  name: REGION_DISPLAY[code] || officialName,
}));

const provinces = provinceRows.map(([name, code]) => ({
  code,
  name,
  regionCode: `${code.slice(0, 2)}00000000`,
}));

const municipalities = municipalityRows.map(([name, code, parentCode]) => ({
  code,
  name,
  parentCode,
}));

const barangays = barangayRows.map(([name, code]) => ({ code, name }));

const postal = postalRows.map(
  ([postalCode, placeName, provinceName, regionName, municipalityCode]) => ({
    postalCode,
    placeName,
    provinceName,
    regionName,
    municipalityCode,
  }),
);

const municipalityByCode = new Map(
  municipalities.map((municipality) => [municipality.code, municipality]),
);
const municipalityByPrefix = new Map();
for (const municipality of municipalities) {
  municipalityByPrefix.set(municipality.code.slice(0, 7), municipality);
}

// Manila's 14 districts are sub-municipalities in the PSGC. The app exposes
// Manila as one city, so their barangays are folded into Manila City and the
// district rows themselves never reach the picker.
const manilaDistrictCodes = new Set(
  municipalities
    .filter((municipality) => municipality.parentCode === MANILA_CITY_CODE)
    .map((municipality) => municipality.code),
);

const postalByMunicipality = new Map();
for (const row of postal) {
  if (!row.municipalityCode) continue;
  const list = postalByMunicipality.get(row.municipalityCode) || [];
  list.push(row);
  postalByMunicipality.set(row.municipalityCode, list);
}

const barangaysByCityCode = new Map();
const orphanBarangays = [];
for (const barangay of barangays) {
  const owner = municipalityByPrefix.get(barangay.code.slice(0, 7));
  if (!owner) {
    orphanBarangays.push(barangay);
    continue;
  }
  const cityCode = manilaDistrictCodes.has(owner.code)
    ? MANILA_CITY_CODE
    : owner.code;
  const list = barangaysByCityCode.get(cityCode) || [];
  list.push(barangay);
  barangaysByCityCode.set(cityCode, list);
}
if (orphanBarangays.length > 0) {
  console.warn(
    `Warning: ${orphanBarangays.length} barangay row(s) had no parent municipality.`,
  );
}

function normalize(value) {
  return (value || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();
}

function choosePostalCode(city, provinceName, isNcr) {
  const rows = postalByMunicipality.get(city.code) || [];
  if (rows.length === 0) return '';

  // Prefer rows that know their province/region: the table carries a handful of
  // old duplicate codes with blank context (e.g. Alcoy 2566 vs 6023).
  const withContext = rows.filter(
    (row) =>
      (row.provinceName || '').trim().length > 0 ||
      (row.regionName || '').trim().length > 0,
  );
  const pool = withContext.length > 0 ? withContext : rows;

  const cityName = normalize(city.name).replace(/\bcity\b/g, '').trim();
  const matches = pool.filter((row) => {
    const place = normalize(row.placeName);
    return (
      place === cityName ||
      place.includes(cityName) ||
      cityName.includes(place)
    );
  });
  const finalPool = matches.length > 0 ? matches : pool;

  // For places with a CPO plus district codes, the CPO (the lowest code) is the
  // sensible default; the field stays editable for the buyer's own barangay.
  return finalPool
    .map((row) => row.postalCode)
    .filter(Boolean)
    .sort((a, b) => a.localeCompare(b, undefined, { numeric: true }))[0] || '';
}

function buildCity(municipality, provinceName, isNcr) {
  const cityBarangays = (barangaysByCityCode.get(municipality.code) || [])
    .slice()
    .sort((a, b) => a.name.localeCompare(b.name));
  return {
    code: municipality.code,
    name: municipality.name,
    postalCode: choosePostalCode(municipality, provinceName, isNcr),
    barangays: cityBarangays.map((barangay) => [barangay.code, barangay.name]),
  };
}

const regionByCode = new Map(regions.map((region) => [region.code, region]));
for (const region of regions) {
  region.provinces = [];
  region.cities = [];
}

const provinceByCode = new Map(
  provinces.map((province) => [province.code, province]),
);
for (const province of provinces) {
  province.cities = [];
  const region = regionByCode.get(province.regionCode);
  if (!region) {
    throw new Error(`Province ${province.name} has no region ${province.regionCode}`);
  }
  region.provinces.push(province);
}

const municipalitiesByParent = new Map();
for (const municipality of municipalities) {
  const list = municipalitiesByParent.get(municipality.parentCode) || [];
  list.push(municipality);
  municipalitiesByParent.set(municipality.parentCode, list);
}

// Normal province -> city groups. Manila's districts and the two PSGC orphan
// parents are handled explicitly below.
const specialParents = new Set([
  MANILA_CITY_CODE,
  ISABELA_PARENT_CODE,
  SGA_PARENT_CODE,
]);
for (const province of provinces) {
  for (const municipality of municipalitiesByParent.get(province.code) || []) {
    if (specialParents.has(municipality.parentCode)) continue;
    if (manilaDistrictCodes.has(municipality.code)) continue;
    province.cities.push(buildCity(municipality, province.name, false));
  }
}

// NCR is province-less: the 16 cities and Pateros hang directly off the region.
for (const municipality of municipalitiesByParent.get(NCR_CODE) || []) {
  const region = regionByCode.get(NCR_CODE);
  region.cities.push(buildCity(municipality, 'Metro Manila', true));
}

// Isabela City is a PSGC orphan (parent 0990100000). The postal table files it
// under Basilan, which is how the app groups it for a mailing address.
const isabelaCity = municipalityByCode.get(ISABELA_CITY_CODE);
const basilan = provinceByCode.get(BASILAN_PROVINCE_CODE);
if (isabelaCity && basilan) {
  basilan.cities.push(buildCity(isabelaCity, basilan.name, false));
}

// The eight BARMM Special Geographic Area municipalities share parent
// 1999900000. They are a synthetic province under BARMM for the picker; the
// postal table still calls them Cotabato/Soccsksargen, which is not the
// administrative region the app should show.
const sgaProvince = {
  code: SGA_PROVINCE_CODE,
  name: 'Special Geographic Area',
  regionCode: '1900000000',
  cities: [],
};
const barmm = regionByCode.get('1900000000');
if (barmm) barmm.provinces.push(sgaProvince);
for (const municipality of municipalitiesByParent.get(SGA_PARENT_CODE) || []) {
  sgaProvince.cities.push(buildCity(municipality, sgaProvince.name, false));
}

function sortCities(list) {
  list.sort((a, b) => a.name.localeCompare(b.name));
}

for (const region of regions) {
  sortCities(region.cities);
  region.provinces.sort((a, b) => a.name.localeCompare(b.name));
  for (const province of region.provinces) sortCities(province.cities);
}
regions.sort((a, b) =>
  a.code.localeCompare(b.code, undefined, { numeric: true }),
);

const output = {
  version: 'PSGC 2026-06-30',
  source:
    '@aivangogh/ph-address 2026.2.4 (MIT); Philippine Statistics Authority PSGC as of 30 June 2026',
  regions: regions.map((region) => ({
    code: region.code,
    name: region.name,
    shortName: region.shortName,
    officialName: region.officialName,
    provinces: region.provinces.map((province) => ({
      code: province.code,
      name: province.name,
      cities: province.cities,
    })),
    cities: region.cities,
  })),
};

const appRoot = path.resolve(__dirname, '..');
const assetPath = path.join(appRoot, 'assets', 'data', 'ph_psgc.json');
fs.mkdirSync(path.dirname(assetPath), { recursive: true });
fs.writeFileSync(assetPath, JSON.stringify(output), 'utf8');

const provinceRegionEntries = [];
for (const region of regions) {
  for (const province of region.provinces) {
    provinceRegionEntries.push([province.name, region.name]);
  }
}
provinceRegionEntries.sort((a, b) => a[0].localeCompare(b[0]));

const dartLines = [
  '// lib/data/ph_province_regions.dart',
  '//',
  '// GENERATED by tool/generate_ph_psgc.js from the same PSGC snapshot as',
  '// assets/data/ph_psgc.json. Kept as a small Dart map because seeding an',
  '// address book happens synchronously, before the JSON asset has necessarily',
  '// finished loading.',
  '',
  'const String kMetroManila = \'Metro Manila\';',
  '',
  'const Map<String, String> phProvinceRegionNames = {',
  ...provinceRegionEntries.map(
    ([province, region]) =>
      `  '${province.replace(/'/g, "\\'")}': '${region.replace(/'/g, "\\'")}',`,
  ),
  '};',
  '',
];
const dartPath = path.join(appRoot, 'lib', 'data', 'ph_province_regions.dart');
fs.writeFileSync(dartPath, dartLines.join('\n'), 'utf8');

const cityCount = regions.reduce(
  (total, region) =>
    total +
    region.cities.length +
    region.provinces.reduce((sum, province) => sum + province.cities.length, 0),
  0,
);
const barangayCount = regions.reduce(
  (total, region) =>
    total +
    region.cities.reduce((sum, city) => sum + city.barangays.length, 0) +
    region.provinces.reduce(
      (sum, province) =>
        sum +
        province.cities.reduce(
          (citySum, city) => citySum + city.barangays.length,
          0,
        ),
      0,
    ),
  0,
);
const bytes = fs.statSync(assetPath).size;
console.log(
  `Wrote ${path.relative(appRoot, assetPath)} (${(bytes / 1024 / 1024).toFixed(2)} MB): ` +
    `${regions.length} regions, ${provinces.length + 1} provinces, ${cityCount} cities/municipalities, ${barangayCount} barangays.`,
);
console.log(`Wrote ${path.relative(appRoot, dartPath)}.`);
