# System Architecture & Database Design

## 1. Hybrid System Architecture

```
+-----------------------------------------------------------------------------+
|                         YUMMY SODA HYBRID ARCHITECTURE                      |
+-----------------------------------------------------------------------------+
|                                                                             |
|   +--------------+      +--------------+      +----------------------+      |
|   |   CUSTOMER   |      |    ADMIN     |      |   ANALYTICS USER     |      |
|   |  INTERFACE   |      |   PANEL      |      |   (Reports/Dashboard)|      |
|   |              |      |              |      |                      |      |
|   | - index.php  |      | - dashboard  |      | - analytics.php      |      |
|   | - cart.php   |      | - products   |      | - decisions.php      |      |
|   | - checkout   |      | - orders     |      | - export_*           |      |
|   | - account    |      | - messages   |      |                      |      |
|   +------+-------+      +------+-------+      +----------+-----------+      |
|          |                     |                         |                    |
|          +---------------------+-------------------------+                    |
|                                |                                             |
|                    +-----------+-----------+                                  |
|                    |    PHP Application    |                                  |
|                    |    (Apache/Nginx)     |                                  |
|                    |                       |                                  |
|                    |  includes/auth.php    |  <- Session & role management   |
|                    |  includes/db.php     |  <- PDO connection pool         |
|                    |  includes/cart.php   |  <- Cart logic (DB + session)   |
|                    |  includes/helpers.php|  <- e(), money() utilities      |
|                    +-----------+-----------+                                  |
|                                |                                             |
|          +---------------------+---------------------+                      |
|          |                     |                     |                      |
|   +------+------+     +-------+--------+    +------+------+              |
|   |    OLTP     |     |  ETL PIPELINE   |    |    OLAP     |              |
|   |  (3NF)      |<--->|  (etl_sync.php) |<--->|  (Star      |              |
|   |             |     |                 |    |   Schema)   |              |
|   | - users     |     | 1. Extract      |    |             |              |
|   | - products  |     | 2. Transform    |    | - Fact:     |              |
|   | - orders    |     | 3. Load         |    |   sales     |              |
|   | - payments  |     | 4. Audit        |    | - Date dim  |              |
|   | - order_items|    |                 |    | - Product   |              |
|   | - cart_items|    |                 |    |   dim       |              |
|   | - messages  |    |                 |    | - Customer  |              |
|   | - auto_     |    |                 |    |   dim       |              |
|   |   approve_  |    |                 |    | - Payment   |              |
|   |   rules     |    |                 |    |   dim       |              |
|   +-------------+     +-----------------+    +-------------+              |
|                                                                             |
|   DATA FLOW:  OLTP -> ETL -> OLAP -> Analytics API -> Dashboard/Exports       |
|                                                                             |
+-----------------------------------------------------------------------------+
```

---

## 2. OLTP Schema - Normalized Entity-Relationship Diagram (3NF)

```
+-----------------+       +-----------------+       +-----------------+
|     users       |       |    products     |       |     orders      |
+-----------------+       +-----------------+       +-----------------+
| PK user_id      |       | PK product_id   |       | PK order_id     |
|    full_name    |       |    sku (UQ)     |<------| FK user_id      |<----+
|    email (UQ)   |<------|    name         |       |    customer_name|     |
|    phone        |       |    category     |       |    customer_phone|    |
|    password_hash|       |    price        |       |    status (ENUM) |    |
|    role (ENUM)  |       |    stock_qty    |       |    ordered_at    |    |
|    created_at   |       |    is_active    |       +-----------------+    |
+-----------------+       |    image_path   |              |                  |
                          |    created_at   |              |                  |
                          +-----------------+              |                  |
                                  ^                        |                  |
                                  |                        |                  |
                          +-------+-------+                |                  |
                          |  order_items  |                |                  |
                          +---------------+                |                  |
                          | PK order_item_id|              |                  |
                          | FK order_id     |--------------+                  |
                          | FK product_id   |----------------------------------+
                          |    quantity     |
                          |    unit_price   |
                          |    line_total   |
                          +---------------+
                                  |
                                  |
                          +-------+-------+
                          |   payments    |
                          +---------------+
                          | PK payment_id |
                          | FK order_id (UQ)|
                          |    method (ENUM)|
                          |    amount       |
                          |    status (ENUM)|
                          |    paid_at      |
                          +---------------+

+-------------------+       +-------------------+
|   cart_items      |       | auto_approve_rules|
+-------------------+       +-------------------+
| PK user_id        |       | PK rule_id        |
| PK product_id     |       |    min_threshold  |
|    qty            |       |    max_threshold  |
+-------------------+       |    is_enabled     |
                            |    label          |
+-------------------+       |    approved_count |
|    messages       |       |    last_run_at    |
+-------------------+       |    created_at     |
| PK message_id     |       +-------------------+
|    name           |
|    phone          |
|    comment        |
|    is_read        |
|    received_at    |
+-------------------+

LEGEND:
  PK = Primary Key
  FK = Foreign Key
  UQ = Unique Constraint
  ---- = Relationship line
  ----> = Foreign key reference
```

