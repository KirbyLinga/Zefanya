# Logistics / Sorting Center Coding Checklist

> **Audit legend (2026-09-20):** [x] = working (code + route + backing data verified) · [ ] = not implemented. Notes in *italics* should explain dependencies, limitations, or UI-only functionality.
>
> **⚠️ AUDIT RESULT: Logistics is NOT STARTED — 0% implemented.** There are no logistics/courier
> routes, controllers, middleware, models, migrations, or views anywhere in the codebase, and
> `config/auth.php` defines NO logistics guard (only buyer/seller/admin). The single trace is
> `GET /register/logistics`, which points to a MISSING view (`Auth.register-logistics`) and
> currently 500s. Everything below is unchecked by design until the Logistics phase begins.
> When it does, remember the settled architecture: **`courier_applications` → (Logistics approval)
> → `couriers`** (never create couriers at registration), `seller_orders 1—1 deliveries`, and
> the Seller workflow ends at READY FOR PICKUP — after that it's Logistics → Courier only.

---

## Foundation / Registration

* [ ] Logistics / Sorting Center registration
* [ ] Last name*
* [ ] First name*
* [ ] Middle initial
* [ ] Sex*
* [ ] E-mail*
* [ ] Contact number*
* [ ] Birthday*
* [ ] Age (auto-generated)*
* [ ] Province dropdown
* [ ] Municipality dropdown
* [ ] Barangay dropdown
* [ ] Street / house number / additional address
* [ ] Address API integration
* [ ] Business name
* [ ] Upload valid ID
* [ ] ID file validation
* [ ] Upload business / DTI permit
* [ ] Business permit file validation
* [ ] Registration form validation
* [ ] Registration submission
* [ ] Pending approval status
* [ ] Administrator approval
* [ ] Administrator rejection
* [ ] Approval email
* [ ] Rejection email
* [ ] Account activation after approval

---

## Login & Authentication

* [ ] Logistics / Sorting Center login
* [ ] Email validation
* [ ] Password validation
* [ ] Approved-account verification
* [ ] Pending-account handling
* [ ] Rejected-account handling
* [ ] Login error messages
* [ ] Logistics authentication middleware
* [ ] Logistics-only route protection
* [ ] Logout
* [ ] Unauthorized access handling

---

## Logistics Dashboard

* [ ] Logistics dashboard
* [ ] Platform/center overview
* [ ] Pending courier applications
* [ ] Active riders/couriers
* [ ] Pending pickup requests
* [ ] Incoming parcels
* [ ] Parcels awaiting sorting
* [ ] Parcels ready for delivery
* [ ] Assigned deliveries
* [ ] In-transit deliveries
* [ ] Delivered parcels
* [ ] Dashboard statistics
* [ ] Recent activities
* [ ] Notifications

---

## Rider / Courier Management

* [ ] Rider/courier management page
* [ ] View courier applications
* [ ] View courier application details
* [ ] Review submitted information
* [ ] Review courier requirements
* [ ] View uploaded ID
* [ ] Approve courier application
* [ ] Disapprove courier application
* [ ] Rejection reason
* [ ] Activate courier
* [ ] Deactivate courier
* [ ] Reactivate courier
* [ ] View courier profile
* [ ] View courier status
* [ ] View courier assigned area
* [ ] Search couriers
* [ ] Filter couriers
* [ ] Sort couriers
* [ ] Courier approval notification
* [ ] Courier status notification

---

## Parcel Pickup Requests

* [ ] Pickup request page
* [ ] View seller pickup requests
* [ ] View pickup request details
* [ ] View seller information
* [ ] View parcel information
* [ ] View order information
* [ ] Verify parcel pickup request
* [ ] Approve pickup request
* [ ] Reject pickup request
* [ ] Pickup rejection reason
* [ ] Confirm parcel ready for pickup
* [ ] Assign pickup to rider
* [ ] Pickup status
* [ ] Pickup confirmation
* [ ] Pickup history
* [ ] Notify seller of pickup decision
* [ ] Notify courier of pickup assignment

