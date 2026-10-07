# Changelog

## 5.0.1 - 2026-10-07

> {warning} Connecting, testing and disconnecting Visma have moved from the plugin settings page to
> **Vismaz → Connection**, behind a new **Connect, test and disconnect Visma** permission. Admins
> have it already; grant it to anyone else who looks after the connection. The plugin settings
> page still shows the connection where admin changes are allowed.

### Fixed
- Connecting, reconnecting after a revoked token, testing and disconnecting all required admin
  changes, so on a production site, where `allowAdminChanges` is off, nobody could do any of them.
  The connection lives in the database, not in project config, so it now has its own screen that
  works in production, behind its own permission. Clearing the connection log needs an admin but
  no longer needs admin changes.

### Security
- The OAuth callback checked only that its `state` existed, not the environment and user it was
  issued for. A sign-in started in sandbox could land its token on production if the environment
  was switched before Visma sent the merchant back. A callback whose environment or user doesn't
  match is now refused.

## 5.0.0

Initial release.

### Added

- OAuth2 connection to Visma eAccounting, with separate sandbox and production connections,
  automatic token refresh under a mutex, and revocation on disconnect.
- **Invoice mode** — one `CustomerInvoice` per order, with customer and article sync, configurable
  payment terms, and credit notes raised from refunds.
- **Voucher mode** — periodic summary journals aggregating orders by ledger account, VAT
  rate and payment method, daily, weekly or monthly.
- **Swedish VAT handling** — domestic rates at 25/12/6/0 %, reverse charge for EU B2B against a
  VIES-validated VAT number, OSS destination VAT for EU B2C, zero-rating for exports outside the
  EU, and öresavrundning posted to 3740.
- **OSS report** — the quarter broken down by destination country and rate, read from the
  same tax decisions the ledger is built from.
- **SIE 4 export** — for merchants on desktop Visma Administration, which has no API.
  CP437-encoded, as `#FORMAT PC8` requires.
- **Payment-method accounting** — per-gateway settlement accounts and processor fees as
  their own cost postings.
- **Reconciliation guard** — the total Visma booked is compared with the total sent, and a
  disagreement marks the document `mismatched` rather than reporting success.
- OAuth tokens encrypted at rest.
- Order-edit panel showing the tax treatment, its reasoning, the accounts it books to, and every
  document sent.
- Connection log with request and response bodies, credentials redacted, and retention pruning.
- Console commands for syncing, dry runs, vouchers, retries, SIE export, auth status and pruning.
- Swedish and English CP translations.