### OLTP Table Specifications

| Table | Purpose | Key Constraints | Indexes |
|-------|---------|-----------------|---------|
| `users` | Customer & admin accounts | PK: user_id, UQ: email | - |
| `products` | Product catalog | PK: product_id, UQ: sku | idx_products_active, idx_products_category |
| `orders` | Order headers | PK: order_id, FK: user_id | idx_orders_status, idx_orders_ordered_at, idx_orders_user |
| `order_items` | Order line items | PK: order_item_id, FK: order_id, product_id | idx_items_order, idx_items_product |
| `payments` | Payment records | PK: payment_id, UQ: order_id, FK: order_id | idx_payments_status, idx_payments_paid_at |
| `cart_items` | Shopping cart (DB-backed) | PK: user_id + product_id | - |
| `messages` | Contact form submissions | PK: message_id | - |
| `etl_runs` | ETL audit log | PK: etl_run_id | - |
| `auto_approve_rules` | Auto-approval tier rules | PK: rule_id, UQ: max_threshold | - |

### Foreign Key Constraints

| Constraint | Parent Table | Child Table | On Delete | On Update |
|------------|-------------|-------------|-----------|-----------|
| `fk_orders_user` | `users` | `orders` | SET NULL | CASCADE |
| `fk_items_order` | `orders` | `order_items` | CASCADE | CASCADE |
| `fk_items_product` | `products` | `order_items` | - | CASCADE |
| `fk_payments_order` | `orders` | `payments` | CASCADE | CASCADE |

### Normalization Analysis

**1NF (First Normal Form):**
- All columns contain atomic values (no arrays/sets in single columns)
- Each row is unique (PK exists on every table)

**2NF (Second Normal Form):**
- No partial dependencies (all non-key attributes depend on full PK)
- `order_items` separates quantity/price from `orders` header

**3NF (Third Normal Form):**
- No transitive dependencies:
  - `orders` does not store customer name (fetched from `users`)
  - `order_items` does not store product name (fetched from `products`)
  - `payments` does not store customer info (linked via `order_id`)
- `line_total` is a calculated field but stored for performance (denormalization acceptable for OLTP)

---

## 3. OLAP Star Schema

```
                         +-----------------+
                         |  olap_dim_date  |
                         +-----------------+
                         | PK date_key     |
                         |    full_date    |
                         |    year         |
                         |    quarter      |
                         |    month        |
                         |    month_name   |
                         |    day          |
                         +--------+--------+
                                  |
                                  |
    +-----------------+           |           +-----------------+
    | olap_dim_product|           |           |olap_dim_customer|
    +-----------------+           |           +-----------------+
    | PK product_key  |           |           | PK customer_key |
    |    product_id   |           |           |    user_id (UQ) |
    |    sku          |           |           |    full_name    |
    |    name         |           |           |    email        |
    |    category     |           |           +-----------------+
    |    is_active    |           |
    +--------+--------+           |
             |                     |
             |    +----------------+----------------+
             |    |      olap_fact_sales            |
             |    +---------------------------------+
             |    | PK sales_id                     |
             +----| FK date_key ---------------------|
                  | FK product_key -----------------|
                  | FK customer_key ----------------|
                  | FK payment_method_key ----------|
                  |    order_id                       |
                  |    quantity (MEASURE)             |
                  |    gross_amount (MEASURE)         |
                  |    payment_status                 |
                  |    order_status                   |
                  +---------------------------------+
                                  ^
                                  |
                         +--------+--------+
                         |olap_dim_payment_|
                         |    method       |
                         +-----------------+
                         | PK payment_     |
                         |    method_key   |
                         |    method (UQ)  |
                         +-----------------+

STAR SCHEMA CHARACTERISTICS:
  - Single fact table at center
  - Four dimension tables surrounding
  - No snowflaking (dimensions are flat)
  - Surrogate keys used for all dimensions
  - Natural keys (user_id, product_id) preserved for traceability
```

