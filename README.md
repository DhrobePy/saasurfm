
# Saasurfm: Integrated Business Management Suite (ERP / POS / HRM)

`saasurfm` is a comprehensive, web-based business management application built for **Ujjal Flour Mills (UFM)**. It is a multi-user, role-based platform that integrates finance, operations, logistics, HR, and sales into a single cohesive system.

---

## Key Features

The application is fully modular:

- **Admin Dashboard** (`/admin/`) — System-wide administration, user management, employee records, reports, balance sheet, settings, and AI-assisted tools.
- **Accounting Suite** (`/accounts/`) — Chart of Accounts, bank account management, journal entries, debit vouchers, internal transfers, daily logs, and financial reporting.
- **Expense Management** (`/expense/`) — Voucher creation, multi-level approval workflow (Initiate → Approve), category management, and expense history with audit trail.
- **Bank Transactions** (`/bank/`) — Bank transaction initiation (currently routed; approval role exists in DB but is unrouted — see bugs below).
- **Credit Sales / CR** (`/cr/`) — Credit-based orders, customer ledger, order workflows, payment collection, invoicing, and payment allocation.
- **Point of Sale (POS)** (`/pos/`) — Immediate sales processing, cash management, receipt printing, and End-of-Day (EOD) summaries.
- **Purchase Management** (`/purchase/`) — Purchase orders, goods received notes (GRN), supplier invoices, purchase payments, supplier ledger, and purchase returns.
- **Sales Management** (`/sales/`) — Branch-wise sales operations for Sirajgonj (SRG), Demra, and other locations.
- **Dispatch** (`/dispatch/`) — Dispatch operations with optional POS access, by branch.
- **Production** (`/production/`) — Production schedule management, by branch.
- **Logistics** (`/logistics/`) — Vehicle management, driver records, trip assignments, fuel logs, maintenance logs, and vehicle rentals.
- **Customer Management** (`/customers/`) — Central customer directory linked to both POS and credit sales.
- **Product & Inventory** (`/product/`) — Base products, variants, pricing rules engine, and inventory tracking.
- **Employee Portal** (`/employee/`) — Employee-facing attendance dashboard and personal profile.
- **Collector** (`/collector/`) — Payment collection interface for collectors.
- **Wheat Shipments** (`/wheat_shipments.php`) — Shipment tracking with API-based alerts.
- **Audit Trail** — Comprehensive logging via `AuditLogger` class. Covers logins, expense actions, credit orders, bank transactions, and user preference changes.

---

## Tech Stack

- **Backend:** PHP (hybrid OOP + procedural). Core logic is encapsulated in `core/classes/`. Application bootstrap is in `core/init.php`.
- **Frontend:** HTML, CSS, JavaScript. UI Framework: Bootstrap 5 (`assets/css/bootstrap.min.css`). Client-side: Vanilla JS and jQuery (via `ajax_handler.php` files).
- **Database:** MySQL / MariaDB. Schema file: `ujjalfmc_saas-18.sql`.
- **Web Server:** Apache (`.htaccess` included for routing and security).
- **Integrations:** Telegram notifications (`TelegramNotifier.php`), AI assistants (Gemini, Groq, DeepSeek via `ai_agent.php`, `ai_dashboard_advisor.php`), PDF generation (`PDF.php`).

---

## Installation & Setup

1. **Clone the Repository:**
   ```bash
   git clone [your-repo-url] saasurfm
   cd saasurfm
   ```

2. **Web Server:** Point Apache document root to the `saasurfm/` directory. Ensure `mod_rewrite` is enabled.

3. **PHP:** PHP 7.4+ or 8.x with the `pdo_mysql` extension.

4. **Database Setup:**
   ```bash
   mysql -u [username] -p [database_name] < ujjalfmc_saas-18.sql
   ```

5. **Configuration:** Edit `core/config/config.php` and update:
   - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - `APP_URL` (e.g., `http://localhost/saasurfm`)
   - API keys (Gemini, Groq, DeepSeek, Telegram)

6. **File Permissions:**
   ```bash
   chmod -R 755 uploads/
   chown -R www-data:www-data uploads/
   ```

