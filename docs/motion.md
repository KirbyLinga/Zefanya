# Zefanya Motion Language: "Easing Into Frame"

The goal of motion in Zefanya is a single feeling: **calm, warm, a little luxurious, as if the app is quietly pleased to see you.** Components ease into frame, screens hand off through soft clouds, and transitions never compete with user intent.

---

## 1. Core Ground Rules (Apply Everywhere)

1. **Transforms and Opacity Only**
   - Animate `Transform.translate`, `Transform.scale`, and `Opacity` exclusively.
   - **Never** animate layout geometry (width, height, flex) or `BackdropFilter` / image blurs.

2. **Concurrency and Restraint**
   - At most **2 concurrent animations** on screen at any moment.
   - Entrances: **8dp vertical rise**, **40ms stagger step**, **280–420ms duration** (standard: 320ms), with `Curves.easeOutCubic`.

3. **Once-Per-Lifetime Entrances**
   - Entrances play **once per screen lifetime**.
   - Returning to a tab or popping back to a parent screen is **instant**. Never replay an entrance during routine navigation.

4. **Reduced Motion (`reduceMotion(context)`)**
   - Always respects `MediaQuery.disableAnimations`.
   - Reduced motion means: **show the end state immediately**. No timers, no infinite loops, no delays.

5. **Touch-First**
   - No mouse hover states. Rely on active press, drag, and scroll physics.

---

## 2. The Primitives (`lib/widgets/motion/`)

### `CloudTransition`
- **What it is:** The signature painterly screen hand-off. Overlapping feathered radial gradient clouds (blush, sage, rose, cream) veil the screen at ~43% duration (300ms), execute `swap()`, and disperse upward/outward (~700ms total).
- **When to use:**
  - Splash → Landing
  - Landing → Login / Register
  - Login / OTP Verification → Buyer or Courier Shell
  - Logout → Landing / Login
  - Role switcher context changes
- **When NOT to use:**
  - Bottom-bar tab switches (too heavy; use `FadeThrough`).
  - Pushing product detail screens (keep standard Hero & slide-fade).
  - Dialogs, bottom sheets, or frequent user actions.
- **Reduced motion:** Replaced with a crisp 120ms cross-fade veil.
- **Safety net:** Hard 1.2s timeout unconditionally completes the transition, preventing trapped interactions.

### `FadeThrough`
- **What it is:** A rapid (<=200ms, standard 180ms) fade-through transition (`FadeThroughIndexedStack` / `FadeThroughSwitch`).
- **When to use:**
  - Tab switches in `BuyerShell` and `CourierShell`.
  - In-screen state toggles (e.g., search suggestions ↔ search results).
- **Reduced motion:** 0ms instantaneous switch.

### `EaseInFrame`
- **What it is:** Standardized component entrance wrapper over reading order items.
- **When to use:**
  - Screen headers, mastheads, and primary cards.
  - Lists and product grids: stagger first 4–6 items in the initial viewport; subsequent items reveal cleanly.
- **Reduced motion:** Renders settled state with 0 timers.

---

## 3. Decision Matrix

| Context | Transition Primitive | Duration | Curve |
| :--- | :--- | :--- | :--- |
| **Auth & Shell Hand-off** | `CloudTransition.run(...)` | 700ms (swap at 300ms) | `Curves.easeOutCubic` |
| **Tab Switch (Shells)** | `FadeThroughIndexedStack` | 180ms | `Curves.easeOutCubic` |
| **Component In-flow** | `EaseInFrame` | 320ms (40ms stagger, 8dp) | `Curves.easeOutCubic` |
| **Pushed Detail Screens** | `AppPageRoute` / Hero | 300ms | Platform curve |
| **Live Value Updates** | `RollingCounter` / Soft Highlight | 250ms | `Curves.easeOutCubic` |