---

## Incoming Parcels

* [ ] Incoming parcels page
* [ ] View incoming parcels
* [ ] Receive parcel
* [ ] Verify parcel information
* [ ] Verify parcel/order number
* [ ] Verify seller information
* [ ] Verify destination
* [ ] Record parcel arrival
* [ ] Parcel status
* [ ] Parcel receiving timestamp
* [ ] Parcel receiving history
* [ ] Search parcels
* [ ] Filter parcels
* [ ] Sort parcels

---

## Parcel Sorting

* [ ] Parcel sorting page
* [ ] View parcels awaiting sorting
* [ ] Sort parcels by destination
* [ ] Sort parcels by area
* [ ] Sort parcels by municipality
* [ ] Sort parcels by barangay
* [ ] Sort parcels by delivery route
* [ ] Update sorting status
* [ ] Record sorting timestamp
* [ ] View sorted parcels
* [ ] Sorting history
* [ ] Search parcels
* [ ] Filter parcels
* [ ] Sort parcel list

---

## Delivery Assignment

* [ ] Delivery assignment page
* [ ] View parcels ready for delivery
* [ ] View available riders
* [ ] Assign parcel to rider
* [ ] Assign multiple parcels
* [ ] Assign delivery per area
* [ ] Assign delivery per rider
* [ ] Check rider availability
* [ ] Check rider assigned area
* [ ] Prevent invalid rider assignment
* [ ] Reassign parcel
* [ ] Assignment status
* [ ] Assignment history
* [ ] Notify rider of assignment
* [ ] Notify buyer of shipment update

---

## Delivery Monitoring

* [ ] Delivery monitoring page
* [ ] View assigned deliveries
* [ ] View active deliveries
* [ ] View rider information
* [ ] View parcel information
* [ ] View delivery area
* [ ] View delivery status
* [ ] Pickup status
* [ ] In transit status
* [ ] Out for delivery status
* [ ] Delivered status
* [ ] Failed delivery status
* [ ] Delivery issue status
* [ ] Delivery timeline
* [ ] Delivery history
* [ ] Search deliveries
* [ ] Filter deliveries
* [ ] Sort deliveries
* [ ] Monitor rider delivery progress

---

## Delivery Status & Tracking

* [ ] Update parcel status
* [ ] Record status history
* [ ] Track parcel location/status
* [ ] Pickup timestamp
* [ ] Sorting timestamp
* [ ] Dispatch timestamp
* [ ] Out-for-delivery timestamp
* [ ] Delivery timestamp
* [ ] Failed delivery reason
* [ ] Return-to-sender handling
* [ ] Notify buyer of status changes
* [ ] Notify seller of status changes
* [ ] Notify courier of status changes

---

## Reports

* [ ] Logistics reports page
* [ ] Parcel report
* [ ] Pickup report
* [ ] Sorting report
* [ ] Delivery report
* [ ] Courier performance report
* [ ] Delivered parcel count
* [ ] Pending parcel count
* [ ] Failed delivery count
* [ ] Cancelled/returned parcel count
* [ ] Date range filter
* [ ] Area filter
* [ ] Rider filter
* [ ] Report search/filter
* [ ] Export report

---

## Chat / Messaging

* [ ] Logistics chat page
* [ ] Conversation list
* [ ] Seller conversations
* [ ] Courier conversations
* [ ] Buyer conversations
* [ ] Send message
* [ ] Receive message
* [ ] Message timestamps
* [ ] Read/unread status
* [ ] Unread message count
* [ ] Message history
* [ ] Parcel/order-related chat
* [ ] Delivery-related chat

---

## Account Management

* [ ] Logistics account page
* [ ] View personal information
* [ ] Edit personal information
* [ ] Edit contact number
* [ ] Edit email
* [ ] Change password
* [ ] Profile picture
* [ ] Business information
* [ ] Edit business name
* [ ] View uploaded ID
* [ ] View business / DTI permit
* [ ] Address management
* [ ] Account status

