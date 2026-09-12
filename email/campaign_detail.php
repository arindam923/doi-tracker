<?php
require_once __DIR__ . '/../config.php';
require_role(['super_admin', 'campaign_manager']);

$id = tf_get_int('id', 0);
if (!$id) redirect(BASE_URL . '/projects/list.php');

try {
    $stmt = $pdo->prepare("SELECT ec.*, p.project_code, p.project_name, p.id as project_id, gv.vendor_name FROM email_campaigns ec JOIN projects p ON ec.project_id = p.id JOIN global_vendors gv ON ec.vendor_id = gv.id WHERE ec.id = ?");
    $stmt->execute([$id]);
    $campaign = $stmt->fetch();
} catch (Throwable $e) {
    error_log('campaign_detail fetch failed: ' . $e->getMessage());
    $campaign = false;
}
if (!$campaign) { set_flash('danger','Campaign not found.'); redirect(BASE_URL.'/projects/list.php'); }

if (tf_get_string('export', '') === 'csv') {
    try {
        $sends = $pdo->prepare("SELECT recipient_email, recipient_name, country, status, sent_at, opened_at, clicked_at, converted_at, error_message FROM email_campaign_sends WHERE campaign_id=? ORDER BY created_at DESC");
        $sends->execute([$id]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="campaign_'.$id.'_sends_'.date('Ymd').'.csv"');
        $out=fopen('php://output','w');
        fputcsv($out,['Email','Name','Country','Status','Sent At','Opened At','Clicked At','Converted At','Error']);
        foreach ($sends->fetchAll() as $r) fputcsv($out,[$r['recipient_email'],$r['recipient_name'],$r['country'],$r['status'],$r['sent_at'],$r['opened_at'],$r['clicked_at'],$r['converted_at'],$r['error_message']]);
        fclose($out); exit;
    } catch (Throwable $e) {
        error_log('campaign_detail export failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Export failed.');
    }
}

$campaign_geos = email_campaign_geos($pdo, $id, (int)$campaign['project_id']);
$eligible = email_campaign_eligible_count($pdo, (int)$campaign['project_id'], (int)$campaign['vendor_id'], $id);

$send_stats = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) AS sent, SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) AS delivered, SUM(CASE WHEN status='opened' THEN 1 ELSE 0 END) AS opened, SUM(CASE WHEN status='clicked' THEN 1 ELSE 0 END) AS clicked, SUM(CASE WHEN status='converted' THEN 1 ELSE 0 END) AS converted, SUM(CASE WHEN status='bounced' THEN 1 ELSE 0 END) AS bounced, SUM(CASE WHEN status IN ('failed','retrying') THEN 1 ELSE 0 END) AS failed, SUM(CASE WHEN status='skipped' THEN 1 ELSE 0 END) AS skipped, SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened_any, SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked_any FROM email_campaign_sends WHERE campaign_id=?");
$send_stats->execute([$id]); $stats=$send_stats->fetch();
$den = (int)($stats['sent']??0)+(int)($stats['delivered']??0)+(int)($stats['opened']??0)+(int)($stats['clicked']??0)+(int)($stats['converted']??0);
if ($den<=0) $den=(int)($stats['total']??0);

$page=max(1,tf_get_int('page',1)); $per_page=20;
$search=tf_get_string('search',''); $fstatus_raw=tf_get_string('fstatus',''); $fcountry_raw=tf_get_string('fcountry','');
$fstatus=in_array($fstatus_raw,['queued','sent','delivered','opened','clicked','converted','bounced','failed','retrying','skipped'],true) ? $fstatus_raw : '';
$fcountry=preg_match('/^[A-Za-z]{2}$/',$fcountry_raw) ? $fcountry_raw : '';
$where=['campaign_id=?']; $params=[$id];
if ($search !== '') { $where[]='(recipient_email LIKE ? ESCAPE \'\\\' OR recipient_name LIKE ? ESCAPE \'\\\' )'; $esc='%'.tf_like_escape($search).'%'; $params[]=$esc; $params[]=$esc; }
if ($fstatus !== '') { $where[]='status=?'; $params[]=$fstatus; }
if ($fcountry !== '') { $where[]='UPPER(country)=?'; $params[]=strtoupper($fcountry); }
$where_sql='WHERE '.implode(' AND ',$where);
try {
    $count_stmt=$pdo->prepare("SELECT COUNT(*) as cnt FROM email_campaign_sends $where_sql"); $count_stmt->execute($params); $total=(int)($count_stmt->fetch()['cnt'] ?? 0);
} catch (Throwable $e) {
    error_log('campaign_detail count failed: ' . $e->getMessage());
    $total=0;
}
$pagination=paginate($total,$per_page,$page);
try {
    $stmt=$pdo->prepare("SELECT * FROM email_campaign_sends $where_sql ORDER BY created_at DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}"); $stmt->execute($params); $sends=$stmt->fetchAll();
} catch (Throwable $e) {
    error_log('campaign_detail sends fetch failed: ' . $e->getMessage());
    $sends=[];
}

