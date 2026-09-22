# Admin Coding Checklist

> **Audit legend (2026-09-20):** [x] = working (code + route + backing data verified) · [ ] = not implemented. Notes in *italics* flag dependencies, UI-only shells, or defects.
> Anything not ticked below was not found in the codebase (no route, controller, table, or view backs it).

---

## Foundation / Authentication

- [x] Admin login *(`GET|POST /admin/login`, `Admin\AuthController`, guest-protected)*
- [x] Email validation
- [x] Password validation
- [x] Admin authentication *(`admin` guard + `admins` table)*
- [x] Admin route protection *(`auth:admin` middleware group)*
- [x] Admin logout *(session invalidation)*
- [x] Unauthorized access handling *(`guest:admin` redirects; guards never shared)*

---

## Admin Dashboard

- [x] Admin dashboard *(`Admin\DashboardController` renders `Admin/dashboard`)*
- [ ] Platform overview
- [ ] Total users *(HARDCODED "128" in the view)*
- [ ] Total buyers
- [ ] Total sellers
- [ ] Total logistics/sorting centers
- [ ] Total couriers/riders
- [ ] Total products
- [ ] Total orders
- [x] Pending registrations *(real query — BUT counts `status = 'pending'` while registrations actually use `pending_approval`; likely always 0)*
- [ ] Pending complaints/disputes *(HARDCODED "5")*
- [ ] Platform sales overview
- [ ] Commission overview *(HARDCODED "₱12,450")*
- [ ] Dashboard statistics
- [ ] Recent activities
- [ ] Dashboard notifications

---

## Manage Account Registrations

