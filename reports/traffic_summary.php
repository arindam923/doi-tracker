<?php
// Traffic Summary is the vendor-focused entry point into the shared reporting suite.
if (!isset($_GET['group'])) $_GET['group'] = 'vendor';
require __DIR__ . '/overview.php';
