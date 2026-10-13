<?php
require_once __DIR__ . '/includes/auth.php';

// The token prevents other sites from silently signing the admin out.
if (is_logged_in() && csrf_valid($_GET['token'] ?? null)) {
    logout_admin();
    start_secure_session();
    flash('success', 'You have been signed out.');
}

redirect('login.php');
