# Project Summary — Multi-Vendor Checkout

## What It Does

A REST API where a customer submits a cart and gets back a confirmed order.

**Example:** A customer orders 2 pencils and 1 toy car. Pencils are sold by Vendor A ($2.50) and Vendor B ($2.30). Vendor B is cheaper, so the system reserves stock from them. Toy cars are only sold by Vendor C. The result is one order with two sub-orders — one for Vendor B, one for Vendor C — each vendor notified separately.

```json
POST /api/orders
{
    "items": [
        { "product_code": "PENCIL", "quantity": 2 },
        { "product_code": "TOY_CAR", "quantity": 1 }
    ]
}
```

---

## Why Laravel

Laravel is a PHP framework that provides a full set of tools for building web APIs out of the box, without having to assemble them from separate libraries.

**Key features used in this project:**

- **Eloquent ORM** — maps database tables to PHP classes. Writing `Order::create([...])` saves a row; `$order->subOrders` loads the relationship. No raw SQL needed for standard operations.
- **DB Facades & Query Builder** — for performance-critical paths (like stock decrement and bulk vendor lookups) raw query builder is used instead of Eloquent, giving full control over the SQL.
- **DB::transaction()** — wraps multiple DB operations in a single atomic unit. If anything throws, everything rolls back automatically.
- **DB::afterCommit()** — runs a callback only after the transaction has been committed. Used here to dispatch vendor notifications safely.
- **Queue & Jobs** — `NotifyVendorJob` is dispatched to a background worker. In development `QUEUE_CONNECTION=sync` runs jobs inline; in production it can be switched to `redis` or `database` with no code changes.
- **Artisan commands** — Laravel's CLI system. `catalog:import` is a custom command for seeding the database from a JSON file.
- **Form Requests** — `CreateOrderRequest` handles input validation before the controller is even called. Invalid payloads return a 422 automatically.
- **Dependency Injection container** — services and rules are wired together in `AppServiceProvider`. Swapping an implementation (e.g. a different discount rule) means changing one line in the provider, not touching the classes that use it.

**Why Laravel over alternatives (Symfony, plain PHP, Node.js):**
Laravel prioritises developer speed for CRUD-heavy applications. The conventions (one model per table, automatic timestamps, built-in queue system) mean less boilerplate. Symfony offers more control but requires more configuration. For a project of this scope and timeline, Laravel's defaults cover 90% of the needs without fighting the framework.

---

## Architectural Decisions

### 1. Single database transaction for checkout

Everything that must succeed together — stock reservation, order creation, sub-order creation — runs inside one `DB::transaction()`. If anything fails, nothing is saved.

**Example:** Customer orders a PENCIL and a TOY_CAR. PENCIL stock is reserved successfully. TOY_CAR is out of stock. Without a transaction, PENCIL stock would be decremented but no order created — the stock is gone and the customer has nothing. With the transaction, both changes roll back.

**Trade-off:** Long-running transactions increase lock contention under high concurrency. Acceptable here because the transaction is short and scoped tightly.

---

### 2. Failure via exceptions, not return values

Inside the transaction, failures throw a `CheckoutFailedException`. The exception is caught outside the transaction, which triggers a rollback before converting it into an error response.

**Why this matters:** Returning a failure value from inside a transaction does not roll it back — Laravel sees a successful return and commits. This was a real bug in the original code:

```
1. PENCIL stock decremented ✓  ← inside transaction
2. TOY_CAR out of stock → return failure DTO  ← transaction commits here!
3. Result: stock gone, no order created
```

The fix: throw an exception on failure. Laravel catches it, rolls back, then the exception is converted to an error response outside.

---

### 3. Vendor allocation by lowest price with stock fallback

For each product, vendors are sorted by price ascending. The system tries the cheapest first. If that vendor lacks enough stock, it moves to the next one. A product is never split across vendors.

**Example:** PENCIL is offered by Vendor A ($2.50, stock: 0) and Vendor B ($2.30, stock: 10). Vendor B is cheaper so it's tried first — but stock is 0. System falls back to Vendor A.

**Trade-off:** Under high load, the cheapest vendor may run out of stock frequently, causing more `OUT_OF_STOCK` errors than if the system factored in stock levels. Kept simple intentionally.

---

### 4. Atomic stock decrement

Stock is decremented using a conditional SQL update:

```sql
UPDATE vendor_products
SET stock = stock - 2
WHERE vendor_code = 'VENDOR_B'
  AND product_code = 'PENCIL'
  AND stock >= 2
```

If the row is updated, the reservation succeeded. If 0 rows are affected, someone else got the last stock first.

