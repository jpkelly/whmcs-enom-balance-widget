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

1. Copy `includes/hooks/enom_balance_widget.php` into your WHMCS root's
   `includes/hooks/` directory:

   ```
   your-whmcs-root/
   └── includes/
       └── hooks/
           └── enom_balance_widget.php
   ```

2. Open (or reload) your admin dashboard. The **eNom Account Balance**
   widget appears automatically. Done.

`includes/hooks/` is preserved by WHMCS upgrades, so the widget survives
version upgrades — unlike a template or addon-module edit.

If you previously had the old Anaxa widget installed, deactivate it under
*Setup → Addon Modules* first to avoid confusion (it isn't loading anyway on
modern PHP, so there's nothing to migrate).

## Configuration

Everything sensible is a default; one knob is worth knowing about:

**Low-balance threshold** — edit `LOW_BALANCE_THRESHOLD` at the top of the
widget class (default: `100`, in USD). A good rule of thumb: one month of
renewals for your domain portfolio. When the balance is below it, the
balance figure turns orange and a warning banner appears.

That's the only configuration. Credentials are read at runtime through
WHMCS's registrar module layer — nothing is stored in the file, nothing is
logged, and the registrar module's **Test Mode** setting is respected
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
- **Widget missing entirely** — confirm the file is in
  `includes/hooks/` (not `modules/`), confirm the filename does not start
  with `_` (WHMCS skips those), and clear `templates_c/`.
- **Balance looks stale** — the data is cached for 15 minutes; use the
  widget's refresh button for an immediate update.

## License

MIT — see [LICENSE](LICENSE). Not affiliated with eNom or WHMCS Ltd.
"eNom" is a trademark of eNom, Inc.; WHMCS is a product of WHMCS Ltd.
This widget is an independent, unofficial integration.