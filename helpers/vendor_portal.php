<?php

/**
 * Return whether a master vendor status can access the vendor portal.
 */
function tf_vendor_status_allowed($status) {
    return in_array((string)$status, ['approved', 'pending'], true);
}

/**
 * SQL predicate shared by vendor campaign/report queries.
 * The vendor ID is always supplied as the first bound parameter.
 */
function tf_vendor_assignment_sql($alias = 'pv') {
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$alias) ?: 'pv';
    return "{$alias}.vendor_id = ? AND {$alias}.status = 'active'";
}

/**
 * Accept only ISO calendar dates for report filters.
 */
function tf_vendor_date($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
}

/**
 * Keep vendor report rows to explicitly approved vendor-facing fields.
 */
function tf_vendor_report_fields(array $row) {
    $allowed = [
        'project_id', 'project_code', 'project_name', 'payout', 'currency',
        'tracking_url', 'qr_url', 'clicks', 'conversions', 'conversion_rate',
    ];
    return array_intersect_key($row, array_flip($allowed));
}