> *Only the buyer+seller unified queue (`Admin\Registrations.index`) exists and works.
> Logistics/sorting-center/courier applications do not exist (Logistics' domain, not built).
> An orphaned `Admin\SellerRegistrationController` + `Admin/Registrations/sellers.blade.php`
> exist but are NOT routed, and the view references undefined route names — rendering it errors.*
- [x] Registration management page *(`GET /admin/registrations`; 404 guard if tables missing)*
- [x] Buyer applications *(`pending_approval` queue)*
- [x] Seller applications *(`pending_approval` queue, unified with buyers)*
- [ ] Logistics applications
- [ ] Sorting center applications
- [ ] Courier/rider applications
- [ ] Search applications
- [ ] Filter applications
- [ ] Sort applications
- [x] View application details *(?view=&type= selection)*
- [x] Review submitted information
- [ ] Review submitted requirements
- [ ] View uploaded ID *(not verified as displayed in the unified queue)*
- [ ] View business permit / DTI permit *(only in the orphaned unrouted view)*
- [ ] Verify submitted information
- [x] Approve registration *(sets `approved_by`/`approved_at`, clears rejection reason)*
- [x] Disapprove registration
- [x] Registration rejection reason *(required, max 500)*
- [x] Applicant approval notification *(`BuyerRegistrationDecision` / `SellerRegistrationDecision`)*
- [x] Applicant rejection notification *(includes reason)*
- [x] Email notification *(via Laravel notifications)*
- [x] Registration status tracking *(status + approved_by + approved_at persisted)*

---

## Manage User Accounts

* [ ] User management page
* [ ] View all users
* [ ] View buyer accounts
* [ ] View seller accounts
* [ ] View logistics/sorting center accounts
* [ ] View courier/rider accounts
* [ ] Search users
* [ ] Filter users by role
* [ ] Sort users
* [ ] View user profile
* [ ] View account status
* [ ] Activate account
* [ ] Suspend account
* [ ] Deactivate account
* [ ] Reactivate account
* [ ] Suspension reason
* [ ] Deactivation reason
* [ ] Account status notification
* [ ] User activity/history

---

## Seller Compliance Monitoring

* [ ] Seller compliance page
* [ ] View seller products
* [ ] Verify product belongs to seller's registered category
* [ ] Identify prohibited products
* [ ] Identify inappropriate products
* [ ] Review product details
* [ ] Review product images
* [ ] Search seller products
* [ ] Filter seller products
* [ ] Issue seller warning
* [ ] Warning reason
* [ ] Suspend seller account
* [ ] Suspension reason
* [ ] Reactivate seller account
* [ ] Seller violation history
* [ ] Compliance status
* [ ] Notify seller of violation
* [ ] Notify seller of suspension

---

## Complaints & Disputes

* [ ] Complaints and disputes page
* [ ] View complaints
* [ ] View dispute details
* [ ] View supporting evidence
* [ ] View related order
* [ ] View buyer information
* [ ] View seller information
* [ ] View courier information
* [ ] Search complaints
* [ ] Filter complaints
* [ ] Sort complaints
* [ ] Complaint status
* [ ] Assign/review complaint
* [ ] Communicate with buyer
* [ ] Communicate with seller
* [ ] Communicate with courier
* [ ] Request additional evidence
* [ ] Record resolution
* [ ] Resolve complaint
* [ ] Close dispute
* [ ] Complaint/dispute history
* [ ] Notify involved users of decision

---

## Commission Management

* [ ] Commission management page
* [ ] Platform commission rate
* [ ] 10% commission configuration
* [ ] Calculate platform commission
* [ ] Calculate commission per order
* [ ] View seller sales
* [ ] View commission per order
* [ ] View total commissions
* [ ] Commission history
* [ ] Commission status
* [ ] Commission report data

---

## Reports

* [ ] Reports page
* [ ] Sales summary report
* [ ] Commission report
* [ ] Date range filter
* [ ] Sales totals
* [ ] Order totals
* [ ] Completed sales
* [ ] Cancelled orders
* [ ] Platform commission
* [ ] Seller sales summary
* [ ] Report filtering
* [ ] Report sorting
* [ ] Export sales report
* [ ] Export commission report

---

## Platform Settings

* [ ] Platform settings page
* [ ] Post announcements
* [ ] Create announcement
* [ ] Edit announcement
* [ ] Delete/archive announcement
* [ ] Publish/unpublish announcement
* [ ] Announcement visibility
* [ ] Update platform policies
* [ ] Create policy
* [ ] Edit policy
* [ ] Publish policy
* [ ] Policy version/history
* [ ] Platform commission setting
* [ ] System configuration

---

## Chat / Messaging

* [ ] Admin chat page
* [ ] Conversation list
* [ ] Buyer conversations
* [ ] Seller conversations
* [ ] Logistics conversations
* [ ] Courier conversations
* [ ] Send message
* [ ] Receive message
* [ ] Message timestamps
* [ ] Read/unread status
* [ ] Unread message count
* [ ] Message history
* [ ] Order-related chat
* [ ] Complaint/dispute-related chat

---

## Account Management

* [ ] Admin account page
* [ ] View profile
* [ ] Edit personal information
* [ ] Edit contact number
* [ ] Edit email
* [ ] Change password
* [ ] Profile picture
* [ ] Account status
* [ ] Security settings

---

## Security & Authorization

- [x] Admin-only route protection *(`auth:admin`; login behind `guest:admin`)*
- [x] Role-based access *(role column + `EnsureAdminRole` middleware — but the middleware is applied to NO route yet)*
- [ ] Admin cannot access another admin's restricted data
- [ ] Authorization for user management *(module not built)*
- [ ] Authorization for seller compliance actions *(module not built)*
- [ ] Authorization for complaint/dispute actions *(module not built)*
- [ ] Authorization for commission management *(module not built)*
- [x] Form validation *(rejection reason required max 500; login validated)*
- [ ] File/viewing authorization
- [x] Unauthorized access handling
- [ ] Action/activity logging
- [ ] Admin action history *(only approved_by/approved_at on registrations)*

---

## UI & Responsive

- [x] Admin desktop UI *(`Admin.Layouts.app` + admin-sidebar component)*
- [ ] Admin tablet UI audit
- [ ] Admin mobile UI audit
- [x] Responsive navigation
- [x] Responsive dashboard *(1200px/700px grid breakpoints)*
- [ ] Responsive registration management *(not deeply audited — visual QA pending)*
- [ ] Responsive user management *(not built)*
- [ ] Responsive complaints/disputes *(not built)*
- [ ] Responsive reports *(not built)*
- [ ] Responsive chat *(not built)*
- [ ] Loading states *(not audited)*
- [ ] Empty states *(not audited)*
- [x] Error states *(404 defensive guard on missing tables)*
- [x] Success messages *(flash on approve/reject)*
- [ ] Confirmation dialogs *(not audited)*

---

## Testing

* [ ] Admin login test
* [ ] Authentication test
* [ ] Registration management test
* [ ] Buyer approval test
* [ ] Seller approval test
* [ ] Logistics approval test
* [ ] Courier approval test
* [ ] User account management test
* [ ] Seller compliance test
* [ ] Complaint/dispute test
* [ ] Commission calculation test
* [ ] Sales report test
* [ ] Commission report test
* [ ] Platform settings test
* [ ] Chat test
* [ ] Authorization test
* [ ] Responsive UI test
* [ ] End-to-end admin flow test

---

## Complete Admin Flow

- [x] Login
- [x] View platform dashboard *(stats mostly placeholder/hardcoded)*
- [x] Review pending registrations
- [ ] Verify submitted requirements
- [x] Approve/disapprove applicant
- [x] Notify applicant *(email)*
- [ ] Manage user accounts
- [ ] Monitor seller compliance
- [ ] Handle complaints/disputes
- [ ] Calculate platform commission
- [ ] Generate reports
- [ ] Manage platform announcements
- [ ] Update platform policies
- [ ] Chat/messaging
- [ ] Manage admin account
- [x] Logout

---

## Known defects / compliance notes (from 2026-09-20 audit)

1. **Hardcoded dashboard stats** — "128 Active Users", "5 Open Disputes", "₱12,450 Commission" are fake numbers rendered directly in `Admin/dashboard.blade.php`.
2. **Wrong status value in the real stat** — dashboard counts `where('status', 'pending')`, but registrations use `pending_approval`; the only live stat is therefore likely always 0.
3. **Orphaned seller-registration screen** — `Admin\SellerRegistrationController` has no routes, and `Admin/Registrations/sellers.blade.php` calls undefined `registrations.sellers.*` route names (rendering it would throw). The live queue is the unified `Registrations.index`.
4. **`EnsureAdminRole` middleware exists but is used by zero routes** — role separation is dormant.
5. **No admin feature tests** exist.
6. **No Policies project-wide** — must be addressed before user management / compliance / dispute administration are built.
7. Per the settled architecture, **courier management must stay with Logistics**, not Admin — keep the current boundary when Logistics ships.
