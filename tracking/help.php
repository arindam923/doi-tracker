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
        <p class="text-muted">Use these parameters in the conversion postback URL you set up on the client's tracking system. The <code>token</code> is unique per project and is shown on the project's detail page.</p>

        <h6 class="mt-4 fw-semibold">Required parameters</h6>
        <table class="table table-sm">
            <thead><tr><th>Name</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>click_id</code></td><td>The 32-char hex ID we sent when the user clicked our tracking link.</td></tr>
                <tr><td><code>status</code></td><td>1 = conversion, 0 = rejection (still recorded).</td></tr>
                <tr><td><code>token</code></td><td>Per-project secret token (shown in the project detail page).</td></tr>
            </tbody>
        </table>

        <h6 class="mt-4 fw-semibold">Optional parameters</h6>
        <table class="table table-sm">
            <thead><tr><th>Name</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td><code>sale_amount</code></td><td>Revenue value (defaults to project Payout if omitted).</td></tr>
                <tr><td><code>currency</code></td><td>ISO 4217 (USD, EUR, GBP, INR…). Defaults to USD.</td></tr>
                <tr><td><code>payout</code></td><td>Per-vendor cost (defaults to vendor Payout).</td></tr>
                <tr><td><code>transaction_id</code></td><td>External transaction ID for deduplication / lookup.</td></tr>
                <tr><td><code>sub1</code>…<code>sub5</code></td><td>Custom parameters — recorded for filtering and reporting.</td></tr>
            </tbody>
        </table>

        <h6 class="mt-4 fw-semibold">Example URL</h6>
        <pre class="bg-light p-3 rounded small" style="font-family: ui-monospace, monospace;">https://yourdomain.com/tracking/postback.php
  ?click_id=REPLACE_ME
  &amp;status=1
  &amp;token=REPLACE_WITH_PROJECT_TOKEN
  &amp;sale_amount=10.00
  &amp;currency=USD
  &amp;payout=2.00
  &amp;transaction_id=TXN12345
  &amp;sub1=facebook
  &amp;sub2=US
  &amp;sub3=campaign_42</pre>

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