7. **Access:** Open `APP_URL` in browser. You will be redirected to the login page.

---

## Directory Structure

```
saasurfm/
├── admin/          # Admin dashboard, user management, employee records, reports
├── accounts/       # Accounting module (Chart of Accounts, journals, bank, vouchers)
├── api/            # API endpoints
├── assets/         # CSS, JS, images
├── auth/           # Login/logout handlers (admin + employee portals)
├── bank/           # Bank transaction management
├── core/
│   ├── classes/    # OOP classes (Database, User, AuditLogger, Payroll, etc.)
│   ├── config/     # config.php with all credentials and constants
│   ├── functions/  # helpers.php — global helper and permission functions
│   └── init.php    # Bootstrap: autoloader, session, DB connection
├── cr/             # Credit sales, customer ledger, order workflows
├── customers/      # Customer management UI
├── dispatch/       # Dispatch operations module
├── employee/       # Employee attendance and profile portal
├── expense/        # Expense voucher creation, approval, and history
├── includes/       # Shared include files
├── logistics/      # Vehicles, drivers, trips, fuel, maintenance, rentals
├── modules/        # Additional module files
├── pos/            # Point of Sale interface
├── production/     # Production schedule management
├── product/        # Products, variants, pricing, inventory
├── purchase/       # Purchase orders, GRN, invoices, supplier payments
├── sales/          # Sales management by branch
├── templates/      # Shared UI components (header.php, footer.php, sidebar.php)
├── uploads/        # User-uploaded files (profile pictures, etc.)
├── index.php       # Main router — redirects logged-in users by role
├── ujjalfmc_saas-18.sql  # Full database schema and seed data
└── .htaccess       # Apache URL routing and security rules
```

---

## Authentication & Entry Points

| URL | Purpose | Who Can Access |
|-----|---------|----------------|
| `/auth/login.php` | Admin/Manager login | All roles |
| `/auth/employee_login.php` | Employee-only login | Employees |
| `/` or `index.php` | Main role-based router | All logged-in users |
| `/admin/` | Admin dashboard | Superadmin, admin |
| `/accounts/` | Accounting dashboard | Accounts roles |
| `/expense/` | Expense dashboard | Expense roles |
| `/bank/` | Bank transactions | bank Transaction initiator |
| `/production/` | Production schedule | Production Manager roles |
| `/dispatch/` | Dispatch dashboard | Dispatch roles |
| `/logistics/` | Logistics dashboard | Transport Manager |
| `/sales/` | Sales dashboard | Sales roles |
| `/collector/` | Payment collection | collector |
| `/cr/` | Credit sales | CR/accounts roles |
| `/pos/` | Point of Sale | accountspos-*, dispatchpos-* roles |
| `/employee/` | Attendance & profile | Employees (separate login) |

---

## Users, Roles & Permissions

### Authentication Flow

1. User submits credentials to `auth/login_handler.php`
2. `User::login()` validates email + bcrypt password
3. On success, session is created with: `user_id`, `user_uuid`, `user_display_name`, `user_email`, `user_role`, `logged_in`, `login_time`, `csrf_token`
4. `index.php` reads `$_SESSION['user_role']` and redirects to the appropriate module
5. Logout destroys session and logs via `AuditLogger`

### Role Definitions (22 Roles in DB ENUM)

The `users.role` column is a MySQL ENUM. All valid roles are listed below exactly as they appear in the database:

