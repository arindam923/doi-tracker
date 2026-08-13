<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();

// ─── Fetch Stats ───
$today = date('Y-m-d');
$today_label = date('l, F j, Y');

$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM projects WHERE status = 'live'");
$stmt->execute();
$active_projects = (int)$stmt->fetch()['cnt'];

$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM clicks WHERE DATE(clicked_at) = ?" . tf_not_test_sql('clicks'));
$stmt->execute([$today]);
$clicks_today = (int)$stmt->fetch()['cnt'];

$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM conversions WHERE DATE(converted_at) = ? AND status = 'complete'" . tf_not_test_sql('conversions'));
$stmt->execute([$today]);
$completes_today = (int)$stmt->fetch()['cnt'];

$stmt = $pdo->prepare("SELECT COALESCE(SUM(client_revenue), 0) as total FROM conversions WHERE DATE(converted_at) = ? AND status = 'complete'" . tf_not_test_sql('conversions'));
$stmt->execute([$today]);
$revenue_today = (float)$stmt->fetch()['total'];

$stmt = $pdo->prepare("SELECT COALESCE(SUM(vendor_cost), 0) as total FROM conversions WHERE DATE(converted_at) = ? AND status = 'complete'" . tf_not_test_sql('conversions'));
$stmt->execute([$today]);
$cost_today = (float)$stmt->fetch()['total'];

$profit_today = $revenue_today - $cost_today;
$profit_margin = $revenue_today > 0 ? round(($profit_today / $revenue_today) * 100, 1) : 0;
$roi_today = $cost_today > 0 ? round(($profit_today / $cost_today) * 100, 1) : 0;
$ccr_today = calc_ccr($completes_today, $clicks_today);

$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM global_vendors WHERE vendor_status = 'approved'");
$stmt->execute();
$active_vendors = (int)$stmt->fetch()['cnt'];

