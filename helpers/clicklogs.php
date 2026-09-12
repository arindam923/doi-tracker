<?php

/**
 * Normalize Click Logs filters and return safe SQL conditions/parameters.
 */
function tf_clicklogs_filters(array $input): array
{
    $date = static function ($value, $fallback): string {
        $value = (string)$value;
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
    };

    $from = $date($input['from'] ?? '', date('Y-m-d', strtotime('-7 days')));
    $to = $date($input['to'] ?? '', date('Y-m-d'));
    if ($from > $to) [$from, $to] = [$to, $from];

    $filters = [
        'from' => $from,
        'to' => $to,
        'vendor_id' => max(0, (int)($input['vendor_id'] ?? 0)),
        'project_id' => max(0, (int)($input['project_id'] ?? 0)),
        'country' => strtoupper(substr(trim((string)($input['country'] ?? '')), 0, 2)),
        'device' => strtolower(trim((string)($input['device'] ?? ''))),
        'browser' => trim((string)($input['browser'] ?? '')),
        'os' => trim((string)($input['os'] ?? '')),
        'isp' => trim((string)($input['isp'] ?? '')),
        'ip_address' => trim((string)($input['ip_address'] ?? '')),
        'click_id' => trim((string)($input['click_id'] ?? '')),
    ];
    if (!in_array($filters['device'], ['', 'desktop', 'mobile', 'tablet'], true)) {
        $filters['device'] = '';
    }

    $where = ['c.clicked_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)'];
    $params = [$filters['from'], $filters['to']];
    foreach ([['vendor_id', 'c.vendor_id = ?'], ['project_id', 'c.project_id = ?']] as [$key, $sql]) {
        if ($filters[$key]) {
            $where[] = $sql;
            $params[] = $filters[$key];
        }
    }
    if ($filters['country'] !== '') { $where[] = 'c.country_code = ?'; $params[] = $filters['country']; }
    if ($filters['device'] !== '') { $where[] = 'c.device_type = ?'; $params[] = $filters['device']; }
    foreach ([['browser', 'c.browser LIKE ?'], ['os', 'c.os LIKE ?'], ['isp', 'c.isp LIKE ?'], ['ip_address', 'c.ip_address LIKE ?']] as [$key, $sql]) {
        if ($filters[$key] !== '') {
            $where[] = $sql;
            $params[] = '%' . $filters[$key] . '%';
        }
    }
    if ($filters['click_id'] !== '') { $where[] = 'c.click_id = ?'; $params[] = $filters['click_id']; }

    $filters['where_sql'] = 'WHERE ' . implode(' AND ', $where);
    $filters['params'] = $params;
    return $filters;
}
