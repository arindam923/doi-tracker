<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

set_flash('info', 'Use Email Campaigns to send traffic. Direct compose is no longer in the nav because it is not list-aware.');
redirect(BASE_URL . '/email/campaigns.php');
