# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Setup (run once after clone)
composer install              # Installs PHP deps and runs full setup: .env, key:generate, migrate, catalog:import

# Development
composer run dev              # Starts Laravel server, queue listener, Pail log viewer, and Vite dev server concurrently
php artisan serve             # Laravel dev server only (http://localhost:8000)

# Testing
composer test                 # Clears config cache, then runs PHPUnit
php artisan test              # Run all tests
php artisan test --filter=test_proper_vendor_grouping  # Run a single test by name

# Code style
./vendor/bin/pint             # Laravel Pint auto-formatter

# Database
php artisan migrate:fresh     # Reset and re-run migrations
php artisan catalog:import    # Re-import products/discount rules from storage/app/catalog.json
php artisan catalog:import --truncate --path=/path/to/file.json  # --truncate clears all three tables
```

## Architecture

This is a **multi-vendor checkout API** built with Laravel 12. The core domain is: a customer submits a cart with product codes and quantities; the system allocates each product to the cheapest vendor with sufficient stock, groups items into per-vendor sub-orders, applies cumulative discounts, and dispatches async vendor notifications — all atomically.

### Request flow

```
POST /api/orders
  → OrderController::store()
  → OrderCheckoutService::checkout()
      → OrderCheckoutManager::createFromCart()  [DB transaction]
          → allocateItemsToVendors()     — picks lowest-price vendor with stock, decrements atomically
          → createOrder()               — computes subtotal, runs DiscountEngine, saves Order
          → createSubOrders()           — groups allocations by vendor, saves SubOrder per vendor
      → dispatchNotifications()         [after DB commit]
          → NotifyVendorJob dispatched per SubOrder
```

### Key classes

| Class | Location | Role |
|---|---|---|
| `OrderCheckoutManager` | `app/Services/Checkout/` | Orchestrates the full checkout transaction |
| `StockManager` | `app/Services/Checkout/` | Atomic `UPDATE … WHERE stock >= qty` to prevent overselling |
| `DiscountEngine` | `app/Discounts/` | Sums all applicable rule percentages, applies to subtotal |
| `OrderCheckoutService` | `app/Services/Checkout/` | Thin layer: calls manager, dispatches jobs post-commit |
| `CatalogImportService` | `app/Services/` | Validates, bulk-upserts, and replaces catalog data; used by `ImportCatalog` command |
| `NotifyVendorJob` | `app/Jobs/` | Queue job; logs notification, updates `sub_orders.status` |

### Discount system

Discounts are **cumulative** (summed, not compounded). Two rule types:
- **QuantityThresholdDiscountRule** — applies if any item's qty ≥ `min_qty`
- **CategoryDiscountRule** — applies if any item's product category matches

Both are registered in `AppServiceProvider` via DI and stored in `quantity_discount_rules` / `category_discount_rules` tables.

### Data model

- `vendor_products` — unique `(vendor_code, product_code)`, holds `price`, `stock`, `category`
- `orders` — `subtotal_price`, `discount`, `total_price`
- `sub_orders` — belongs to order, one per vendor; holds `items_snapshot` (JSON), `status` enum (`created` | `vendor_notified`)

### API

```
GET  /api/orders   → list orders with sub-orders
POST /api/orders   → { "items": [{ "product_code": "PENCIL", "quantity": 5 }] }
```

Error responses (422) include an `error` field with one of: `unknown_product`, `out_of_stock`, `no_offers`.

### Testing

All tests live in `tests/Unit/` and use `RefreshDatabase` (in-memory SQLite configured in `phpunit.xml`). Test files and what they cover:

| File | Covers |
|---|---|
| `CheckoutProcessTest` | Vendor grouping, discount math, job dispatching |
| `CheckoutErrorPathsTest` | `UNKNOWN_PRODUCT`, `OUT_OF_STOCK`, vendor fallback, transaction rollback on partial failure |
| `CheckoutQueryCountTest` | Asserts a single `vendor_products` SELECT regardless of cart size (guards against N+1 regression) |
| `CategoryDiscountRuleTest` | Discount rule correctness and single-query assertion |
| `CatalogImportServiceTest` | Import correctness, truncate behaviour, validation atomicity, stock warnings |

### Queue & notifications

`QUEUE_CONNECTION=sync` by default (notifications fire inline). Switch to `database` or `redis` for async. Vendor notifications are dispatched inside `DB::afterCommit()` to guarantee the order is persisted before jobs run.
