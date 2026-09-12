<?php
/**
 * Vendor email database, send tracking, and Resend helpers.
 */

function ensure_vendor_email_list(PDO $pdo, int $vendor_id, $created_by = null): int {
    $stmt = $pdo->prepare('SELECT id FROM email_lists WHERE vendor_id = ? LIMIT 1');
    $stmt->execute([$vendor_id]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }

    $v = $pdo->prepare('SELECT vendor_name FROM global_vendors WHERE id = ?');
    $v->execute([$vendor_id]);
    $name = $v->fetchColumn() ?: 'Vendor';

    try {
        $pdo->prepare('
            INSERT INTO email_lists (name, source, vendor_id, is_deduped, record_count, created_by, created_at)
            VALUES (?, ?, ?, 1, 0, ?, NOW())
        ')->execute([$name . ' — Database', 'vendor', $vendor_id, $created_by]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        $stmt = $pdo->prepare('SELECT id FROM email_lists WHERE vendor_id = ? LIMIT 1');
        $stmt->execute([$vendor_id]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int)$id;
        }
        throw $e;
    }
}

function email_normalize_country($value): ?string {
    $value = strtoupper(trim((string)$value));
    if ($value === '') {
        return null;
    }
    if (preg_match('/^[A-Z]{2}$/', $value)) {
        return $value;
    }
    return substr($value, 0, 2) ?: null;
}

function email_header_index(array $header, array $aliases) {
    foreach ($aliases as $alias) {
        $i = array_search($alias, $header, true);
        if ($i !== false) {
            return $i;
        }
    }
    return false;
}

function email_rows_from_assoc_grid(array $grid): array {
    if (empty($grid)) {
        return [];
    }
    $header = array_map(static function ($h) {
        return strtolower(trim((string)$h));
    }, $grid[0]);
    $has_email = in_array('email', $header, true);

    $rows = [];
    if ($has_email) {
        $i_email = email_header_index($header, ['email', 'email address', 'e-mail']);
        $i_country = email_header_index($header, ['country', 'country_code', 'geo', 'country code']);
        $i_first = email_header_index($header, ['first_name', 'firstname', 'first name', 'fname']);
        $i_last = email_header_index($header, ['last_name', 'lastname', 'last name', 'lname']);
        $i_name = email_header_index($header, ['name', 'full_name', 'full name']);
        $i_source = email_header_index($header, ['source']);
        for ($r = 1, $n = count($grid); $r < $n; $r++) {
            $line = $grid[$r];
            $email = trim((string)($line[$i_email] ?? ''));
            if ($email === '') {
                continue;
            }
            $first = $i_first !== false ? trim((string)($line[$i_first] ?? '')) : '';
            $last = $i_last !== false ? trim((string)($line[$i_last] ?? '')) : '';
            $name = $i_name !== false ? trim((string)($line[$i_name] ?? '')) : '';
            if ($name === '') {
                $name = trim($first . ' ' . $last);
            }
            $rows[] = [
                'email' => $email,
                'first_name' => $first !== '' ? $first : null,
                'last_name' => $last !== '' ? $last : null,
                'name' => $name !== '' ? $name : null,
                'country' => $i_country !== false ? email_normalize_country($line[$i_country] ?? '') : null,
                'source' => $i_source !== false ? (trim((string)($line[$i_source] ?? '')) ?: null) : null,
            ];
        }
        return $rows;
    }

    for ($r = 0, $n = count($grid); $r < $n; $r++) {
        $line = $grid[$r];
        $email = trim((string)($line[0] ?? ''));
        if ($email === '') {
            continue;
        }
        $first = trim((string)($line[2] ?? ''));
        $last = trim((string)($line[3] ?? ''));
        $name = trim($first . ' ' . $last);
        $rows[] = [
            'email' => $email,
            'country' => email_normalize_country($line[1] ?? ''),
            'first_name' => $first !== '' ? $first : null,
            'last_name' => $last !== '' ? $last : null,
            'name' => $name !== '' ? $name : null,
            'source' => trim((string)($line[4] ?? '')) ?: null,
        ];
    }
    return $rows;
}