### OLAP Table Specifications

| Table | Type | Purpose | Grain |
|-------|------|---------|-------|
| `olap_fact_sales` | Fact | Sales transactions | One row per order line item per day |
| `olap_dim_date` | Dimension | Time hierarchy | Calendar dates with Y-Q-M-D breakdown |
| `olap_dim_product` | Dimension | Product attributes | Current product catalog snapshot |
| `olap_dim_customer` | Dimension | Customer demographics | Registered users |
| `olap_dim_payment_method` | Dimension | Payment types | CASH, GCASH, CARD |

### OLAP Indexes

| Table | Index Name | Columns | Purpose |
|-------|-----------|---------|---------|
| `olap_dim_date` | `idx_dim_date_year_month` | year, month | Fast monthly rollup queries |
| `olap_fact_sales` | `idx_fact_date` | date_key | Fast date filtering |
| `olap_fact_sales` | `idx_fact_product` | product_key | Fast product slicing |
| `olap_fact_sales` | `idx_fact_method` | payment_method_key | Fast payment method slicing |
| `olap_fact_sales` | `idx_fact_customer` | customer_key | Fast customer filtering |

### Surrogate Key Strategy

| Dimension | Surrogate Key | Natural Key | Rationale |
|-----------|--------------|-------------|-----------|
| Date | `date_key` (INT, YYYYMMDD) | `full_date` | Fast integer joins, supports date arithmetic |
| Product | `product_key` (AUTO_INCREMENT) | `product_id` | Decouples from OLTP changes, supports SCD |
| Customer | `customer_key` (AUTO_INCREMENT) | `user_id` | Decouples from OLTP changes, supports SCD |
| Payment | `payment_method_key` (AUTO_INCREMENT) | `method` | Small dimension, surrogate for consistency |

### OLAP Foreign Key Constraints

| Constraint | Parent Table | Child Table |
|------------|-------------|-------------|
| `fk_fact_date` | `olap_dim_date` | `olap_fact_sales` |
| `fk_fact_product` | `olap_dim_product` | `olap_fact_sales` |
| `fk_fact_customer` | `olap_dim_customer` | `olap_fact_sales` |
| `fk_fact_method` | `olap_dim_payment_method` | `olap_fact_sales` |

---

## 4. ETL Pipeline Specification

### Pipeline Flow

```
+-------------+     +-------------+     +-------------+     +-------------+
|   EXTRACT   |---->|  TRANSFORM  |---->|    LOAD     |---->|   AUDIT     |
|             |     |             |     |             |     |             |
| - users     |     | - Surrogate |     | - UPSERT    |     | - Log start |
| - products  |     |   key mapping|    |   dimensions|     | - Log end   |
| - orders    |     | - Date dim  |     | - TRUNCATE  |     | - Row count |
| - payments  |     |   generation|     |   + INSERT  |     | - Status    |
| - order_items|    | - Aggregation|    |   fact table|     | - Errors    |
+-------------+     +-------------+     +-------------+     +-------------+
```

### ETL Step Details

**Step 1: Extract**
```sql
-- Source tables queried:
SELECT * FROM products
SELECT * FROM users
SELECT DISTINCT DATE(ordered_at) FROM orders
SELECT * FROM orders o
JOIN order_items oi ON oi.order_id = o.order_id
LEFT JOIN payments p ON p.order_id = o.order_id
```

