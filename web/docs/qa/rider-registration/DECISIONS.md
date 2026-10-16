# Decisions (spec ambiguities — conservative readings)

- D1 license lowercase: reject; format is `^[A-Z]\d{2}-\d{2}-\d{6}$` exactly as printed. Rationale: spec shows uppercase; normalizing silently could mask fraud.
- D2 plate/license case: store uppercase (trim + strtoupper), compare case-insensitive. Rationale: plates are read aloud; `abc123` == `ABC123`.
- D3 email: trim + lowercase; unique across buyers/sellers/providers/riders (case-insensitive). Matches A3 + buyer precedent.
- D4 mobile: strip spaces/hyphens/parens, `+63`→`09`; store `09…`; unique across all accounts. Matches A4 + buyer `normalizePhoneNumber`.
- D5 uniqueness scope: GLOBAL for email/mobile/license/plate (spec A3/A4/A14 say "across ALL"), overriding the unimplemented contract's per-provider plate idea.
- D6 vehicle_type: exactly `Motorcycle|Car|Van` (spec dropdown); map legacy `bike` couriers elsewhere, not here.
- D7 uploads: accept `.jpg/.jpeg/.png/.pdf` (jpeg == jpg); 5,242,880 bytes OK, 5,242,881 reject; check extension + MIME + magic bytes.
- D8 timezone: all age/expiry math in `Asia/Manila`, never app UTC.
- D9 rider vs courier: NEW `riders` login table (not couriers extension) — activation password + tokens + guard need a real Authenticatable; couriers stay fleet rows.
- D10 resend: new token invalidates previous (spec TC-024 second-link-wins).