$page_title='Campaign Detail';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="campaign-hero">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <div class="small text-white-50">Project <a class="text-white" href="<?php echo BASE_URL; ?>/projects/detail.php?id=<?php echo (int)$campaign['project_id']; ?>#email-campaigns"><code class="text-white"><?php echo sanitize($campaign['project_code']); ?></code></a></div>
            <h5 class="mb-0"><?php echo sanitize($campaign['name']); ?></h5>
            <div class="small text-white-50">Vendor: <?php echo sanitize($campaign['vendor_name']); ?> · Subject: <?php echo sanitize($campaign['subject']); ?></div>
            <?php if ($campaign_geos): ?><div class="small text-white-50">GEO: <?php foreach ($campaign_geos as $gc) echo sanitize($gc).' '; ?>(campaign override)</div><?php endif; ?>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if (in_array($campaign['status'],['draft','paused','scheduled'],true)): ?>
            <form method="POST" action="<?php echo BASE_URL; ?>/email/campaign_status.php"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$id; ?>"><input type="hidden" name="action" value="<?php echo $campaign['status']==='paused'?'resume':'launch'; ?>"><button type="submit" class="btn btn-light btn-sm"><i class="bi bi-play-fill"></i><?php echo $campaign['status']==='paused'?'Resume':'Launch'; ?></button></form>
            <?php endif; ?>
            <?php if ($campaign['status']==='running'): ?>
            <form method="POST" action="<?php echo BASE_URL; ?>/email/campaign_status.php"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$id; ?>"><input type="hidden" name="action" value="pause"><button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-pause-fill"></i>Pause</button></form>
            <?php endif; ?>
            <?php if ($campaign['is_multi_step'] && in_array($campaign['status'],['completed','running','paused'],true)): ?>
            <form method="POST" action="<?php echo BASE_URL; ?>/email/campaign_status.php"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$id; ?>"><input type="hidden" name="action" value="resend"><button type="submit" class="btn btn-outline-light btn-sm" data-confirm="Manual resend will allow duplicates for this campaign. Continue?"><i class="bi bi-send"></i>Resend</button></form>
            <?php endif; ?>
            <?php if ($campaign['is_multi_step'] && $campaign['status']==='completed'): ?>
            <form method="POST" action="<?php echo BASE_URL; ?>/email/campaign_status.php"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$id; ?>"><input type="hidden" name="action" value="launch"><button type="submit" class="btn btn-light btn-sm"><i class="bi bi-arrow-repeat"></i>Relaunch</button></form>
            <?php endif; ?>
            <a href="<?php echo BASE_URL; ?>/email/campaign_edit.php?id=<?php echo (int)$id; ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-pencil"></i>Edit</a>
            <form method="POST" action="<?php echo BASE_URL; ?>/email/campaign_delete.php" data-confirm="Delete this campaign? This cannot be undone."><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$id; ?>"><button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i>Delete</button></form>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Total</div><div class="kpi-value"><?php echo number_format((int)($stats['total']??0)); ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Sent</div><div class="kpi-value"><?php echo number_format((int)($stats['sent']??0)); ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Delivered</div><div class="kpi-value"><?php echo number_format((int)($stats['delivered']??0)); ?> <small class="text-muted"><?php echo email_pct($stats['delivered']??0,$den); ?></small></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Opened</div><div class="kpi-value"><?php echo number_format((int)($stats['opened_any']??0)); ?> <small class="text-muted"><?php echo email_pct($stats['opened_any']??0,$den); ?></small></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Clicked</div><div class="kpi-value"><?php echo number_format((int)($stats['clicked_any']??0)); ?> <small class="text-muted"><?php echo email_pct($stats['clicked_any']??0,$den); ?></small></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Converted</div><div class="kpi-value"><?php echo number_format((int)($stats['converted']??0)); ?> <small class="text-muted"><?php echo email_pct($stats['converted']??0,$den); ?></small></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Bounced</div><div class="kpi-value"><?php echo number_format((int)($stats['bounced']??0)); ?> <small class="text-muted"><?php echo email_pct($stats['bounced']??0,$den); ?></small></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi-card"><div class="kpi-label">Failed</div><div class="kpi-value"><?php echo number_format((int)($stats['failed']??0)); ?></div></div></div>
