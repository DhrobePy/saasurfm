
# Saasurfm: Integrated Business Management Suite (ERP / POS / HRM)

`saasurfm` is a comprehensive, web-based business management application built with PHP. It is designed as a multi-user software-as-a-service (SaaS) platform to integrate key business operations into a single, cohesive system.

The application combines a Point of Sale (POS) system, a credit sales management module, a full accounting suite, and a human resource management (HRM) system with payroll.

-----

## 🔑 Key Features

The application is modular, with several key components:

  * **Point of Sale (POS):** A dedicated interface (`/pos/`) for processing immediate sales, managing cash, printing receipts, and performing "End of Day" (EOD) summaries.
  * **Credit Sales Management (CR):** A separate module (`/cr/`) for managing credit-based orders, customer ledgers, credit approvals, invoicing, and customer payments.
  * **Full Accounting Suite:** A robust accounting module (`/accounts/`) featuring a Chart of Accounts, bank account management, transaction logging, internal transfers, and financial reporting (e.g., Balance Sheet in `/admin/`).
  * **Human Resource Management (HRM):** Complete employee management (`/admin/employees.php`), including:
      * Detailed employee profiles
      * Attendance tracking (`/employee/`)
      * Payroll processing (`core/classes/Payroll.php`)
      * Salary advances and loan management (`core/classes/SalaryAdvance.php`, `core/classes/Loan.php`)
  * **Product & Inventory Management:** A module (`/product/`) for defining base products, managing variants, setting pricing, and tracking inventory.
  * **Customer Management:** A central directory (`/customers/`) for managing customer profiles, linked to both POS and credit sales.
  * **Role-Based Access Control:** Separate login portals and dashboards for administrative users (`/auth/login.php`) and employees (`/auth/employee_login.php`).
  * **Reporting & Administration:** A central admin dashboard (`/admin/`) for system settings, user management, and viewing consolidated reports.

-----

## 🛠️ Tech Stack

  * **Backend:** PHP (utilizing a hybrid of procedural and Object-Oriented (OOP) patterns).
      * Core logic is encapsulated in `core/classes/`.
      * Application bootstrap is handled by `core/init.php`.
  * **Frontend:** Standard HTML, CSS, and JavaScript.
      * **UI Framework:** Bootstrap (v5, as seen in `assets/css/bootstrap.min.css`).
      * **Client-side Logic:** Vanilla JavaScript and jQuery (inferred from `ajax_handler.php` files).
  * **Database:** MySQL / MariaDB (schema provided in `ujjalfmc_saas-7.sql`).
  * **Web Server:** Apache (recommended, as the repo includes a `.htaccess` file for URL routing and security).

-----

## 🚀 Installation and Setup

To get the application running in a local development environment:

1.  **Clone the Repository:**

    ```bash
    git clone [your-repo-url] saasurfm
    cd saasurfm
    ```

2.  **Web Server:**

      * Point your Apache server's document root to the `saasurfm` directory.
      * Ensure `mod_rewrite` is enabled in Apache to process the `.htaccess` file.

3.  **PHP Environment:**

      * Ensure you have a compatible PHP version (e.g., 7.4+ or 8.x) with the `pdo_mysql` extension enabled.

4.  **Database Setup:**

      * Create a new MySQL database (e.g., `saasurfm_db`).
      * Import the database schema and data using the provided SQL file:
        ```bash
        mysql -u [your_username] -p [your_database_name] < ujjalfmc_saas-7.sql
        ```

5.  **Configuration:**

      * **This is the most critical step.** Copy or rename `core/config/config.php` (if a sample exists) or directly edit:
        `core/config/config.php`
      * Update the database credentials (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) and any other environment-specific constants (like `BASE_URL`).

6.  **File Permissions:**

      * Ensure the web server has write permissions for the `uploads/` directory, which is used for profile pictures and other user-generated content.
        ```bash
        chmod -R 755 uploads/
        chown -R www-data:www-data uploads/
        ```
        *(Adjust `www-data` to your server's user, e.g., `apache`)*

7.  **Access the Application:**

      * Open your configured `BASE_URL` in a browser.

-----

## 📁 Directory Structure

Here is a high-level overview of the project's structure:

```
saasurfm/
├── admin/         # Admin dashboard, settings, reports, and user management
├── accounts/      # Financial accounting module (Chart of Accounts, transactions)
├── assets/        # Compiled CSS, JS, and image files
├── auth/          # Authentication scripts (login, logout, handlers)
├── core/          # Core application logic
│   ├── classes/   # OOP classes (Database, User, Payroll, Accounting, etc.)
│   ├── config/    # config.php with database credentials
│   ├── functions/ # Global helper functions
│   └── init.php   # Application bootstrap (autoloader, session start)
├── cr/            # Credit (Customer) sales and ledger module
├── customers/     # Customer management UI
├── employee/      # Employee-facing dashboard (e.g., attendance)
├── pos/           # Point of Sale (POS) interface and logic
├── product/       # Product, variant, pricing, and inventory management
├── templates/     # Reusable UI components (header.php, footer.php, sidebar.php)
├── uploads/       # Directory for user-uploaded files
│   └── profiles/  # Employee and customer profile pictures
├── .htaccess      # Apache configuration for routing and security
├── index.php      # Main application entry point (redirects to login)
└── ujjalfmc_saas-7.sql  # Database schema and data
```

-----

## 🚦 Usage & Entry Points

  * **Main Login (Admin/Manager):** `/auth/login.php` (or the root `/`)
  * **Employee Login:** `/auth/employee_login.php`
  * **Admin Dashboard:** `/admin/`
  * **POS Interface:** `/pos/`
  * **Credit Sales:** `/cr/`