<?php
/**
 * Enom Account Balance — admin dashboard widget for WHMCS.
 *
 * A free, plaintext replacement for the abandoned Anaxa/aspnix
 * "enom_balance_widget" v1.0.2 addon (ionCube-encoded for PHP 7.1; refuses
 * to load under PHP 8.1+ because the ionCube Loader only executes files
 * encoded for PHP 8.2/8.3 there — which is why the widget silently vanished
 * from admin dashboards after WHMCS 8.13 + PHP 8.x upgrades).
 *
 * Features:
 *   - Current + available Enom reseller balance (one GETBALANCE API call,
 *     cached 15 minutes, refreshable via the widget refresh button)
 *   - Domain count at Enom (returned by the same GETBALANCE call)
 *   - Pending transfers-in count (from the local tbldomains table, which
 *     WHMCS core's 4-hourly Domain Transfer Status cron keeps in sync)
 *   - Low-balance warning banner (threshold configurable below)
 *
 * Requirements:
 *   - WHMCS 8.x / 9.x (uses the documented AdminHomeWidgets hook +
 *     WHMCS\Module\AbstractWidget pattern)
 *   - The WHMCS Enom registrar module, with credentials configured
 *   - PHP curl extension
 *
 * Credentials are read at runtime through the WHMCS registrar module layer
 * (Module\Registrar->load('enom') + buildParams()) — nothing is stored in
 * this file, nothing is logged, and TestMode is respected automatically.
 *
 * Installation: copy this file into your WHMCS root's includes/hooks/
 * directory. That's it. WHMCS upgrades never touch includes/hooks/.
 *
 * Upgrade safety: includes/hooks/ is preserved by WHMCS upgrades. This file
 * was statically verified against WHMCS 9.0.6 (classes, hook points, and
 * every CSS class it emits).
 *
 * License: MIT (see LICENSE). No affiliation with eNom or WHMCS Ltd.
 */

