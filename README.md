# Yummy Soda - Hybrid OLTP & OLAP System

## Overview

Yummy Soda is a full-stack PHP e-commerce system with a hybrid transactional and analytical architecture. It handles daily sales operations via **OLTP (Online Transaction Processing)** and powers business intelligence via **OLAP (Online Analytical Processing)** using a Star Schema.

---

## Tech Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Backend | PHP | 8.0+ |
| Database | MySQL / MariaDB | 5.7+ / 10.3+ |
| Web Server | Apache / Nginx | 2.4+ / 1.18+ |
| Frontend | HTML5, CSS3, JavaScript | - |
| Charts | Chart.js | 3.x+ |
| PDF Engine | Custom TinyPDF | Zero dependencies |
| Excel Engine | SpreadsheetML XML | 2003 format |

---

## System Architecture

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
|   +-------------+     +-----------------+    |   dim       |              |
|                                                | - Customer  |              |
|                                                |   dim       |              |
|                                                | - Payment   |              |
|                                                |   dim       |              |
|                                                +-------------+              |
|                                                                             |
|   DATA FLOW:  OLTP -> ETL -> OLAP -> Analytics API -> Dashboard/Exports       |
|                                                                             |
+-----------------------------------------------------------------------------+
```

---

## Folder Structure

```
yummy-soda/
├── admin/              # Admin dashboard, ETL, analytics, product/order CRUD
│   ├── _nav.php        # Shared sidebar navigation component
│   ├── admin.css       # Admin panel design system stylesheet
│   ├── analytics.php   # Analytics dashboard with charts
│   ├── dashboard.php     # Admin dashboard with KPIs and recent orders
│   ├── decisions.php     # Decision support system with auto-approval rules
│   ├── etl_sync.php      # ETL pipeline trigger and audit log viewer
│   ├── login.php         # Admin login page
│   ├── logout.php        # Admin logout handler
│   ├── messages.php      # Customer message/inquiry management
│   ├── orders.php        # Order management with status updates
│   └── products.php      # Product CRUD with image upload
│
├── api/                # RESTful data endpoints (JSON + CSV + Excel + PDF)
│   ├── analytics_data.php # OLAP data API (rollup, drilldown, slice, dice)
│   ├── export_csv.php     # CSV export with filters
│   ├── export_excel.php   # Excel 2003 XML export with styling
│   └── export_pdf.php     # Zero-dependency PDF report generation
│
├── customer/           # Customer authentication and order history
│   ├── login.php         # Customer login page
│   ├── logout.php        # Customer logout handler
│   ├── orders.php        # Customer order history view
│   └── register.php      # Customer registration page
│
├── docs/               # Technical and user documentation
│   ├── ARCHITECTURE.md   # System architecture, database diagrams, ETL specs
│   ├── INSTALLATION.md   # Step-by-step setup guide
│   ├── README.md         # This file — project overview
│   └── USER_MANUAL.md    # Complete user workflow guide
│
├── includes/           # Core PHP libraries
│   ├── auth.php        # Session & role management (ADMIN/CUSTOMER)
│   ├── cart.php        # DB-backed cart with session fallback for guests
│   ├── config.php      # Environment configuration (reads .env)
│   ├── db.php          # PDO connection pool with prepared statements
│   └── helpers.php     # e(), money() utility functions
│
├── public/             # Landing page, cart, checkout, customer assets
│   ├── account.php       # Customer account page with password change
│   ├── add_to_cart.php   # AJAX endpoint for adding items to cart
│   ├── cart.php          # Shopping cart with selective checkout
│   ├── checkout.php      # Checkout handler with stock locking & auto-approval
│   ├── index.php         # Landing page with product modal & contact form
│   ├── place_order.php   # Guest checkout handler (legacy/direct orders)
│   ├── update_cart.php   # AJAX endpoint for cart quantity updates
│   └── assets/           # CSS, JS, images
│       ├── app.css       # Admin/app utility styles (legacy)
│       ├── app.js        # Global confirmation dialogs
│       ├── cart.css      # Cart page specific styles (legacy)
│       ├── scroll-animations.js  # Scroll-triggered animations & parallax
│       └── style.css     # Main design system with tokens & components
│
├── sql/                # Database scripts
│   └── yummy_soda.sql  # Complete schema + seed data
│
├── .env                # Environment variables (NOT in version control)
├── .env.example        # Template for .env configuration
├── .gitignore          # Prevents .env and other sensitive files from being committed
└── .htaccess           # Apache access rules (blocks sensitive files, enables rewrites)
```

---

## Key Features

### OLTP (Transactional)
- Full CRUD for products, orders, messages
- ACID transactions with rollback mechanisms
- Shopping cart (DB-backed for users, session for guests)
- Guest checkout + registered user checkout
- Stock management with `FOR UPDATE` locking
- Contact form with admin message center
- Customer account page with password change
- Auto-approval engine for orders within configured thresholds

### OLAP (Analytical)
- Star Schema with 4 dimensions + 1 fact table
- ETL pipeline with audit logging (`etl_runs`)
- Roll-up, Drill-down, Slice, Dice operations
- Interactive Chart.js dashboard
- Export to CSV, Excel, PDF with professional formatting
- Decision support with rule-based recommendations
- Auto-approval queue with tier-based threshold rules

### Security
- PDO prepared statements (all queries parameterized)
- Password hashing with `bcrypt` (`password_hash` / `password_verify`)
- Role-based access control (`ADMIN` / `CUSTOMER`)
- Input validation and sanitization (`htmlspecialchars`)
- `.env` stored outside web root or protected by `.htaccess`
- Checkout token system to prevent double-submit
- File upload type validation (images only)

---

## Screenshots

### Admin Dashboard
*KPI cards showing total orders, revenue, customers, products, and low stock alerts. Recent orders table with status pills (Paid, Pending, Cancelled).*

### Analytics Dashboard - Roll-up & Drill-down
*Monthly Revenue Roll-up line chart showing 12-month trends. Daily Drill-down bar chart with month picker and Load Days button.*

### Analytics Dashboard - Slice & Dice
*Revenue by Payment Method doughnut chart with custom legend and percentages. Dice export controls with date range, category filter, and payment method filter.*

### ETL Sync Pipeline
*ETL pipeline steps (Extract, Transform, Load, Audit). Recent ETL Runs table showing run ID, timestamp, status (Success/Failed), and rows inserted.*

### Decision Support System
*Auto-approval rule cards with toggle switches, pending counts, and lifetime approved totals. Rule-based recommendations for stock alerts and revenue trends.*

---

## Quick Start

See [docs/INSTALLATION.md](docs/INSTALLATION.md) for detailed setup instructions.

### Prerequisites
- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+
- Apache 2.4+ with mod_rewrite or Nginx 1.18+

### 5-Minute Setup
```bash
# 1. Import database
mysql -u root -p < sql/yummy_soda.sql

