<?php
/**
 * Track Flow — Per-campaign XLSX Traffic Export
 * Generates a true .xlsx file using PHP's built-in ZipArchive.
 * No external dependencies (no PhpSpreadsheet, no composer).
 *
 * URL: /projects/export.php?id=<project_id>&from=YYYY-MM-DD&to=YYYY-MM-DD
 */

require_once __DIR__ . '/../config.php';
require_login();

$project_id = intval($_GET['id'] ?? $_GET['project_id'] ?? 0);
if (!$project_id) {
    set_flash('danger', 'Project id is required.');
    redirect(BASE_URL . '/projects/list.php');
}

$stmt = $pdo->prepare("SELECT p.*, c.client_name, c.client_code FROM projects p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch();
if (!$project) {
    set_flash('danger', 'Project not found.');
    redirect(BASE_URL . '/projects/list.php');
}

// Date range
$from_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : ($project['start_date'] ?: date('Y-m-d', strtotime('-30 days')));
$to_date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d');
if (strtotime($to_date) < strtotime($from_date)) $to_date = $from_date;
$from_dt = $from_date . ' 00:00:00';
$to_dt   = $to_date . ' 23:59:59';

// ─── Fetch clicks ───
$clicks_stmt = $pdo->prepare("
    SELECT cl.click_id, cl.ip_address, cl.user_agent, cl.referrer,
        cl.device_type, cl.country_detected, cl.is_converted, cl.clicked_at,
        gv.vendor_name, gv.id AS vendor_id
    FROM clicks cl
    JOIN global_vendors gv ON cl.vendor_id = gv.id
    WHERE cl.project_id = ?
      AND cl.clicked_at BETWEEN ? AND ?
    ORDER BY cl.clicked_at DESC
");
$clicks_stmt->execute([$project_id, $from_dt, $to_dt]);
$clicks = $clicks_stmt->fetchAll();

// ─── Fetch conversions ───
$conv_stmt = $pdo->prepare("
    SELECT cv.click_id, cv.status, cv.client_revenue, cv.vendor_cost, cv.profit,
        cv.is_manual, cv.converted_at, cv.rejection_reason,
        gv.vendor_name
    FROM conversions cv
    JOIN global_vendors gv ON cv.vendor_id = gv.id
    WHERE cv.project_id = ?
      AND cv.converted_at BETWEEN ? AND ?
    ORDER BY cv.converted_at DESC
");
$conv_stmt->execute([$project_id, $from_dt, $to_dt]);
$conversions = $conv_stmt->fetchAll();

// ─── Summary row ───
$sum_stmt = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM clicks WHERE project_id = ? AND clicked_at BETWEEN ? AND ?) AS total_clicks,
        (SELECT COUNT(*) FROM conversions WHERE project_id = ? AND status = 'complete' AND converted_at BETWEEN ? AND ?) AS total_conv,
        (SELECT COALESCE(SUM(client_revenue),0) FROM conversions WHERE project_id = ? AND status = 'complete' AND converted_at BETWEEN ? AND ?) AS total_rev,
        (SELECT COALESCE(SUM(vendor_cost),0)    FROM conversions WHERE project_id = ? AND status = 'complete' AND converted_at BETWEEN ? AND ?) AS total_cost,
        (SELECT COALESCE(SUM(profit),0)         FROM conversions WHERE project_id = ? AND status = 'complete' AND converted_at BETWEEN ? AND ?) AS total_profit
");
$sum_stmt->execute([$project_id, $from_dt, $to_dt, $project_id, $from_dt, $to_dt, $project_id, $from_dt, $to_dt, $project_id, $from_dt, $to_dt, $project_id, $from_dt, $to_dt]);
$summary = $sum_stmt->fetch();

// ─── Build XLSX ───
$filename = 'trackflow_' . $project['project_code'] . '_' . $from_date . '_to_' . $to_date . '.xlsx';

$sheet_clicks = [
    ['Track Flow — Traffic Export'],
    ['Project', $project['project_code'] . ' — ' . $project['project_name']],
    ['Client', $project['client_name'] ?? '-'],
    ['Campaign Type', $project['campaign_type'] ?? 'CPL'],
    ['Date Range', $from_date . ' → ' . $to_date],
    ['Exported At', date('Y-m-d H:i:s')],
    [],
    ['Summary'],
    ['Total Clicks', (int)$summary['total_clicks']],
    ['Total Conversions', (int)$summary['total_conv']],
    ['Total Revenue', (float)$summary['total_rev']],
    ['Total Cost', (float)$summary['total_cost']],
    ['Total Profit', (float)$summary['total_profit']],
    [],
    ['Clicks'],
    ['Click ID', 'Vendor', 'IP', 'Device', 'Country', 'Referrer', 'Converted', 'Clicked At'],
];

foreach ($clicks as $c) {
    $sheet_clicks[] = [
        $c['click_id'],
        $c['vendor_name'],
        $c['ip_address'],
        $c['device_type'],
        $c['country_detected'],
        $c['referrer'],
        $c['is_converted'] ? 'Yes' : 'No',
        $c['clicked_at'],
    ];
}

$sheet_conversions = [
    ['Conversions'],
    [],
    ['Click ID', 'Vendor', 'Status', 'Revenue', 'Cost', 'Profit', 'Manual', 'Converted At'],
];

foreach ($conversions as $cv) {
    $sheet_conversions[] = [
        $cv['click_id'],
        $cv['vendor_name'],
        $cv['status'],
        (float)$cv['client_revenue'],
        (float)$cv['vendor_cost'],
        (float)$cv['profit'],
        $cv['is_manual'] ? 'Yes' : 'No',
        $cv['converted_at'],
    ];
}

// Build shared strings
$shared = [];
$sidx = [];
function ss($v) {
    global $shared, $sidx;
    $v = (string)$v;
    if (!isset($sidx[$v])) {
        $sidx[$v] = count($shared);
        $shared[] = $v;
    }
    return $sidx[$v];
}

function col_letter($i) {
    $s = '';
    while ($i >= 0) {
        $s = chr(65 + ($i % 26)) . $s;
        $i = intdiv($i, 26) - 1;
    }
    return $s;
}

function cell_ref($col, $row) {
    return col_letter($col) . ($row + 1);
}

function build_sheet_xml($rows) {
    global $sidx;
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<cols>';
    // Column widths (heuristic by header length, max 28)
    $col_widths = [22, 22, 16, 14, 16, 32, 14, 22];
    foreach ($col_widths as $i => $w) {
        $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }
    $xml .= '</cols>';
    $xml .= '<sheetData>';
    foreach ($rows as $r => $row) {
        $xml .= '<row r="' . ($r + 1) . '">';
        foreach ($row as $c => $val) {
            $ref = cell_ref($c, $r);
            if (is_numeric($val) && !is_string($val)) {
                $xml .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
            } else {
                $s = ss((string)$val);
                $xml .= '<c r="' . $ref . '" t="s"><v>' . $s . '</v></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData>';
    $xml .= '</worksheet>';
    return $xml;
}

$sheet1_xml = build_sheet_xml($sheet_clicks);
$sheet2_xml = build_sheet_xml($sheet_conversions);

$shared_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$shared_xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($shared) . '" uniqueCount="' . count($shared) . '">';
foreach ($shared as $i => $s) {
    $shared_xml .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
}
$shared_xml .= '</sst>';

$workbook_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$workbook_xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
$workbook_xml .= '<sheets>';
$workbook_xml .= '<sheet name="Clicks" sheetId="1" r:id="rId1"/>';
$workbook_xml .= '<sheet name="Conversions" sheetId="2" r:id="rId2"/>';
$workbook_xml .= '</sheets>';
$workbook_xml .= '</workbook>';

$workbook_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$workbook_rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
$workbook_rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>';
$workbook_rels .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>';
$workbook_rels .= '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
$workbook_rels .= '</Relationships>';

$root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$root_rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
$root_rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
$root_rels .= '</Relationships>';

$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$content_types .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
$content_types .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
$content_types .= '<Default Extension="xml" ContentType="application/xml"/>';
$content_types .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
$content_types .= '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
$content_types .= '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
$content_types .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
$content_types .= '</Types>';

if (!class_exists('ZipArchive')) {
    die('ZipArchive is not enabled on this server. Please contact admin.');
}

$tmpfile = tempnam(sys_get_temp_dir(), 'tf_xlsx_');
$zip = new ZipArchive();
if ($zip->open($tmpfile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die('Failed to create XLSX file.');
}
$zip->addFromString('[Content_Types].xml', $content_types);
$zip->addFromString('_rels/.rels', $root_rels);
$zip->addFromString('xl/workbook.xml', $workbook_xml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbook_rels);
$zip->addFromString('xl/sharedStrings.xml', $shared_xml);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet1_xml);
$zip->addFromString('xl/worksheets/sheet2.xml', $sheet2_xml);
$zip->close();

if (!is_file($tmpfile)) {
    die('Failed to write XLSX file.');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpfile));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
readfile($tmpfile);
@unlink($tmpfile);
exit;