use WHMCS\Database\Capsule;
use WHMCS\Module\AbstractWidget;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class EnomBalanceWidget extends AbstractWidget
{
    protected $title = 'eNom Account Balance';
    protected $description = 'Current Enom reseller account balance and transfer status.';
    protected $columns = 1;
    protected $weight = 125;
    protected $colour = 'green';
    protected $cache = true;
    protected $cacheExpiry = 15 * 60;
    protected $requiredPermission = '';

    /**
     * Warn when the balance drops below this many USD.
     * Rule of thumb: enough to cover ~1 month of renewals for your domains.
     */
    const LOW_BALANCE_THRESHOLD = 100;

    public function getData()
    {
        $data = [
            'configured' => false,
            'error' => '',
            'balance' => null,
            'availableBalance' => null,
            'domainCount' => null,
            'pendingTransfers' => null,
        ];

        try {
            if (!function_exists('curl_init')) {
                throw new \RuntimeException('PHP curl extension is not available.');
            }

            $registrar = new \WHMCS\Module\Registrar();
            if (!$registrar->load('enom')) {
                throw new \RuntimeException('Enom registrar module not found.');
            }

            // No domain context needed for GETBALANCE — buildParams([]) is enough.
            $params = $registrar->buildParams([]);
            $uid = (string) ($params['Username'] ?? '');
            $pw = (string) ($params['Password'] ?? '');
            if ($uid === '' || $pw === '') {
                throw new \RuntimeException('Enom credentials are not configured.');
            }
            $data['configured'] = true;

            $host = (stripos((string) ($params['TestMode'] ?? ''), 'on') === 0)
                ? 'resellertest.enom.com'
                : 'reseller.enom.com';

            $query = http_build_query([
                'command' => 'GETBALANCE',
                'uid' => $uid,
                'pw' => $pw,
                'responsetype' => 'xml',
            ]);

            $ch = curl_init('https://' . $host . '/interface.asp?' . $query);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new \RuntimeException('Connection to Enom failed: ' . $curlError);
            }

            $xml = @simplexml_load_string($response);
            if ($xml === false) {
                throw new \RuntimeException('Unparseable response from Enom.');
            }

            if ((int) $xml->ErrCount > 0) {
                $err = (string) ($xml->errors->Err1 ?? 'Enom API error');
                throw new \RuntimeException($err);
            }

            // eNom returns amounts like "2,261.65" — strip thousands separators.
            $data['balance'] = (float) str_replace(',', '', (string) $xml->Balance);
            $data['availableBalance'] = (float) str_replace(',', '', (string) $xml->AvailableBalance);
            $data['domainCount'] = (int) $xml->DomainCount;
        } catch (\Throwable $e) {
            $data['error'] = $e->getMessage();
        }

        // Pending transfers-in come from the local DB (kept in sync by WHMCS
        // core's Domain Transfer Status cron) — deliberately outside the
        // try/catch above so an API outage still leaves this accurate.
        try {
            $data['pendingTransfers'] = (int) Capsule::table('tbldomains')
                ->where('status', 'Pending Transfer')
                ->where('registrar', 'enom')
                ->count();
        } catch (\Throwable $e) {
            // leave null; render shows a dash
        }

        return $data;
    }

    public function generateOutput($data)
    {
        if (!empty($data['error'])) {
            return '<div class="widget-content-padded">'
                . '<div class="data color-orange">Enom error</div>'
                . '<div class="note">' . htmlspecialchars($data['error']) . '</div>'
                . '</div>';
        }

        if (empty($data['configured'])) {
            return '<div class="widget-content-padded">'
                . '<div class="note">Configure the Enom registrar module credentials '
                . '(Setup &gt; Products/Services &gt; Domain Registrars) to display '
                . 'your account balance here.</div>'
                . '</div>';
        }

        $balance = $data['balance'];
        $available = $data['availableBalance'];
        $low = ($balance !== null && $balance < self::LOW_BALANCE_THRESHOLD);
        // WHMCS admin CSS has no color-red; stock widgets use color-orange for warnings.
        $balanceClass = $low ? 'data color-orange' : 'data color-green';
        $balanceFormatted = '$' . number_format((float) $balance, 2);
        $availableFormatted = '$' . number_format((float) $available, 2);
        $domainCount = (int) ($data['domainCount'] ?? 0);
        $transfersText = ($data['pendingTransfers'] === null)
            ? '&mdash;'
            : (string) $data['pendingTransfers'];

        $banner = '';
        if ($low) {
            $thresholdFormatted = '$' . number_format((float) self::LOW_BALANCE_THRESHOLD, 0);
            $banner = '<div class="banner">'
                . '<strong>Low balance.</strong> '
                . '(Below ' . $thresholdFormatted . '.) &mdash; refill at '
                . '<a href="https://cp.enom.com/myaccount/refillaccount.aspx" target="_blank" rel="noopener">Enom</a>.'
                . '</div>';
        }

        // Typography mirrors the stock Billing widget exactly (its rules are
        // scoped to .widget-billing/.widget-stripe, so they don't apply here).
        // Scoping: WHMCS's dashboard template (homepage.tpl) wraps every widget
        // in <div class="panel ... widget-{getId()|strtolower}"> — for this
        // class that is .widget-enombalancewidget, which is exactly how the
        // stock Billing widget gets its .widget-billing styles. So we scope to
        // that native panel class and emit NO wrapper div of our own — no
        // wrapper means no risk of an unclosed div nesting subsequent widgets
        // inside this one (a bug this widget shipped with once). The style
        // block is safe to emit per-render: the browser dedupes identical
        // <style> rules, and stock WHMCS widgets already embed <script> blocks
        // in their output, so inline assets here are an established pattern.
        $output = <<<EOF
<style>
.widget-enombalancewidget .row {
    margin: 0;
}
.widget-enombalancewidget .item {
    padding: 13px 0;
    white-space: nowrap;
    overflow: hidden;
}
.widget-enombalancewidget .item .data {
    display: block;
    font-size: 1.8em;
}
.widget-enombalancewidget .item .note {
    font-size: 0.9em;
    color: #a2a6af;
}
.widget-enombalancewidget .bordered-right {
    border-right: 1px solid #eee;
}
.widget-enombalancewidget .bordered-top {
    border-top: 1px solid #eee;
}
.widget-enombalancewidget .banner {
    margin: 10px 0 0;
    padding: 8px 12px;
    font-size: 0.9em;
    border: 1px solid #f3d48f;
    border-radius: 4px;
    background-color: #fcf8e3;
    color: #8a6d3b;
}
/* Same small-screen behaviour as the stock Billing widget: drop the grid
   hairlines and use bottom borders on narrow viewports. */
@media only screen and (max-width: 767px) {
    .widget-enombalancewidget .bordered-right,
    .widget-enombalancewidget .bordered-top {
        border-right: 0;
        border-top: 0;
    }
    .widget-enombalancewidget .col-sm-6 {
        border-bottom: 1px solid #eee;
    }
    .widget-enombalancewidget .col-sm-6:last-child {
        border: 0;
    }
}
</style>
<div class="row">
    <div class="col-sm-6 bordered-right">
        <div class="item">
            <div class="{$balanceClass}">{$balanceFormatted}</div>
            <div class="note">Account Balance</div>
        </div>
    </div>
    <div class="col-sm-6">
        <div class="item">
            <div class="data">{$availableFormatted}</div>
            <div class="note">Available Balance</div>
        </div>
    </div>
    <div class="col-sm-6 bordered-right bordered-top">
        <div class="item">
            <div class="data">{$domainCount}</div>
            <div class="note">Domains at Enom</div>
        </div>
    </div>
    <div class="col-sm-6 bordered-top">
        <div class="item">
            <div class="data">{$transfersText}</div>
            <div class="note">Pending Transfers In</div>
        </div>
    </div>
</div>
{$banner}
EOF;

        return $output;
    }
}

add_hook('AdminHomeWidgets', 1, function () {
    return new EnomBalanceWidget();
});