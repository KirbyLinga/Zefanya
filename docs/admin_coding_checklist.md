# Admin Coding Checklist

> **System context (for AI):** Zefanya is a Laravel 11 / PHP 8.x marketplace. The admin panel (`/admin/*`, `auth:admin` guard, `admins` table) manages buyer + seller + logistics-provider registrations via a unified approval queue, and is the only surface with access to platform-wide user data. All admin routes are grouped under the `admin.` name prefix (defined in `routes/admin.php`, loaded by `bootstrap/app.php`). The `EnsureAdminRole` middleware (`alias: admin.role`) exists but is applied to zero routes. The dashboard has two real stat queries (active users and pending registrations across all roles) and two `—` placeholders (disputes, commission) because those modules don't exist yet. The registration queue (`Admin\RegistrationController`) handles buyers and sellers via `?view=&type=` params, and logistics providers via the same controller. Three duplicate/ghost model files (`app/Models/Seller/Product.php`, `app/Models/Seller/ProductImage.php`) exist on disk but declare `namespace App\Models` — they are dead and can never be autoloaded. All tests pass (127 passing as of 2026-09-22).

> **Audit legend:** `[x]` = working (code + route + backing data verified) · `[ ]` = not implemented. *Italics* flag known defects, shells, or dependencies.

---

## Foundation / Authentication

- [x] Admin login *(`GET|POST /admin/login`, `Admin\AuthController`, `guest:admin` protected)*
- [x] Email validation
- [x] Password validation
- [x] Admin authentication *(`admin` guard + `admins` table with `role` string column)*
- [x] Admin route protection *(`auth:admin` middleware group covers all authenticated admin routes)*
- [x] Admin logout *(session invalidation + token regenerate)*
- [x] Unauthorized access handling *(`guest:admin` redirects to `/admin/login`; guards never shared)*

---

## Admin Dashboard

- [x] Admin dashboard page *(`GET /admin/dashboard` → `Admin\DashboardController@index` → `Admin/dashboard`)*
- [x] Active users stat *(real query: `approved` buyers + sellers + logistics providers, controller-computed)*
- [x] Pending registrations stat *(real query: `pending_approval` across buyers + sellers + logistics providers)*
- [ ] Total buyers / sellers / logistics breakdown *(single combined count only, no per-role breakdown)*
- [ ] Total orders
- [ ] Total products
- [ ] Open disputes *(hardcoded `—` placeholder — disputes module not built)*
- [ ] Commission this month *(hardcoded `—` placeholder — commission module not built)*
- [ ] Dashboard statistics (full)
- [ ] Recent activities
- [ ] Dashboard notifications

---

## Manage Account Registrations

> Buyer + seller applications live in the unified `Admin\RegistrationController`. Logistics applications are approved via the same controller (`approveLogistics` / `rejectLogistics`). Logistics registration backend is fully built (OTP, store, pending page) as of 2026-09-21; the UI entry card on the register-type page is **disabled ("Coming soon")** — the backend routes are live but the front-end trigger is intentionally hidden until the logistics panel ships.

- [x] Registration management page *(`GET /admin/registrations`; 404 guard if tables missing)*
- [x] Buyer applications *(`pending_approval` queue)*
- [x] Seller applications *(`pending_approval` queue, unified with buyers)*
- [x] Logistics provider applications *(`approveLogistics`/`rejectLogistics` routes exist and are tested)*
- [ ] Sorting center / courier applications *(not started — courier lifecycle belongs to Logistics, not Admin)*
- [ ] Search applications
- [ ] Filter applications
- [ ] Sort applications
- [x] View application details *(`?view={id}&type={buyer|seller|logistics}` selection panel)*
- [x] Review submitted information *(personal info, address, address_detail, status shown)*
- [ ] View uploaded ID / business permit in detail panel *(links present but not deeply audited)*
- [x] Approve registration *(sets `approved_by`/`approved_at`, clears rejection reason; sends notification)*
- [x] Disapprove / reject registration *(sets `rejected` status + `rejection_reason`)*
- [x] Registration rejection reason *(required, max 500)*
- [x] Applicant approval notification *(`BuyerRegistrationDecision` / `SellerRegistrationDecision` / `LogisticsRegistrationDecision`)*
- [x] Applicant rejection notification *(includes reason)*
- [x] Email notification *(via Laravel Notifications)*
- [x] Registration status tracking *(`status` + `approved_by` + `approved_at` persisted)*

---

## Manage User Accounts

- [ ] User management page
- [ ] View all users
- [ ] View buyer accounts
- [ ] View seller accounts
- [ ] View logistics accounts
- [ ] Search / filter / sort users
- [ ] View user profile
- [ ] Activate / suspend / deactivate account
- [ ] Suspension / deactivation reason
- [ ] Account status notification
- [ ] User activity / history

---

## Seller Compliance Monitoring

- [ ] Seller compliance page
- [ ] View / verify seller products
- [ ] Identify prohibited / inappropriate products
- [ ] Issue seller warning / suspension
- [ ] Seller violation history
- [ ] Notify seller of violation / suspension

