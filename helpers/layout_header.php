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
        ['url' => '/vendors/global.php', 'icon' => 'bi-people', 'label' => 'Vendors'],
    ],
    'analytics' => [
        ['url' => '/reports/overview.php', 'icon' => 'bi-graph-up', 'label' => 'Revenue Report'],
        ['url' => '/reports/traffic_summary.php', 'icon' => 'bi-bar-chart-line', 'label' => 'Traffic Summary'],
        ['url' => '/reports/scheduled_reports.php', 'icon' => 'bi-clock', 'label' => 'Scheduled Reports'],
        ['url' => '/clicklogs/list.php', 'icon' => 'bi-cursor', 'label' => 'Click Logs'],
        ['url' => '/convlogs/list.php', 'icon' => 'bi-check2-square', 'label' => 'Conversion Logs'],
    ],
    'email' => [
        ['url' => '/email/campaigns.php', 'icon' => 'bi-funnel', 'label' => 'Campaigns'],
        ['url' => '/email/lists.php', 'icon' => 'bi-list-check', 'label' => 'Email Lists'],
        ['url' => '/email/templates.php', 'icon' => 'bi-file-earmark-text', 'label' => 'Templates'],
        ['url' => '/email/history.php', 'icon' => 'bi-clock-history', 'label' => 'Email History'],
    ],
    'system' => [
        ['url' => '/audit/list.php', 'icon' => 'bi-shield-check', 'label' => 'Audit Log', 'roles' => ['super_admin']],
        ['url' => '/logs/view.php', 'icon' => 'bi-journal-text', 'label' => 'Logs'],
        ['url' => '/settings/index.php', 'icon' => 'bi-gear', 'label' => 'Settings', 'roles' => ['super_admin']],
        ['url' => '/settings/users.php', 'icon' => 'bi-people', 'label' => 'Users', 'roles' => ['super_admin']],
        ['url' => '/settings/vendor_portal_users.php', 'icon' => 'bi-person-badge', 'label' => 'Vendor Portal Users', 'roles' => ['super_admin']],
    ],
];

$current_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function is_nav_active($url) {
    global $current_path;
    $base = rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?: '', '/');
    $target = $base . $url;
    if ($current_path === $target) return true;
    $prefixes = [
        '/vendors/global.php' => $base . '/vendors/',
        '/clicklogs/list.php' => $base . '/clicklogs/',
        '/convlogs/list.php' => $base . '/convlogs/',
    ];
    if (isset($prefixes[$url]) && strpos($current_path, $prefixes[$url]) === 0) return true;
    return false;
}

$__current_user = current_user();
$user_name = $__current_user['username'] ?? 'User';
$user_role = $__current_user['role'] ?? 'user';
$user_initials = '';
foreach (explode(' ', $user_name) as $part) {
    $user_initials .= strtoupper(mb_substr($part, 0, 1));
    if (mb_strlen($user_initials) >= 2) break;
}
$user_initials = $user_initials ?: mb_substr($user_name, 0, 2);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') . ' — ' : ''; ?><?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?> — DOI registration and tracking platform.">
    <meta name="theme-color" content="#4f46e5">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Tailwind CSS CDN (utility classes for legacy markup) -->
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

    <!-- Track Flow Design System -->
    <link href="<?php echo BASE_URL; ?>/assets/css/app.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/app.css'); ?>" rel="stylesheet">

    <?php if (isset($extra_head)) echo $extra_head; ?>
