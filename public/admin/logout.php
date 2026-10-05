<?php
require_once __DIR__ . '/../../includes/admin-auth.php';

// Ends the admin session and returns to the login page.
logAdminOut();
header('Location: /admin/login.php');
exit;