function email_parse_csv_file($path): array {
    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new RuntimeException('Could not open uploaded file.');
    }
    $grid = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if ($line === [null] || $line === false) {
            continue;
        }
        $grid[] = $line;
    }
    fclose($handle);
    return email_rows_from_assoc_grid($grid);
}

function email_xlsx_col_index($cell_ref): int {
    $col = preg_replace('/[0-9]+/', '', (string)$cell_ref);
    $n = 0;
    $len = strlen($col);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($col[$i]) - 64);
    }
    return max(0, $n - 1);
}

function email_parse_xlsx_file($path): array {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('XLSX uploads require ZipArchive. Export CSV instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open XLSX file.');
    }

    $strings = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss) {
        $xml = @simplexml_load_string($ss);
        if ($xml) {
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } else {
                    foreach ($si->r as $run) {
                        $text .= (string)$run->t;
                    }
                }
                $strings[] = $text;
            }
        }
    }

    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet_xml === false) {
        $zip->close();
        throw new RuntimeException('XLSX has no first sheet.');
    }
    $zip->close();

    $sheet = @simplexml_load_string($sheet_xml);
    if (!$sheet) {
        throw new RuntimeException('Could not parse XLSX sheet.');
    }

    $grid = [];
    foreach ($sheet->sheetData->row as $row) {
        $line = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $idx = email_xlsx_col_index($ref);
            $type = (string)$c['t'];
            $val = (string)($c->v ?? '');
            if ($type === 's' && isset($strings[(int)$val])) {
                $val = $strings[(int)$val];
            } elseif ($type === 'inlineStr') {
                $val = (string)($c->is->t ?? '');
            }
            $line[$idx] = $val;
        }
        if ($line) {
            ksort($line);
            $max = max(array_keys($line));
            $padded = [];
            for ($i = 0; $i <= $max; $i++) {
                $padded[$i] = $line[$i] ?? '';
            }
            $grid[] = $padded;
        }
    }

    return email_rows_from_assoc_grid($grid);
}

function email_parse_contact_upload($tmp_path, $original_name): array {
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return email_parse_xlsx_file($tmp_path);
    }
    if ($ext === 'xls') {
        throw new RuntimeException('Legacy .xls is not supported. Upload CSV or .xlsx.');
    }
    return email_parse_csv_file($tmp_path);
}

function email_send_token(int $send_id): string {
    $sig = hash_hmac('sha256', (string)$send_id, ENCRYPTION_KEY);
    return $send_id . '.' . substr($sig, 0, 24);
}

function email_send_token_verify($token): ?int {
    $token = (string)$token;
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0])) {
        return null;
    }
    $id = (int)$parts[0];
    $expected = substr(hash_hmac('sha256', (string)$id, ENCRYPTION_KEY), 0, 24);
    if (!hash_equals($expected, $parts[1])) {
        return null;
    }
    return $id;
}

function email_public_url(string $path, array $query = []): string {
    $base = rtrim(BASE_URL, '/');
    $url = $base . $path;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

function email_inject_tracking(string $html, int $send_id): string {
    $token = email_send_token($send_id);
    $open_url = email_public_url('/email/t/open.php', ['t' => $token]);
    $unsub_url = email_public_url('/email/t/unsub.php', ['t' => $token]);
    $html = str_replace(['{{unsubscribe}}', '{{unsub_url}}'], $unsub_url, $html);

    $html = preg_replace_callback(
        '/(<a\b[^>]*\bhref\s*=\s*)([\'"])(https?:\/\/[^\'"]+)\2/i',
        static function ($m) use ($token, $unsub_url) {
            $dest = $m[3];
            if (strpos($dest, '/email/t/') !== false || $dest === $unsub_url) {
                return $m[0];
            }
            $wrapped = email_public_url('/email/t/click.php', ['t' => $token, 'u' => $dest]);
            return $m[1] . $m[2] . htmlspecialchars($wrapped, ENT_QUOTES, 'UTF-8') . $m[2];
        },
        $html
    );

    $pixel = '<img src="' . htmlspecialchars($open_url, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" alt="" style="display:none;border:0">';
    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $pixel . '</body>', $html, 1);
    }
    return $html . $pixel;
}