**Why:** Without this, two simultaneous checkouts could both read `stock = 1`, both decide they can proceed, and both succeed — leaving stock at -1. Doing it in one atomic DB operation eliminates the race condition without application-level locks.

---

### 5. Vendor notifications after commit

Notifications are dispatched inside `DB::afterCommit()`, not immediately after the DB writes.

**Why:** If a job is dispatched while still inside a transaction and the transaction later rolls back, the queue worker picks up a job referencing an order that no longer exists.

**Trade-off:** If the application crashes between commit and dispatch, notifications are lost. A fully reliable solution would use the outbox pattern (store pending notifications in the DB as part of the same transaction), but that adds significant complexity for a low-probability failure.

---

### 6. Cumulative discount engine

Multiple discount rules are summed, not compounded. Each applies to the full price independently.

**Example:** Buy 10 TOY_CARs at $15 each = $150 subtotal.
- Quantity rule (10+ items): 10% → $15 off
- Category rule (toys): 10% → $15 off
- **Total discount: $30 (20% of $150), not $28.50 (compounded)**

**Trade-off:** Summing always favours the customer more than compounding. This is an intentional business decision.

**Extensibility:** Each rule is its own class implementing `DiscountRuleInterface`. Adding a new rule type (e.g. a loyalty discount) means writing one new class — no changes to the engine or existing rules.

---

### 7. Catalog import as a separate service

Import logic lives in `CatalogImportService`, not in the Artisan command. The command is a thin wrapper that reads the file and delegates.

**Why:** A class that mixes file I/O, validation, and database writes cannot be unit tested without going through the CLI. Extracting the service allows testing every behaviour directly:

```php
// Test calls the service directly — no CLI, no file system
$result = $service->import($catalogData, truncate: true);
$this->assertEquals(7, $result->vendorProductsCount);
```

**Approach:** All input is validated in a pure PHP pass before any DB write. If offer #50 is invalid, offers #1–49 are not written. Vendor products are written in a single bulk `upsert()` instead of one query per row.

---

## Code Architecture

### Layered separation

```
HTTP layer         OrderController         — validates input, returns HTTP responses
Use case layer     OrderCheckoutService    — coordinates the use case, dispatches jobs
Transaction layer  OrderCheckoutManager    — owns the DB transaction, allocates stock, builds the order
Domain layer       StockManager, DiscountEngine, discount rules — pure logic, no HTTP or DB framework knowledge
```

The controller never touches the database. The manager never knows about HTTP. Each layer can be tested and reasoned about independently.

---

### DTOs for data flow between layers

Instead of passing raw arrays between classes (where any key can be missing or mistyped), typed Data Transfer Objects make the contract explicit:

```php
// Without DTOs — fragile, no autocomplete, fails at runtime
$item['prodcut_code']  // typo discovered at runtime

// With DTOs — caught at IDE/static analysis level
$item->productCode     // typo is a compile error
```

DTOs used in this project:
- `CartItemDTO` — input from the HTTP request
- `ProductAllocationDTO` — result of assigning a product to a vendor
- `DiscountInputDTO` — data the discount engine needs
- `CheckoutResultDTO` — success or failure result returned up the stack
- `CatalogImportResultDTO` — counts and warnings from the import service

---

### Discount rules as pluggable objects

`DiscountEngine` knows nothing about specific rules — it just iterates and sums:

```php
foreach ($this->rules as $rule) {
    $totalDiscount += $rule->calculate($input);  // same interface, any rule type
}
```

Adding a new discount type (e.g. a first-order discount) requires writing one new class. No existing code changes. This is the Open/Closed principle in practice.

---

### Internal vs external exceptions

- `CheckoutFailedException` — internal. Thrown inside the transaction to force a rollback, caught just outside it, never reaches HTTP.
- `CatalogImportException` — external. Surfaces to the CLI or test with `offerIndex` and `field` context so the caller can show a precise error message.

---

## Key Challenges

| Challenge | Root Cause | Fix |
|---|---|---|
| Stock not rolling back on failed checkout | Failure returned as a value inside transaction — no rollback triggered | Throw exception inside, catch outside |
| N+1 queries during checkout | One DB query per cart item for existence check + vendor lookup | Single `whereIn` query for all product codes upfront |
| N+1 in discount calculation | One DB query per cart item to look up product category | Single `whereIn` query for all product codes before the loop |
| Import command untestable | File I/O, validation, and DB writes all in one class | Extracted `CatalogImportService` with full unit test coverage |
| Partial import on bad data | Validation mixed with DB writes — some rows written before failure | Validate all data first, write nothing until validation passes |
