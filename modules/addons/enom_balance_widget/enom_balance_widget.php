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
 *   - enom_balance_widget.php (this file): module config/activate/_output —
 *     WHMCS looks for _config() in the module root file (Configure button on
 *     Setup -> Addon Modules) and renders _output() as the module's own
 *     admin page in the Addon Modules sidebar.
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
 *   - Two-level low-balance warning (yellow/orange + red/pink), thresholds
 *     configurable on the module page AND via Configure
 *   - Widget column width (1 or 2 dashboard columns) configurable too
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
 *   2. Activate it under Setup -> Addon Modules, then open the module page
 *      (or its Configure button) to set the thresholds and widget width.
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
            . 'two-level low-balance warning.',
        'version' => '1.2.0',
        'author' => 'JP Kelly',
        'fields' => [
            'low_balance_threshold' => [
                'FriendlyName' => 'Yellow Threshold (warning)',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '100',
                'Description' => 'Balance (USD) below which the balance figure '
                    . 'turns orange and the yellow banner appears. Default: 100.',
            ],
            'red_balance_threshold' => [
                'FriendlyName' => 'Red Threshold (eNom warning level)',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '30',
                'Description' => 'Balance (USD) below which the balance figure '
                    . 'turns red-pink and the red banner appears. Set this to '
                    . 'the same value as your eNom panel warning threshold. '
                    . 'Default: 30. Must be lower than the yellow threshold.',
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
 * Called on activation — seed the settings with their defaults so both
 * settings surfaces are populated before the admin ever opens them.
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
            . 'dashboard. Open this module (or its Configure button) to set '
            . 'the thresholds and widget width.',
    ];
}

function enom_balance_widget_deactivate()
{
    return ['status' => 'success', 'description' => 'Enom Balance Widget disabled.'];
}

/**
 * Admin-area module page (Setup -> Addon Modules -> Enom Balance Widget,
 * also linked from the Addons dropdown menu).
 *
 * NOTE THE WHMCS CONTRACT, easy to get wrong: per developers.whmcs.com
 * ("Addon Module Output"), <module>_output() must ECHO its HTML — the
 * return value is silently discarded by WHMCS, which just captures what the
 * function prints. ("This should be actually output (i.e. echo'd) and not
 * returned.") A returning _output() therefore produces a BLANK page. Build
 * the HTML string (handy for escaping/testing) and echo it once at the end.
 * All output is captured by WHMCS and displayed inside the admin template.
 */
function enom_balance_widget_output($vars)
{
    $module = 'enom_balance_widget';

    // CSRF token: WHMCS makes generate_token() available in the admin area.
    $token = function_exists('generate_token') ? generate_token('form') : '';

    $saved = false;
    $error = '';

    if (isset($_REQUEST['save']) && $_REQUEST['save'] == '1') {
        $yellow = (float) $_REQUEST['low_balance_threshold'];
        $red = (float) $_REQUEST['red_balance_threshold'];

        if ($red >= $yellow) {
            $error = 'The red threshold must be lower than the yellow threshold.';
        } else {
            try {
                $columns = ($_REQUEST['widget_columns'] == '2') ? '2' : '1';
                foreach ([
                    'low_balance_threshold' => (string) $yellow,
                    'red_balance_threshold' => (string) $red,
                    'widget_columns' => $columns,
                ] as $setting => $value) {
                    $exists = Capsule::table('tbladdonmodules')
                        ->where('module', $module)
                        ->where('setting', $setting)
                        ->count();
                    if ($exists > 0) {
                        Capsule::table('tbladdonmodules')
                            ->where('module', $module)
                            ->where('setting', $setting)
                            ->update(['value' => $value]);
                    } else {
                        Capsule::table('tbladdonmodules')->insert([
                            'module' => $module,
                            'setting' => $setting,
                            'value' => $value,
                        ]);
                    }
                }
                $saved = true;
            } catch (\Throwable $e) {
                $error = 'Save failed: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    $yellow = enom_balance_widget_get_setting('low_balance_threshold', '100');
    $red = enom_balance_widget_get_setting('red_balance_threshold', '30');
    $columns = enom_balance_widget_get_setting('widget_columns', '1');

    $notice = $saved
        ? '<div class="alert alert-success">Settings saved. The dashboard '
            . 'widget updates on its next data refresh (cached 15 minutes, '
            . 'or click the widget\'s refresh icon).</div>'
        : ($error !== '' ? '<div class="alert alert-danger">' . $error . '</div>' : '');

    $col1 = ($columns == '1') ? ' selected' : '';
    $col2 = ($columns == '2') ? ' selected' : '';
    $yellowEsc = htmlspecialchars((string) $yellow, ENT_QUOTES, 'UTF-8');
    $redEsc = htmlspecialchars((string) $red, ENT_QUOTES, 'UTF-8');

    // Build as a string (testable, escapable), then ECHO it — WHMCS captures
    // echoed output and discards return values for _output().
    $html = <<<HTML
{$notice}
<p>The eNom Account Balance dashboard widget shows your eNom reseller
balance, domain count at eNom, pending transfers-in, and a two-level
low-balance warning. Thresholds apply to the <em>current balance</em>
reported by the eNom GETBALANCE API.</p>

<form method="post" action="addonmodules.php?module={$module}">
    <input type="hidden" name="save" value="1" />
    <input type="hidden" name="token" value="{$token}" />
    <table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">
        <tr>
            <td width="30%" class="fieldlabel">Yellow Threshold (warning)</td>
            <td class="fieldarea">
                <input type="text" name="low_balance_threshold" value="{$yellowEsc}" size="10" /> USD
                <div class="fieldnote">Balance figure turns orange and a yellow banner appears when the current balance is below this.</div>
            </td>
        </tr>
        <tr>
            <td class="fieldlabel">Red Threshold (eNom warning level)</td>
            <td class="fieldarea">
                <input type="text" name="red_balance_threshold" value="{$redEsc}" size="10" /> USD
                <div class="fieldnote">Balance figure turns red-pink and a red "critically low" banner appears. Set this to the same value as your eNom panel warning threshold. Must be lower than the yellow threshold.</div>
            </td>
        </tr>
        <tr>
            <td class="fieldlabel">Widget Width</td>
            <td class="fieldarea">
                <select name="widget_columns">
                    <option value="1"{$col1}>1 column</option>
                    <option value="2"{$col2}>2 columns</option>
                </select>
            </td>
        </tr>
    </table>
    <div class="btn-container">
        <input type="submit" value="Save Changes" class="btn btn-primary" />
    </div>
</form>
HTML;

    echo $html;
}