function email_personalize(string $text, array $row): string {
    $first = $row['first_name'] ?? '';
    $last = $row['last_name'] ?? '';
    $name = $row['name'] ?? trim($first . ' ' . $last);
    $country = strtoupper(substr((string)($row['country'] ?? ''), 0, 2));
    return str_replace(
        ['{{name}}', '{{first_name}}', '{{last_name}}', '{{email}}', '{{country}}'],
        [$name, $first, $last, $row['email'] ?? '', $country],
        $text
    );
}

function email_mark_engagement(PDO $pdo, int $send_id, string $event): void {
    $send = tf_fetch_one($pdo, 'SELECT * FROM email_campaign_sends WHERE id = ?', [$send_id]);
    if (!$send) {
        return;
    }

    $status = $send['status'];
    $sets = [];
    $params = [];
    $campaign_inc = null;

    if ($event === 'open') {
        if (empty($send['opened_at'])) {
            $sets[] = 'opened_at = NOW()';
            $campaign_inc = 'opened_count';
        }
        if (in_array($status, ['sent', 'delivered', 'queued'], true)) {
            $sets[] = "status = 'opened'";
        }
    } elseif ($event === 'click') {
        if (empty($send['opened_at'])) {
            $sets[] = 'opened_at = NOW()';
            $pdo->prepare('UPDATE email_campaigns SET opened_count = opened_count + 1 WHERE id = ?')->execute([$send['campaign_id']]);
        }
        if (empty($send['clicked_at'])) {
            $sets[] = 'clicked_at = NOW()';
            $campaign_inc = 'clicked_count';
        }
        if (!in_array($status, ['converted', 'bounced', 'failed'], true)) {
            $sets[] = "status = 'clicked'";
        }
    } elseif ($event === 'converted') {
        if (empty($send['converted_at'])) {
            $sets[] = 'converted_at = NOW()';
            $campaign_inc = 'converted_count';
        }
        if ($status !== 'bounced') {
            $sets[] = "status = 'converted'";
        }
    } elseif ($event === 'bounced') {
        if ($status === 'bounced') {
            return;
        }
        $sets[] = "status = 'bounced'";
        $campaign_inc = 'bounced_count';
        if (!empty($send['entry_id'])) {
            $pdo->prepare("UPDATE email_list_entries SET status = 'bounced' WHERE id = ?")->execute([(int)$send['entry_id']]);
        }
    } elseif ($event === 'delivered') {
        if (in_array($status, ['sent', 'queued'], true)) {
            $sets[] = "status = 'delivered'";
            $campaign_inc = 'delivered_count';
        }
    } elseif ($event === 'unsubscribed') {
        $already = false;
        if (!empty($send['entry_id'])) {
            $cur = tf_fetch_one($pdo, 'SELECT status FROM email_list_entries WHERE id = ?', [(int)$send['entry_id']]);
            $already = ($cur['status'] ?? '') === 'unsubscribed';
        }
        if (!$already) {
            $campaign_inc = 'unsubscribed_count';
        }
        if (!empty($send['entry_id'])) {
            $pdo->prepare("UPDATE email_list_entries SET status = 'unsubscribed', is_unsubscribed = 1 WHERE id = ?")
                ->execute([(int)$send['entry_id']]);
        } else {
            $pdo->prepare("UPDATE email_list_entries SET status = 'unsubscribed', is_unsubscribed = 1 WHERE email = ? AND vendor_id = ?")
                ->execute([$send['recipient_email'], $send['vendor_id']]);
        }
    }

    if ($sets) {
        $sql = 'UPDATE email_campaign_sends SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $params[] = $send_id;
        $pdo->prepare($sql)->execute($params);
    }
    if ($campaign_inc) {
        $pdo->prepare("UPDATE email_campaigns SET {$campaign_inc} = {$campaign_inc} + 1 WHERE id = ?")
            ->execute([$send['campaign_id']]);
    }
}

function email_append_sid(string $url, int $send_id): string {
    $sep = (strpos($url, '?') !== false) ? '&' : '?';
    if (stripos($url, 'sid=') !== false) {
        return $url;
    }
    return $url . $sep . 'sid=' . $send_id;
}

