<?php
require_once __DIR__ . '/../config.php';

if (vendor_logged_in() && isset($_SESSION['TF_VENDOR']['global_vendor_id'])) {
    audit_log($pdo, 'logout', 'vendor', $_SESSION['TF_VENDOR']['global_vendor_id'], null, ['via' => 'logout']);
}

// Destroy only the vendor namespace, leaving any admin session intact
unset($_SESSION['TF_VENDOR']);
redirect(BASE_URL . '/vendor_portal/auth.php');