---

## Complaints & Disputes

- [ ] Complaints and disputes page
- [ ] View / assign / resolve complaints
- [ ] Communicate with buyer / seller / courier
- [ ] Complaint status / history
- [ ] Notify involved users of decision

---

## Commission Management

- [ ] Commission management page
- [ ] 10% platform commission configuration
- [ ] Calculate commission per order / total
- [ ] Commission history / report

---

## Reports

- [ ] Reports page
- [ ] Sales summary / commission report
- [ ] Date range filter
- [ ] Export sales / commission report

---

## Platform Settings

- [ ] Platform settings page
- [ ] Post / edit / delete announcements
- [ ] Update platform policies
- [ ] Platform commission setting

---

## Chat / Messaging

- [ ] Admin chat page
- [ ] Buyer / seller / logistics / courier conversations
- [ ] Send / receive messages
- [ ] Read/unread status / history

---

## Account Management

- [ ] Admin account page
- [ ] Edit personal information / contact / email
- [ ] Change password / profile picture
- [ ] Security settings

---

## Security & Authorization

- [x] Admin-only route protection *(`auth:admin`; login behind `guest:admin`)*
- [x] Role column exists *(`admins.role` string, default `'admin'`; values: `admin`, `super_admin`, `moderator`, `support`)*
- [x] `EnsureAdminRole` middleware exists and is aliased as `admin.role` *(usage: `Route::middleware('admin.role:super_admin')`)*
- [ ] `EnsureAdminRole` applied to any route *(middleware defined but used by zero routes — role separation is dormant)*
- [ ] Admin cannot access another admin's restricted data
- [ ] Authorization for user management / compliance / disputes / commissions *(modules not built)*
- [ ] No Laravel Policies anywhere in the project *(ownership checks are controller-level only; Policies required before orders/payments land)*
- [x] Form validation *(rejection reason required max 500; login validated)*
- [x] Unauthorized access handling *(guards + redirects)*
- [ ] Action/activity logging *(only `approved_by`/`approved_at` on registrations)*

---

## UI & Responsive

- [x] Admin desktop UI *(`Admin.Layouts.app` + admin-sidebar component)*
- [x] Responsive dashboard *(1200 px / 700 px grid breakpoints)*
- [x] Responsive navigation
- [ ] Responsive registration management *(visual QA pending)*
- [ ] Responsive user management / compliance / disputes / reports / chat *(not built)*
- [x] Error states *(404 defensive guard on missing tables)*
- [x] Success messages *(flash on approve/reject)*

---

## Testing

- [x] Logistics registration tests *(`LogisticsRegistrationTest` — route fix, store, validation, cross-role email, OTP flow, admin approve/reject — 21 tests)*
- [x] Seller dashboard hero tests *(`SellerDashboardHeroTest` — 5 tests)*
- [x] Seller panel fixes tests *(`SellerPanelFixesTest` — sidebar, hero layout)*
- [x] Street/address tests *(`RegistrationStreetTest` — buyer + seller + admin view — 19 tests)*
- [x] Admin registrations view *(covered via `RegistrationStreetTest` admin assertions)*
- [ ] Admin login test
- [ ] Registration management unit test
- [ ] Buyer / seller approval test *(approval flow covered indirectly in street test)*
- [ ] Logistics approval test *(covered in `LogisticsRegistrationTest`)*
- [ ] User account management test
- [ ] Seller compliance test
- [ ] Commission / reports test
- [ ] Authorization / role test

---

## Complete Admin Flow

- [x] Login
- [x] View platform dashboard *(two real stats; two `—` placeholders)*
- [x] Review pending registrations
- [x] Approve / disapprove applicant
- [x] Notify applicant (email)
- [ ] Manage user accounts
- [ ] Monitor seller compliance
- [ ] Handle complaints / disputes
- [ ] Calculate platform commission
- [ ] Generate reports
- [ ] Manage platform announcements / policies
- [ ] Chat / messaging
- [ ] Manage admin account
- [x] Logout

---

## Known defects / open items (as of 2026-09-22)

1. **`EnsureAdminRole` middleware applied to no routes** — role separation is dormant; all authenticated admins can reach every admin route regardless of role value.
2. **`app/Models/Seller/Product.php` and `app/Models/Seller/ProductImage.php` are ghost files** — both declare `namespace App\Models` (wrong for their path) and will never be autoloaded. They are dead code but still on disk.
3. **`admin_coding_checklist.md` defect #3 (orphaned `SellerRegistrationController`) still unresolved** — `Admin\SellerRegistrationController` has no routes; `Admin/Registrations/sellers.blade.php` calls undefined route names and would throw if reached.
4. **No Laravel Policies project-wide** — required before user management, compliance actions, or dispute administration are built.
5. **Dashboard "Open Disputes" and "Commission" stats are `—` placeholders** — disputes and commission modules do not exist.
6. **Courier management must stay with Logistics, not Admin** — maintain this boundary when the Logistics panel ships.
7. **All 127 tests pass** as of the 2026-09-22 session.
