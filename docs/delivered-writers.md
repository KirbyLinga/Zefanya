# Delivered Writers

Every case-insensitive "delivered" hit in `web/app`, `web/routes`, and `web/database`,
classified as **writer** (assigns the status) or **reader** (reads/compares the status).

Scan patterns:
- `status'?\s*=>\s*'delivered'` (array key assignment)
- `->status\s*=\s*'delivered'` (property assignment)
- `OrderStatus::Delivered` (enum assignment/reference)

Whitelisted writers: `OrderDeliveryService.php`, `OrderStatus.php`, seeders, factories.

---

## Writers (status assigned here)

| File | Line | Note |
|------|------|------|
| `app/Enums/OrderStatus.php` | 33 | `case Delivered = 'DELIVERED'` — enum definition |
| `app/Services/OrderDeliveryService.php` | 92 | `'status' => OrderStatus::Delivered` in `markDelivered()` — courier path |
| `app/Services/OrderDeliveryService.php` | 141 | `'status' => OrderStatus::Delivered` in `adminOverride()` — admin path |

## Readers (status compared, not assigned)

| File | Line | Note |
|------|------|------|
| `routes/admin.php` | 34 | Comment referencing delivered path (not an assignment) |
| `database/migrations/2026_11_01_100000_add_item_verification_to_reviews.php` | 79 | SQL `where('status', 'DELIVERED')` — read only |

## All writers route through OrderDeliveryService

Both production write paths (`markDelivered()` and `adminOverride()`) live exclusively in
`OrderDeliveryService`. The service enforces:
- Verified buyer code OR signed token (for `markDelivered`)
- Admin reason + proof photo + audit row (for `adminOverride`)

No controller, seeder, factory, or route file may assign the delivered status directly.
The `DeliveredWritersTest` enforces this invariant at test time.