| Role (exact DB value) | Routed To | Description |
|-----------------------|-----------|-------------|
| `Superadmin` | `/admin/` | Full system access; only role that can delete users, edit/delete expenses, manage categories, view audit trail |
| `admin` | `/admin/` | Administrative access (same dashboard as Superadmin, slightly fewer permissions) |
| `Accounts` | `/accounts/` | General accounting access across all branches |
| `accounts-demra` | `/accounts/` | Accounting for Demra branch |
| `accounts-srg` | `/accounts/` | Accounting for Sirajgonj branch |
| `accountspos-demra` | `/accounts/` | Accounting + POS access for Demra |
| `accountspos-srg` | `/accounts/` | Accounting + POS access for Sirajgonj |
| `production manager-demra` | `/production/` | Production schedule management for Demra |
| `production manager-srg` | `/production/` | Production schedule management for Sirajgonj |
| `dispatch-demra` | `/dispatch/` | Dispatch operations for Demra |
| `dispatch-srg` | `/dispatch/` | Dispatch operations for Sirajgonj |
| `dispatchpos-demra` | `/dispatch/` | Dispatch + POS access for Demra |
| `dispatchpos-srg` | `/dispatch/` | Dispatch + POS access for Sirajgonj |
| `sales-demra` | `/sales/` | Sales for Demra branch |
| `sales-srg` | `/sales/` | Sales for Sirajgonj branch |
| `sales-other` | `/sales/` | Sales for other branches |
| `collector` | `/collector/` | Payment collection only |
| `Transport Manager` | `/logistics/` | Full logistics management (vehicles, drivers, trips, fuel, maintenance) |
| `Expense Initiator` | `/expense/` | Can create expense vouchers only; cannot approve |
| `Expense Approver` | `/expense/` | Can approve expense vouchers only; cannot create |
| `bank Transaction initiator` | `/bank/` | Can initiate bank transactions |
| `Bank Transaction Approver` | **UNROUTED** | Exists in DB ENUM but has no route in `index.php` — **BUG, see below** |

### Permission Matrix

| Permission | Superadmin | admin | Accounts | accounts-* | accountspos-* | Expense Initiator | Expense Approver | Dispatch | Production | Sales | Transport Manager | bank Transaction initiator |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Manage Users | ✓ | ✓ | | | | | | | | | | |
| Delete Users | ✓ | | | | | | | | | | | |
| Access Admin Dashboard | ✓ | ✓ | | | | | | | | | | |
| Access Accounts Module | ✓ | | ✓ | ✓ | ✓ | | | | | | | |
| Access Expense Module | ✓ | | ✓ | ✓ | | ✓ | ✓ | | | | | |
| Create Expense Voucher | ✓ | ✓ | ✓ | ✓ | | ✓ | | | | | | |
| Approve Expense Voucher | ✓ | ✓ | ✓ | | | | ✓ | | | | | |
| Edit Expense Voucher | ✓ | | | | | | | | | | | |
| Delete Expense Voucher | ✓ | | | | | | | | | | | |
| Manage Expense Categories | ✓ | | | | | | | | | | | |
| Access Audit Trail | ✓ | | | | | | | | | | | |
| Access Bank Module | ✓ | | | | | | | | | | | ✓ |
| Access Dispatch Module | ✓ | | | | | | | ✓ | | | | |
| Access Production Module | ✓ | | | | | | | | ✓ | | | |
| Access Sales Module | ✓ | | | | | | | | | ✓ | | |
| Access Logistics Module | ✓ | | | | | | | | | | ✓ | |

---

## Known Bugs & Mistakes

### BUG 1 — CRITICAL: Status ENUM Mismatch (Breaks user creation/editing)

**Files affected:** `admin/manage_user.php` (line 425–428), `admin/users.php` (lines 100–105), `ujjalfmc_saas-18.sql`

The database `users.status` column is defined as:
```sql
status enum('active','pending','suspended')
```

But the form in `manage_user.php` offers:
```html
<option value="active">Active</option>
<option value="inactive">Inactive</option>   <!-- NOT in DB ENUM -->
<option value="disabled">Disabled</option>   <!-- NOT in DB ENUM -->
```

And `users.php` expects these same wrong values for badge styling:
```php
$statusClasses = [
    'active'   => 'bg-green-100 text-green-800',
    'inactive' => 'bg-yellow-100 text-yellow-800',  // never matches DB
    'disabled' => 'bg-red-100 text-red-800',         // never matches DB
];
```

**Impact:** In MySQL strict mode, attempting to save `inactive` or `disabled` throws an error. In non-strict mode, an empty string is stored. Users with `pending` or `suspended` status always display as gray (unknown badge).

**Fix:** Change the form options to match the DB: `active`, `pending`, `suspended`. Update badge classes to match.

---

### BUG 2 — CRITICAL: `Bank Transaction Approver` Role Has No Route

