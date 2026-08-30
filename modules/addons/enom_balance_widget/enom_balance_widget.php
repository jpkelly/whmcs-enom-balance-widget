<?php
/**
 * Enom Account Balance — admin dashboard widget + settings for WHMCS.
 *
 * A free, plaintext replacement for the abandoned Anaxa/aspnix
 * "enom_balance_widget" v1.0.2 addon (ionCube-encoded for PHP 7.1; refuses
 * to load under PHP 8.1+ because the ionCube Loader only executes files
 * encoded for PHP 8.2/8.3 there — which is why the widget silently vanished
 * from admin dashboards after WHMCS 8.13 + PHP 8.x upgrades).
 *
 * File layout:
 *   - enom_balance_widget.php (this file): module config/activate/output —
 *     WHMCS looks for _config() in the module root file and renders it as
 *     the Configure form under Setup -> Addon Modules.
 *   - widget.php: the EnomBalanceWidget dashboard-widget class + the
 *     settings-reading helper.
 *   - hooks.php: registers the widget via the AdminHomeWidgets hook; WHMCS
 *     loads this on every request when the addon is active.
 *
 * Features:
 *   - Current + available Enom reseller balance (one GETBALANCE API call,
 *     cached 15 minutes, refreshable via the widget refresh button)
 *   - Domain count at Enom (returned by the same GETBALANCE call)
 *   - Pending transfers-in count (from the local tbldomains table, which
 *     WHMCS core's 4-hourly Domain Transfer Status cron keeps in sync)
 *   - Low-balance warning banner + orange balance figure, threshold
 *     configurable under Setup -> Addon Modules -> Enom Balance Widget
 *   - Widget column width (1 or 2 dashboard columns) configurable there too
 *
 * Requirements:
 *   - WHMCS 8.x / 9.x (AdminHomeWidgets hook + WHMCS\Module\AbstractWidget)
 *   - The WHMCS Enom registrar module, with credentials configured
 *   - PHP curl extension
 *
 * Credentials are read at runtime through the WHMCS registrar module layer
 * (Module\Registrar->load('enom') + buildParams()) — nothing is stored in
 * this module, nothing is logged, and TestMode is respected automatically.
 *
 * Installation:
 *   1. Copy the enom_balance_widget/ folder into your WHMCS root's
 *      modules/addons/ directory.
 *   2. Activate it under Setup -> Addon Modules, then click Configure to
 *      set the low-balance threshold and widget width.
 *   The widget registers itself via the module's hooks.php — nothing to
 *   copy into includes/hooks/.
 *
 * License: MIT (see LICENSE). No affiliation with eNom or WHMCS Ltd.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * Addon module configuration — WHMCS renders this as the "Configure" form
 * under Setup -> Addon Modules -> Enom Balance Widget -> Configure.
 */
function enom_balance_widget_config()
{
    return [
        'name' => 'Enom Balance Widget',
        'description' => 'Admin dashboard widget showing the Enom reseller '
            . 'account balance, domain count, pending transfers, and a '
            . 'configurable low-balance warning.',
        'version' => '1.1.0',
        'author' => 'JP Kelly',
        'fields' => [
            'low_balance_threshold' => [
                'FriendlyName' => 'Yellow Threshold (warning)',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '100',
                'Description' => 'Balance (USD) below which the balance figure '
                    . 'turns orange and the low-balance banner appears. '
                    . 'Default: 100.',
            ],
            'red_balance_threshold' => [
                'FriendlyName' => 'Red Threshold (eNom warning level)',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '30',
                'Description' => 'Balance (USD) below which the balance figure '
                    . 'turns red-style bold emphasis. Set this to the same value '
                    . 'as your eNom panel warning threshold. Default: 30. '
                    . 'Must be lower than the yellow threshold.',
            ],
            'widget_columns' => [
                'FriendlyName' => 'Widget Width',
                'Type' => 'dropdown',
                'Options' => '1,2',
                'Default' => '1',
                'Description' => 'Dashboard grid width of the widget.',
            ],
        ],
    ];
}

/**
 * Called on activation — seed the settings with their defaults so the
 * Configure form is populated before the admin ever opens it.
 */
function enom_balance_widget_activate()
{
    try {
        $existing = Capsule::table('tbladdonmodules')
            ->where('module', 'enom_balance_widget')
            ->count();

        if ($existing === 0) {
            foreach (enom_balance_widget_config()['fields'] as $field => $meta) {
                Capsule::table('tbladdonmodules')->insert([
                    'module' => 'enom_balance_widget',
                    'setting' => $field,
                    'value' => $meta['Default'] ?? '',
                ]);
            }
        }
    } catch (\Throwable $e) {
        // Activation should not hard-fail on a seeding problem; the widget
        // falls back to hard-coded defaults regardless.
    }

    return [
        'status' => 'success',
        'description' => 'Add the "eNom Account Balance" widget to the admin '
            . 'dashboard. Use Configure to set the low-balance threshold and '
            . 'widget width.',
    ];
}

function enom_balance_widget_deactivate()
{
    return ['status' => 'success', 'description' => 'Enom Balance Widget disabled.'];
}

function enom_balance_widget_output($vars)
{
    // The admin-area module page needs no custom content: WHMCS renders the
    // Configure form itself from enom_balance_widget_config().
    return '';
}

/**
 * The dashboard widget itself. Kept in this same file — WHMCS loads addon
 * hook files (modules/addons/<name>/hooks.php), and the widget class must
 * be defined once.
 */
if (!class_exists('EnomBalanceWidget')) {
    class EnomBalanceWidget extends \WHMCS\Module\AbstractWidget
    {
        protected $title = 'eNom Account Balance';
        protected $description = 'Current Enom reseller account balance and transfer status.';
        protected $columns = 1;
        protected $weight = 125;
        protected $colour = 'green';
        protected $cache = true;
        protected $cacheExpiry = 15 * 60;
        protected $requiredPermission = '';

        public function __construct()
        {
            // Config-driven width: 1 (default) or 2 dashboard columns.
            $columns = (int) enom_balance_widget_get_setting('widget_columns', 1);
            if ($columns === 2) {
                $this->columns = 2;
            }
        }

        public function getData()
        {
            $data = [
                'configured' => false,
                'error' => '',
                'balance' => null,
                'availableBalance' => null,
                'domainCount' => null,
                'pendingTransfers' => null,
                'threshold' => (float) enom_balance_widget_get_setting('low_balance_threshold', 100),
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
            $threshold = (float) ($data['threshold'] ?? 100);
            $low = ($balance !== null && $balance < $threshold);
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
                $thresholdFormatted = '$' . number_format($threshold, 0);
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
            // inside this one. The .row margin reset is REQUIRED: inside the
            // zero-padding panel-body, Bootstrap's negative row margins otherwise
            // cancel the column padding and the left cells sit flush (clipped by
            // panel-body overflow:hidden). The media query matches Billing's
            // small-screen behaviour. Inline <style> in widget output is an
            // established pattern (stock widgets embed <script> blocks).
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
}

add_hook('AdminHomeWidgets', 1, function () {
    return new EnomBalanceWidget();
});