</head>
<body class="text-slate-800 antialiased h-screen flex overflow-hidden">

    <!-- Skip links for accessibility -->
    <a class="tf-skip-link" href="#main-content">Skip to main content</a>
    <a class="tf-skip-link" href="#sidebar-nav" style="left: 14rem;">Skip to navigation</a>

    <!-- Sidebar -->
    <aside id="sidebar" class="tf-sidebar sidebar-transition sidebar-mobile-hidden" role="navigation" aria-label="Main navigation">
        <div class="tf-sidebar-header">
            <a href="<?php echo BASE_URL; ?>/dashboard.php" class="tf-sidebar-brand" aria-label="<?php echo htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8'); ?> home">
                <div>
                    <span class="tf-sidebar-brand-text"><?php echo SITE_NAME; ?></span>
                </div>
            </a>
        </div>

        <div class="tf-sidebar-nav-wrap" id="sidebar-nav">
            <?php foreach ($nav_items as $section => $items): ?>
            <div class="tf-sidebar-section">
                <p class="tf-sidebar-section-title" id="nav-group-<?php echo $section; ?>"><?php echo ucfirst($section); ?></p>
                <nav class="tf-nav" aria-labelledby="nav-group-<?php echo $section; ?>">
                    <?php foreach ($items as $item): ?>
                        <?php
                        if (isset($item['roles'])) {
                            if (!$__current_user || !in_array($__current_user['role'], $item['roles'])) continue;
                        }
                        $active = is_nav_active($item['url']);
                        $linkClass = $active ? 'tf-nav-link is-active' : 'tf-nav-link';
                        ?>
                        <a href="<?php echo BASE_URL . $item['url']; ?>"
                           class="<?php echo $linkClass; ?>"
                           <?php if ($active) echo 'aria-current="page"'; ?>>
                            <i class="bi <?php echo $item['icon']; ?> tf-nav-icon" aria-hidden="true"></i>
                            <span class="tf-nav-label"><?php echo $item['label']; ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="tf-sidebar-footer">
            <a href="<?php echo BASE_URL; ?>/auth.php?action=logout" class="tf-nav-link tf-nav-link-danger">
                <i class="bi bi-box-arrow-left tf-nav-icon" aria-hidden="true"></i>
                <span class="tf-nav-label">Logout</span>
            </a>
        </div>
    </aside>

    <!-- Mobile sidebar backdrop -->
    <div id="sidebar-backdrop" class="tf-sidebar-backdrop" aria-hidden="true" onclick="toggleSidebar()"></div>

    <!-- Main Content -->
    <main id="main-content" class="tf-main flex-1 flex flex-col min-w-0 overflow-hidden bg-slate-50 h-screen" tabindex="-1">
        <!-- Topbar -->
        <header class="tf-topbar">
            <div class="tf-topbar-inner">
                <div class="flex items-center gap-4">
                    <button type="button" class="lg:hidden text-slate-300 hover:text-white p-2 -ml-2 rounded" onclick="toggleSidebar()" aria-label="Open sidebar" aria-expanded="false" aria-controls="sidebar">
                        <i class="bi bi-list text-2xl" aria-hidden="true"></i>
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

                    <div class="tf-dropdown tf-user-menu">
                        <button type="button" class="tf-user-chip tf-dropdown-trigger" aria-haspopup="true" aria-expanded="false" aria-label="User menu: <?php echo sanitize($user_name); ?>">
                            <span class="tf-visually-hidden">User:</span>
                            <span class="tf-user-avatar" aria-hidden="true"><?php echo sanitize($user_initials); ?></span>
                            <span><?php echo sanitize($user_name); ?></span>
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </button>
                        <ul class="tf-dropdown-menu" hidden role="menu">
                            <li role="none">
                                <span class="tf-dropdown-item" role="menuitem" tabindex="-1">
                                    <i class="bi bi-person" aria-hidden="true"></i>
                                    Role: <?php echo ucfirst(str_replace('_', ' ', $user_role)); ?>
                                </span>
                            </li>
                            <li role="none">
                                <a href="<?php echo BASE_URL; ?>/settings/index.php" class="tf-dropdown-item" role="menuitem">
                                    <i class="bi bi-gear" aria-hidden="true"></i>
                                    Settings
                                </a>
                            </li>
                            <li class="tf-dropdown-divider" role="separator"></li>
                            <li role="none">
                                <a href="<?php echo BASE_URL; ?>/auth.php?action=logout" class="tf-dropdown-item is-danger" role="menuitem">
                                    <i class="bi bi-box-arrow-left" aria-hidden="true"></i>
                                    Logout
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <!-- Flash Messages & Page Content -->
        <div class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
            <?php
            $flash = get_flash();
            if ($flash):
                $alertClasses = [
                    'success' => 'alert-success',
                    'danger' => 'alert-danger',
                    'warning' => 'alert-warning',
                    'info' => 'alert-info',
                ];
                $alertClass = $alertClasses[$flash['type']] ?? 'alert-info';
                $liveRegion = $flash['type'] === 'danger' ? 'role="alert" aria-live="assertive"' : 'role="status" aria-live="polite"';
            ?>
            <div class="alert <?php echo $alertClass; ?>" <?php echo $liveRegion; ?> id="flash-alert">
                <div class="alert-body"><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                <button type="button" class="alert-close" aria-label="Dismiss message" onclick="document.getElementById('flash-alert').remove()">
                    <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
            </div>
            <?php endif; ?>
