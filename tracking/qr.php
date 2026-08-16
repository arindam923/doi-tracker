<?php
/**
 * Track Flow — QR page for a tracking short code.
 * Generates the QR locally (SVG). Google Chart API is retired and
 * InfinityFree cannot reach outbound image APIs.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/qr_svg.php';
require_login();

$code = trim($_GET['c'] ?? '');
if (!$code || !preg_match('/^[A-Za-z0-9_-]{4,32}$/', $code)) {
    http_response_code(400);
    die('Invalid code.');
}

$found = false;
$stmt = $pdo->prepare("SELECT 1 FROM short_links WHERE code = ? LIMIT 1");
$stmt->execute([$code]);
if ($stmt->fetch()) $found = true;
$stmt->closeCursor();
if (!$found) {
    $stmt = $pdo->prepare("SELECT 1 FROM projects WHERE short_code = ? LIMIT 1");
    $stmt->execute([$code]);
    if ($stmt->fetch()) $found = true;
    $stmt->closeCursor();
}
if (!$found) {
    http_response_code(404);
    die('Tracking link not found.');
}

$url = rtrim(BASE_URL, '/') . '/c/' . $code;
$size = max(180, min(640, intval($_GET['size'] ?? 320)));
$svg = tf_qr_svg($url, $size);

$page_title = 'Tracking QR';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title . ' — ' . SITE_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="<?php echo BASE_URL; ?>/assets/css/app.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/app.css'); ?>" rel="stylesheet">
    <style>
        .tf-qr-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; background: #f8fafc; }
        .tf-qr-card { width: min(28rem, 100%); background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.5rem; text-align: center; }
        .tf-qr-card h1 { font-size: 1.15rem; margin: 0 0 .35rem; }
        .tf-qr-card p { color: #64748b; font-size: .9rem; margin: 0 0 1rem; }
        .tf-qr-art { display: flex; justify-content: center; margin: 0 0 1rem; }
        .tf-qr-art svg { width: min(320px, 100%); height: auto; border: 1px solid #e2e8f0; border-radius: .5rem; background: #fff; }
        .tf-qr-url { font-family: ui-monospace, monospace; font-size: .75rem; word-break: break-all; background: #f1f5f9; border-radius: .5rem; padding: .75rem; }
        @media print { .tf-qr-actions { display: none; } .tf-qr-page { background: #fff; padding: 0; } .tf-qr-card { border: 0; } }
    </style>
</head>
<body>
    <main class="tf-qr-page">
        <div class="tf-qr-card">
            <h1>Scan to open tracking link</h1>
            <p>Code <code><?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></code></p>
            <div class="tf-qr-art"><?php echo $svg; ?></div>
            <div class="tf-qr-url"><?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="tf-qr-actions" style="display:flex;gap:.5rem;justify-content:center;margin-top:1rem;">
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Print</button>
                <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); ?>">Open link</a>
            </div>
        </div>
    </main>
</body>
</html>
