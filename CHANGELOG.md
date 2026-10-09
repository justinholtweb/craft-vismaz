# Changelog

## 5.1.0 - 2026-10-08

> {warning} In invoice mode, Vismaz now registers each payment against its Visma invoice, and needs
> to know which **Visma bank account** to register it to. Set one per gateway under **Payment
> methods** in the settings, or a **Default Visma bank account** — a ledger account number such as
> `1930`, or the bank account's Visma ID (`php craft vismaz/sync/bank-accounts` lists them). Until
> one is set, payments are refused with a message saying so, and wait to be re-run. Also check your
> payment-method accounts: mappings saved before 5.1.0 were never read (see Fixed), and now apply.

### Added
- Invoice payments. Every successful capture or purchase on an order is registered against its
  Visma customer invoice (`POST /v2/customerinvoices/{id}/payments`), with the transaction's amount,
  currency and date, as a partial payment or as the one that settles the order. Invoices no longer
  sit unpaid in Visma's receivables after the customer has paid, and Visma's reminders stop
  chasing them.
- A payment made before its invoice exists waits, and is registered as soon as the invoice is in
  Visma.
- Registration is queued and retried by the queue when Visma is unreachable or answers 5xx. A
  refusal is recorded with its reason instead of retried.
- One Commerce transaction is registered at most once: a payment row unique on the transaction,
  claimed under a lock, plus a check of what Visma still has open on the invoice before each
  payment, which refuses one that would overpay it.
- Payments on the order panel, with their status and the reference Visma has for them, and a
  **Register payments** button that re-runs any not yet in Visma. Payments registered against an
  invoice show on its document screen; each attempt is in the log, with a re-run button.
- A **Visma bank account** per payment gateway and a default, as a ledger account number or a
  Visma ID, env-var-able.
- `vismaz/sync/payments` re-runs payments that failed or are waiting; `vismaz/sync/bank-accounts`
  lists the bank accounts set up in Visma.

### Fixed
- Payment-method accounts set in the CP were saved in a shape the settings never read back, so
  every gateway settled to the default account and the table showed empty on reload. They are now
  read as saved, and stored keyed by gateway.
- A ledger account in the payment-method table that is not a BAS account number now fails
  validation, like every other account setting.

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