**Step 2: Transform**
```sql
-- Dimension: Date
INSERT INTO olap_dim_date (date_key, full_date, year, quarter, month, month_name, day)
VALUES (20260606, '2026-06-06', 2026, 2, 6, 'June', 6);

-- Dimension: Product (UPSERT)
INSERT INTO olap_dim_product (product_id, sku, name, category, is_active)
VALUES (1, 'SODA-LIME', 'Lime Boost', 'Soda', 1)
ON DUPLICATE KEY UPDATE sku=VALUES(sku), name=VALUES(name);

-- Fact: Sales (aggregate line items)
INSERT INTO olap_fact_sales (date_key, product_key, customer_key, payment_method_key, ...)
SELECT CAST(DATE_FORMAT(o.ordered_at,'%Y%m%d') AS UNSIGNED),
       dp.product_key, dc.customer_key, dpm.payment_method_key,
       oi.quantity, oi.line_total, COALESCE(p.status, 'UNPAID'), o.status
FROM orders o
JOIN order_items oi ON oi.order_id = o.order_id
LEFT JOIN payments p ON p.order_id = o.order_id
JOIN olap_dim_product dp ON dp.product_id = oi.product_id
JOIN olap_dim_customer dc ON dc.user_id = o.user_id
JOIN olap_dim_payment_method dpm ON dpm.method = COALESCE(p.method, 'CASH');
```

**Step 3: Load**
- Transaction wrapped: `BEGIN` -> `DELETE FROM olap_fact_sales` -> `INSERT ...` -> `COMMIT`
- Ensures atomicity: either all data loaded or none

**Step 4: Audit**
```sql
INSERT INTO etl_runs (started_at, status) VALUES (NOW(), 'RUNNING');
-- ... ETL execution ...
UPDATE etl_runs SET finished_at=NOW(), status='SUCCESS', rows_inserted=79 WHERE etl_run_id=1;
```

---

## 5. OLAP Query Specifications

### 5.1 Roll-up: Monthly Revenue Aggregation

**Operation:** Aggregate sales data up the time hierarchy (Day -> Month -> Year)

**SQL Query:**
```sql
SELECT 
    dd.year,
    dd.month,
    dd.month_name,
    SUM(f.gross_amount) AS revenue,
    SUM(f.quantity) AS qty
FROM olap_fact_sales f
JOIN olap_dim_date dd ON dd.date_key = f.date_key
WHERE f.payment_status = 'PAID'
GROUP BY dd.year, dd.month, dd.month_name
ORDER BY dd.year, dd.month;
```

**Business Purpose:** View monthly revenue trends for the last 12 months
**Optimization:** Index on `olap_dim_date(year, month)` and `olap_fact_sales(date_key)`

---

### 5.2 Drill-down: Daily Revenue Breakdown

**Operation:** Navigate from summarized monthly data to detailed daily data

**SQL Query:**
```sql
SELECT 
    dd.full_date,
    SUM(f.gross_amount) AS revenue,
    SUM(f.quantity) AS qty
FROM olap_fact_sales f
JOIN olap_dim_date dd ON dd.date_key = f.date_key
WHERE f.payment_status = 'PAID'
  AND DATE_FORMAT(dd.full_date, '%Y-%m') = '2026-06'
GROUP BY dd.full_date
ORDER BY dd.full_date;
```

**Business Purpose:** Identify which days in a specific month had highest/lowest sales
**Parameter:** `ym` (YYYY-MM format)
**Optimization:** Uses `date_key` integer comparison for fast filtering

---

### 5.3 Slice: Filter by Payment Method

**Operation:** Filter data on a single dimension (payment method)

**SQL Query:**
```sql
SELECT 
    dp.name AS product,
    SUM(f.gross_amount) AS revenue,
    SUM(f.quantity) AS qty
FROM olap_fact_sales f
JOIN olap_dim_product dp ON dp.product_key = f.product_key
JOIN olap_dim_payment_method pm ON pm.payment_method_key = f.payment_method_key
WHERE f.payment_status = 'PAID'
  AND pm.method = 'GCASH'
GROUP BY dp.name
ORDER BY revenue DESC;
```

**Business Purpose:** Analyze which products are popular with specific payment methods
**Parameter:** `method` (CASH, GCASH, CARD)
**Optimization:** Index on `olap_fact_sales(payment_method_key)`

---

### 5.4 Dice: Multi-Dimensional Sub-cube

**Operation:** Define a sub-cube by filtering on multiple dimensions simultaneously

**SQL Query:**
```sql
SELECT 
    dd.full_date,
    dp.name AS product,
    dp.category,
    SUM(f.quantity) AS qty,
    SUM(f.gross_amount) AS revenue
FROM olap_fact_sales f
JOIN olap_dim_date dd ON dd.date_key = f.date_key
JOIN olap_dim_product dp ON dp.product_key = f.product_key
JOIN olap_dim_payment_method pm ON pm.payment_method_key = f.payment_method_key
WHERE f.payment_status = 'PAID'
  AND dd.full_date BETWEEN '2026-05-01' AND '2026-05-31'
  AND dp.category = 'Soda'
  AND pm.method = 'CASH'
GROUP BY dd.full_date, dp.name, dp.category
ORDER BY dd.full_date, revenue DESC;
```