**File affected:** `index.php`

The DB ENUM includes `'Bank Transaction Approver'` as a valid role, but `index.php` only routes `bank Transaction initiator`:
```php
case 'bank Transaction initiator':
    header('Location: bank/index.php');
    exit();
// Bank Transaction Approver is never handled
```

**Impact:** Any user assigned the `Bank Transaction Approver` role falls into the `default` case and is immediately logged out with "Invalid user role assigned."

**Fix:** Add a route for `Bank Transaction Approver` (most likely also to `/bank/index.php`).

---

### BUG 3 — MODERATE: `accounts-rampura` Is a Ghost Role

**Files affected:** `index.php` (line 27), `admin/manage_user.php` (line 34)

`index.php` routes this role and `manage_user.php` includes it in the fallback roles list:
```php
case 'accounts-rampura':
    header('Location: accounts/index.php');
    exit();
```

But `accounts-rampura` does **not exist** in the `users.role` DB ENUM. It can never be assigned through the UI or the DB. Dead code — and it suggests a Rampura branch may have been planned but never implemented.

**Fix:** Remove `accounts-rampura` from `index.php` and from the fallback `$all_roles` array in `manage_user.php`, OR add the role to the DB ENUM if the branch exists.

---

### BUG 4 — MODERATE: `driver` Role Has No DB ENUM Entry

**File affected:** `index.php` (line 63)

`index.php` routes `driver` to logistics:
```php
case 'driver':
    header('Location: logistics/index.php');
    exit();
```

But `driver` does **not exist** in the `users.role` DB ENUM. The role cannot be assigned through any UI or DB insert without manually altering the schema.

**Fix:** Either add `'driver'` to the ENUM in `ujjalfmc_saas-18.sql` and `alter table users modify role enum(...)` to include it, OR remove the dead route from `index.php`.

---

### BUG 5 — MODERATE: Fallback Roles List in `manage_user.php` Is Incomplete

**File affected:** `admin/manage_user.php` (lines 33–39)

The hardcoded fallback `$all_roles` (used when the DB ENUM query fails) is missing five roles that exist in the database:

```php
// Current fallback (INCOMPLETE):
$all_roles = [
    'Superadmin', 'admin', 'Accounts', 'accounts-rampura', 'accounts-srg',  // 'accounts-rampura' doesn't exist in DB!
    'accounts-demra', 'accountspos-demra', 'accountspos-srg',
    'production manager-srg', 'production manager-demra',
    'dispatch-srg', 'dispatch-demra', 'dispatchpos-demra', 'dispatchpos-srg',
    'sales-srg', 'sales-demra', 'sales-other', 'collector'
    // Missing: 'Transport Manager', 'Expense Initiator', 'Expense Approver',
    //          'bank Transaction initiator', 'Bank Transaction Approver'
];
```

**Fix:** Update fallback to exactly match the DB ENUM (and remove `accounts-rampura`).

---

### BUG 6 — MINOR: `accountspos-*` Roles Excluded from Expense Module Access

**File affected:** `core/functions/helpers.php` — `canAccessExpense()` (line 224–232)

Users with `accountspos-demra` and `accountspos-srg` roles have combined Accounts + POS access, but they are **not** included in `canAccessExpense()`, `canCreateExpense()`, or `canAccessExpenseHistory()`. The `accounts-demra` and `accounts-srg` roles are included, but their POS counterparts are not.

**Impact:** A user with `accountspos-demra` cannot access the expense module, even though a `accounts-demra` user with identical accounting responsibilities can.

**Fix:** Add `accountspos-demra` and `accountspos-srg` to all expense permission functions.

---

### BUG 7 — MINOR: Copy-Paste Comment Error in `index.php`

**File affected:** `index.php` (lines 60–62)

The comment above the Logistics routing block incorrectly reads `"--- Dispatch Roles ---"`:
```php
// --- Logistics Roles ---- 
// --- Dispatch Roles ---      <-- wrong, should be "--- Logistics Roles ---"
case 'Transport Manager':
```

---

### BUG 8 — MINOR: `admin` Role Listed in Expense Permission Functions but Unreachable

