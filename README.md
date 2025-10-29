# HRM System - Pure PHP Human Resources Management

A comprehensive, professional-grade Human Resources Management System built with pure PHP, MySQL, and Bootstrap. This system provides complete HR functionality with customizable options for super administrators.

## Features

### Core Modules
- **Employee Management**: Complete employee lifecycle management
- **Attendance System**: Clock in/out tracking and monitoring
- **Leave Management**: Leave requests, approvals, and tracking
- **Payroll System**: Automated salary calculations with allowances and deductions
- **Accounting Module**: Basic financial tracking and journal entries
- **Reporting System**: Comprehensive reports and salary certificate generation
- **Super Admin Settings**: Fully customizable system settings

### Key Capabilities
- Role-based access control (Super Admin, Admin, Employee)
- Secure authentication with password hashing
- Responsive Bootstrap UI
- Database-driven architecture
- Modular, object-oriented PHP code
- Professional reporting and certificate generation

## Installation

### Prerequisites
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx)

### Setup Steps

1. **Clone/Download** the project files to your web server directory

2. **Configure Database**
   - Update database credentials in `core/config/config.php`
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   define('DB_NAME', 'hrm_system');
   ```

3. **Initialize Database**
   - Run the setup script: `http://yoursite.com/hrm_system/setup.php`
   - This creates the database, tables, and default admin user
   - **Delete setup.php after running it**

4. **Login**
   - Default credentials: `superadmin` / `admin123`
   - **Change the default password immediately**

## File Structure

```
hrm_system/
├── admin/                  # Admin interface pages
├── assets/                 # CSS, JS, images
├── auth/                   # Authentication scripts
├── core/                   # Core application logic
│   ├── classes/           # PHP classes (OOP)
│   ├── config/            # Configuration files
│   └── functions/         # Helper functions
├── templates/             # Reusable UI components
├── database_schema.sql    # Complete database schema
├── setup.php             # One-time setup script
└── index.php             # Main entry point
```

## Database Schema

The system uses a normalized database structure with the following main tables:
- `users` - User authentication
- `employees` - Employee information
- `departments` & `positions` - Organizational structure
- `attendance` - Time tracking
- `leave_requests` - Leave management
- `payrolls` & `payroll_items` - Payroll data
- `chart_of_accounts` & `journal_entries` - Accounting
- `settings` - System configuration

## Security Features

- Password hashing using PHP's `password_hash()`
- SQL injection prevention with prepared statements
- Session management with regeneration
- Role-based access control
- Input sanitization and validation

## Customization

### Super Admin Features
- Company profile settings
- Logo upload
- Payroll component configuration
- User role management
- System-wide settings

### Adding New Features
The modular architecture makes it easy to extend:
1. Create new classes in `core/classes/`
2. Add corresponding UI pages in `admin/`
3. Update navigation in `templates/sidebar.php`

## Usage

### Employee Management
- Add, edit, delete employee records
- Assign departments and positions
- Track employment status

### Attendance Tracking
- Manual clock in/out entry
- Daily attendance reports
- Attendance history by employee

### Payroll Processing
- Configure allowances and deductions
- Run payroll for individual employees
- Generate payslips and history

### Reporting
- Employee roster reports
- Attendance summaries
- Payroll registers
- Salary certificates

## Support

This is a complete, production-ready HRM system. For customizations or additional features, the modular architecture allows for easy extensions.

## License

This project is provided as-is for educational and commercial use.

---

**Important Security Notes:**
- Change default passwords immediately
- Use HTTPS in production
- Regularly backup your database
- Keep PHP and MySQL updated
# ufmujjalfm
