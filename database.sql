-- =====================================================================
--  Library Attendance and Borrowing Management System
--  Database: library_management_system
--  Compatible with MySQL 5.7+/8.x and MariaDB 10.3+ (XAMPP)
-- =====================================================================

DROP DATABASE IF EXISTS library_management_system;
CREATE DATABASE library_management_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE library_management_system;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- admins: administrators allowed to sign in (no public sign-up)
-- ---------------------------------------------------------------------
CREATE TABLE admins (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username        VARCHAR(50)  NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    full_name       VARCHAR(120) NOT NULL,
    email           VARCHAR(120) NULL,
    status          ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    last_login      DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- users: library users (students, teachers, staff, others)
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_number       VARCHAR(30)  NOT NULL COMMENT 'Student ID / Employee ID',
    first_name      VARCHAR(60)  NOT NULL,
    middle_name     VARCHAR(60)  NULL,
    last_name       VARCHAR(60)  NOT NULL,
    user_type       ENUM('Student','Teacher','Staff','Other') NOT NULL DEFAULT 'Student',
    department      VARCHAR(120) NULL COMMENT 'Course / Department / Office',
    year_level      VARCHAR(30)  NULL COMMENT 'Year & section (students)',
    email           VARCHAR(120) NULL,
    phone           VARCHAR(30)  NULL,
    address         VARCHAR(255) NULL,
    status          ENUM('Active','Inactive','Suspended') NOT NULL DEFAULT 'Active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_id_number (id_number),
    KEY idx_users_name (last_name, first_name),
    KEY idx_users_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- categories: book categories
-- ---------------------------------------------------------------------
CREATE TABLE categories (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(80)  NOT NULL,
    description     VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- books: catalogue with copy tracking
-- ---------------------------------------------------------------------
CREATE TABLE books (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    isbn                VARCHAR(20)  NULL,
    title               VARCHAR(255) NOT NULL,
    author              VARCHAR(255) NOT NULL,
    publisher           VARCHAR(150) NULL,
    category_id         INT UNSIGNED NULL,
    publication_year    SMALLINT UNSIGNED NULL,
    shelf_location      VARCHAR(50)  NULL,
    total_copies        INT UNSIGNED NOT NULL DEFAULT 1,
    available_copies    INT UNSIGNED NOT NULL DEFAULT 1,
    description         TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_books_isbn (isbn),
    KEY idx_books_title (title),
    KEY idx_books_author (author),
    CONSTRAINT fk_books_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_books_copies CHECK (available_copies <= total_copies)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- attendance: time-in / time-out log
-- active_user_id is only filled while the user has not timed out yet;
-- its UNIQUE index makes duplicate active entries impossible.
-- ---------------------------------------------------------------------
CREATE TABLE attendance (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    time_in         DATETIME NOT NULL,
    time_out        DATETIME NULL,
    purpose         VARCHAR(120) NULL,
    recorded_by     INT UNSIGNED NULL,
    active_user_id  INT UNSIGNED GENERATED ALWAYS AS (IF(time_out IS NULL, user_id, NULL)) STORED,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_active (active_user_id),
    KEY idx_attendance_date (attendance_date),
    KEY idx_attendance_user (user_id),
    CONSTRAINT fk_attendance_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_admin FOREIGN KEY (recorded_by)
        REFERENCES admins (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- borrowings: one row per borrowed copy
-- ---------------------------------------------------------------------
CREATE TABLE borrowings (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_no  VARCHAR(30)  NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    book_id         INT UNSIGNED NOT NULL,
    borrow_date     DATE NOT NULL,
    due_date        DATE NOT NULL,
    status          ENUM('Borrowed','Returned') NOT NULL DEFAULT 'Borrowed',
    remarks         VARCHAR(255) NULL,
    processed_by    INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_borrowings_txn (transaction_no),
    KEY idx_borrowings_status_due (status, due_date),
    KEY idx_borrowings_user (user_id),
    KEY idx_borrowings_book (book_id),
    CONSTRAINT fk_borrowings_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_borrowings_book FOREIGN KEY (book_id)
        REFERENCES books (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_borrowings_admin FOREIGN KEY (processed_by)
        REFERENCES admins (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_borrowings_dates CHECK (due_date >= borrow_date)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- returns: one return per borrowing (UNIQUE blocks duplicate returns)
-- ---------------------------------------------------------------------
CREATE TABLE returns (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    return_no       VARCHAR(30)  NOT NULL,
    borrowing_id    INT UNSIGNED NOT NULL,
    return_date     DATETIME NOT NULL,
    overdue_days    INT UNSIGNED NOT NULL DEFAULT 0,
    penalty_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    book_condition  ENUM('Good','Damaged','Lost') NOT NULL DEFAULT 'Good',
    remarks         VARCHAR(255) NULL,
    processed_by    INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_returns_no (return_no),
    UNIQUE KEY uq_returns_borrowing (borrowing_id),
    KEY idx_returns_date (return_date),
    CONSTRAINT fk_returns_borrowing FOREIGN KEY (borrowing_id)
        REFERENCES borrowings (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_returns_admin FOREIGN KEY (processed_by)
        REFERENCES admins (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- penalties: late-return fines
-- ---------------------------------------------------------------------
CREATE TABLE penalties (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    borrowing_id    INT UNSIGNED NOT NULL,
    return_id       INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    overdue_days    INT UNSIGNED NOT NULL,
    rate_per_day    DECIMAL(10,2) NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    status          ENUM('Unpaid','Paid') NOT NULL DEFAULT 'Unpaid',
    receipt_no      VARCHAR(30)  NULL,
    paid_at         DATETIME NULL,
    payment_remarks VARCHAR(255) NULL,
    received_by     INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_penalties_return (return_id),
    UNIQUE KEY uq_penalties_receipt (receipt_no),
    KEY idx_penalties_status (status),
    KEY idx_penalties_user (user_id),
    CONSTRAINT fk_penalties_borrowing FOREIGN KEY (borrowing_id)
        REFERENCES borrowings (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_penalties_return FOREIGN KEY (return_id)
        REFERENCES returns (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_penalties_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_penalties_admin FOREIGN KEY (received_by)
        REFERENCES admins (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- notifications: due-today / due-soon / overdue alerts per borrowing
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    borrowing_id    INT UNSIGNED NOT NULL,
    type            ENUM('due_soon','due_today','overdue') NOT NULL,
    message         VARCHAR(255) NOT NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    read_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_borrowing (borrowing_id),
    KEY idx_notifications_read (is_read),
    CONSTRAINT fk_notifications_borrowing FOREIGN KEY (borrowing_id)
        REFERENCES borrowings (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- settings: key/value configuration
-- ---------------------------------------------------------------------
CREATE TABLE settings (
    setting_key     VARCHAR(60)  NOT NULL,
    setting_value   VARCHAR(255) NOT NULL,
    description     VARCHAR(255) NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  Initial data
-- =====================================================================

-- Default administrator
--   username: admin
--   password: admin123   (change it right after the first login)
INSERT INTO admins (username, password_hash, full_name, email, status) VALUES
('admin', '$2y$10$PGpH21MfWSatbARwUo5W8eDhP.Hx4OG0ZxGWsIGlqWyfMwOVPk3v2', 'System Administrator', 'admin@library.local', 'Active');

INSERT INTO settings (setting_key, setting_value, description) VALUES
('library_name',      'School Library',  'Name shown on the interface and on printed reports'),
('penalty_rate',      '5.00',            'Penalty per overdue day (PHP)'),
('loan_period_days',  '7',               'Default number of days a book may be borrowed'),
('max_active_loans',  '3',               'Maximum books a user may borrow at the same time'),
('due_soon_days',     '3',               'Days before the due date when a reminder is generated');

INSERT INTO categories (name, description) VALUES
('Fiction',              'Novels, short stories and literary works'),
('Science',              'Physics, chemistry, biology and earth science'),
('Mathematics',          'Mathematics and statistics'),
('History',              'World and Philippine history'),
('Computer Science',     'Programming, networking and information technology'),
('Reference',            'Dictionaries, encyclopedias and almanacs'),
('Filipiniana',          'Works by Filipino authors and about the Philippines');

INSERT INTO books (isbn, title, author, publisher, category_id, publication_year, shelf_location, total_copies, available_copies) VALUES
('9789712700861', 'Noli Me Tangere',                    'Jose Rizal',              'Anvil Publishing',      7, 1887, 'FIL-01', 5, 5),
('9789712700878', 'El Filibusterismo',                  'Jose Rizal',              'Anvil Publishing',      7, 1891, 'FIL-01', 5, 5),
('9780061120084', 'To Kill a Mockingbird',              'Harper Lee',              'Harper Perennial',      1, 1960, 'FIC-03', 3, 3),
('9780451524935', 'Nineteen Eighty-Four',               'George Orwell',           'Signet Classics',       1, 1949, 'FIC-04', 2, 2),
('9780262033848', 'Introduction to Algorithms',         'Thomas H. Cormen et al.', 'MIT Press',             5, 2009, 'CS-02',  2, 2),
('9780132350884', 'Clean Code',                         'Robert C. Martin',        'Prentice Hall',         5, 2008, 'CS-03',  2, 2),
('9780553380163', 'A Brief History of Time',            'Stephen Hawking',         'Bantam',                2, 1998, 'SCI-01', 3, 3),
('9780393609394', 'Astrophysics for People in a Hurry', 'Neil deGrasse Tyson',     'W. W. Norton',          2, 2017, 'SCI-02', 2, 2),
('9780486613888', 'Calculus Made Easy',                 'Silvanus P. Thompson',    'St. Martin''s Press',   3, 1998, 'MAT-01', 2, 2),
('9780877798095', 'Merriam-Webster''s Collegiate Dictionary', 'Merriam-Webster',   'Merriam-Webster',       6, 2003, 'REF-01', 1, 1);

INSERT INTO users (id_number, first_name, middle_name, last_name, user_type, department, year_level, email, phone, status) VALUES
('2024-00001', 'Maria',   'Santos',  'Dela Cruz', 'Student', 'BS Information Technology', '3rd Year - A', 'maria.delacruz@school.edu',  '09171234567', 'Active'),
('2024-00002', 'Juan',    'Reyes',   'Bautista',  'Student', 'BS Education',              '2nd Year - B', 'juan.bautista@school.edu',   '09181234567', 'Active'),
('2024-00003', 'Angela',  NULL,      'Garcia',    'Student', 'BS Nursing',                '1st Year - C', 'angela.garcia@school.edu',   '09191234567', 'Active'),
('EMP-0101',   'Roberto', 'Lim',     'Mendoza',   'Teacher', 'College of Computing',      NULL,           'roberto.mendoza@school.edu', '09201234567', 'Active'),
('EMP-0205',   'Liza',    NULL,      'Villanueva','Staff',   'Registrar''s Office',       NULL,           'liza.villanueva@school.edu', '09211234567', 'Active');