**File affected:** `core/functions/helpers.php`

`canAccessExpense()`, `canCreateExpense()`, and `canApproveExpense()` all include `'admin'` in their allowed roles. However, `admin` users are routed exclusively to `/admin/index.php` by `index.php`, and there is no navigation link from the admin module to the expense module. These permission checks for `admin` are effectively dead code.

**Fix:** Either add a navigation link from the admin module to the expense module to make this intentional, or remove `'admin'` from the expense permission functions.

---

## Security Notes

- Passwords are hashed using `password_hash($password, PASSWORD_DEFAULT)` (bcrypt).
- Sessions are regenerated on login (`session_regenerate_id(true)`).
- CSRF tokens are generated at login (`$_SESSION['csrf_token']`).
- All SQL queries use PDO prepared statements via `Database.php`.
- All output is escaped with `htmlspecialchars()`.
- **WARNING:** `core/config/config.php` contains plaintext database credentials and API keys. This file must not be committed to public repositories. Use environment variables or a `.env` file in production.
- Only `Superadmin` can delete users; deletion is blocked if they are the last Superadmin.
- Only `Superadmin` can change the role of the last Superadmin (enforced in both PHP and UI).
- User deletion unlinks any associated employee record before removing the user row.

---

## Database Overview

**Schema file:** `ujjalfmc_saas-18.sql`  
**Engine:** InnoDB, charset utf8mb4

Key table groups:

| Group | Tables |
|-------|--------|
| Users & Auth | `users`, `login_attempts`, `password_reset_tokens` |
| Employees & HR | `employees`, `departments`, `positions`, `branches`, `driver_attendance` |
| Accounting | `chart_of_accounts`, `journal_entries`, `transaction_lines`, `bank_accounts`, `bank_transactions`, `debit_vouchers` |
| Expenses | `expense_vouchers`, `expense_categories`, `expense_subcategories`, `expense_action_log` |
| Credit Sales | `credit_orders`, `credit_order_items`, `customer_ledger`, `customer_payments`, `payment_allocations` |
| Purchase | `purchase_orders`, `purchase_invoices`, `goods_received_notes`, `purchase_payments`, `supplier_ledger` |
| Logistics | `vehicles`, `drivers`, `trips`, `fuel_logs`, `maintenance_logs`, `vehicle_rentals` |
| Production | `production_schedule`, `wheat_shipments` |
| Audit | `system_audit_log`, `expense_action_log`, `bank_tx_audit_log`, `credit_order_audit`, `eod_audit_trail` |
| POS | `orders`, `order_items` |
| Inventory | `products`, `product_variants`, `product_prices`, `inventory`, `pricing_rules` |

The schema also includes **stored procedures** for: payment allocation, order weight calculation, trip consolidation, bank transaction numbering, customer outstanding balance, and shipment number generation.

---

## Core Classes

| Class | File | Purpose |
|-------|------|---------|
| `Database` | `core/classes/Database.php` | PDO singleton wrapper with query, insert, update, delete methods |
| `User` | `core/classes/User.php` | Login, logout, authentication |
| `AuditLogger` | `core/classes/AuditLogger.php` | Comprehensive audit trail logging |
| `Accounting` | `core/classes/Accounting.php` | Journal entries, account management |
| `ExpenseManager` | `core/classes/ExpenseManager.php` | Expense voucher lifecycle |
| `Employee` | `core/classes/Employee.php` | Employee record management |
| `Payroll` | `core/classes/Payroll.php` | Payroll processing |
| `SalaryAdvance` | `core/classes/SalaryAdvance.php` | Salary advance management |
| `Loan` | `core/classes/Loan.php` | Employee loan management |
| `PricingRulesEngine` | `core/classes/PricingRulesEngine.php` | Dynamic pricing rule evaluation |
| `TelegramNotifier` | `core/classes/TelegramNotifier.php` | Telegram bot notifications |
| `PDF` | `core/classes/PDF.php` | PDF generation |
| `Report` | `core/classes/Report.php` | Report generation |
| `AuditLogger` | `core/classes/AuditLogger.php` | System-wide audit logging |
