# Installation Guide — Yummy Soda

## Prerequisites

| Requirement | Minimum Version | Recommended |
|-------------|----------------|-------------|
| PHP | 8.0 | 8.2+ |
| MySQL | 5.7 | 8.0+ or MariaDB 10.6+ |
| Web Server | Apache 2.4 with mod_rewrite | Nginx 1.20+ |
| Browser | Chrome 90+, Firefox 88+, Safari 14+ | Latest stable |

---

## Step 1: Extract Project Files

Extract the project archive to your web root directory:

**Windows (XAMPP):**
```
Extract to C:\xampp\htdocs\yummy-soda
```

**Linux/macOS:**
```bash
sudo unzip yummy-soda.zip -d /var/www/html/
```

**Expected folder structure:**
```
yummy-soda/
├── admin/              # Admin panel files
├── api/                # REST API endpoints
├── customer/           # Customer auth pages
├── docs/               # Documentation
├── includes/           # Core PHP libraries
├── public/             # Frontend & customer pages
│   └── assets/         # CSS, JS, images
├── sql/                # Database scripts
├── .env                # Environment variables (created in Step 3)
├── .env.example        # Environment template
├── .gitignore          # Git ignore rules
└── .htaccess           # Apache access rules (included in project)
```

---

## Step 2: Create Database & Dedicated User

### SECURITY WARNING
**Never use the root MySQL user.** Create a dedicated user with limited privileges.

### Using phpMyAdmin (Recommended for Windows)

1. Open `http://localhost/phpmyadmin`
2. Click **User accounts** tab → Click **Add user account**
3. Enter:
   - Username: `yummy_soda`
   - Host name: `localhost`
   - Password: `YummySoda2026!Secure` (or your own strong password)
   - Re-type password
4. Check **Create database with same name and grant all privileges**
5. Click **Go**

6. Select the `yummy_soda` database → Click **Import** tab
7. Choose `sql/yummy_soda.sql` → Click **Go**

### Using Command Line

```bash
# Log into MySQL as root
mysql -u root -p

# Create database
CREATE DATABASE yummy_soda CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Create dedicated user (use a strong password!)
CREATE USER 'yummy_soda'@'localhost' IDENTIFIED BY 'YummySoda2026!Secure';
GRANT ALL PRIVILEGES ON yummy_soda.* TO 'yummy_soda'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# Import schema and seed data
mysql -u yummy_soda -p yummy_soda < sql/yummy_soda.sql
```

**Verify import:** You should see 13+ tables created (including `auto_approve_rules` and `etl_runs`).

---

## Step 3: Configure Environment (CRITICAL)

The project includes a `.htaccess` file that blocks access to sensitive files. You have **two options** for `.env` placement:

### Option A: .env Inside Project Folder (Recommended — Uses Included .htaccess)

The project includes a `.htaccess` file that blocks access to `.env`, `.gitignore`, `.md`, `.sql`, and other sensitive files.

1. **Copy `.env.example` to `.env`** inside the `yummy-soda/` folder:
   ```bash
   cd /var/www/html/yummy-soda
   cp .env.example .env
   ```

2. **Edit `.env`** with your database credentials:
   ```bash
   DB_HOST=127.0.0.1
   DB_NAME=yummy_soda
   DB_USER=yummy_soda
   DB_PASS=YummySoda2026!Secure

   APP_NAME=Yummy Soda
   APP_URL=http://localhost/yummy-soda
   ```

3. **Verify `includes/config.php` path:**

   Open `yummy-soda/includes/config.php` and confirm line 3:
   ```php
   $envFile = __DIR__ . '/../../.env';
   ```

   This tells PHP to look **two levels up** from `includes/` (which is inside `yummy-soda/`), so it finds `.env` in `yummy-soda/`.

4. **The included `.htaccess` already protects `.env`:**
   ```apache
   <FilesMatch "^\.">
       Require all denied
   </FilesMatch>

   <FilesMatch "\.(env|gitignore|md|sql|log|bak|config|ini)$">
       Require all denied
   </FilesMatch>
   ```