---

## Security & Authorization

* [ ] Logistics route protection
* [ ] Role-based access
* [ ] Logistics can manage couriers
* [ ] Logistics can manage pickup requests
* [ ] Logistics can manage incoming parcels
* [ ] Logistics can manage parcel sorting
* [ ] Logistics can assign deliveries
* [ ] Logistics can monitor deliveries
* [ ] Logistics cannot access admin-only functions
* [ ] Logistics cannot modify seller products
* [ ] Logistics cannot modify buyer accounts
* [ ] Logistics cannot modify unauthorized orders
* [ ] Form validation
* [ ] File upload validation
* [ ] Authorization policies
* [ ] Unauthorized access handling
* [ ] Activity/action logging

---

## UI & Responsive

* [ ] Logistics desktop UI
* [ ] Logistics tablet UI
* [ ] Logistics mobile UI
* [ ] Responsive navigation
* [ ] Responsive dashboard
* [ ] Responsive rider management
* [ ] Responsive pickup requests
* [ ] Responsive parcel management
* [ ] Responsive sorting
* [ ] Responsive delivery assignment
* [ ] Responsive delivery monitoring
* [ ] Responsive reports
* [ ] Responsive chat
* [ ] Responsive account page
* [ ] Loading states
* [ ] Empty states
* [ ] Error states
* [ ] Success messages
* [ ] Confirmation dialogs

---

## Testing

* [ ] Logistics registration test
* [ ] Registration validation test
* [ ] Address API test
* [ ] ID upload test
* [ ] Business permit upload test
* [ ] Approval flow test
* [ ] Logistics login test
* [ ] Courier application test
* [ ] Courier approval test
* [ ] Courier activation/deactivation test
* [ ] Pickup request test
* [ ] Parcel receiving test
* [ ] Parcel sorting test
* [ ] Delivery assignment test
* [ ] Delivery monitoring test
* [ ] Delivery status flow test
* [ ] Delivery notification test
* [ ] Reports test
* [ ] Chat test
* [ ] Account management test
* [ ] Authorization test
* [ ] Responsive UI test
* [ ] End-to-end logistics flow test

---

## Complete Logistics / Sorting Center Flow

* [ ] Register
* [ ] Submit ID and business/DTI permit
* [ ] Wait for administrator approval
* [ ] Receive approval email
* [ ] Login
* [ ] View logistics dashboard
* [ ] Review courier applications
* [ ] Approve/disapprove courier
* [ ] Activate/deactivate courier
* [ ] Receive seller pickup request
* [ ] Verify pickup request
* [ ] Approve pickup
* [ ] Receive incoming parcel
* [ ] Verify parcel
* [ ] Sort parcel
* [ ] Assign delivery to rider
* [ ] Monitor delivery
* [ ] Parcel out for delivery
* [ ] Parcel delivered
* [ ] Generate reports
* [ ] Chat/messaging
* [ ] Manage account
* [ ] Logout

---

## Known defects / compliance notes (from 2026-09-20 audit)

1. **`GET /register/logistics` → live 500** — the route points to `Auth.register-logistics`, which does not exist as a view.
2. **No logistics auth guard** — `config/auth.php` defines only `buyer`/`seller`/`admin` guards; a logistics guard + provider must be added before any of this is buildable.
3. **No supporting tables** — no `courier_applications`, `couriers`, `deliveries`, `delivery_status_history`, or sorting-center tables exist.
4. **No models/middleware/policies** — the settled architecture requires `CourierApplicationPolicy`, `DeliveryPolicy`, `lockForUpdate`-safe inventory on pickup, and permanent (never-deleted) delivery records.
5. **Role boundary reminder** — Logistics owns the ENTIRE courier lifecycle; Admin must not manage courier registration/verification, and Sellers must never assign couriers or touch delivery status after READY FOR PICKUP.
