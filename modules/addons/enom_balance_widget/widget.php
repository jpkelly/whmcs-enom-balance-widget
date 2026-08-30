<?php
/**
 * Enom Account Balance — admin dashboard widget class.
 *
 * Loaded by the addon module (modules/addons/enom_balance_widget/) so the
 * widget and its settings page share one home. See the module main file
 * for the full feature list, requirements, and licence.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Read one addon setting with a hard-coded fallback so the widget still
 * works if the module was never configured.
 */
if (!function_exists('enom_balance_widget_get_setting')) {
    function enom_balance_widget_get_setting($setting, $default)
    {
        try {
            $value = Capsule::table('tbladdonmodules')
                ->where('module', 'enom_balance_widget')
                ->where('setting', $setting)
                ->value('value');

            return ($value === null || $value === '') ? $default : $value;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}

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
            'yellowThreshold' => (float) enom_balance_widget_get_setting('low_balance_threshold', 100),
            'redThreshold' => (float) enom_balance_widget_get_setting('red_balance_threshold', 30),
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
        $yellow = (float) ($data['yellowThreshold'] ?? 100);
        $red = (float) ($data['redThreshold'] ?? 30);

        // Severity ladder: red (at/below red threshold) > yellow (below yellow
        // threshold) > normal. Red is the "act now" level — typically set to
        // the same value as eNom's own panel warning threshold.
        $balanceClass = 'data';
        $banner = '';
        if ($balance !== null && $balance < $red) {
            // WHMCS admin CSS has no color-red; stock widgets use color-pink
            // for the strongest figure emphasis (see Billing's "This Year").
            $balanceClass = 'data color-pink';
            $thresholdFormatted = '$' . number_format($red, 0);
            $banner = '<div class="banner banner-red">'
                . '<strong>Balance critically low.</strong> '
                . '(Below ' . $thresholdFormatted . '.) &mdash; refill at '
                . '<a href="https://cp.enom.com/myaccount/refillaccount.aspx" target="_blank" rel="noopener">Enom</a>.'
                . '</div>';
        } elseif ($balance !== null && $balance < $yellow) {
            $balanceClass = 'data color-orange';
            $thresholdFormatted = '$' . number_format($yellow, 0);
            $banner = '<div class="banner">'
                . '<strong>Low balance.</strong> '
                . '(Below ' . $thresholdFormatted . '.) &mdash; refill at '
                . '<a href="https://cp.enom.com/myaccount/refillaccount.aspx" target="_blank" rel="noopener">Enom</a>.'
                . '</div>';
        }

        $balanceFormatted = '$' . number_format((float) $balance, 2);
        $availableFormatted = '$' . number_format((float) $available, 2);
        $domainCount = (int) ($data['domainCount'] ?? 0);
        $transfersText = ($data['pendingTransfers'] === null)
            ? '&mdash;'
            : (string) $data['pendingTransfers'];

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
.widget-enombalancewidget .banner-red {
    border-color: #e3b0b0;
    background-color: #f8e3e3;
    color: #8a3b3b;
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