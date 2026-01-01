# Multi-Vendor Checkout System

This is Laravel-based multi-vendor checkout system. It allows customers to place orders containing products from multiple vendors. The system automatically groups items by vendor, calculates cumulative discounts, and notifies vendors about their respective parts of the order.

### Quick Setup

After checking out from GitHub, follow these steps to get the project running:

1.  **Install Dependencies & Setup:**
    ```bash
    composer install
    ```
    This command will automatically install PHP dependencies, create your `.env` file, generate the app key, initialize the SQLite database, and import the catalog data.

2.  **Start the Application:**
    Ensure your local server is running or run `php artisan serve` and access it at `http://localhost:8000`.

---

### Updating Existing Installations

If you already have the project checked out, run these commands to get the latest changes and refresh your environment:

```bash
git pull origin main
composer install
```
*(This will update your dependencies and automatically run the setup script to refresh your database and catalog)*

---

### Configuring Data

The system is designed to import its catalog (products and discount rules) from a JSON file.

#### 1. Using the Import Command
To populate or refresh the database with data from `storage/app/catalog.json`, run:
```bash
php artisan catalog:import
```
*Optional: Use `--truncate` to clear existing products before importing.*

#### 2. Data Structure
The `catalog.json` file contains:
*   **offers**: Products linked to vendors with price and stock.
*   **quantity_discount_rules**: Global rules based on item quantity.
*   **category_discount_rules**: Rules applied to specific product categories.

---

### Running Endpoints

You can use tools like Postman, Insomnia, or `curl` to interact with the API.

#### 1. Place an Order
**POST** `/api/orders`

**Payload:**
```json
{
    "items": [
        {"product_code": "PENCIL", "quantity": 10},
        {"product_code": "TOY_CAR", "quantity": 1}
    ]
}
```

#### 2. View All Orders
**GET** `/api/orders`

---

### How Discounts Work

The system uses a **cumulative discount engine**. 

*   **Applied on Full Price:** For simplicity, discounts are always applied to the full price, even if there are several discounts on the same item.
*   **Cumulative:** If multiple rules apply, their percentages are summed up.

#### Discount Types:
*   **Quantity Threshold:** Applied if the quantity of a specific product in the cart meets or exceeds the `min_qty`.
*   **Category Discount:** Applied if the product belongs to a specific category.

**Example (based on default catalog.json):**
If you buy 10 units of `PENCIL` ($2.50 each):
- Quantity rule (10+ units) gives 5% discount.
- `PENCIL` is in `stationery` category (no category discount in default rules).
- Total discount = 5% of $25.00 = $1.25.
- Final Price = $23.75.

---

### Product Allocation & Multi-Vendor Handling

When ordering products, the system handles multi-vendor logic as follows:

*   **Lowest Price Priority:** It always attempts to take the product from the vendor with the lowest price first.
*   **Stock Handling:** If you order more than 1 of the same product and the vendor with the lowest price does not have enough stock, the system will not split the order across vendors. Instead, it will take the required amount from the next lowest-priced vendor.

---

### Atomicity & Data Integrity

The checkout process is designed to be reliable and consistent:

*   **Atomic Transactions:** All database operations during checkout (searching for the lowest price, reserving stock, and creating the order) are wrapped in a single database transaction. This ensures that either the entire order is placed successfully or nothing changes at all, preventing partial orders or stock inconsistencies.
*   **Race Condition Protection:** Stock updates are performed using atomic database decrements with a condition check (`decrement` where `stock >= requested_qty`), ensuring that two simultaneous orders cannot over-purchase the same stock.

---

### Checking Notification Logs

Notifications are only dispatched if the order is successfully placed and the database transaction has been committed. If an order fails (e.g., due to being out of stock), no notifications are sent.

To see the notifications:
1.  Ensure your queue worker is running (or `QUEUE_CONNECTION=sync` in `.env` for immediate execution):
    ```bash
    php artisan queue:listen
    ```
2.  Check the log file:
    ```bash
    tail -f storage/logs/laravel.log
    ```
    Look for entries like: `Vendor: VENDOR_A | Order: 1 | Items: PENCIL (x10)`

---

### Database Schema

The system uses the following tables to manage the multi-vendor checkout process:

*   **vendor_products**: Contains the product catalog. Each entry represents a product offered by a specific vendor.
    *   `vendor_code`: Unique identifier for the vendor.
    *   `product_code`: Unique identifier for the product.
    *   `price`: The unit price offered by this vendor.
    *   `stock`: Current inventory level for this vendor.
    *   `category`: The category the product belongs to (used for discounts).
*   **orders**: Stores the primary order information.
    *   `subtotal_price`: The sum of all items before discounts.
    *   `discount`: The total calculated discount.
    *   `total_price`: The final amount to be paid.
*   **sub_orders**: Tracks the portion of an order assigned to a specific vendor.
    *   `order_id`: Reference to the main order.
    *   `vendor_code`: The vendor responsible for this sub-order.
    *   `status`: Current state (e.g., `created`, `vendor_notified`).
    *   `items_snapshot`: A JSON field containing the details of products and quantities at the time of the order. This approach was chosen for simplicity in this project; in a real-world application, a separate `sub_order_items` table would typically be used for better relational integrity and querying.
*   **quantity_discount_rules**: Stores global discount rules based on the number of items.
    *   `min_qty`: Minimum quantity required to trigger the discount.
    *   `percent`: The discount percentage to apply.
*   **category_discount_rules**: Stores discount rules for specific product categories.
    *   `category`: The target category.
    *   `percent`: The discount percentage to apply.

---

### Running Tests

To ensure everything is working correctly, run the automated test suite:

```bash
php artisan test
```
The test suite includes:
1.  **Vendor Grouping:** Verifies that items from different vendors are correctly split into separate sub-orders.
2.  **Discount Calculations:** Ensures that cumulative discounts (quantity-based and category-based) are applied correctly to the total price.
3.  **Notification Dispatching:** Confirms that vendor notification jobs are dispatched for each sub-order created.