**Business Purpose:** Analyze specific market segments (e.g., "Soda category, Cash payments, May 2026")
**Parameters:** `from`, `to`, `category`, `method`
**Optimization:** Composite filtering on multiple dimension keys

---

## 6. Decision Support System (Auto-Approval Engine)

### 6.1 Auto-Approve Rules Table

| Column | Type | Description |
|--------|------|-------------|
| `rule_id` | INT PK | Unique rule identifier |
| `min_threshold` | DECIMAL(10,2) | Minimum order amount (exclusive) |
| `max_threshold` | DECIMAL(10,2) | Maximum order amount (inclusive) |
| `is_enabled` | TINYINT(1) | Whether this rule is active (default: 0) |
| `label` | VARCHAR(60) | Human-readable tier description |
| `approved_count` | INT | Lifetime count of auto-approved orders |
| `last_run_at` | DATETIME | Last time this rule triggered |
| `created_at` | DATETIME | Rule creation timestamp |

### 6.2 Pre-Configured Tiers (Seeded on First Visit)

| Tier | Min | Max | Label | Default Status |
|------|-----|-----|-------|---------------|
| 1 | 0.00 | 500.00 | Up to 500 | Disabled |
| 2 | 500.00 | 1000.00 | Up to 1,000 | Disabled |
| 3 | 1000.00 | 2000.00 | Up to 2,000 | Disabled |
| 4 | 2000.00 | 5000.00 | Up to 5,000 | Disabled |
| 5 | 5000.00 | 999999.99 | 5,001 & above | Disabled |

### 6.3 Auto-Approval Flow

```
+-----------------+
|  Order Created  |
|  Status: PAID   |
+--------+--------+
         |
         v
+-------------------------+
| Check enabled rules     |
| WHERE min < total       |
|   AND max >= total      |
+---------+---------------+
          |
     +----+----+
     | Match?  |
     +----+----+
   Yes /    \ No
      /      \
     v        v
+----------+ +----------+
| INSTANT  | | PENDING  |
| APPROVE  | | (Admin   |
| Order    | | manual)  |
+----------+ +----------+
```

---

## 7. API Endpoint Specification

| Endpoint | Method | Auth | Parameters | Response | Description |
|----------|--------|------|------------|----------|-------------|
| `analytics_data.php?op=rollup_month` | GET | Admin | - | JSON array | Monthly revenue roll-up |
| `analytics_data.php?op=drilldown_day` | GET | Admin | `ym` | JSON array | Daily drill-down |
| `analytics_data.php?op=slice_method` | GET | Admin | `method` | JSON array | Slice by payment |
| `analytics_data.php?op=dice` | GET | Admin | `from`, `to`, `category`, `method` | JSON array | Multi-filter dice |
| `export_csv.php` | GET | Admin | `from`, `to`, `category`, `method` | CSV file | CSV export |
| `export_excel.php` | GET | Admin | `from`, `to`, `category`, `method` | XLS file | Excel export |
| `export_pdf.php` | GET | Admin | `from`, `to`, `category`, `method` | PDF file | PDF export |

**Authentication:** All API endpoints require `require_admin()` session check
**Error Handling:** Returns HTTP 400 with JSON `{"error": "message"}` for invalid parameters
**Fallback:** If OLAP tables are empty, queries fall back to OLTP tables automatically

---

## 8. Security Architecture

