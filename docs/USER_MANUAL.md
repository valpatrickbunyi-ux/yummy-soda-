# Yummy Soda — User Manual

## Table of Contents
1. [System Overview](#system-overview)
2. [Customer Workflow](#customer-workflow)
3. [Admin Workflow](#admin-workflow)
4. [Analytics & Reporting](#analytics--reporting)
5. [ETL Pipeline](#etl-pipeline)
6. [Decision Support](#decision-support)

---

## System Overview

Yummy Soda is a hybrid **OLTP (Online Transaction Processing)** and **OLAP (Online Analytical Processing)** e-commerce system. It handles daily sales operations while providing business intelligence through multidimensional analytics.

### Architecture Diagram

```
+-----------------------------------------------------------------------------+
|                           YUMMY SODA SYSTEM                                   |
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
|   +------+------+     +--------+--------+    +------+------+              |
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
|   DATA FLOW:  OLTP -> ETL -> OLAP -> Analytics API -> Dashboard/Exports     |
|                                                                             |
+-----------------------------------------------------------------------------+
```

---

## Customer Workflow

### 1. Browse Products

**Path:** `public/index.php` → Scroll to "Choose Your Flavor" section

- View product cards with images, names, and prices
- Click any product button to open the **Product Modal**

**Product Modal Features:**
- Product image (uploaded or static PNG)
- Description and price
- Quantity selector (+/- buttons or direct input)
- **Add to Cart** button
- **Buy Now** button (scrolls to order section)
- Out-of-stock indicator with disabled button

**Current Product Catalog (from seed data):**
| Product | Price | Stock | Status |
|---------|-------|-------|--------|
| Lime Boost | ₱49.00 | 9,812 | Active |
| Strawberry Boost | ₱49.00 | 0 | Active (Out of Stock) |
| Orange Boost | ₱49.00 | 9,381 | Active |

### 2. Add to Cart

1. In product modal, select quantity (default: 1)
2. Click **Add to Cart**
3. Green confirmation message appears
4. Cart persists in database (logged-in users) or session (guests)

**Cart Features:**
- Quantity adjustment with +/- buttons
- Auto-save quantity after 800ms of inactivity
- Remove items (trash button)
- Low stock warnings ("Only X left!")
- Selective checkout (checkbox per item)
- Real-time total calculation for selected items
- Select All / Deselect All functionality

### 3. Checkout

1. Navigate to **Cart** page (`public/cart.php`)
2. Select items to purchase via checkboxes
3. Select **Payment Method**:
   - Cash on Delivery
   - GCash
   - Card
4. Click **Checkout →**
5. System validates stock with `FOR UPDATE` locking
6. Order created with `PENDING` status
7. If order total matches an enabled auto-approve rule → instantly `PAID`
8. Otherwise stays `PENDING` until admin approval
9. Success banner shows order reference (e.g., `YS-00001`)

**Order Confirmation:**
- Order reference number displayed
- Items, quantities, and total shown
- Payment method recorded
- One-time checkout token prevents double-submit

### 4. View Order History

**Path:** `customer/orders.php` or `public/account.php`

- List of all past orders
- Order ID, date, amount, payment method, status
- Status colors: Pending | Paid | Cancelled
- Expandable order cards with item breakdown on Account page

### 5. Account Management

**Path:** `public/account.php`

**Profile Card:**
- Avatar with initial
- Full name and email
- Role badge (Customer)

**Statistics:**
- Total Orders count
- Total Spent (₱)

**Order History Cards:**
- Expandable order details
- Item breakdown (product, quantity, line total)
- Payment method and order total
- Status badge with color coding
- Per-user sequential order numbers (YS-00001, YS-00002, etc.)

**Password Change:**
1. Click **Change Password**
2. Enter current password
3. Enter new password (strength meter shows Weak → Very Strong)
4. Confirm new password
5. Click **Update Password**

---

## Admin Workflow

### 1. Admin Login

**Path:** `admin/login.php`

- Email and password authentication
- Role verification (must be `ADMIN`)
- Failed login shows error message
- Successful login redirects to Dashboard

**Default Admin Account (from SQL seed):**
- Email: `admin123@gmail.com`
- Password: `password`
- Name: `admin123`

**Note:** Change this password immediately after first login for security.

### 2. Dashboard (`admin/dashboard.php`)

**KPI Cards:**
| Metric | Icon | Description |
|--------|------|-------------|
| Total Orders | Package | Count of all orders |
| Revenue | Money bag | Sum of paid payments |
| Customers | People | Count of customer accounts |
| Products | Beverage | Count of products in catalog |
| Low Stock | Warning | Products with < 20 units |

**Recent Orders Table:**
- Order #, Customer, Date, Amount, Status
- Status pills: Paid | Pending | Cancelled
- Click **View All →** to go to Orders page

### 3. Product Management (`admin/products.php`)

**Add New Product:**
1. Click **+ Add Product** button
2. Fill form:
   - SKU (e.g., `SODA-LIME`)
   - Product Name (e.g., `Lime Boost`)
   - Category (e.g., `Soda`)
   - Price (₱)
   - Stock Quantity
   - Active checkbox
   - Product Image (drag & drop or click to upload)
3. Click **Save Product**

**Edit Product:**
1. Click **Edit** on any product row
2. Form pre-fills with current data
3. Upload new image to replace existing
4. Click **Save Product**

**Delete Product:**
1. Click **Trash** button
2. Confirm deletion
3. Image file also removed from server

**Product Table Features:**
- Thumbnail images
- Stock status badges (green >20 | red <20)
- Active/Inactive status pills

### 4. Order Management (`admin/orders.php`)

**Quick Stats:**
- Pending: Hourglass Count
- Paid: Checkmark Count
- Cancelled: X Count

**Order Actions:**
1. View last 50 orders in table
2. Update status via dropdown:
   - `PENDING` → `PAID` → `CANCELLED`
3. Click **Update** to save
4. Success message appears

**Export Options:**
- Export CSV
- Export Excel

### 5. Messages (`admin/messages.php`)

**Customer Inquiry Management:**
- View all contact form submissions
- Unread messages highlighted with amber border
- **Mark Read** button
- **Delete** button
- **Mark All Read** topbar action
- Unread count badge in sidebar

---

## Analytics & Reporting

### Analytics Dashboard (`admin/analytics.php`)

**Monthly Revenue Roll-up (Line Chart)**
- Shows last 12 months of revenue
- Interactive hover tooltips
- Auto-loads on page open
- Teal gradient fill with smooth curves

**Daily Drill-down (Bar Chart)**
1. Select month from **Pick Month** dropdown (default: current month)
2. Click **Load Days**
3. Bar chart shows daily revenue for selected month
- Amber bars with rounded corners

**Revenue by Payment Method (Doughnut Chart)**
1. Select payment method: `CASH`, `GCASH`, or `CARD`
2. Click **Load Slice**
3. Doughnut chart shows revenue distribution by product
4. Custom legend with percentages and color dots
- 62% center cutout with method label

**Dice — Custom Date Range Export**
1. Set **From** and **To** dates
2. Optionally filter by **Category** (e.g., `Soda`)
3. Optionally filter by **Payment Method**
4. Click export format:
   - **Export CSV** — Opens download with filtered data
   - **Export Excel** — Opens `.xls` with styled formatting
   - **Export PDF** — Opens professional PDF report
- Glowing teal/amber buttons for visual emphasis

### Export Features

**CSV Export (`api/export_csv.php`)**
- UTF-8 BOM for Excel compatibility
- Headers: Date, Product, Category, Payment Method, Qty, Revenue
- Metadata rows: Generated At, Period, filters applied
- Totals row at bottom
- Grand totals for quantity and revenue

**Excel Export (`api/export_excel.php`)**
- SpreadsheetML XML format (no PHP extensions required)
- Styled headers (teal background, white text)
- Alternating row colors (even rows highlighted)
- Number formatting with right alignment
- Totals row with amber highlight
- Column widths optimized for readability

**PDF Export (`api/export_pdf.php`)**
- Custom TinyPDF engine (zero dependencies)
- Two-page layout:
  - Page 1: Summary (KPIs, payment method breakdown, top 5 products)
  - Page 2+: Detailed data table with auto-pagination
- Professional formatting with colors and borders
- Header repeat on continuation pages
- Clipping paths prevent text overflow
- Real Helvetica glyph-width table for accurate text measurement

---

## ETL Pipeline

### ETL Sync Page (`admin/etl_sync.php`)

**Purpose:** Synchronize transactional data to analytical warehouse

**Steps:**
1. Navigate to **Tools → Run ETL**
2. Review pipeline steps:
   - Step 1: Update product, customer & payment dimensions
   - Step 2: Build date dimension from order history
   - Step 3: Clear and reload sales fact table (in transaction)
   - Step 4: Log run result & redirect to Analytics
3. Click **Run ETL Now**
4. Wait for completion (redirects to Analytics)

**Recent ETL Runs Table:**
- Run ID, Started time, Status (Success | Failed | Running)
- Rows inserted count
- Error messages for failed runs

**When to Run ETL:**
- After importing new orders
- After bulk product updates
- Before generating analytics reports
- Recommended: Daily or after significant transaction volume

**Bug Fixes in ETL:**
- Single transaction wraps ALL steps (prevents partial commits)
- LEFT JOIN on payments includes orders with no payment row
- Success status logged BEFORE redirect (prevents stuck "RUNNING" status)

---

## Decision Support (`admin/decisions.php`)

### Auto-Approve Engine

**Rule-Based Tier System:**
| Tier | Range | Default Status |
|------|-------|---------------|
| 1 | ₱0 – ₱500 | Disabled |
| 2 | ₱501 – ₱1,000 | Disabled |
| 3 | ₱1,001 – ₱2,000 | Disabled |
| 4 | ₱2,001 – ₱5,000 | Disabled |
| 5 | ₱5,001+ | Disabled |

**Rule Card Features:**
- Threshold display (e.g., "₱500" or "₱5,000+")
- Pending order count in tier
- Lifetime approved count
- Last run timestamp
- Enable/Disable toggle button
- Reset counter button (resets lifetime count to 0)

**Auto-Approval Behavior:**
- When a tier is **enabled**, any new order whose total falls within that range is confirmed automatically at checkout
- When enabling a rule, existing pending orders in that range are instantly backfilled to PAID
- Rules are evaluated at checkout time via `checkout.php`

### Rule-Based Recommendations

| Alert Type | Trigger | Suggested Action |
|------------|---------|------------------|
| Warning | Stock < 20 units | "Restock [Product] — only X left" |
| Success | Top seller identified | "Feature in promotions" |
| Danger | Revenue drop > 10% | "Review pricing" |
| Success | Revenue growth > 10% | "Expand marketing" |
| Info | Stable trend | "Monitor closely" + projection |

### Top Selling Products
- Ranked table with medal emojis (1st, 2nd, 3rd)
- Units sold and revenue per product
- SKU badges with monospace font
- Ranked by total quantity sold

---

## API Endpoints Reference

| Endpoint | Method | Auth | Parameters | Description |
|----------|--------|------|------------|-------------|
| `/api/analytics_data.php?op=rollup_month` | GET | Admin | - | Monthly revenue roll-up |
| `/api/analytics_data.php?op=drilldown_day&ym=YYYY-MM` | GET | Admin | `ym` | Daily drill-down |
| `/api/analytics_data.php?op=slice_method&method=X` | GET | Admin | `method` | Slice by payment method |
| `/api/analytics_data.php?op=dice&from=&to=&category=&method=` | GET | Admin | `from`, `to`, `category`, `method` | Dice with multi-filter |
| `/api/export_csv.php` | GET | Admin | `from`, `to`, `category`, `method` | CSV export |
| `/api/export_excel.php` | GET | Admin | `from`, `to`, `category`, `method` | Excel export |
| `/api/export_pdf.php` | GET | Admin | `from`, `to`, `category`, `method` | PDF export |

---

## Troubleshooting Common Issues

| Symptom | Cause | Solution |
|---------|-------|----------|
| Analytics charts empty | ETL not run | Go to Admin → Tools → Run ETL |
| "No data" in exports | Date range has no orders | Widen date range or run ETL |
| Images not showing | Missing `image_path` or file | Check `public/assets/images/` permissions |
| Cart empty after login | Session cart not merged | Logout and login again |
| "Product not available" | `is_active=0` or stock=0 | Check product status in admin |
| Order status stuck | Payment not recorded | Check `payments` table in database |
| Auto-approve not working | Rule not enabled | Go to Decisions page and enable the tier |
| "Invalid checkout session" | Token expired or reused | Refresh cart page and try again |
| Contact form not sending | AJAX error | Check browser console for network errors |
| "Strawberry Boost out of stock" | stock_qty=0 in database | Restock via admin products page |
| Admin login fails | Wrong credentials | Use `admin123@gmail.com` / `password` from seed |
| No customer test account | SQL only seeds admin | Register via customer page or SQL insert |

---

## Security Notes

- All passwords hashed with `bcrypt` (PASSWORD_DEFAULT)
- All database queries use PDO prepared statements
- Role-based access control on all admin pages
- Input sanitized with `htmlspecialchars()`
- `.env` file protected by `.htaccess` (403 Forbidden)
- File uploads restricted to images (JPG, PNG, WebP, GIF)
- Session cookies use `httponly` flag
- Checkout tokens prevent double-submit attacks
- Stock locking with `FOR UPDATE` prevents overselling
- Foreign key constraints with CASCADE/SET NULL for data integrity
