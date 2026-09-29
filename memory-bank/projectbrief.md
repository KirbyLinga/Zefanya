# Project Brief — Zefanya

A two-role marketplace app for Philippine artisan/boutique goods. Built in
Flutter (`mobile/`), targeting Android/iOS.

There is a `web/` directory in the repo; it has not been read in this session.
Do not assume its contents.

## Core requirements

- **Buyers** browse a curated catalogue, add to cart, check out, track
  delivery, and review what arrived.
- **Couriers** take delivery jobs through a milestone pipeline (assign →
  pick up → transit → deliver / return) and are paid per completed job.
- **One marketplace order = one seller.** A cart spanning sellers splits into
  one order per seller at checkout, each with its own delivery job.
- **Both roles share one app** — role routing happens at the root on the
  session's `UserRole`.

## What makes this app specific

The design intent is *editorial*: asymmetric shapes, oversized display
numerals, torn-ticket/perforation motifs, and a strong "hand-made goods"
voice. All of it is expressed through a token layer (see
`systemPatterns.md`), not ad-hoc per screen.

## Non-goals

- No recommendation engine. Personalization goes as far as joins over data
  that already exists (see `Order.productIds`).
- No invented content. Where real data doesn't exist yet, the app either
  states the gap or omits the surface — it does not fabricate. This is a
  deliberate, enforced rule; see `progress.md`.