```
+-----------------------------------------+
|           SECURITY LAYERS               |
+-----------------------------------------+
|  Layer 1: Input Validation              |
|  - htmlspecialchars() on all output       |
|  - regex validation on dates            |
|  - ENUM checks on payment methods       |
|  - File type validation on uploads      |
+-----------------------------------------+
|  Layer 2: SQL Injection Prevention      |
|  - PDO prepared statements (ALL queries)|
|  - ATTR_EMULATE_PREPARES => false       |
|  - No string concatenation in SQL       |
+-----------------------------------------+
|  Layer 3: Authentication                |
|  - password_hash() with bcrypt          |
|  - password_verify() on login           |
|  - Session-based auth with httponly     |
|  - Role-based access (ADMIN/CUSTOMER)   |
+-----------------------------------------+
|  Layer 4: Authorization               |
|  - require_admin() on all admin pages   |
|  - require_login() on customer pages    |
|  - Redirect to login on unauthorized    |
+-----------------------------------------+
|  Layer 5: Data Integrity                |
|  - Foreign key constraints              |
|  - Transaction wrapping (ACID)          |
|  - FOR UPDATE stock locking             |
|  - Cascading deletes where appropriate  |
+-----------------------------------------+
|  Layer 6: Environment Protection          |
|  - .env stored outside web root         |
|  - .htaccess blocks .env access         |
|  - .gitignore prevents commit           |
|  - No hardcoded credentials in code     |
+-----------------------------------------+
```

---

## 9. Technology Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Backend | PHP | 8.0+ |
| Database | MySQL/MariaDB | 5.7+ / 10.3+ |
| Web Server | Apache/Nginx | 2.4+ / 1.18+ |
| Frontend | HTML5, CSS3, JavaScript | - |
| Charts | Chart.js | 3.x+ |
| PDF Engine | Custom TinyPDF | Zero dependencies |
| Excel Engine | SpreadsheetML XML | 2003 format |

---

## 10. File Reference Map

### Admin Panel (`admin/`)
| File | Purpose |
|------|---------|
| `_nav.php` | Shared sidebar navigation with badge counts |
| `admin.css` | Design system with CSS variables, components, animations |
| `analytics.php` | Chart.js dashboard: rollup, drilldown, slice, dice, export controls |
| `dashboard.php` | KPI cards, recent orders table, status pills |
| `decisions.php` | Auto-approval rules engine, recommendations, best sellers |
| `etl_sync.php` | ETL pipeline trigger, step visualization, audit log |
| `login.php` | Admin login with gradient background |
| `logout.php` | Session destroy + redirect |
| `messages.php` | Contact form submissions with read/unread status |
| `orders.php` | Order status management with dropdown updates |
| `products.php` | Product CRUD with drag-drop image upload |

### API Endpoints (`api/`)
| File | Purpose |
|------|---------|
| `analytics_data.php` | JSON API for OLAP operations (rollup, drilldown, slice, dice) |
| `export_csv.php` | Streaming CSV with UTF-8 BOM, metadata, totals |
| `export_excel.php` | SpreadsheetML XML with styled headers, alternating rows |
| `export_pdf.php` | Custom TinyPDF engine with two-page summary + detail layout |

### Customer Pages (`customer/`)
| File | Purpose |
|------|---------|
| `login.php` | Customer login with redirect to shop |
| `logout.php` | Session destroy + redirect to homepage |
| `orders.php` | Customer order history with payment details |
| `register.php` | Account creation with validation |

### Public Pages (`public/`)
| File | Purpose |
|------|---------|
| `account.php` | Customer profile, password change, order history cards |
| `add_to_cart.php` | AJAX endpoint for cart addition (POST only) |
| `cart.php` | Selective checkout with quantity controls, stock warnings |
| `checkout.php` | Transactional checkout with stock locking, auto-approval |
| `index.php` | Landing page with product modal, contact form, scroll animations |
| `place_order.php` | Guest checkout handler (legacy direct order flow) |
| `update_cart.php` | AJAX endpoint for cart quantity updates/removal |

### Core Libraries (`includes/`)
| File | Purpose |
|------|---------|
| `auth.php` | Session management, role guards, login/logout helpers |
| `cart.php` | DB-backed cart with session fallback, merge on login |
| `config.php` | .env parser with validation, root user prevention |
| `db.php` | Singleton PDO with prepared statements, error handling |
| `helpers.php` | `e()` (htmlspecialchars), `money()` (number_format) |

### Assets (`public/assets/`)
| File | Purpose |
|------|---------|
| `app.css` | Utility styles for admin cards and forms (legacy) |
| `app.js` | Global confirmation dialogs for destructive actions |
| `cart.css` | Cart page specific styles (legacy, mostly in style.css now) |
| `scroll-animations.js` | IntersectionObserver animations, parallax, progress bar |
| `style.css` | Main design system: tokens, nav, hero, bento, modal, cart, auth |
