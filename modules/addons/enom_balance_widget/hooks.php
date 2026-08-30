<?php
/**
 * Addon hooks: registers the dashboard widget.
 *
 * WHMCS automatically loads modules/addons/<module>/hooks.php when the addon
 * is active, so the widget registers from here — no includes/hooks/ copy
 * needed. The widget class lives in widget.php (shared with the module's
 * admin-area page); _config()/_activate()/_deactivate()/_output() live in
 * the module root file enom_balance_widget.php.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

// Idempotent: defines enom_balance_widget_get_setting() + EnomBalanceWidget
// if they are not already loaded.
require_once __DIR__ . '/widget.php';

add_hook('AdminHomeWidgets', 1, function () {
    return new EnomBalanceWidget();
});