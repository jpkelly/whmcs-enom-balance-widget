# WHMCS eNom Account Balance Widget

A free, plaintext admin dashboard widget for WHMCS that displays your **eNom
reseller account balance** — plus your domain count at eNom, pending
transfers-in, and a low-balance warning.

It replaces the abandoned Anaxa/aspnix "Enom Balance Widget" addon, which
silently stopped working for everyone on PHP 8.1+ (it was ionCube-encoded for
PHP 7.1, and the ionCube Loader refuses to execute such files on PHP 8.1+,
so the widget just vanished from the dashboard — with no error anywhere).

## Screenshot

<!-- TODO: add screenshot.png after first deployment, then re-enable:
![Widget on the WHMCS admin dashboard](screenshot.png)
-->

The widget renders four tiles in the standard WHMCS dashboard grid —
*Account Balance* and *Available Balance* on the first row, *Domains at
eNom* and *Pending Transfers In* on the second — plus an orange low-balance
banner underneath when the balance is under the threshold.

## What it shows

| Tile | Source |
|---|---|
| Account Balance | eNom `GETBALANCE` API (live, cached 15 min) |
| Available Balance | same API call |
| Domains at eNom | same API call — no extra request |
| Pending Transfers In | your local WHMCS database — zero API calls |
| Low-balance warning | shown when balance is under the threshold |

The **Pending Transfers In** count comes from your own `tbldomains` table,
which WHMCS core's 4-hourly *Domain Transfer Status Synchronisation* cron
keeps in sync — so the widget never adds API load for it. If the eNom API is
unreachable, the balance tiles show an error, but the transfer count stays
accurate.

The **low-balance warning** highlights the balance in orange and shows a
banner when your balance falls below a threshold you configure — eNom
declines registrations and renewals once the balance reaches $0, so this is
the one thing worth being nagged about.

## Requirements

- WHMCS 8.x or 9.x
- The **Enom** domain registrar module (ships with WHMCS), with your eNom
  credentials configured under *Setup → Products/Services → Domain Registrars*
- PHP `curl` extension
- No other dependencies. Nothing else to install.

## Installation

It's a WHMCS **addon module** with a settings page — no core-file edits:

1. Copy the `modules/addons/enom_balance_widget/` folder into your WHMCS
   root's `modules/addons/` directory:

   ```
   your-whmcs-root/
   └── modules/
       └── addons/
           └── enom_balance_widget/
               ├── enom_balance_widget.php   (module config)
               ├── widget.php                (the widget class)
               └── hooks.php                 (registers the widget)
   ```

2. Go to *Setup → Addon Modules*, find **Enom Balance Widget**, click
   **Activate**, then **Configure** to set the options.

3. Open (or reload) your admin dashboard. The **eNom Account Balance**
   widget appears automatically.

The widget registers itself from the addon module — nothing needs to be
copied into `includes/hooks/`. Addon module code lives outside the upgraded
file trees, so it survives WHMCS upgrades just like a hook file would.

If you previously had the old Anaxa widget installed, deactivate it under
*Setup → Addon Modules* first to avoid confusion (it isn't loading anyway on
modern PHP, so there's nothing to migrate).

## Settings

*Setup → Addon Modules → Enom Balance Widget → Configure:*

| Setting | Default | What it does |
|---|---|---|
| **Yellow Threshold (warning)** | `100` | The balance (USD) below which the balance figure turns orange and a yellow low-balance banner appears. A good rule of thumb: one month of renewals for your domain portfolio. |
| **Red Threshold (eNom warning level)** | `30` | The balance (USD) below which the figure turns red-pink and a red "critically low" banner appears. Set this to the same value as eNom's own panel warning threshold. Must be lower than the yellow threshold. |
| **Widget Width** | `1` | Dashboard grid width — `1` (standard column) or `2` (double width). |

Defaults are seeded on activation, so the widget works sensibly even before
you ever open the Configure form.

Credentials are never entered here: they're read at runtime through
WHMCS's registrar module layer — nothing is stored by this module, nothing
is logged, and the registrar module's **Test Mode** setting is respected
automatically (uses `resellertest.enom.com` when enabled).

## Compatibility

- **WHMCS 8.x**: tested on 8.13.5 (PHP 8.3, ionCube loader present) —
  verified end-to-end with live API calls.
- **WHMCS 9.x**: statically verified against the 9.0.6 release — same widget
  classes, same `AdminHomeWidgets` hook point, same CSS classes, same
  registrar-module credential API. No changes needed.
- Uses only documented WHMCS developer APIs (`AdminHomeWidgets` hook,
  `WHMCS\Module\AbstractWidget`, `WHMCS\Module\Registrar::buildParams`) and
  the public eNom `GETBALANCE` command.

## Troubleshooting

- **"Configure the Enom registrar module credentials…"** — the Enom
  registrar module has no Username/Password saved. Set them under
  *Setup → Products/Services → Domain Registrars → Enom*.
- **"Enom error" with a message** — the API call failed or returned an
  error; the message shown is the API's own error text. Check your eNom
  account status and API access (eNom requires your server IP to be
  allow-listed for API access).
- **Widget missing entirely** — confirm the addon is **Active** under
  *Setup → Addon Modules* (inactive modules don't load their hooks), that
  the folder is at `modules/addons/enom_balance_widget/` (not nested a
  level deeper), and clear `templates_c/` if unsure.
- **Balance looks stale** — the data is cached for 15 minutes; use the
  widget's refresh button for an immediate update.

> **Note on eNom's own low-balance email:** eNom lets you set a notification
> amount in its panel (or via the `UpdateNotificationAmount` API command),
> but that value is **write-only** via the API — there is no read command —
> so this widget cannot display or sync with it. That's why the widget has
> its own **Red Threshold**: set it to the same number you use for eNom's
> panel warning so the dashboard banner and eNom's email agree.

## License

MIT — see [LICENSE](LICENSE). Not affiliated with eNom or WHMCS Ltd.
"eNom" is a trademark of eNom, Inc.; WHMCS is a product of WHMCS Ltd.
This widget is an independent, unofficial integration.