### Option B: .env Outside Web Root (Advanced — Requires Manual Path Edit)

For maximum security, you can move `.env` outside the `yummy-soda/` folder:

**Windows:**
1. Create `.env` in `C:\xampp\htdocs\` (one level above `yummy-soda/`)
2. Edit `includes/config.php` line 3:
   ```php
   $envFile = __DIR__ . '/../../.env';  // This already works for htdocs/.env
   ```

**Linux/macOS:**
1. Create `.env` in `/var/www/html/` (one level above `yummy-soda/`):
   ```bash
   sudo nano /var/www/html/.env
   ```
2. Set secure permissions:
   ```bash
   sudo chmod 640 /var/www/html/.env
   sudo chown www-data:www-data /var/www/html/.env
   ```

---

## Step 4: Create Test Users (Optional)

The SQL seed data only includes an admin account. To test customer features, create a customer account:

**Option 1: Register via Website**
1. Visit `http://localhost/yummy-soda/customer/register.php`
2. Fill in the registration form
3. Account is created automatically

**Option 2: Insert via SQL**
```sql
-- Log into MySQL
mysql -u yummy_soda -p yummy_soda

-- Insert a test customer
INSERT INTO users (full_name, email, phone, password_hash, role, created_at)
VALUES (
    'Test Customer',
    'testuser@example.com',
    '09123456789',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',  -- password: 'password'
    'CUSTOMER',
    NOW()
);
```

---

## Step 5: Verify .env is Protected

| Test | URL | Expected Result |
|------|-----|-----------------|
| `.env` blocked | `http://localhost/yummy-soda/.env` | 403 Forbidden |
| Website works | `http://localhost/yummy-soda/public/index.php` | Page loads |
| Admin works | `http://localhost/yummy-soda/admin/login.php` | Login page loads |

**If `.env` is accessible:** Check that `.htaccess` exists in `yummy-soda/` and Apache `mod_rewrite` is enabled.

---

## Step 6: Configure Web Server

### Apache (XAMPP)

The included `.htaccess` file already blocks sensitive files and enables rewrite rules. Ensure `mod_rewrite` is enabled:

1. Open `C:\xampp\apache\conf\httpd.conf` (Windows) or `/etc/apache2/apache2.conf` (Linux)
2. Find and uncomment: `LoadModule rewrite_module modules/mod_rewrite.so`
3. Ensure `AllowOverride All` is set for your document root:
   ```apache
   <Directory "/var/www/html">
       AllowOverride All
       Require all granted
   </Directory>
   ```
4. **Restart Apache** after any changes:
   - Windows: Open XAMPP Control Panel → Stop/Start Apache
   - Linux: `sudo systemctl restart apache2`

### Nginx

If using Nginx instead of Apache, create this server block:

```nginx
server {
    listen 80;
    server_name localhost;
    root /var/www/html/yummy-soda;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Block access to sensitive files
    location ~ /\.(env|gitignore|md|sql|log)$ {
        deny all;
        return 403;
    }

    # Block access to hidden files
    location ~ /\. {
        deny all;
        return 403;
    }
}
```

---

## Step 7: Set Permissions (Linux/macOS)

```bash
# Set ownership
sudo chown -R www-data:www-data /var/www/html/yummy-soda

# Set directory permissions
sudo find /var/www/html/yummy-soda -type d -exec chmod 755 {} \;

# Set file permissions
sudo find /var/www/html/yummy-soda -type f -exec chmod 644 {} \;

# Ensure upload directory is writable
sudo chmod -R 775 /var/www/html/yummy-soda/public/assets/images

# Protect .env (if using Option B — outside web root)
sudo chmod 640 /var/www/html/.env
sudo chown www-data:www-data /var/www/html/.env
```

---

## Step 8: Verify Installation

