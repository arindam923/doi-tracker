<?php
require_once __DIR__ . '/../config.php';

$log_dir = __DIR__ . '/../storage/logs';
if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);

function campaign_log($msg) {
    $line = '[' . date('c') . '] ' . $msg . PHP_EOL;
    $log_dir = __DIR__ . '/../storage/logs';
    if (is_dir($log_dir) && is_writable($log_dir)) @file_put_contents($log_dir . '/email_campaign_worker.log', $line, FILE_APPEND | LOCK_EX);
    error_log('email_campaign_worker: ' . $msg);
}

$resend_api_key = get_setting($pdo, 'resend_api_key');
$from_address = get_setting($pdo, 'email_from_address', 'noreply@yourdomain.com');
$from_name = get_setting($pdo, 'email_from_name', 'Track Flow');

if (empty($resend_api_key)) {
    campaign_log('no resend_api_key configured; aborting');
    exit(0);
}

[$rate_per_minute, $tokens, $now] = email_rate_tokens_load($pdo);

try {
    $pdo->beginTransaction();
    $campaign = $pdo->query("SELECT ec.*, p.project_code, p.project_name FROM email_campaigns ec JOIN projects p ON ec.project_id = p.id WHERE ec.status = 'running' ORDER BY ec.created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
    if (!$campaign) {
        $pdo->commit();
        $retry = $pdo->prepare("SELECT id, campaign_id, project_id, vendor_id, list_id, entry_id, recipient_email, recipient_name, country, retry_count FROM email_campaign_sends WHERE status='retrying' AND (next_retry_at IS NULL OR next_retry_at <= NOW()) ORDER BY next_retry_at ASC LIMIT 1");
        $retry->execute();
        $rs = $retry->fetch();
        if ($rs) {
            $rc = (int)($rs['campaign_id'] ?? 0);
            $camp = tf_fetch_one($pdo, "SELECT * FROM email_campaigns WHERE id=?", [$rc]);
            if ($camp && in_array($camp['status'], ['running','paused'], true)) {
                $campaign = $camp;
                $campaign['_retry_row'] = $rs;
            }
        }
        if (!$campaign) {
            campaign_log('no running campaign found');
            email_rate_tokens_save($pdo, $tokens, $now);
            exit(0);
        }
    } else {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    campaign_log('lock error: ' . $e->getMessage());
    email_rate_tokens_save($pdo, $tokens, $now);
    exit(1);
}

if (!empty($campaign['_retry_row'])) {
    $rs = $campaign['_retry_row'];
    $campaign_id = (int)$rs['campaign_id'];
    $vendor_id = (int)$rs['vendor_id'];
    $project_id = (int)$rs['project_id'];
    $from_name_use = $campaign['from_name'] ?: $from_name;
    $from_email_use = $campaign['from_email'] ?: $from_address;
    if ($tokens < 1) { campaign_log('rate limit reached; pausing retry'); email_rate_tokens_save($pdo, $tokens, $now); exit(0); }
    $entry = tf_fetch_one($pdo, "SELECT * FROM email_list_entries WHERE id=?", [(int)$rs['entry_id']]);
    $row = ['email'=>$rs['recipient_email'],'name'=>$rs['recipient_name'],'first_name'=>$entry['first_name']??'','last_name'=>$entry['last_name']??'','country'=>$rs['country']];
    [$http_code,$response,$curl_error] = email_send_via_resend($resend_api_key,$from_email_use,$from_name_use,$rs['recipient_email'],email_personalize($campaign['subject'],$row),email_inject_tracking(email_personalize($campaign['html_body'],$row),(int)$rs['id']));
    $tokens--;
    if ($http_code===429) { campaign_log('Resend 429 retry; pausing'); email_rate_tokens_save($pdo,$tokens,$now); exit(0); }
    if ($http_code===200||$http_code===202) {
        $decoded=json_decode($response,true); $rid=$decoded['id']??null;
        if ($rid) { $pdo->prepare("UPDATE email_campaign_sends SET status='sent', resend_id=?, error_message=NULL, sent_at=NOW(), next_retry_at=NULL WHERE id=?")->execute([$rid,$rs['id']]); $pdo->prepare("UPDATE email_list_entries SET last_emailed_at=NOW(), total_emails_sent=total_emails_sent+1 WHERE id=?")->execute([(int)$rs['entry_id']]); $pdo->prepare("UPDATE email_campaigns SET sent_count=sent_count+1, failed_count=GREATEST(0,failed_count-1) WHERE id=?")->execute([$campaign_id]); campaign_log("retry sent campaign $campaign_id id ".$rs['id']); }
        else { $pdo->prepare("UPDATE email_campaign_sends SET retry_count=retry_count+1, next_retry_at=DATE_ADD(NOW(), INTERVAL 60 SECOND), error_message=? WHERE id=?")->execute(['missing_resend_id',$rs['id']]); }
    } else {
        $err='http_'.$http_code.($curl_error?': '.$curl_error:'');
        $nc=(int)$rs['retry_count']+1;
        if ($nc>=3) { $pdo->prepare("UPDATE email_campaign_sends SET status='failed', retry_count=?, error_message=?, next_retry_at=NULL WHERE id=?")->execute([$nc,$err,$rs['id']]); }
        else { $back=[5,20,60][$nc]??60; $pdo->prepare("UPDATE email_campaign_sends SET retry_count=?, error_message=?, next_retry_at=DATE_ADD(NOW(), INTERVAL $back SECOND) WHERE id=?")->execute([$nc,$err,$rs['id']]); }
    }
    email_rate_tokens_save($pdo,$tokens,microtime(true));
    exit(0);
}

$campaign_id = (int)$campaign['id'];
$vendor_id = (int)$campaign['vendor_id'];
$project_id = (int)$campaign['project_id'];
$is_multi = !empty($campaign['is_multi_step']);

$today_count = email_count_scalar($pdo,"SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id=? AND DATE(COALESCE(sent_at, created_at))=CURDATE() AND status IN ('sent','delivered','opened','clicked','converted','bounced','failed')",[$campaign_id]);
$total_sent = email_count_scalar($pdo,"SELECT COUNT(*) FROM email_campaign_sends WHERE campaign_id=? AND status IN ('sent','delivered','opened','clicked','converted','bounced','failed')",[$campaign_id]);
$daily_limit=(int)$campaign['daily_limit']; $total_limit=(int)$campaign['total_limit'];
if ($total_limit>0 && $total_sent >= $total_limit) { $pdo->prepare("UPDATE email_campaigns SET status='completed', completed_at=NOW() WHERE id=?")->execute([$campaign_id]); campaign_log('campaign '.$campaign_id.' completed by total_limit'); email_rate_tokens_save($pdo,$tokens,$now); exit(0); }
if ($daily_limit>0 && $today_count >= $daily_limit) { campaign_log('campaign '.$campaign_id.' daily limit reached ('.$today_count.'/'.$daily_limit.'); waiting until tomorrow'); email_rate_tokens_save($pdo,$tokens,$now); exit(0); }
$batch_size=50;
$today_remaining=$daily_limit>0?max(0,$daily_limit-$today_count):$batch_size;
$total_remaining=$total_limit>0?max(0,$total_limit-$total_sent):$batch_size;
$batch_size=(int)min($batch_size,$today_remaining,$total_remaining,max(0,(int)floor($tokens)));
if ($batch_size<=0) { campaign_log('campaign '.$campaign_id.' no remaining quota or rate tokens'); email_rate_tokens_save($pdo,$tokens,$now); exit(0); }

$geos = email_campaign_geos($pdo, $campaign_id, $project_id);
if (!$geos) { $pdo->prepare("UPDATE email_campaigns SET status='completed', completed_at=NOW() WHERE id=?")->execute([$campaign_id]); campaign_log('campaign '.$campaign_id.' no campaign GEO configured; not sending globally'); email_rate_tokens_save($pdo,$tokens,$now); exit(0); }

$list_id = ensure_vendor_email_list($pdo, $vendor_id);
$ph = implode(',', array_fill(0, count($geos), '?'));
if ($is_multi) {
    $sql="SELECT ele.id, ele.email, ele.name, ele.first_name, ele.last_name, ele.country, ele.list_id FROM email_list_entries ele JOIN email_lists el ON el.id=ele.list_id WHERE el.vendor_id=? AND ele.status='active' AND ele.is_unsubscribed=0 AND UPPER(ele.country) IN ($ph) ORDER BY ele.id ASC LIMIT ".(int)$batch_size;
    $params=array_merge([$vendor_id],$geos);
} else {
    $sql="SELECT ele.id, ele.email, ele.name, ele.first_name, ele.last_name, ele.country, ele.list_id FROM email_list_entries ele JOIN email_lists el ON el.id=ele.list_id WHERE el.vendor_id=? AND ele.status='active' AND ele.is_unsubscribed=0 AND UPPER(ele.country) IN ($ph) AND NOT EXISTS (SELECT 1 FROM email_campaign_sends s WHERE s.campaign_id=? AND LOWER(s.recipient_email)=LOWER(ele.email) AND s.status!='failed') ORDER BY ele.id ASC LIMIT ".(int)$batch_size;
    $params=array_merge([$vendor_id],$geos,[$campaign_id]);
}
$stmt=$pdo->prepare($sql); $stmt->execute($params); $recipients=$stmt->fetchAll();
if (empty($recipients)) { $pdo->prepare("UPDATE email_campaigns SET status='completed', completed_at=NOW() WHERE id=?")->execute([$campaign_id]); campaign_log('campaign '.$campaign_id.' no eligible recipients remaining'); email_rate_tokens_save($pdo,$tokens,$now); exit(0); }

$from_name_use=$campaign['from_name']?:$from_name; $from_email_use=$campaign['from_email']?:$from_address;
$sent=0; $failed=0;
foreach ($recipients as $row) {
    if ($tokens<1) { campaign_log('rate limit reached; pausing until next tick'); break; }
    $insert=$pdo->prepare("INSERT INTO email_campaign_sends (campaign_id, project_id, vendor_id, list_id, entry_id, recipient_email, recipient_name, country, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'queued', NOW())");
    try {
        $name=$row['name']?:trim(($row['first_name']??'').' '.($row['last_name']??''));
        $insert->execute([$campaign_id,$project_id,$vendor_id,$row['list_id']?:$list_id,(int)$row['id'],$row['email'],$name!==''?$name:null,strtoupper(substr((string)$row['country'],0,2))?:null]);
    } catch (PDOException $e) { continue; }
    $send_id=(int)$pdo->lastInsertId(); if ($send_id<1) continue;
    $personalized_html=email_personalize($campaign['html_body'],$row); $personalized_html=email_inject_tracking($personalized_html,$send_id); $personalized_subject=email_personalize($campaign['subject'],$row);
    [$http_code,$response,$curl_error]=email_send_via_resend($resend_api_key,$from_email_use,$from_name_use,$row['email'],$personalized_subject,$personalized_html);
    $tokens--;
    if ($http_code===429) { $pdo->prepare("DELETE FROM email_campaign_sends WHERE id=? AND status='queued'")->execute([$send_id]); campaign_log('Resend 429; pausing'); break; }
    $status='failed'; $resend_id=null; $error_message=null;
    if ($http_code===200||$http_code===202) { $decoded=json_decode($response,true); $resend_id=$decoded['id']??null; if ($resend_id) $status='sent'; else $error_message='missing_resend_id'; } else $error_message='http_'.$http_code.($curl_error?': '.$curl_error:'');
    if ($status==='sent') {
        $pdo->prepare("UPDATE email_campaign_sends SET status=?, resend_id=?, error_message=NULL, sent_at=NOW(), next_retry_at=NULL WHERE id=?")->execute([$status,$resend_id,$send_id]);
        $sent++; $pdo->prepare("UPDATE email_list_entries SET last_emailed_at=NOW(), total_emails_sent=total_emails_sent+1 WHERE id=?")->execute([(int)$row['id']]); $pdo->prepare("UPDATE email_campaigns SET sent_count=sent_count+1, started_at=COALESCE(started_at,NOW()), updated_at=NOW() WHERE id=?")->execute([$campaign_id]);
    } else {
        $back=[5,20,60][0]??5;
        $pdo->prepare("UPDATE email_campaign_sends SET status='retrying', retry_count=1, error_message=?, next_retry_at=DATE_ADD(NOW(), INTERVAL 5 SECOND) WHERE id=?")->execute([$error_message,$send_id]);
        $failed++; $pdo->prepare("UPDATE email_campaigns SET failed_count=failed_count+1, updated_at=NOW() WHERE id=?")->execute([$campaign_id]);
    }
    usleep(150000);
}
email_rate_tokens_save($pdo,$tokens,microtime(true));
campaign_log('campaign '.$campaign_id.' sent='.$sent.' failed='.$failed.' checked='.count($recipients));