function email_rate_tokens_load(PDO $pdo): array {
    $rate = max(1, (int)get_setting($pdo, 'email_rate_per_minute', '50'));
    $tokens = (float)get_setting($pdo, 'email_bucket_tokens', (string)$rate);
    $last_ts = (float)get_setting($pdo, 'email_bucket_last_ts', '0');
    $now = microtime(true);
    if ($last_ts > 0) {
        $tokens = min($rate, $tokens + (($now - $last_ts) / 60) * $rate);
    } else {
        $tokens = $rate;
    }
    return [$rate, $tokens, $now];
}

function email_rate_tokens_save(PDO $pdo, $tokens, $now): void {
    set_setting($pdo, 'email_bucket_tokens', (string)$tokens);
    set_setting($pdo, 'email_bucket_last_ts', (string)$now);
}

function email_send_via_resend($api_key, $from_email, $from_name, $to_email, $subject, $html): array {
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json',
    ]);
    $from = $from_name ? ($from_name . ' <' . $from_email . '>') : $from_email;
    $payload = [
        'from' => $from,
        'to' => [$to_email],
        'subject' => $subject,
        'html' => $html,
    ];
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    return [$http_code, $response, $curl_error];
}

function email_pct($num, $den): string {
    $den = (int)$den;
    if ($den <= 0) {
        return '0%';
    }
    return number_format(((int)$num / $den) * 100, 1) . '%';
}

function email_count_scalar(PDO $pdo, string $sql, array $params = []): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function email_tpl_p($text) {
    return '<p style="margin:0 0 14px;">' . $text . '</p>';
}

