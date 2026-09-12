<?php
require_once __DIR__ . '/../config.php';
require_login();

$page_title = 'Postback Reference';
require_once __DIR__ . '/../helpers/layout_header.php';
?>

<div class="tf-card">
    <div class="tf-card-header">
        <h5 class="mb-0 fw-semibold">Postback URL Reference</h5>
    </div>
    <div class="card-body">
        <p class="text-muted">Give the client ONE reusable URL from its <a href="<?php echo BASE_URL; ?>/clients/list.php">client page</a> — it works for every project of that client via <code>click_id</code> lookup. A legacy per-project URL still works as fallback. Track Flow records the values and can pass them on to the vendor global/default postback (blank assignment = inherit).</p>

        <h6 class="mt-4 fw-semibold">Required parameters</h6>
        <table class="table table-sm">
            <thead><tr><th>Name</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>click_id</code></td><td>The 32-char hex ID we sent when the user clicked our tracking link.</td></tr>
                <tr><td><code>status</code></td><td>1 = accepted conversion; 0 = rejected conversion (recorded but not counted as a completion).</td></tr>
                <tr><td><code>token</code></td><td>Client default token (reusable across all its projects) <em>or</em> per-project token (shown on project / client pages). Either is accepted via <code>click_id</code> lookup.</td></tr>
            </tbody>
        </table>

        <h6 class="mt-4 fw-semibold">Optional parameters</h6>
        <table class="table table-sm">
            <thead><tr><th>Name</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>sale_amount</code></td><td>Sale/revenue value (defaults to the project client CPI if omitted).</td></tr>
                <tr><td><code>currency</code></td><td>ISO 4217 (USD, EUR, GBP, INR…). Defaults to USD.</td></tr>
                <tr><td><code>payout</code></td><td>Per-vendor cost (defaults to vendor Payout).</td></tr>
                <tr><td><code>transaction_id</code></td><td>External transaction ID for deduplication / lookup.</td></tr>
                <tr><td><code>sub1</code>…<code>sub5</code></td><td>Custom values such as source, country, campaign, creative, or placement; recorded and available as outbound macros.</td></tr>
            </tbody>
        </table>

        <h6 class="mt-4 fw-semibold">Vendor/global postback macros</h6>
        <p class="text-muted small">When configuring a vendor or global postback URL, use these placeholders. They are replaced after a postback is accepted.</p>
        <p class="small"><code>{click_id}</code>, <code>{status}</code>, <code>{sale_amount}</code>, <code>{currency}</code>, <code>{payout}</code>, <code>{transaction_id}</code>, <code>{sub1}</code>…<code>{sub5}</code>. The older <code>{conversion_id}</code> placeholder remains supported as an alias for <code>{transaction_id}</code>.</p>

        <h6 class="mt-4 fw-semibold">Example URL</h6>
        <pre class="bg-light p-3 rounded small" style="font-family: ui-monospace, monospace;">https://yourdomain.com/tracking/postback.php
  ?click_id=REPLACE_ME
  &amp;status=1
  &amp;token=REPLACE_WITH_CLIENT_DEFAULT_OR_PROJECT_TOKEN
  &amp;sale_amount=10.00
  &amp;currency=USD
  &amp;payout=2.00
  &amp;transaction_id=TXN12345
  &amp;sub1=facebook
  &amp;sub2=US
  &amp;sub3=campaign_42</pre>

        <div class="alert alert-info small mt-3 mb-0">
            <strong>New user setup:</strong> copy the <strong>client default postback URL</strong> (one per client) and reuse it for every campaign — no per-project setup needed. Replace <code>REPLACE_ME</code> and the token, then map your platform's click ID and conversion fields to the parameters above. Keep <code>status=1</code> for success and <code>status=0</code> for rejection. Vendor side: set one <strong>Global Postback URL</strong> per vendor; leave project assignments blank to inherit.
        </div>

        <h6 class="mt-4 fw-semibold">Response codes</h6>
        <table class="table table-sm">
            <thead><tr><th>HTTP</th><th>Body</th><th>Meaning</th></tr></thead>
            <tbody>
                <tr><td>200</td><td><code>OK:RECORDED</code></td><td>Conversion accepted.</td></tr>
                <tr><td>200</td><td><code>OK:DUPLICATE</code></td><td>Already converted; ignored.</td></tr>
                <tr><td>200</td><td><code>OK:QUOTA_REACHED</code></td><td>Project quota exhausted.</td></tr>
                <tr><td>200</td><td><code>OK:PROJECT_NOT_LIVE</code></td><td>Project paused / closed.</td></tr>
                <tr><td>200</td><td><code>OK:VENDOR_BLOCKED</code></td><td>Vendor suspended / blacklisted.</td></tr>
                <tr><td>400</td><td><code>Invalid click_id format</code></td><td>Click ID malformed.</td></tr>
                <tr><td>403</td><td><code>Invalid token</code></td><td>Token mismatch.</td></tr>
                <tr><td>404</td><td><code>Click not found</code></td><td>Click ID doesn't exist.</td></tr>
                <tr><td>410</td><td>—</td><td>Campaign not active.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../helpers/layout_footer.php'; ?>
