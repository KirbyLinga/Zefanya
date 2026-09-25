Zefanya UI Design Guide

Purpose

This file is the UI source of truth for Zefanya.

Any coding agent working on the UI must follow this guide.

1. Styling Rules

Use plain CSS only.

Do not use Tailwind CSS.

Use CSS custom properties from resources/css/design-system.css.

Load design-system.css before module-specific CSS.

Keep page-specific styles in the appropriate module stylesheet.

Example:

@vite([
    'resources/css/design-system.css',
    'resources/css/seller/seller-app.css',
    'resources/css/seller/seller-products.css',
])

2. Fonts

Headings

Font: Playfair Display

Fallback: Georgia, serif

Weight: 400

Body/UI

Font: Montserrat

Fallback: Helvetica, sans-serif

Weights: 400, 500, 600, 800

Google Fonts:

<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;800&family=Playfair+Display:wght@400&display=swap" rel="stylesheet">

3. Main Colors

Use the existing variables instead of hard-coding colors.

Primary Rose

--color-primary-50: #FDF5F7;
--color-primary-100: #FBEAED;
--color-primary-200: #F5D3DA;
--color-primary-300: #E2B4BD;
--color-primary-400: #D4949F;
--color-primary-500: #C07A85;
--color-primary-600: #A65F6B;
--color-primary-700: #8B4A55;
--color-primary-800: #79545C;
--color-primary-900: #5F4047;
--color-primary-950: #3A262B;

Secondary Coral

--color-secondary-100: #FDF0EE;
--color-secondary-200: #FBE0DC;
--color-secondary-300: #F7D6D0;
--color-secondary-500: #E8A59B;
--color-secondary-700: #C47065;
--color-secondary-800: #A85A50;
--color-secondary-950: #4A2521;

Tertiary Sage

--color-tertiary-200: #D1E1D1;
--color-tertiary-500: #6B916B;
--color-tertiary-600: #547654;

Neutral

--color-neutral-50: #FAF9F9;
--color-neutral-100: #F3F2F2;
--color-neutral-200: #E8E6E6;
--color-neutral-300: #D4D1D1;
--color-neutral-400: #A9A4A4;
--color-neutral-500: #7C7676;
--color-neutral-600: #635E5E;
--color-neutral-700: #504B4B;
--color-neutral-800: #3D3939;
--color-neutral-900: #2A2727;
--color-neutral-950: #1E1B1B;

Semantic Colors

--color-success: #6B916B;
--color-warning: #D4A017;
--color-error: #C44A4A;
--color-info: #C07A85;

Backgrounds

--bg-page: #FDF5F7;
--bg-surface: #FFFFFF;
--bg-sidebar: #1E1B1B;
--bg-sidebar-hover: rgba(255,248,247,0.08);
--bg-sidebar-active: rgba(226,180,189,0.2);

Text

--text-primary: #1E1B1B;
--text-secondary: #635E5E;
--text-muted: #7C7676;
--text-inverse: #FFF8F7;

Borders

--border-light: #E8E6E6;
--border-default: #D4D1D1;
--border-focus: #D4949F;

4. Spacing

Use the spacing variables.

--space-1: 4px;
--space-2: 8px;
--space-3: 12px;
--space-4: 16px;
--space-5: 20px;
--space-6: 24px;
--space-8: 32px;
--space-10: 40px;
--space-12: 48px;
--space-16: 64px;

5. Border Radius

--radius-sm: 4px;
--radius-md: 6px;
--radius-lg: 8px;
--radius-xl: 12px;
--radius-full: 9999px;

Use --radius-full for pills, tags, and circular UI.

6. Shadows

--shadow-sm: 0 1px 3px rgba(30,27,27,0.08);
--shadow-md: 0 4px 12px rgba(30,27,27,0.10);
--shadow-lg: 0 12px 32px rgba(30,27,27,0.15);

7. Typography

Page titles: 24–32px, Montserrat, 600–800

Section headings: 20–24px, Montserrat, 600

Product/card names: 14–16px, Montserrat, 600

Body: 13–14px, Montserrat, 400

Secondary text: 12.5–13.5px, Montserrat, 400

Muted text: 12px, Montserrat, 400

Form labels: 11px, Montserrat, 600, uppercase

Status badges: 10–11.5px, Montserrat, 600, uppercase

Buttons: 12.5–13.5px, Montserrat, 600

Tags: 11.5px, Montserrat, 600

Modal titles: 26px, Playfair Display, 400

8. Common Components

Inputs

Height: 44–48px

Horizontal padding: 16px

Border: 1px solid var(--border-default)

Focus: var(--border-focus)

Use --radius-md or --radius-lg

Buttons

Height: about 40–42px

Font: Montserrat

Weight: 600

Letter spacing: about 0.8–1.4px

Primary seller button:

background-color: var(--color-primary-950);
color: var(--text-inverse);

Hover:

background-color: var(--color-primary-800);

Tags

Height: about 28px

Font: 11.5px

Weight: 600

Use --radius-full

Status Badges

Use pill-shaped badges.

/* Active */
background: var(--color-tertiary-500);
color: #fff;

/* Draft */
background: var(--color-neutral-200);
color: var(--color-neutral-900);

/* Inactive/Error */
background: #FBE9E7;
color: #A5333D;

9. Seller UI

Seller panel uses:

Dark sidebar: --bg-sidebar

Active navigation: --bg-sidebar-active

Sidebar hover: --bg-sidebar-hover

Main page background: --bg-page

Cards/surfaces: --bg-surface

Primary text: --text-primary

Muted text: --text-muted

Seller buttons:

Primary: dark #1E1B1B

Outline: #79545C

Danger: #A5333D

Seller UI should feel clean, elegant, minimal, and consistent with the Zefanya rose/sage theme.

10. Existing CSS Files

resources/css/
├── design-system.css
├── auth.css
├── seller/
│   ├── seller-app.css
│   ├── seller-products.css
│   └── ...
└── buyer/
    └── buyer-register-modal.css

File responsibilities

design-system.css → global variables, reset, shared components

seller/seller-app.css → seller layout, sidebar, navigation, logout

seller/seller-products.css → seller product pages

buyer/buyer-register-modal.css → shared registration modal styles

auth.css → authentication pages

11. Rules for Coding Agents

When creating or modifying Zefanya UI:

Check resources/css/design-system.css first.

Reuse existing CSS variables.

Reuse existing components when possible.

Do not introduce Tailwind.

Do not create a new color when an existing token fits.

Do not randomly change the existing design.

Keep seller styles inside resources/css/seller/.

Keep buyer styles inside resources/css/buyer/.

Make new pages responsive.

Match existing spacing, typography, borders, shadows, and buttons.

Check existing CSS before creating duplicate styles.

12. Current UI Status

Complete

Design system

Seller panel shell

Seller sidebar/navigation

Seller product surface

Still Needs UI Work

Seller dashboard

Inventory

Orders

Shipments

Reports

Chat/Messaging

Account management

Other seller pages

Known Cleanup

Replace hard-coded error colors with proper --color-error variables.

Golden Rule

When in doubt, inspect the existing CSS and copy the existing design patterns instead of inventing a new style.