function email_html_shell($preheader, $eyebrow, $heading, $body_html, $cta_label = '', $cta_url = 'https://example.com/offer') {
    $cta = '';
    if ($cta_label !== '') {
        $cta = '<tr><td align="center" style="padding:8px 0 28px;">'
            . '<a href="' . htmlspecialchars($cta_url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#0f766e;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;text-decoration:none;padding:14px 28px;border-radius:8px;">'
            . htmlspecialchars($cta_label, ENT_QUOTES, 'UTF-8') . '</a></td></tr>';
    }

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
        . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f1ea;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ea;padding:28px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fffcf7;border:1px solid #e4ddd0;border-radius:12px;overflow:hidden;">'
        . '<tr><td style="background:#14181f;padding:22px 32px;">'
        . '<p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:11px;letter-spacing:0.16em;text-transform:uppercase;color:#99f6e4;">Track Flow</p>'
        . '<p style="margin:6px 0 0;font-family:Georgia,serif;font-size:22px;color:#fffcf7;">' . htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</td></tr><tr><td style="padding:32px 32px 8px;">'
        . '<h1 style="margin:0 0 16px;font-family:Georgia,serif;font-size:26px;line-height:1.25;color:#1c1917;">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.65;color:#44403c;">' . $body_html . '</div>'
        . '</td></tr>' . $cta
        . '<tr><td style="padding:0 32px 28px;"><p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#78716c;">'
        . 'If this was not meant for you, you can <a href="{{unsubscribe}}" style="color:#0f766e;">unsubscribe</a> any time.</p></td></tr>'
        . '</table><p style="margin:16px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#a8a29e;">Sent via Track Flow</p>'
        . '</td></tr></table></body></html>';
}

function email_starter_templates() {
    return [
        [
            'name' => 'DOI - Confirm your email',
            'subject' => '{{first_name}}, confirm your email to continue',
            'is_default' => 1,
            'html_body' => email_html_shell(
                'One tap confirms your subscription.',
                'Double opt-in',
                'Please confirm your email',
                email_tpl_p('Hi {{first_name}},') .
                email_tpl_p('Thanks for signing up. Confirm this address so we can send you the offer and keep your record compliant.') .
                email_tpl_p('This confirmation is tied to <strong>{{email}}</strong> / {{country}}.'),
                'Confirm my email',
                'https://example.com/confirm'
            ),
        ],
        [
            'name' => 'DOI - Reminder to confirm',
            'subject' => 'Still waiting on your confirmation, {{first_name}}',
            'is_default' => 0,
            'html_body' => email_html_shell(
                'Finish DOI so we can send the offer.',
                'Friendly reminder',
                'Your confirmation is still open',
                email_tpl_p('Hi {{name}},') .
                email_tpl_p('We received a signup for {{email}}, but the double opt-in is not complete yet.') .
                email_tpl_p('Confirm once and we will stop reminding you.'),
                'Finish confirmation',
                'https://example.com/confirm'
            ),
        ],
        [
            'name' => 'Offer - Click through',
            'subject' => '{{first_name}}, your exclusive offer is ready',
            'is_default' => 0,
            'html_body' => email_html_shell(
                'Tap through to claim the offer.',
                'Member offer',
                'Your exclusive offer is live',
                email_tpl_p('Hi {{first_name}},') .
                email_tpl_p('You are confirmed. Open the offer below. Clicks are tracked back to this campaign so conversions stay attributed.') .
                email_tpl_p('Replace the button URL with your tracked landing page before sending.'),
                'View the offer',
                'https://example.com/offer'
            ),
        ],
        [
            'name' => 'Welcome - DOI complete',
            'subject' => 'You are in, {{first_name}}',
            'is_default' => 0,
            'html_body' => email_html_shell(
                'Your email is confirmed.',
                'Welcome',
                'You are on the list',
                email_tpl_p('Hi {{name}},') .
                email_tpl_p('Your double opt-in is complete. We will only send relevant campaign mail to {{email}}.') .
                email_tpl_p('If anything looks off, use the unsubscribe link at the bottom.'),
                '',
                ''
            ),
        ],
        [
            'name' => 'Client - Campaign status',
            'subject' => 'Campaign update for {{name}}',
            'is_default' => 0,
            'html_body' => email_html_shell(
                'A short status note from Track Flow.',
                'Client update',
                'Here is the latest on your campaign',
                email_tpl_p('Hello {{name}},') .
                email_tpl_p('Sharing a quick pulse on traffic, DOI completions, and conversions. Reply if you want a deeper cut by geo or vendor.') .
                email_tpl_p('This template is meant for client stakeholders. Keep the tone operational, not promotional.'),
                'Open the dashboard',
                'https://example.com/dashboard'
            ),
        ],
        [
            'name' => 'Re-engagement - Come back',
            'subject' => '{{first_name}}, we saved your spot',
            'is_default' => 0,
            'html_body' => email_html_shell(
                'One more look at the offer.',
                'Still interested?',
                'We held the offer for you',
                email_tpl_p('Hi {{first_name}},') .
                email_tpl_p('You started with us but never finished. If you still want in, the link below takes you back to the same offer.') .
                email_tpl_p('No pressure. You can unsubscribe in one click.'),
                'Continue where I left off',
                'https://example.com/offer'
            ),
        ],
    ];
}

function email_ensure_starter_templates(PDO $pdo, $created_by = null) {
    try {
        $existing = [];
        foreach ($pdo->query('SELECT name FROM email_templates') as $row) {
            $existing[] = (string)$row['name'];
        }
        $has_default = (int)$pdo->query('SELECT COUNT(*) FROM email_templates WHERE is_default = 1')->fetchColumn() > 0;
        $inserted = 0;
        $stmt = $pdo->prepare('INSERT INTO email_templates (name, subject, html_body, is_default, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
        foreach (email_starter_templates() as $tpl) {
            if (in_array($tpl['name'], $existing, true)) {
                continue;
            }
            $make_default = !empty($tpl['is_default']) && !$has_default;
            if ($make_default) {
                $has_default = true;
            }
            $stmt->execute([$tpl['name'], $tpl['subject'], $tpl['html_body'], $make_default ? 1 : 0, $created_by]);
            $inserted++;
        }
        return $inserted;
    } catch (Throwable $e) {
        error_log('email_ensure_starter_templates: ' . $e->getMessage());
        return 0;
    }
}

function email_campaign_geos(PDO $pdo, int $campaign_id, int $project_id): array {
    $geos = [];
    try {
        $stmt = $pdo->prepare('SELECT country_code FROM email_campaign_geo WHERE campaign_id = ?');
        $stmt->execute([$campaign_id]);
        foreach ($stmt->fetchAll() as $row) {
            $code = strtoupper(substr((string)($row['country_code'] ?? ''), 0, 2));
            if ($code !== '') $geos[] = $code;
        }
        if ($geos) return array_values(array_unique($geos));
    } catch (Throwable $e) {}
    $g = $pdo->prepare('SELECT country_code FROM campaign_geo WHERE project_id = ?');
    $g->execute([$project_id]);
    foreach ($g->fetchAll() as $row) {
        $code = strtoupper(substr((string)($row['country_code'] ?? ''), 0, 2));
        if ($code !== '') $geos[] = $code;
    }
    return array_values(array_unique($geos));
}

function email_campaign_eligible_count(PDO $pdo, int $project_id, int $vendor_id, int $campaign_id = 0): int {
    $geos = $campaign_id > 0 ? email_campaign_geos($pdo, $campaign_id, $project_id) : [];
    if (!$geos) {
        $g = $pdo->prepare('SELECT country_code FROM campaign_geo WHERE project_id = ?');
        $g->execute([$project_id]);
        foreach ($g->fetchAll() as $row) {
            $code = strtoupper(substr((string)($row['country_code'] ?? ''), 0, 2));
            if ($code !== '') $geos[] = $code;
        }
        $geos = array_values(array_unique($geos));
    }
    if (!$geos) return 0;
    $ph = implode(',', array_fill(0, count($geos), '?'));
    return email_count_scalar(
        $pdo,
        "SELECT COUNT(*) FROM email_list_entries ele
         JOIN email_lists el ON el.id = ele.list_id
         WHERE el.vendor_id = ?
           AND ele.status = 'active'
           AND ele.is_unsubscribed = 0
           AND UPPER(ele.country) IN ($ph)",
        array_merge([$vendor_id], $geos)
    );
}

function email_campaign_save_geos(PDO $pdo, int $campaign_id, array $codes): void {
    $codes = tf_normalize_geo_codes($codes);
    try {
        $pdo->prepare('DELETE FROM email_campaign_geo WHERE campaign_id = ?')->execute([$campaign_id]);
    } catch (Throwable $e) {
        try { $pdo->exec("CREATE TABLE IF NOT EXISTS `email_campaign_geo` (`id` INT AUTO_INCREMENT PRIMARY KEY, `campaign_id` INT NOT NULL, `country_code` CHAR(2) NOT NULL, `country_name` VARCHAR(100) DEFAULT NULL, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_campaign_country (`campaign_id`,`country_code`), INDEX idx_country_code (`country_code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e2) {}
        try { $pdo->prepare('DELETE FROM email_campaign_geo WHERE campaign_id = ?')->execute([$campaign_id]); } catch (Throwable $e3) { return; }
    }
    if (!$codes) return;
    $stmt = $pdo->prepare('INSERT INTO email_campaign_geo (campaign_id, country_code, country_name) VALUES (?, ?, ?)');
    $countries = tf_countries();
    foreach ($codes as $code) {
        try { $stmt->execute([$campaign_id, $code, $countries[$code] ?? null]); } catch (Throwable $e) {}
    }
}

function email_ensure_campaign_sends_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `email_campaign_geo` (`id` INT AUTO_INCREMENT PRIMARY KEY, `campaign_id` INT NOT NULL, `country_code` CHAR(2) NOT NULL, `country_name` VARCHAR(100) DEFAULT NULL, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uk_campaign_country (`campaign_id`,`country_code`), INDEX idx_country_code (`country_code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    try {
        $has = false;
        try { $pdo->query("SELECT next_retry_at FROM email_campaign_sends LIMIT 0"); $has = true; } catch (Throwable $e) { $has = false; }
        if (!$has) {
            try { $pdo->exec("ALTER TABLE email_campaign_sends ADD COLUMN next_retry_at DATETIME DEFAULT NULL AFTER retry_count"); } catch (Throwable $e2) {}
            try { $pdo->exec("ALTER TABLE email_campaign_sends ADD INDEX idx_next_retry (next_retry_at)"); } catch (Throwable $e2) {}
        }
    } catch (Throwable $e) {}
}
