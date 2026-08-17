<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$project_id = intval($_GET['project_id'] ?? 0);
if ($project_id) {
    redirect(BASE_URL . '/projects/detail.php?id=' . $project_id . '#email-campaigns');
}
redirect(BASE_URL . '/projects/list.php');
