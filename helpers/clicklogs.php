<?php

/**
 * Normalize Click Logs filters and return safe SQL conditions/parameters.
 */
function tf_clicklogs_filters(array $input): array
{
    $date = static function ($value, $fallback): string {
        $v = is_array($value) ? $fallback : (string)$value;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $fallback;
        [$y,$m,$d]=explode('-',$v);
        return checkdate((int)$m,(int)$d,(int)$y) ? $v : $fallback;
    };

    $from = $date($input['from'] ?? '', date('Y-m-d', strtotime('-7 days')));
    $to = $date($input['to'] ?? '', date('Y-m-d'));
    if ($from > $to) [$from, $to] = [$to, $from];

    $sf = static function($k) use ($input) { $v=$input[$k]??''; return is_array($v)?'':trim((string)$v); };
    $filters = [
        'from' => $from,
        'to' => $to,
        'vendor_id' => max(0, (int)(is_array($input['vendor_id']??null)?0:($input['vendor_id']??0))),
        'project_id' => max(0, (int)(is_array($input['project_id']??null)?0:($input['project_id']??0))),
        'country' => strtoupper(substr($sf('country'), 0, 2)),
        'device' => strtolower($sf('device')),
        'browser' => $sf('browser'),
        'os' => $sf('os'),
        'isp' => $sf('isp'),
        'ip_address' => $sf('ip_address'),
        'click_id' => $sf('click_id'),
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
    foreach ([['browser', 'c.browser LIKE ? ESCAPE \'\\\''], ['os', 'c.os LIKE ? ESCAPE \'\\\''], ['isp', 'c.isp LIKE ? ESCAPE \'\\\''], ['ip_address', 'c.ip_address LIKE ? ESCAPE \'\\\'']] as [$key, $sql]) {
        if ($filters[$key] !== '') {
            $where[] = $sql;
            $params[] = '%' . tf_like_escape($filters[$key]) . '%';
        }
    }
    if ($filters['click_id'] !== '') { $where[] = 'c.click_id = ?'; $params[] = $filters['click_id']; }

    $filters['where_sql'] = 'WHERE ' . implode(' AND ', $where);
    $filters['params'] = $params;
    return $filters;
}
