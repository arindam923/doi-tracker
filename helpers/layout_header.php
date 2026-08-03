<?php
/**
 * Shared layout: sidebar + topbar
 * Include this at the top of every page after config.php
 */

// Navigation structure
$nav_items = [
    'main' => [
        ['url' => '/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
    ],
    'management' => [
        ['url' => '/projects/list.php', 'icon' => 'bi-folder2-open', 'label' => 'Projects'],
        ['url' => '/clients/list.php', 'icon' => 'bi-building', 'label' => 'Clients'],
    ],
    'analytics' => [
        ['url' => '/reports/overview.php', 'icon' => 'bi-graph-up', 'label' => 'Revenue Report'],
        ['url' => '/reports/traffic_summary.php', 'icon' => 'bi-bar-chart-line', 'label' => 'Traffic Summary'],
    ],
    'email' => [
        ['url' => '/email/compose.php', 'icon' => 'bi-send', 'label' => 'Send Email'],
        ['url' => '/email/history.php', 'icon' => 'bi-clock-history', 'label' => 'Email History'],
    ],
    'system' => [
        ['url' => '/logs/view.php', 'icon' => 'bi-journal-text', 'label' => 'Logs'],
        ['url' => '/settings/index.php', 'icon' => 'bi-gear', 'label' => 'Settings', 'roles' => ['super_admin']],
        ['url' => '/settings/users.php', 'icon' => 'bi-people', 'label' => 'Users', 'roles' => ['super_admin']],
    ],
];

$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function is_nav_active($url) {
    global $current_path;
    $base = rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?: '', '/');
    $dir = dirname($base . $url);
    return strpos($current_path, $dir . '/') !== false || $current_path === $base . $url;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') . ' — ' : ''; ?><?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Bootstrap 5 (loaded before Tailwind so Tailwind utility classes still win for shared class names) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Tailwind CSS CDN (kept for the existing sidebar/topbar markup) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: { primary: '#4f46e5' }
                }
            }
        }
    </script>

    <link href="<?php echo BASE_URL; ?>/assets/css/app.css" rel="stylesheet">
    <style>
        /* Layout-specific minimal styles (sidebar + topbar) */
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .sidebar-transition { transition: transform 0.3s ease-in-out; }
        @media (max-width: 1024px) {
            .sidebar-mobile-hidden { transform: translateX(-100%); }
            .sidebar-mobile-show { transform: translateX(0); }
        }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }

        /* ─── Bootstrap theme overrides (keep indigo + Inter + soft badges) ─── */
        :root, [data-bs-theme="light"] {
            --bs-primary: #4f46e5;
            --bs-primary-rgb: 79, 70, 229;
            --bs-link-color: #4f46e5;
            --bs-link-color-rgb: 79, 70, 229;
            --bs-link-hover-color: #4338ca;
            --bs-link-hover-color-rgb: 67, 56, 202;
            --bs-border-radius: 0.5rem;
            --bs-border-radius-sm: 0.375rem;
            --bs-border-radius-lg: 0.75rem;
            --bs-body-font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            --bs-body-font-size: 0.9375rem;
            --bs-body-color: #0f172a;
            --bs-body-bg: #f8fafc;
        }
        .btn-primary {
            --bs-btn-bg: #4f46e5;
            --bs-btn-border-color: #4f46e5;
            --bs-btn-hover-bg: #4338ca;
            --bs-btn-hover-border-color: #4338ca;
            --bs-btn-active-bg: #4338ca;
            --bs-btn-active-border-color: #4338ca;
            --bs-btn-disabled-bg: #4f46e5;
            --bs-btn-disabled-border-color: #4f46e5;
        }
        .btn-outline-primary {
            --bs-btn-color: #4f46e5;
            --bs-btn-border-color: #4f46e5;
            --bs-btn-hover-bg: #4f46e5;
            --bs-btn-hover-border-color: #4f46e5;
            --bs-btn-active-bg: #4338ca;
            --bs-btn-active-border-color: #4338ca;
        }
        /* Soft badge theme — preserves the look from status_badge()/ccr_color() */
        .badge.bg-success { background-color: #d1fae5 !important; color: #047857 !important; }
        .badge.bg-warning { background-color: #fef3c7 !important; color: #b45309 !important; }
        .badge.bg-danger  { background-color: #fee2e2 !important; color: #b91c1c !important; }
        .badge.bg-info    { background-color: #dbeafe !important; color: #1e40af !important; }
        .badge.bg-light   { background-color: #f1f5f9 !important; color: #475569 !important; border-color: #e2e8f0 !important; }
        .badge.bg-primary { background-color: #e0e7ff !important; color: #4338ca !important; }
    </style>

    <?php if (isset($extra_head)) echo $extra_head; ?>
</head>
<body class="text-slate-800 antialiased min-h-screen flex">

    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar-transition sidebar-mobile-hidden fixed lg:static inset-y-0 left-0 z-50 w-64 bg-slate-900 text-slate-300 flex flex-col custom-scrollbar overflow-y-auto shrink-0 shadow-xl lg:shadow-none">
        <div class="tf-sidebar-header">
            <h4 class="tf-sidebar-brand">
                <i class="bi bi-lightning-charge-fill"></i>
                <?php echo SITE_NAME; ?>
            </h4>
            <small class="tf-sidebar-tagline">DOI Tracking Platform</small>
        </div>

        <div class="flex-1 py-4">
            <?php foreach ($nav_items as $section => $items): ?>
            <div class="tf-sidebar-section">
                <p class="tf-sidebar-section-title"><?php echo ucfirst($section); ?></p>
                <nav class="tf-nav">
                    <?php foreach ($items as $item): ?>
                        <?php
                        if (isset($item['roles'])) {
                            $user = current_user();
                            if (!$user || !in_array($user['role'], $item['roles'])) continue;
                        }
                        $active = is_nav_active($item['url']);
                        $linkClass = $active
                            ? 'tf-nav-link is-active'
                            : 'tf-nav-link';
                        ?>
                        <a href="<?php echo BASE_URL . $item['url']; ?>"
                           class="<?php echo $linkClass; ?>"<?php if ($active) echo ' aria-current="page"'; ?>>
                            <i class="bi <?php echo $item['icon']; ?> tf-nav-icon"></i>
                            <?php echo $item['label']; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="p-4 border-t border-slate-800 mt-auto">
            <a href="<?php echo BASE_URL; ?>/auth.php?action=logout" class="tf-nav-link" style="color: #fca5a5;">
                <i class="bi bi-box-arrow-left tf-nav-icon" style="color: #fca5a5;"></i> Logout
            </a>
        </div>
    </aside>

    <!-- Overlay for mobile -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-slate-900/50 z-40 hidden lg:hidden" onclick="toggleSidebar()"></div>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col min-w-0 overflow-hidden bg-slate-50 min-h-screen">
        <!-- Topbar -->
        <header class="tf-topbar">
            <div class="tf-topbar-inner">
                <div class="flex items-center gap-4">
                    <button class="lg:hidden text-slate-500 hover:text-slate-700" onclick="toggleSidebar()" aria-label="Open sidebar">
                        <i class="bi bi-list text-2xl"></i>
                    </button>
                    <?php if (isset($page_title)): ?>
                    <h1 class="tf-topbar-title"><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-4">
                    <?php if (isset($page_actions)): ?>
                    <div class="tf-topbar-actions">
                        <?php echo $page_actions; ?>
                    </div>
                    <?php endif; ?>
                    <div class="tf-user-chip">
                        <i class="bi bi-person-circle"></i>
                        <span><?php echo sanitize(current_user()['username'] ?? 'User'); ?></span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Flash Messages & Page Content -->
        <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
            <?php
            $flash = get_flash();
            if ($flash): 
                $alertClass = match($flash['type']) {
                    'success' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
                    'danger' => 'bg-red-50 text-red-800 border-red-200',
                    'warning' => 'bg-amber-50 text-amber-800 border-amber-200',
                    'info' => 'bg-blue-50 text-blue-800 border-blue-200',
                    default => 'bg-slate-50 text-slate-800 border-slate-200'
                };
            ?>
            <div class="mb-6 p-4 rounded-lg border flex justify-between items-start <?php echo $alertClass; ?>" role="alert" id="flash-alert">
                <div class="text-sm font-medium"><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                <button type="button" class="text-current opacity-70 hover:opacity-100" onclick="document.getElementById('flash-alert').remove()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <?php endif; ?>
