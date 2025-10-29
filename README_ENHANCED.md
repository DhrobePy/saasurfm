# Enhanced HRM System - Complete Human Resources Management

A comprehensive, professional-grade Human Resources Management System built with pure PHP, MySQL, and Bootstrap. This enhanced version includes advanced features for complete HR functionality with customizable options for super administrators.

## New Features Added

### Advanced Features
- **PDF Report Generation**: Generate professional PDF reports for all modules
- **Employee Profile Views**: Detailed employee profiles with tabbed interface showing personal info, attendance history, and payroll records
- **Salary Advance System**: Request, approve, and track salary advances for employees
- **Bulk Employee Upload**: Upload multiple employees via CSV file with validation
- **Employee Login Portal**: Separate login system for employees to clock in/out
- **Loan Management**: Create and track employee loans with automatic payroll deductions
- **Bulk Salary Disbursement**: Process payroll for all employees with automatic loan adjustments

### Core Modules (Enhanced)
- **Employee Management**: Complete employee lifecycle with profile views and bulk upload
- **Attendance System**: Clock in/out tracking with employee self-service portal
- **Leave Management**: Leave requests, approvals, and tracking
- **Payroll System**: Automated salary calculations with loan deductions and bulk processing
- **Accounting Module**: Financial tracking with automatic loan installment entries
- **Reporting System**: PDF-enabled reports and salary certificate generation
- **Super Admin Settings**: Fully customizable system settings

## Installation

### Prerequisites
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx)
- Composer (for PDF generation)

### Setup Steps

1. **Extract Files**
   - Extract the `hrm_system_enhanced.zip` to your web server directory

2. **Install Dependencies**
   ```bash
   cd hrm_system
   composer install
   ```

3. **Configure Database**
   - Update database credentials in `core/config/config.php`
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   define('DB_NAME', 'hrm_system');
   ```

4. **Initialize Database**
   - Run the setup script: `http://yoursite.com/hrm_system/setup.php`
   - This creates the database, tables, and default admin user
   - **Delete setup.php after running it**

5. **Login**
   - Admin login: `http://yoursite.com/hrm_system/auth/login.php`
   - Employee login: `http://yoursite.com/hrm_system/auth/employee_login.php`
   - Default admin credentials: `superadmin` / `admin123`
   - **Change the default password immediately**

## Enhanced File Structure

```
hrm_system/
├── admin/                     # Admin interface pages
│   ├── salary_advance.php     # NEW: Salary advance management
│   ├── loan.php              # NEW: Loan management
│   ├── bulk_disbursement.php # NEW: Bulk salary disbursement
│   ├── employee_profile.php  # NEW: Employee profile view
│   └── bulk_upload_handler.php # NEW: CSV upload handler
├── employee/                  # NEW: Employee self-service portal
│   ├── index.php             # Employee dashboard
│   └── attendance_handler.php # Attendance clock in/out
├── auth/
│   └── employee_login.php    # NEW: Employee login page
├── core/classes/
│   ├── SalaryAdvance.php     # NEW: Salary advance management
│   ├── Loan.php              # NEW: Loan management
│   └── PDF.php               # NEW: PDF report generation
└── database_schema.sql       # Enhanced with new tables
```

## New Database Tables

The enhanced system includes additional tables:
- `salary_advances` - Employee salary advance requests
- `loans` - Employee loan records
- `loan_installments` - Loan payment tracking
- Enhanced `employees` table with profile picture support
- Enhanced `users` table with employee linking

## Usage Guide

### Employee Self-Service Portal
Employees can now log in separately to:
- Clock in and out for attendance
- View their attendance history
- Access their profile information

### Salary Advance Management
Administrators can:
- Create salary advance requests
- Approve or reject requests
- Track advance status and payments

### Loan Management
- Create employee loans with installment plans
- Automatic monthly deductions from payroll
- Track loan status and payment history

### Bulk Operations
- **Bulk Employee Upload**: Upload CSV files with employee data
- **Bulk Salary Disbursement**: Process payroll for all active employees with automatic loan deductions

### PDF Reports
All reports can now be downloaded as professional PDF documents with proper formatting and company branding.

## CSV Upload Format

For bulk employee upload, use this CSV format:
```
first_name,last_name,email,phone,address,position_id,hire_date,base_salary,status
John,Doe,john@example.com,123-456-7890,123 Main St,1,2023-01-15,5000.00,active
```

## Security Enhancements

- Separate employee authentication system
- Role-based access control for all new features
- Enhanced session management
- Input validation for all new forms
- SQL injection prevention with prepared statements

## Customization

The modular architecture makes it easy to extend further:
1. Create new classes in `core/classes/`
2. Add corresponding UI pages in `admin/` or `employee/`
3. Update navigation in `templates/sidebar.php`

## Support

This enhanced HRM system provides enterprise-level functionality suitable for small to medium businesses. The system handles complex payroll scenarios including loan deductions, salary advances, and bulk processing.

---

**Important Notes:**
- Run database setup after extracting files
- Install Composer dependencies for PDF functionality
- Configure proper file permissions for uploads
- Use HTTPS in production environments
- Regular database backups recommended
