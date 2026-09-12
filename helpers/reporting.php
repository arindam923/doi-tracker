<?php

/** Shared reporting filters, formulas, and aggregate queries. */

function tf_reporting_periods() {
    return ['daily', 'weekly', 'monthly', 'custom'];
}

function tf_reporting_groups() {
    return ['vendor', 'project', 'client', 'country', 'device'];
}

function tf_reporting_date($value, $fallback) {
    $v = is_array($value) ? $fallback : (string)$value;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $fallback;
    [$y,$m,$d]=explode('-',$v);
    return checkdate((int)$m,(int)$d,(int)$y) ? $v : $fallback;
}

function tf_reporting_normalize_filters(array $input, array $defaults = []) {
    $today = date('Y-m-d');
    $default_from = $defaults['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $period_raw = is_array($input['period'] ?? null) ? '' : (string)($input['period'] ?? '');
    $group_raw = is_array($input['group'] ?? null) ? '' : (string)($input['group'] ?? '');
    $period = in_array($period_raw, tf_reporting_periods(), true) ? $period_raw : ($defaults['period'] ?? 'daily');
    $group = in_array($group_raw, tf_reporting_groups(), true) ? $group_raw : ($defaults['group'] ?? 'project');
    $from = tf_reporting_date($input['from'] ?? '', $default_from);
    $to = tf_reporting_date($input['to'] ?? '', $today);
    if ($to < $from) [$from, $to] = [$to, $from];

    return [
        'period' => $period,
        'group' => $group,
        'from' => $from,
        'to' => $to,
        'project_id' => max(0, (int)($input['project_id'] ?? 0)),
        'client_id' => max(0, (int)($input['client_id'] ?? 0)),
        'vendor_id' => max(0, (int)($input['vendor_id'] ?? 0)),
    ];
}

function tf_reporting_bucket_expression($period, $column = 'c.converted_at') {
    switch ($period) {
        case 'weekly': return "YEARWEEK($column, 3)";
        case 'monthly': return "DATE_FORMAT($column, '%Y-%m')";
        default: return "DATE($column)";
    }
}

function tf_reporting_rejected_predicate($alias = 'c') {
    return "($alias.status = 'rejected' OR $alias.approval_status = 'rejected')";
}

function tf_reporting_metrics(array $row) {
    $clicks = (int)($row['clicks'] ?? 0);
    $conversions = (int)($row['conversions'] ?? 0);
    $revenue = round((float)($row['revenue'] ?? 0), 2);
    $cost = round((float)($row['cost'] ?? 0), 2);
    $profit = round($revenue - $cost, 2);
    return [
        'clicks' => $clicks,
        'conversions' => $conversions,
        'rejected_leads' => (int)($row['rejected_leads'] ?? 0),
        'revenue' => $revenue,
        'cost' => $cost,
        'profit' => $profit,
        'roi' => $cost > 0 ? round(($profit / $cost) * 100, 2) : 0.0,
        'conversion_rate' => $clicks > 0 ? round(($conversions / $clicks) * 100, 2) : 0.0,
        'epc' => $clicks > 0 ? round($revenue / $clicks, 4) : 0.0,
    ];
}

function tf_reporting_group_sql($group, $conversion_alias = 'c', $click_alias = 'k') {
    switch ($group) {
        case 'vendor':
            return ['conversion_label' => 'gv.vendor_name', 'conversion_key' => "$conversion_alias.vendor_id", 'click_label' => 'gvk.vendor_name', 'click_key' => "$click_alias.vendor_id", 'conversion_joins' => ' JOIN global_vendors gv ON gv.id = c.vendor_id', 'click_joins' => ' JOIN global_vendors gvk ON gvk.id = k.vendor_id', 'label' => 'Vendor'];
        case 'client':
            return ['conversion_label' => 'cli.client_name', 'conversion_key' => 'p.client_id', 'click_label' => 'clik.client_name', 'click_key' => 'pk.client_id', 'conversion_joins' => ' JOIN clients cli ON cli.id = p.client_id', 'click_joins' => '', 'label' => 'Client'];
        case 'country':
            return ['conversion_label' => "COALESCE(ck.country_code, 'Unknown')", 'conversion_key' => "COALESCE(ck.country_code, '')", 'click_label' => "COALESCE(k.country_code, 'Unknown')", 'click_key' => "COALESCE(k.country_code, '')", 'conversion_joins' => ' LEFT JOIN clicks ck ON ck.click_id = c.click_id', 'click_joins' => '', 'label' => 'Country'];
        case 'device':
            return ['conversion_label' => "COALESCE(ck.device_type, 'Unknown')", 'conversion_key' => "COALESCE(ck.device_type, '')", 'click_label' => "COALESCE(k.device_type, 'Unknown')", 'click_key' => "COALESCE(k.device_type, '')", 'conversion_joins' => ' LEFT JOIN clicks ck ON ck.click_id = c.click_id', 'click_joins' => '', 'label' => 'Device'];
        default:
            return ['conversion_label' => 'p.project_code', 'conversion_key' => "$conversion_alias.project_id", 'click_label' => 'pk.project_code', 'click_key' => "$click_alias.project_id", 'conversion_joins' => '', 'click_joins' => '', 'label' => 'Project'];
    }
}

function tf_reporting_filter_sql(array $filters, $alias, $project_column, $client_column = null, $vendor_column = null) {
    $where = [];
    $params = [$filters['from'], $filters['to']];
    if ($filters['project_id']) { $where[] = "$project_column = ?"; $params[] = $filters['project_id']; }
    if ($filters['client_id'] && $client_column) { $where[] = "$client_column = ?"; $params[] = $filters['client_id']; }
    if ($filters['vendor_id'] && $vendor_column) { $where[] = "$vendor_column = ?"; $params[] = $filters['vendor_id']; }
    return ['where' => implode(' AND ', $where), 'params' => $params, 'date' => "$alias" . ($alias === 'c' ? '.converted_at' : '.clicked_at') . " BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)"];
}

function tf_reporting_build_report(PDO $pdo, array $input) {
    $filters = tf_reporting_normalize_filters($input);
    $group = tf_reporting_group_sql($filters['group']);
    $date_c = 'c.converted_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)';
    $where_c = [$date_c];
    $params_c = [$filters['from'], $filters['to']];
    if ($filters['project_id']) { $where_c[] = 'c.project_id = ?'; $params_c[] = $filters['project_id']; }
    if ($filters['client_id']) { $where_c[] = 'p.client_id = ?'; $params_c[] = $filters['client_id']; }
    if ($filters['vendor_id']) { $where_c[] = 'c.vendor_id = ?'; $params_c[] = $filters['vendor_id']; }
    $sql_c = "SELECT {$group['conversion_label']} AS label,
        COUNT(CASE WHEN c.status = 'complete' AND c.approval_status = 'approved' THEN 1 END) AS conversions,
        SUM(CASE WHEN c.status = 'complete' AND c.approval_status = 'approved' THEN c.client_revenue ELSE 0 END) AS revenue,
        SUM(CASE WHEN c.status = 'complete' AND c.approval_status = 'approved' THEN c.vendor_cost ELSE 0 END) AS cost,
        SUM(CASE WHEN " . tf_reporting_rejected_predicate() . " THEN 1 ELSE 0 END) AS rejected_leads
        FROM conversions c JOIN projects p ON p.id = c.project_id {$group['conversion_joins']}
        WHERE " . implode(' AND ', $where_c) . " GROUP BY {$group['conversion_key']} ORDER BY revenue DESC";
    $stmt = $pdo->prepare($sql_c); $stmt->execute($params_c); $conversion_rows = $stmt->fetchAll();

    $where_k = ['k.clicked_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)'];
    $params_k = [$filters['from'], $filters['to']];
    if ($filters['project_id']) { $where_k[] = 'k.project_id = ?'; $params_k[] = $filters['project_id']; }
    if ($filters['client_id']) { $where_k[] = 'pk.client_id = ?'; $params_k[] = $filters['client_id']; }
    if ($filters['vendor_id']) { $where_k[] = 'k.vendor_id = ?'; $params_k[] = $filters['vendor_id']; }
    $click_project_join = in_array($filters['group'], ['client'], true) || $filters['client_id'] ? ' JOIN projects pk ON pk.id = k.project_id' : ($filters['group'] === 'project' ? ' JOIN projects pk ON pk.id = k.project_id' : '');
    $click_group = $group['click_key'];
    $click_label = $group['click_label'];
    if ($filters['group'] === 'client') $click_project_join = ' JOIN projects pk ON pk.id = k.project_id JOIN clients clik ON clik.id = pk.client_id';
    $sql_k = "SELECT $click_label AS label, COUNT(*) AS clicks FROM clicks k $click_project_join {$group['click_joins']} WHERE " . implode(' AND ', $where_k) . " GROUP BY $click_group";
    $stmt = $pdo->prepare($sql_k); $stmt->execute($params_k); $click_rows = $stmt->fetchAll();

    $merged = [];
    foreach ($click_rows as $row) $merged[(string)$row['label']] = ['label' => $row['label'], 'clicks' => (int)$row['clicks'], 'conversions' => 0, 'rejected_leads' => 0, 'revenue' => 0, 'cost' => 0];
    foreach ($conversion_rows as $row) $merged[(string)$row['label']] = array_merge($merged[(string)$row['label']] ?? ['label' => $row['label'], 'clicks' => 0], $row);
    $rows = [];
    foreach ($merged as $row) $rows[] = ['label' => $row['label']] + tf_reporting_metrics($row);

    $totals_raw = ['clicks' => 0, 'conversions' => 0, 'rejected_leads' => 0, 'revenue' => 0, 'cost' => 0];
    foreach ($rows as $row) foreach (array_keys($totals_raw) as $key) $totals_raw[$key] += (float)($row[$key] ?? 0);
    $totals = tf_reporting_metrics($totals_raw);
    $bucket = tf_reporting_bucket_expression($filters['period'], 'c.converted_at');
    $trend_where = [$date_c]; $trend_params = [$filters['from'], $filters['to']];
    if ($filters['project_id']) { $trend_where[] = 'c.project_id = ?'; $trend_params[] = $filters['project_id']; }
    if ($filters['client_id']) { $trend_where[] = 'p.client_id = ?'; $trend_params[] = $filters['client_id']; }
    if ($filters['vendor_id']) { $trend_where[] = 'c.vendor_id = ?'; $trend_params[] = $filters['vendor_id']; }
    $trend_sql = "SELECT $bucket AS bucket,
        COUNT(CASE WHEN c.status = 'complete' AND c.approval_status = 'approved' THEN 1 END) AS conversions,
        SUM(CASE WHEN c.status = 'complete' AND c.approval_status = 'approved' THEN c.client_revenue ELSE 0 END) AS revenue,
        SUM(CASE WHEN c.status = 'complete' AND c.approval_status = 'approved' THEN c.vendor_cost ELSE 0 END) AS cost,
        SUM(CASE WHEN " . tf_reporting_rejected_predicate() . " THEN 1 ELSE 0 END) AS rejected_leads
        FROM conversions c JOIN projects p ON p.id = c.project_id
        WHERE " . implode(' AND ', $trend_where) . " GROUP BY bucket ORDER BY bucket";
    $stmt = $pdo->prepare($trend_sql); $stmt->execute($trend_params); $trend_conversions = $stmt->fetchAll();
    $click_bucket = tf_reporting_bucket_expression($filters['period'], 'k.clicked_at');
    $click_where = ['k.clicked_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)']; $click_params = [$filters['from'], $filters['to']];
    if ($filters['project_id']) { $click_where[] = 'k.project_id = ?'; $click_params[] = $filters['project_id']; }
    if ($filters['client_id']) { $click_where[] = 'pk.client_id = ?'; $click_params[] = $filters['client_id']; }
    if ($filters['vendor_id']) { $click_where[] = 'k.vendor_id = ?'; $click_params[] = $filters['vendor_id']; }
    $trend_project_join = $filters['client_id'] ? ' JOIN projects pk ON pk.id = k.project_id' : '';
    $stmt = $pdo->prepare("SELECT $click_bucket AS bucket, COUNT(*) AS clicks FROM clicks k $trend_project_join WHERE " . implode(' AND ', $click_where) . ' GROUP BY bucket ORDER BY bucket');
    $stmt->execute($click_params); $trend_clicks = $stmt->fetchAll();
    $trend_map = [];
    foreach ($trend_clicks as $row) $trend_map[(string)$row['bucket']] = ['bucket' => $row['bucket'], 'clicks' => (int)$row['clicks'], 'conversions' => 0, 'rejected_leads' => 0, 'revenue' => 0, 'cost' => 0];
    foreach ($trend_conversions as $row) $trend_map[(string)$row['bucket']] = array_merge($trend_map[(string)$row['bucket']] ?? ['bucket' => $row['bucket'], 'clicks' => 0], $row);
    $trend = [];
    foreach ($trend_map as $row) $trend[] = ['bucket' => $row['bucket']] + tf_reporting_metrics($row);
    return ['filters' => $filters, 'group_label' => $group['label'], 'rows' => $rows, 'totals' => $totals, 'trend' => $trend];
}