$top_campaigns = $pdo->query("
    SELECT p.id, p.project_code, p.project_name,
           COUNT(c.id) AS completes,
           COALESCE(SUM(c.profit), 0) AS profit
    FROM conversions c
    JOIN projects p ON p.id = c.project_id
    WHERE c.status = 'complete' AND COALESCE(c.is_test, 0) = 0 AND c.converted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY p.id
    ORDER BY completes DESC
    LIMIT 5
")->fetchAll();

$recent_conversions = $pdo->query("
    SELECT c.click_id, c.converted_at, c.client_revenue, c.profit, p.project_code, gv.vendor_name
    FROM conversions c
    JOIN projects p ON p.id = c.project_id
    JOIN global_vendors gv ON gv.id = c.vendor_id
    WHERE c.status = 'complete' AND COALESCE(c.is_test, 0) = 0
    ORDER BY c.converted_at DESC
    LIMIT 8
")->fetchAll();

// ─── Recent Projects ───
$stmt = $pdo->prepare("
    SELECT p.*, c.client_name
    FROM projects p
    LEFT JOIN clients c ON p.client_id = c.id
    ORDER BY p.updated_at DESC
    LIMIT 10
");
$stmt->execute();
$recent_projects = $stmt->fetchAll();

// ─── Chart Data: Last 7 days ───
$chart_click_data = [];
$chart_conv_data = [];

$cc_stmt = $pdo->prepare("SELECT DATE(clicked_at) as d, COUNT(*) as cnt FROM clicks WHERE clicked_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND COALESCE(is_test, 0) = 0 GROUP BY DATE(clicked_at)");
$cc_stmt->execute();
while ($row = $cc_stmt->fetch()) { $chart_click_data[$row['d']] = (int)$row['cnt']; }

$ccv_stmt = $pdo->prepare("SELECT DATE(converted_at) as d, COUNT(*) as cnt FROM conversions WHERE status = 'complete' AND COALESCE(is_test, 0) = 0 AND converted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY DATE(converted_at)");
$ccv_stmt->execute();
while ($row = $ccv_stmt->fetch()) { $chart_conv_data[$row['d']] = (int)$row['cnt']; }

$chart_labels = [];
$chart_clicks = [];
$chart_conversions = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $chart_labels[] = date('M d', strtotime($d));
    $chart_clicks[] = $chart_click_data[$d] ?? 0;
    $chart_conversions[] = $chart_conv_data[$d] ?? 0;
}

$first_name = explode(' ', $user['username'] ?? 'there')[0];
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$page_title = 'Dashboard';
require_once __DIR__ . '/helpers/layout_header.php';
?>

<!-- Hero Header -->
<section class="tf-hero" aria-labelledby="dashboard-greeting">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-4 position-relative" style="z-index: 1;">
        <div>
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <span class="tf-hero-pill"><span class="dot" aria-hidden="true"></span>Today</span>
                <span class="tf-hero-pill"><i class="bi bi-calendar-event" aria-hidden="true"></i> <?php echo $today_label; ?></span>
            </div>
            <h1 id="dashboard-greeting" class="tf-hero-title"><?php echo $greeting; ?>, <?php echo sanitize($first_name); ?></h1>
            <p class="tf-hero-subtitle">Here's what's happening with your campaigns today</p>
        </div>
        <div class="d-flex gap-4 flex-wrap">
            <div>
                <div style="font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; opacity: .75; font-weight: 700;">Net Profit</div>
                <div style="font-size: 1.75rem; font-weight: 800; line-height: 1.1; margin-top: .25rem;"><?php echo format_currency($profit_today); ?></div>
            </div>
            <div>
                <div style="font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; opacity: .75; font-weight: 700;">Revenue</div>
                <div style="font-size: 1.75rem; font-weight: 800; line-height: 1.1; margin-top: .25rem;"><?php echo format_currency($revenue_today); ?></div>
            </div>
            <div>
                <div style="font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; opacity: .75; font-weight: 700;">ROI</div>
                <div style="font-size: 1.75rem; font-weight: 800; line-height: 1.1; margin-top: .25rem;"><?php echo $roi_today; ?>%</div>
            </div>
            <div>
                <div style="font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; opacity: .75; font-weight: 700;">Cost</div>
                <div style="font-size: 1.75rem; font-weight: 800; line-height: 1.1; margin-top: .25rem;"><?php echo format_currency($cost_today); ?></div>
            </div>
        </div>
    </div>
</section>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4 col-xl-2">
        <article class="tf-stat">
            <div class="tf-stat-body">
                <p class="tf-stat-label">Active Projects</p>
                <p class="tf-stat-value"><?php echo number_format($active_projects); ?></p>
            </div>
            <div class="tf-stat-icon is-indigo" aria-hidden="true"><i class="bi bi-folder2-open"></i></div>
        </article>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <article class="tf-stat">
            <div class="tf-stat-body">
                <p class="tf-stat-label">Clicks Today</p>
                <p class="tf-stat-value"><?php echo number_format($clicks_today); ?></p>
            </div>
            <div class="tf-stat-icon is-blue" aria-hidden="true"><i class="bi bi-cursor-fill"></i></div>
        </article>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <article class="tf-stat">
            <div class="tf-stat-body">
                <p class="tf-stat-label">Completes Today</p>
                <p class="tf-stat-value"><?php echo number_format($completes_today); ?></p>
            </div>
            <div class="tf-stat-icon is-emerald" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></div>
        </article>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <article class="tf-stat">
            <div class="tf-stat-body">
                <p class="tf-stat-label">CCR Today</p>
                <p class="tf-stat-value"><?php echo $ccr_today; ?>%</p>
            </div>
            <div class="tf-stat-icon is-amber" aria-hidden="true"><i class="bi bi-percent"></i></div>
        </article>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <article class="tf-stat">
            <div class="tf-stat-body">
                <p class="tf-stat-label">Revenue Today</p>
                <p class="tf-stat-value is-currency is-positive"><?php echo format_currency($revenue_today); ?></p>
            </div>
            <div class="tf-stat-icon is-emerald" aria-hidden="true"><i class="bi bi-arrow-up-circle-fill"></i></div>
        </article>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <article class="tf-stat">
            <div class="tf-stat-body">
                <p class="tf-stat-label">Cost Today</p>
                <p class="tf-stat-value is-currency is-negative"><?php echo format_currency($cost_today); ?></p>
            </div>
            <div class="tf-stat-icon is-red" aria-hidden="true"><i class="bi bi-arrow-down-circle-fill"></i></div>
        </article>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <article class="tf-stat">
            <div class="tf-stat-body">
                <p class="tf-stat-label">Active Vendors</p>
                <p class="tf-stat-value"><?php echo number_format($active_vendors); ?></p>
            </div>
            <div class="tf-stat-icon is-indigo" aria-hidden="true"><i class="bi bi-people-fill"></i></div>
        </article>
    </div>
</div>

<!-- Chart & Recent Projects -->
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-8">
        <section class="tf-chart-card h-100" aria-labelledby="chart-title">
            <div class="tf-chart-header">
                <div>
                    <h5 id="chart-title">Activity Overview</h5>
                    <p class="tf-chart-subtitle">Clicks and conversions over the last 7 days</p>
                </div>
                <div class="tf-chart-toolbar">
                    <div class="tf-chart-legend" aria-hidden="true">
                        <span class="tf-chart-legend-item"><span class="tf-chart-legend-dot" style="background: var(--tf-primary-500);"></span>Clicks</span>
                        <span class="tf-chart-legend-item"><span class="tf-chart-legend-dot" style="background: var(--tf-success-600);"></span>Conversions</span>
                    </div>
                    <div class="tf-chart-toggle" role="tablist" aria-label="Chart view">
                        <button type="button" class="view-btn is-active" data-view="bar" role="tab" aria-selected="true" aria-label="Bar chart view">
                            <i class="bi bi-bar-chart-fill" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="view-btn" data-view="line" role="tab" aria-selected="false" aria-label="Line chart view">
                            <i class="bi bi-graph-up" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div style="height: 360px; position: relative;">
                    <canvas id="dashboardChart" role="img" aria-label="Bar chart showing clicks and conversions over the last 7 days"></canvas>
                </div>
            </div>
        </section>
    </div>

    <div class="col-12 col-xl-4">
        <section class="tf-card h-100" aria-labelledby="recent-projects-title">
            <div class="tf-card-header">
                <div>
                    <h5 id="recent-projects-title" class="tf-card-title">Recent Projects</h5>
                    <p class="tf-card-subtitle">Latest campaign activity</p>
                </div>
                <a href="<?php echo BASE_URL; ?>/projects/list.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="tf-card-body is-flush" style="max-height: 420px; overflow-y: auto;">
                <?php if (empty($recent_projects)): ?>
                    <div class="tf-empty">
                        <i class="bi bi-folder2-open" aria-hidden="true"></i>
                        <h5>No projects yet</h5>
                        <p>Get started by creating your first campaign project.</p>
                            <a href="<?php echo BASE_URL; ?>/projects/create.php" class="tf-empty-action" aria-label="Create new project">
                                <span class="tf-create-icon" aria-hidden="true">+</span>
                                <span>Create Project</span>
                            </a>
                    </div>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach (array_slice($recent_projects, 0, 5) as $p): ?>
                        <li class="project-row">
                            <div class="proj-icon" aria-hidden="true"><i class="bi bi-folder2-open"></i></div>
                            <div class="min-w-0 flex-grow-1">
                                <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo $p['id']; ?>" class="project-name d-block text-truncate">
                                    <?php echo sanitize($p['project_name']); ?>
                                    <span class="tf-visually-hidden">project code <?php echo sanitize($p['project_code']); ?></span>
                                </a>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <span class="project-code"><?php echo sanitize($p['project_code']); ?></span>
                                    <span class="client-name text-truncate"><?php echo sanitize($p['client_name'] ?? '—'); ?></span>
                                </div>
                            </div>
                            <div class="flex-shrink-0">
                                <?php echo status_badge($p['status']); ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-xl-6">
        <section class="tf-card h-100" aria-labelledby="top-campaigns-title">
            <div class="tf-card-header">
                <div>
                    <h5 id="top-campaigns-title" class="tf-card-title">Top Campaigns</h5>
                    <p class="tf-card-subtitle">Completes over the last 7 days</p>
                </div>
                <a href="<?php echo BASE_URL; ?>/reports/overview.php" class="btn btn-sm btn-outline-primary">Reports</a>
            </div>
            <div class="tf-card-body is-flush">
                <?php if (empty($top_campaigns)): ?>
                    <div class="tf-empty"><p class="mb-0">No conversions in the last 7 days.</p></div>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($top_campaigns as $campaign): ?>
                        <li class="project-row">
                            <div class="min-w-0 flex-grow-1">
                                <a href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo (int)$campaign['id']; ?>" class="project-name d-block text-truncate">
                                    <?php echo sanitize($campaign['project_name']); ?>
                                </a>
                                <div class="project-code"><?php echo sanitize($campaign['project_code']); ?></div>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <div class="fw-semibold"><?php echo number_format((int)$campaign['completes']); ?></div>
                                <div class="small text-muted"><?php echo format_currency($campaign['profit']); ?></div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="col-12 col-xl-6">
        <section class="tf-card h-100" aria-labelledby="recent-conversions-title">
            <div class="tf-card-header">
                <div>
                    <h5 id="recent-conversions-title" class="tf-card-title">Recent Conversions</h5>
                    <p class="tf-card-subtitle">Live postbacks, tests excluded</p>
                </div>
                <a href="<?php echo BASE_URL; ?>/convlogs/list.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="tf-card-body is-flush">
                <?php if (empty($recent_conversions)): ?>
                    <div class="tf-empty"><p class="mb-0">No conversions yet.</p></div>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($recent_conversions as $conversion): ?>
                        <li class="project-row">
                            <div class="min-w-0 flex-grow-1">
                                <div class="project-name"><?php echo sanitize($conversion['project_code']); ?></div>
                                <div class="small text-muted"><?php echo sanitize($conversion['vendor_name']); ?> · <code><?php echo sanitize(substr($conversion['click_id'], 0, 10)); ?></code></div>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <div class="fw-semibold"><?php echo format_currency($conversion['client_revenue']); ?></div>
                                <div class="small text-muted"><?php echo sanitize($conversion['converted_at']); ?></div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<style>
    .project-row {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .875rem 1.25rem;
        border-bottom: 1px solid var(--tf-border);
        transition: background-color var(--tf-transition-fast);
    }
    .project-row:last-child { border-bottom: 0; }
    .project-row:hover { background-color: var(--tf-surface-muted); }
    .project-row .proj-icon {
        width: 2.25rem;
        height: 2.25rem;
        border-radius: var(--tf-radius-sm);
        background: linear-gradient(135deg, var(--tf-primary-50), var(--tf-primary-100));
        color: var(--tf-primary-700);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }
    .project-row .project-name {
        color: var(--tf-text);
        font-weight: 700;
        font-size: 0.875rem;
        text-decoration: none;
        line-height: 1.3;
    }
    .project-row .project-name:hover { color: var(--tf-primary-600); text-decoration: none; }
    .project-row .project-name:focus-visible { outline-offset: 2px; }
    .project-row .client-name { color: var(--tf-muted); font-size: 0.75rem; font-weight: 500; }
    @media (prefers-color-scheme: dark) {
        .project-row .proj-icon { background: rgba(99, 102, 241, 0.15); color: #a5b4fc; }
    }

    .tf-empty-action {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        padding: .6rem 1.25rem;
        background: linear-gradient(135deg, var(--tf-primary-600), var(--tf-primary-700));
        color: #fff;
        border-radius: var(--tf-radius-full);
        font-weight: 600;
        font-size: 0.875rem;
        text-decoration: none;
        box-shadow: 0 6px 20px -4px rgba(79, 70, 229, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.18);
        transition: transform var(--tf-transition-fast), box-shadow var(--tf-transition-fast), background-color var(--tf-transition-fast);
    }
    .tf-empty-action:hover {
        color: #fff;
        text-decoration: none;
        transform: translateY(-1px);
        box-shadow: 0 10px 24px -4px rgba(79, 70, 229, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.18);
        background: linear-gradient(135deg, var(--tf-primary-700), var(--tf-primary-800));
    }
    .tf-create-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 1.35rem;
        height: 1.35rem;
        border-radius: var(--tf-radius-full);
        background: rgba(255, 255, 255, 0.22);
        font-size: 1rem;
        font-weight: 700;
        line-height: 1;
        flex-shrink: 0;
    }
</style>

<?php
$chart_labels_json = json_encode($chart_labels);
$chart_clicks_json = json_encode($chart_clicks);
$chart_conv_json = json_encode($chart_conversions);

$extra_js = <<<EOT
<script>
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.color = '#64748b';
Chart.defaults.borderColor = '#f1f5f9';

const labels = {$chart_labels_json};
const clicksData = {$chart_clicks_json};
const convData = {$chart_conv_json};

const clicksBar = (ctx) => {
    const c = ctx.chart.ctx;
    const g = c.createLinearGradient(0, 0, 0, 320);
    g.addColorStop(0, 'rgba(99, 102, 241, .95)');
    g.addColorStop(1, 'rgba(99, 102, 241, .55)');
    return g;
};
const convBar = (ctx) => {
    const c = ctx.chart.ctx;
    const g = c.createLinearGradient(0, 0, 0, 320);
    g.addColorStop(0, 'rgba(16, 185, 129, .95)');
    g.addColorStop(1, 'rgba(16, 185, 129, .55)');
    return g;
};
const clicksLine = (ctx) => {
    const c = ctx.chart.ctx;
    const g = c.createLinearGradient(0, 0, 0, 320);
    g.addColorStop(0, 'rgba(99, 102, 241, .28)');
    g.addColorStop(1, 'rgba(99, 102, 241, 0)');
    return g;
};
const convLine = (ctx) => {
    const c = ctx.chart.ctx;
    const g = c.createLinearGradient(0, 0, 0, 320);
    g.addColorStop(0, 'rgba(16, 185, 129, .25)');
    g.addColorStop(1, 'rgba(16, 185, 129, 0)');
    return g;
};

const buildDatasets = (view) => view === 'bar'
    ? [
        { label: 'Clicks', data: clicksData, backgroundColor: clicksBar, hoverBackgroundColor: '#4f46e5', borderRadius: { topLeft: 6, topRight: 6 }, borderSkipped: false, barPercentage: 0.65, categoryPercentage: 0.7 },
        { label: 'Conversions', data: convData, backgroundColor: convBar, hoverBackgroundColor: '#059669', borderRadius: { topLeft: 6, topRight: 6 }, borderSkipped: false, barPercentage: 0.65, categoryPercentage: 0.7 }
      ]
    : [
        { label: 'Clicks', data: clicksData, borderColor: '#6366f1', backgroundColor: clicksLine, fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#6366f1', pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2, borderWidth: 2.5 },
        { label: 'Conversions', data: convData, borderColor: '#10b981', backgroundColor: convLine, fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#10b981', pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2, borderWidth: 2.5 }
      ];

const baseOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    animation: { duration: 600, easing: 'easeOutCubic' },
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: 'rgba(15, 23, 42, .96)',
            titleColor: '#f8fafc',
            titleFont: { size: 12, weight: '700' },
            bodyColor: '#cbd5e1',
            bodyFont: { size: 13 },
            padding: { top: 10, right: 12, bottom: 10, left: 12 },
            cornerRadius: 10,
            borderColor: 'rgba(99, 102, 241, .35)',
            borderWidth: 1,
            displayColors: true,
            boxWidth: 8, boxHeight: 8, usePointStyle: true, boxPadding: 4
        }
    },
    scales: {
        x: {
            grid: { display: false, drawBorder: false },
            ticks: { maxRotation: 0, autoSkip: true, autoSkipPadding: 16, padding: 8, color: '#94a3b8', font: { size: 11 } }
        },
        y: {
            beginAtZero: true,
            grid: { color: 'rgba(148, 163, 184, .14)', drawBorder: false, tickLength: 0 },
            border: { display: false },
            ticks: { padding: 12, color: '#94a3b8', font: { size: 11 }, precision: 0 }
        }
    }
};

let chart = null;
const ctx = document.getElementById('dashboardChart');

function renderChart(view) {
    if (chart) chart.destroy();
    chart = new Chart(ctx, {
        type: view === 'bar' ? 'bar' : 'line',
        data: { labels, datasets: buildDatasets(view) },
        options: baseOptions
    });
}

renderChart('bar');

document.querySelectorAll('.tf-chart-toggle .view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tf-chart-toggle .view-btn').forEach(b => {
            b.classList.remove('is-active');
            b.setAttribute('aria-selected', 'false');
        });
        btn.classList.add('is-active');
        btn.setAttribute('aria-selected', 'true');
        renderChart(btn.dataset.view);
    });
});
</script>
EOT;

require_once __DIR__ . '/helpers/layout_footer.php';
?>
