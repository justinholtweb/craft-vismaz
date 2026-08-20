# Vismaz — build plan

**Visma eAccounting integration for Craft Commerce 5.** Package `justinholtweb/craft-vismaz`,
handle `vismaz`, namespace `justinholtweb\vismaz`. **Lite $79 / Pro $149.**

## What it does

A completed Commerce order has to end up in the merchant's books. In Sweden that means Visma —
the product formerly sold as *Visma eEkonomi*, renamed *Bokföring & Fakturering* under the
**Spiris** brand in 2025. The API did not change with the rebrand.

Two ways an order can land, chosen per store in settings:

1. **Invoice mode** — one `CustomerInvoice` per order, with the customer and articles synced
   ahead of it, a payment recorded against it when the order is paid, and a credit note when it
   is refunded. What a B2B shop wants.
2. **Voucher mode** — orders aggregate into a periodic summary **voucher** (journal entry), one
   per period, with lines grouped by ledger account. What a Swedish accountant actually wants
   from a high-volume B2C shop: 900 orders a day become one verification, not 900 invoices
   clogging eAccounting.

Plus **SIE 4 export** (Pro), because a large share of Swedish merchants are on desktop *Visma
Administration 500/2000*, which has no public API at all. The same journal that would have been
pushed as a voucher is written to a `.se` file the accountant imports by hand.

## Why it exists

The Swedish Craft Commerce shop's current options are a bookkeeper re-keying orders, a generic
Zapier hop that knows nothing about moms, or a WooCommerce-only plugin. None of them handle the
things that actually make Swedish e-commerce bookkeeping hard, which is where all of Vismaz's
value is:

- **Öresavrundning.** Swedish invoices settle to whole kronor; the difference is a real posting
  to 3740, not a rounding error you hide.
- **Omvänd betalningsskyldighet.** An EU B2B sale to a validated VAT number is zero-rated and
  the invoice must say so, in the buyer's language, with the buyer's VAT number on it.
- **OSS.** B2C distance selling into the EU uses the *destination* country's VAT rate, and the
  quarterly OSS declaration needs it broken out per country.
- **Payment-method accounting.** Klarna, Swish, Stripe and card do not settle to the same
  account, and the PSP fee is a cost posting of its own. Getting this wrong is the single most
  common reason a webshop's books do not reconcile.

## Editions

| | Lite ($79) | Pro ($149) |
|---|---|---|
| Connect to Visma (OAuth2), connection log | ✓ | ✓ |
| Invoice mode, customer + article sync | ✓ | ✓ |
| Swedish VAT mapping, öresavrundning | ✓ | ✓ |
| Manual push + preview from the order screen | ✓ | ✓ |
| Voucher mode (periodic summary journals) | | ✓ |
| Reverse charge with VIES validation | | ✓ |
| OSS destination VAT + per-country report | | ✓ |
| Payment-method → ledger account mapping, PSP fees | | ✓ |
| Credit notes from refunds | | ✓ |
| SIE 4 export | | ✓ |
| Automatic push on order completion (queue) | | ✓ |
| Console commands | | ✓ |

## Protocol notes (read, not guessed)

- **Auth** is OAuth2 authorization code. Authorize `https://identity.vismaonline.com/connect/authorize`,
  token/refresh `…/connect/token`, revoke `…/connect/revocation`. Token endpoint wants HTTP Basic
  `client_id:client_secret`. Scopes `ea:api offline_access` — `offline_access` is what gets you a
  refresh token at all. **Access token lives 60 minutes; the refresh token lives two years but
  dies when the user changes their password.** `prompt=select_account` is strongly recommended,
  otherwise the merchant silently reconnects the wrong company.
- **Sandbox is a different host in both places**: `identity-sandbox.test.vismaonline.com` and
  `eaccountingapi-sandbox.test.vismaonline.com/v2`. Production is
  `identity.vismaonline.com` / `eaccountingapi.vismaonline.com/v2`.
- **Rate limit is 600 requests/minute per client per endpoint**, answered with `429` and an
  application-level `ErrorCode: 4010`. Back off and retry rather than failing the sync.
- **JSON is PascalCase** (`CustomerId`, `InvoiceDate`, `Rows[].UnitPrice`, `VatPercent`), and
  queries filter with OData (`$filter`, `$top`, `$skip`).
- **VIES** validates EU VAT numbers at
  `https://ec.europa.eu/taxation_customs/vies/rest-api/ms/{country}/vat/{number}` — no key, no
  registration, and frequently down, so every call is cached and fails *open*.

## BAS defaults (verified against the published chart, all overridable)

Getting these wrong silently corrupts a merchant's books, so they were checked rather than
recalled: `3001/3002/3003/3004` domestic sales at 25/12/6/0 %, `3105` goods outside the EU,
`3106/3108` goods to another EU country (taxable/exempt), **`3305` services *outside* the EU and
`3308` services to another EU country** (that pair is the reverse of the obvious guess),
**`3520` invoiced freight** and **`3540` invoicing fees** (also easy to swap), `3590` other
invoiced costs, `3740` öres- och kronutjämning, `1510` accounts receivable, `1580` card/coupon
receivable, `1930` bank, `2610/2620/2630` output VAT at 25/12/6 %, `2614/2624/2634` output VAT
under reverse charge, `6570` bank charges.

## Architecture

### Three invariants

1. **`services\Documents` is the only place a Commerce order becomes Visma data.** The CP
   preview, the console dry run, the real push and the SIE writer all consume the same
   `models\Document`. A preview is therefore identical to what is sent, which is the only way a
   merchant can trust the preview at all.
2. **`services\Sync::record()` is the only place a document row is written.** Idempotency, the
   attempt counter and the sent/failed decision are made once and cannot disagree.
3. **`services\Tax::treat()` is the only place a VAT treatment is decided.** Invoice rows,
   voucher lines, the SIE file and the OSS report all ask it the same question, so a sale cannot
   be reverse-charged on the invoice and domestic in the report.

### Idempotency

`{{%vismaz_documents}}` carries a unique index on `(type, sourceKey)`. That index *is* the
guarantee — the code cannot double-post even if the queue retries, the merchant mashes the
button, and the console runs at the same time.

- invoice → `order:<orderId>`
- creditnote → `refund:<orderId>:<transactionHash>`
- voucher → `voucher:<periodStart>:<periodEnd>`

`{{%vismaz_documentorders}}` has a unique index on `orderId` so an order cannot be swept into two
different summary vouchers.

### Failing open

Order completion **queues** a push; it never pushes inline. A Visma outage, an expired refresh
token or a VIES timeout must never be able to stop a customer paying, and must never leave a paid
order unsaved.

## Testing

`tests/integration/checks.php`, run inside the shared plugin-testing harness. Switches to Pro for
the bulk of the run and exercises Lite in its own section; restores edition, settings and every
fixture in a `finally`.
