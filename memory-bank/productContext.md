# Product Context — Zefanya

## Why it exists

A marketplace for small-scale makers (ceramics, linen, rattan, textiles)
where the *seller's hand* is the product story, not price or speed. The app
is therefore designed to feel like a shop catalogue that someone curates,
rather than a grid of SKUs.

## The two people the app serves, and how they differ

**The buyer** visits occasionally, browses slowly, and buys once. Their
moments of truth are: does this look real, and did my order arrive.

**The courier** uses the app standing up, in daylight, holding a parcel,
20–40 times a shift. This asymmetry drives several decisions:

- The courier's completion screen **confirms instantly and celebrates
  rarely** ("confirm the many, celebrate the few"); the buyer's checkout
  gets a full flourish because purchase happens once.
- High-contrast targets and large tap areas matter more on the courier side.
- Never make the courier read prose to finish a job.

## Experience principles (all enforced somewhere in code)

1. **Every action does what its label says.** Originally violated by a
   "Track Order" button that only unwound the navigation stack, and two
   "Leave a Review" buttons wired to `() {}`. Both fixed; see `progress.md`.
2. **State a fact, not a vibe.** Earnings confirmations state the fee *and*
   the day's total so the claim can be checked in place.
3. **Two outcomes get two treatments.** A returned parcel never gets success
   copy or confetti.
4. **No fetched or invented content in place of the real thing.** Product
   photos are explicitly-labelled stock placeholders; reviewer names and
   maker notes that were hardcoded have been removed rather than rewritten.
5. **Colour is never the only signal** — ratings are also stated in words;
   status has a label as well as a hue.