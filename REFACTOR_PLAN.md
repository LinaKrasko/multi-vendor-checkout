# Refactoring Plan

Tests are already written and failing where expected. Work through steps in order — each step should leave the test suite no worse than before it started.

---

## Step 0a — Fix transaction rollback bug `OrderCheckoutManager` ✦ do first

**Problem:** `DB::transaction()` only rolls back on exception. `allocateItemsToVendors` currently returns a `CheckoutResultDTO` failure value, so the transaction commits with partial stock decrements already applied.

**Fix:**
- Introduce an internal `CheckoutFailedException` (carrying `errorCode` + `productCode`)
- Throw it inside `allocateItemsToVendors` instead of returning a failure DTO
- In `createFromCart`, wrap the transaction in a try/catch, convert the exception back to `CheckoutResultDTO::failure(...)` outside the transaction so the rollback fires naturally

**Verifies:** `CheckoutErrorPathsTest::test_partial_failure_rolls_back_entire_order`

---

## Step 0b — Bulk vendor loading `OrderCheckoutManager::allocateItemsToVendors`

**Problem:** 2 SELECT queries per cart item (existence check + vendor offers fetch).

**Fix:**
- Before the loop, collect all unique product codes from `$items`
- Run one `whereIn` query to check existence for all product codes at once
- Run one `whereIn` query to fetch all relevant `vendor_products` rows ordered by price
- Key both results by `product_code` and work in-memory inside the loop
- `decrementStock()` stays per-item — must remain an atomic conditional UPDATE

**Verifies:** `CheckoutQueryCountTest` (both tests)

---

## Step 0c — Bulk category lookup `CategoryDiscountRule::calculate`

**Problem:** 1 SELECT query per cart item to fetch `VendorProduct` category.

**Fix:**
- Collect all `productCode`s from `$input->items` before the loop
- Fetch all matching `VendorProduct` rows in one `whereIn` query, keyed by `product_code`
- Iterate in-memory

**Verifies:** `CategoryDiscountRuleTest::test_uses_single_vendor_products_query_for_multiple_items`

---

## Step 0d — Bulk writes `ImportCatalog`

**Problem:** `updateOrCreate` per offer = 2 queries × N offers inside a transaction.

**Fix:**
- Validate all offers first in a pure PHP loop (no DB access during validation)
- Replace `updateOrCreate` loop with a single `VendorProduct::upsert()` call, keyed on `[vendor_code, product_code]`
- Replace per-rule `create()` loops with `insert()` bulk calls after truncate

---

## Step 0e — Bulk sub-order creation `OrderCheckoutManager::createSubOrders`

**Problem:** 1 INSERT per vendor group.

**Fix:** Verify whether returned model IDs are used downstream. If not, replace individual `SubOrder::create()` calls with a single `SubOrder::insert()`.

---

## Step 1 — Extract `CatalogImportService`

**Problem:** `ImportCatalog` command mixes file I/O, validation, and persistence — untestable in isolation.

**Fix:**
- Create `app/Services/CatalogImportService.php` with `import(array $data, bool $truncate = false): CatalogImportResultDTO`
- Move all DB logic into the service; command becomes: parse options → read file → call service → output results
- `CatalogImportResultDTO` already exists as a skeleton

**Verifies:** All `CatalogImportServiceTest` correctness cases

---

## Step 2 — Separate validation from persistence in `CatalogImportService`

**Problem:** Validation (currently raw `\Exception` throw mid-loop) is mixed with DB writes, allowing partial state on failure.

**Fix:**
- Add a dedicated validation pass over all offers and rules before any DB writes begin
- Throw `CatalogImportException` (already created as skeleton) with `offerIndex` and `field` context
- Validate discount rule fields (`min_qty`, `percent`, `category`) with the same rigor as offer fields

**Verifies:** `CatalogImportServiceTest` validation and atomicity cases

---

## Step 3 — Unify upsert strategy for discount rules

**Problem:** `--truncate` only clears `vendor_products` but discount rules are always truncated unconditionally — inconsistent behaviour.

**Fix:** Tie all three tables to the `truncate` flag consistently:
- `truncate: true` → clear `vendor_products`, `quantity_discount_rules`, `category_discount_rules` before import
- `truncate: false` → use `updateOrCreate` (or equivalent upsert) for all three tables

**Verifies:** `CatalogImportServiceTest::test_truncate_also_clears_discount_rules` and `test_discount_rules_are_replaced_not_accumulated_on_reimport`

---

## Step 4 — Warn on missing `stock`

**Problem:** Offers without a `stock` field silently default to `0`, which can create invisible out-of-stock products.

**Fix:** During validation, detect absent `stock` key (distinct from explicit `stock: 0`) and add a warning string to `CatalogImportResultDTO::$warnings`.

**Verifies:** `CatalogImportServiceTest::test_missing_stock_defaults_to_zero_and_adds_warning` and `test_explicit_zero_stock_does_not_produce_warning`

---

## Completion check

All tests passing:
- [x] `CheckoutErrorPathsTest` (8 tests)
- [x] `CategoryDiscountRuleTest` (7 tests)
- [x] `CheckoutQueryCountTest` (2 tests) — 1 SELECT query per checkout (not 2N)
- [x] `CatalogImportServiceTest` (15 tests)
- [x] `CheckoutProcessTest` (3 tests)