</div>

<div class="tf-card mb-4">
    <div class="tf-card-header"><h5 class="mb-0 fw-semibold">Configuration</h5></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-md-2"><strong>Status</strong><div><?php echo status_badge($campaign['status']); ?></div></div>
            <div class="col-6 col-md-2"><strong>Daily Limit</strong><div><?php echo number_format((int)$campaign['daily_limit']); ?></div></div>
            <div class="col-6 col-md-2"><strong>Total Limit</strong><div><?php echo (int)$campaign['total_limit']>0?number_format((int)$campaign['total_limit']):'Unlimited'; ?></div></div>
            <div class="col-6 col-md-2"><strong>Multi-step</strong><div><?php echo $campaign['is_multi_step']?'Yes — manual resend allowed':'No'; ?></div></div>
            <div class="col-6 col-md-2"><strong>Eligible</strong><div><?php echo number_format($eligible); ?></div></div>
            <div class="col-6 col-md-2"><strong>GEO</strong><div><?php echo $campaign_geos?sanitize(implode(', ',$campaign_geos)):'Project GEO'; ?></div></div>
        </div>
    </div>
</div>

<div class="tf-card">
    <div class="tf-card-header flex-wrap gap-2">
        <h5 class="mb-0 fw-semibold">Recipients</h5>
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <form method="GET" class="d-flex gap-2 flex-wrap">
                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                <input type="text" name="search" value="<?php echo sanitize($search); ?>" placeholder="Search email/name" class="form-control form-control-sm" style="width:160px">
                <select name="fstatus" class="form-select form-select-sm" style="width:130px"><option value="">All status</option><?php foreach (['queued','sent','delivered','opened','clicked','converted','bounced','failed','retrying'] as $o) echo '<option value="'.$o.'"'.($fstatus===$o?' selected':'').'>'.ucfirst($o).'</option>'; ?></select>
                <input type="text" name="fcountry" value="<?php echo sanitize($fcountry); ?>" placeholder="CC" class="form-control form-control-sm" style="width:70px" maxlength="2">
                <button class="btn btn-outline-secondary btn-sm">Filter</button>
                <?php if ($search||$fstatus||$fcountry): ?><a href="<?php echo BASE_URL; ?>/email/campaign_detail.php?id=<?php echo (int)$id; ?>" class="btn btn-link btn-sm">Clear</a><?php endif; ?>
            </form>
            <a href="<?php echo BASE_URL; ?>/email/campaign_detail.php?id=<?php echo (int)$id; ?>&export=csv" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download"></i>CSV</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Recipient</th><th>Country</th><th>Status</th><th>Sent At</th><th>Opened</th><th>Clicked</th><th>Converted</th></tr></thead>
            <tbody>
                <?php if (empty($sends)): ?><tr><td colspan="7" class="text-center py-5 text-muted">No send records yet.</td></tr>
                <?php else: foreach ($sends as $s): ?>
                    <tr>
                        <td><?php echo sanitize($s['recipient_name']?$s['recipient_name'].' <'.$s['recipient_email'].'>':$s['recipient_email']); ?><?php if (!empty($s['error_message'])): ?><div class="small text-danger"><?php echo sanitize(substr($s['error_message'],0,120)); ?></div><?php endif; ?></td>
                        <td><?php echo sanitize($s['country']??'-'); ?></td>
                        <td><?php echo status_badge($s['status']); ?></td>
                        <td class="text-muted small"><?php echo sanitize($s['sent_at']??'-'); ?></td>
                        <td><?php echo $s['opened_at']?'<i class="bi bi-check2 text-success"></i>':'-'; ?></td>
                        <td><?php echo $s['clicked_at']?'<i class="bi bi-check2 text-success"></i>':'-'; ?></td>
                        <td><?php echo $s['converted_at']?'<i class="bi bi-check2 text-success"></i>':'-'; ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="tf-card-footer"><?php $q=http_build_query(array_filter(['search'=>$search,'fstatus'=>$fstatus,'fcountry'=>$fcountry], static fn($v) => $v !== '' && $v !== null)); echo render_pagination($pagination, BASE_URL.'/email/campaign_detail.php?id='.$id.($q?'&'.$q:'')); ?></div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