# 2. Create .env from template
cp .env.example .env
# Edit .env with your database credentials

# 3. Ensure .env is protected
# Option A: Move .env outside web root (recommended)
# Option B: Use included .htaccess to block access

# 4. Access application
# Frontend: http://localhost/yummy-soda/public/index.php
# Admin:    http://localhost/yummy-soda/admin/login.php
```

---

## API Endpoints

| Endpoint | Method | Auth | Parameters | Description |
|----------|--------|------|------------|-------------|
| `api/analytics_data.php?op=rollup_month` | GET | Admin | - | Monthly revenue roll-up |
| `api/analytics_data.php?op=drilldown_day` | GET | Admin | `ym` | Daily drill-down |
| `api/analytics_data.php?op=slice_method` | GET | Admin | `method` | Slice by payment method |
| `api/analytics_data.php?op=dice` | GET | Admin | `from`, `to`, `category`, `method` | Multi-filter dice |
| `api/export_csv.php` | GET | Admin | `from`, `to`, `category`, `method` | CSV export |
| `api/export_excel.php` | GET | Admin | `from`, `to`, `category`, `method` | Excel export |
| `api/export_pdf.php` | GET | Admin | `from`, `to`, `category`, `method` | PDF export |

---

## Documentation

| Document | Purpose |
|----------|---------|
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Step-by-step setup guide with security notes |
| [docs/USER_MANUAL.md](docs/USER_MANUAL.md) | Complete user workflow guide for customers and admins |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | System architecture, database diagrams, ETL specs, SQL examples |

---

## License

Academic project for Cavite State University - Advanced Database Systems (DCIT 55B).
