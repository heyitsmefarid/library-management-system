# Library Attendance and Borrowing Management System

Plain PHP (no framework) + MySQL. PDO with prepared statements, session-based admin login, CSRF protection on every form.

## Requirements

- XAMPP with PHP 7.4 or newer (PHP 8.x recommended) and MySQL/MariaDB
- A modern browser

## Running it in XAMPP

1. **Copy the project.** Put the `library_management_system` folder in `C:\xampp\htdocs\`, so you have
   `C:\xampp\htdocs\library_management_system\index.php`.
2. **Start the servers.** Open the XAMPP Control Panel and click **Start** for **Apache** and **MySQL**.
3. **Create the database in phpMyAdmin.**
   1. Open <http://localhost/phpmyadmin>.
   2. Click the **Import** tab (no database needs to be selected).
   3. Choose `library_management_system/database.sql` and click **Import** (or **Go**).
   4. The database `library_management_system` appears with 10 tables: `admins`, `users`, `books`, `categories`, `attendance`, `borrowings`, `returns`, `notifications`, `penalties`, `settings`.

   > The script starts with `DROP DATABASE IF EXISTS library_management_system`. Re-importing it **erases all data** in that database.
4. **Check the connection settings.** Open `config/database.php`. The defaults match a fresh XAMPP install:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```
   If your MySQL `root` user has a password, or MySQL runs on another port, change these values.
5. **Open the system** at <http://localhost/library_management_system/>.

## Running it from VS Code (Laragon)

Open this folder in VS Code, make sure MySQL is started in Laragon and `database.sql` is imported, then press **F5** (Run → Start Debugging). VS Code starts PHP's built-in server and opens <http://127.0.0.1:8090/> in Edge; choose the Chrome configuration in the Run and Debug panel to use Chrome. The PHP path in `.vscode/tasks.json` points to Laragon's PHP 8.2 — change it if your PHP is elsewhere (for XAMPP: `C:/xampp/php/php.exe` and `C:/xampp/php/ext`).

## Initial admin account

| Username | Password   |
|----------|------------|
| `admin`  | `admin123` |

After signing in for the first time:

1. Go to **Settings → My account** and change the password. The new password needs at least 8 characters with letters and numbers.
2. To add more librarians, go to **Settings → Administrators → Add administrator**. There is no public sign-up page; only a signed-in administrator can create accounts.

**Forgot the password?** Reset it from the command line. The script works only from the CLI, never from a browser:

```
cd C:\xampp\htdocs\library_management_system
C:\xampp\php\php.exe setup\create_admin.php admin NewPassword123
```

The same command with a new username creates a new administrator:
`setup\create_admin.php librarian2 Secret12345 "Ana Reyes"`.

## Sample data

`database.sql` loads sample records so you can try the system right away:

- 7 book categories
- 10 books
- 5 library users (students, a teacher and a staff member)

None of the dashboard figures are hard-coded. Every number is calculated from the database.

To start empty, delete the sample books and users from the interface. You can also remove the `INSERT INTO books` and `INSERT INTO users` blocks from `database.sql` before importing.

## Modules

| Page | What it does |
|------|--------------|
| **Dashboard** | Live counts: registered users, books, available copies, borrowed, today's attendance, overdue and unpaid penalties. Also lists what's due soon, who's in the library now, and recent loans. |
| **Attendance** | Quick entry by ID number: one field times a user in, or out if they're already inside. You can also search by name or ID, see who's inside, and time everyone out at closing. History has date-range filters. |
| **Library Users** | Register, edit, view and search users. Each profile shows attendance, borrowing and penalty history. Account status can be set to Active, Inactive or Suspended. |
| **Books** | Add, edit, view and delete books. Search by title, author, ISBN, category or publisher. Tracks total and available copies, and has category management. |
| **Borrowing** | Pick an active user and an available book. Generates the transaction number (`BRW-YYYYMMDD-#####`), checks copies are available, and decreases them. Tabs: active, overdue, completed, all. Includes a printable slip. |
| **Returning** | Search borrowed books and process returns. The return date is recorded automatically; overdue days and the penalty are calculated and copies restored. A book can't be returned twice. |
| **Notifications** | Generated from the borrowing records: due today, due within 3 days, overdue. Shows borrower, book and due date, and can be marked read or unread. |
| **Penalties** | Paid and unpaid penalties, payment recording with receipt numbers, collection summaries and outstanding balances per borrower. |
| **Reports** | Attendance, book inventory, borrowed books, returned books, overdue books, borrowing history, penalty payments and outstanding penalties. Date filters; **Print report** gives a clean printable page. |
| **Settings** | Penalty rate per day (default ₱5.00), loan period, maximum books per user, due-soon window, library name. Also your own account and administrator accounts. |

## Business rules

- **Penalty** = overdue days × the daily rate in effect when the book is returned. The rate is stored with each penalty, so changing it later doesn't alter old records.
- **Borrowing is refused** when:
  - the user isn't Active,
  - the user has an overdue book,
  - the user has reached the maximum number of books,
  - the user already has a copy of the same title, or
  - no copies are available.
- **Returned as "Lost":** the copy is removed from the total instead of being put back on the shelf.
- **Attendance:** a user can't have two open entries. The application checks this inside a transaction, and a unique index in the database enforces it.
- **Deleting records:** a book or user with transaction history can't be deleted; foreign keys protect the records. Set the user to Inactive instead.

## Security notes

- Passwords are hashed with `password_hash()` (bcrypt).
- On login the session ID is regenerated. The session cookie is HttpOnly and SameSite=Lax.
- Sessions sign out after 60 minutes of inactivity.
- After 5 failed logins, the login is locked for 5 minutes.
- Every form carries a CSRF token, and every query uses prepared statements. All output is escaped.
- Stock-changing operations run inside database transactions with row locks: borrowing, returning, payments and copy adjustments.
- `.htaccess` files block direct browser access to `config/`, `includes/` and `setup/`.

## Folder structure

```
library_management_system/
├── config/database.php        DB connection + app constants
├── includes/
│   ├── auth.php               session, login/logout, require_login(), CSRF check
│   ├── functions.php          helpers, settings, penalties, notifications, pagination, icons
│   ├── header.php, sidebar.php, footer.php
├── assets/css/style.css       responsive UI + print styles
├── assets/js/app.js           confirmations, mobile menu, list filters
├── attendance/   index.php, history.php
├── books/        index.php, form.php, view.php, delete.php, categories.php
├── users/        index.php, form.php, view.php, status.php, delete.php
├── borrowing/    index.php, create.php, view.php
├── returning/    index.php, process.php
├── notifications/index.php
├── penalties/    index.php, pay.php
├── reports/      index.php
├── settings/     index.php, account.php, admins.php, tabs.php
├── setup/        create_admin.php (CLI only)
├── dashboard.php, index.php, login.php, logout.php
└── database.sql
```
