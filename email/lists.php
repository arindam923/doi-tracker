<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$vendor_id = intval($_GET['vendor_id'] ?? 0);
if ($vendor_id) {
    redirect(BASE_URL . '/vendors/edit_global.php?id=' . $vendor_id . '#email-db');
}
redirect(BASE_URL . '/vendors/global.php');