### 1. Frontend Test
- Visit: `http://localhost/yummy-soda/public/index.php`
- Should see: Yummy Soda landing page with product cards and scroll animations

### 2. Customer Registration Test
- Visit: `http://localhost/yummy-soda/customer/register.php`
- Create a test account, or use the SQL method from Step 4
- Should see: Account page with order history after login

### 3. Admin Login Test
- Visit: `http://localhost/yummy-soda/admin/login.php`
- Test credentials from SQL seed: `admin123@gmail.com` / `password`
- Should see: Dashboard with KPI cards and recent orders

### 4. ETL Test
- In admin panel: Click **Tools → Run ETL**
- Should see: Success message, then redirect to analytics

### 5. Analytics Test
- After ETL, view Analytics page
- Should see: Charts for Monthly Roll-up, Daily Drill-down, Slice by Payment Method

### 6. Decision Support Test
- Navigate to **Decisions** in admin sidebar
- Should see: Auto-approval rule cards, recommendations, best sellers table

### 7. Security Test
- Visit: `http://localhost/yummy-soda/.env`
- Should see: **403 Forbidden** (blocked by `.htaccess`)
- Visit: `http://localhost/yummy-soda/.gitignore`
- Should see: **403 Forbidden**

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| "Configuration error: .env file not found" | Check `.env` exists in `yummy-soda/` (Option A) or `htdocs/` (Option B). Check `config.php` path matches your setup |
| "Access denied for user" | Check `.env` DB_USER and DB_PASS match your MySQL user |
| "Using root database user is not allowed" | Change DB_USER from `root` to `yummy_soda` in `.env` |
| "Table doesn't exist" | Re-import `sql/yummy_soda.sql` |
| 404 errors on subpages | Enable `mod_rewrite` in Apache, or check Nginx `try_files` |
| Images not uploading | Check `public/assets/images` is writable (chmod 775) |
| `.env` accessible from browser | Verify `.htaccess` exists and `mod_rewrite` is enabled. Or use Option B (outside web root) |
| Internal Server Error from `.htaccess` | Ensure `AllowOverride All` is set in Apache config. If still failing, delete `.htaccess` and use Option B |
| "Auto-approve rules not found" | The `auto_approve_rules` table is auto-created on first visit to `decisions.php` |
| "No customer test account" | The SQL only seeds an admin. Create a customer via registration page or SQL insert |

---

## Post-Installation Checklist

- [ ] Database imported with dedicated user (not root)
- [ ] `.env` created from `.env.example` with correct credentials
- [ ] `.env` is protected by `.htaccess` (Option A) OR moved outside web root (Option B)
- [ ] `.env` is NOT tracked by git (check `.gitignore`)
- [ ] `.env` returns 403 when accessed via browser
- [ ] Frontend loads correctly with animations
- [ ] Customer registration works (create test account)
- [ ] Admin login works (admin123@gmail.com / password)
- [ ] Can place test order
- [ ] ETL sync runs successfully
- [ ] Analytics charts display after ETL
- [ ] Decision Support page shows auto-approval rules
- [ ] CSV/Excel/PDF exports work
- [ ] Contact form submits messages to admin panel

---

## Production Deployment Notes

1. **Change ALL default passwords** — Seed data uses simple passwords for testing
2. **Enable HTTPS** — Use SSL certificate (Let's Encrypt)
3. **Set `display_errors = Off`** in `php.ini`
4. **Configure regular backups:**
   ```bash
   mysqldump -u yummy_soda -p yummy_soda > backup_$(date +%Y%m%d).sql
   ```
5. **Monitor `etl_runs` table** for ETL pipeline health
6. **Set strict file permissions:**
   ```bash
   find . -type f -exec chmod 644 {} \;
   find . -type d -exec chmod 755 {} \;
   chmod 640 .env
   chmod 775 public/assets/images
   ```
7. **Never commit `.env`** — Ensure `.gitignore` is active:
   ```
   .env
   /public/assets/images/*
   !/public/assets/images/.gitkeep
   ```
