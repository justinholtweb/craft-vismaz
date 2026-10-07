# Vismaz

**Visma eAccounting integration for Craft Commerce 5.**

A completed Commerce order has to end up in the merchant's books. In Sweden that means Visma — the
product sold as *Visma eEkonomi*, renamed *Bokföring & Fakturering* under the **Spiris** brand in
2025. Vismaz posts orders there as customer invoices or as periodic summary vouchers, handles the
Swedish VAT rules properly, and can write a SIE 4 file for merchants on desktop Visma
Administration, which has no API at all.

## Why it exists

Getting orders into Visma is the easy part. The parts that are actually hard — and that generic
integrations get wrong — are all Swedish:

- **Öresavrundning.** A Swedish invoice settles to whole kronor, and the difference is a real
  posting to 3740, not a rounding error absorbed into the last line's price.
- **Omvänd betalningsskyldighet.** An EU B2B sale against a validated VAT number is zero-rated,
  and the invoice must say so, with the buyer's VAT number on it.
- **OSS.** B2C distance selling into the EU uses the *destination* country's VAT rate, and the
  quarterly declaration needs it broken out per country and rate.
- **Payment-method accounting.** Klarna, Swish, Stripe and card do not settle to the same account,
  and the processor's cut is a cost posting of its own. Getting this wrong is the single most
  common reason a webshop's books will not reconcile.

## Two ways an order can land

**Invoice mode** creates one `CustomerInvoice` per order, with the customer and articles synced
ahead of it and a credit note when it is refunded. This is what a B2B shop wants, and it makes
Visma's own statements and reminders work.

**Voucher mode** aggregates orders into a periodic summary journal — one verification per day,
week or month, with lines grouped by ledger account. This is what a Swedish accountant actually
wants from a busy consumer shop: 900 orders a day become one verification, not 900 invoices
clogging the customer ledger.

The choice is a setting, and both go through the same builder.

## Requirements

- Craft CMS 5.3+
- Craft Commerce 5.0+
- PHP 8.2+
- A Visma developer client (`client_id` / `client_secret`). Sandbox registration is self-service.

## Installation

```sh
composer require justinholtweb/craft-vismaz
php craft plugin/install vismaz
```

## Setting up

1. Create an app in the Visma developer portal and register the redirect URI shown on Vismaz's
   settings screen — **exactly**, character for character. A mismatch is rejected by Visma before
   your merchant ever reaches a sign-in screen.
2. Paste the client ID and secret into Settings → Vismaz, pick **Sandbox** or **Production**, and
   save. Both are supported as env variables.
3. Go to **Vismaz → Connection**, press **Connect to Visma** and pick the company. That screen works
   in production too, and anyone with the **Connect, test and disconnect Visma** permission can use
   it, not just admins. Vismaz always asks Visma which company to use
   rather than accepting the last one, so a merchant with a trading company and a holding company
   cannot silently connect the wrong books.
4. Choose invoice or voucher mode, and check the ledger accounts against the merchant's own chart.

Sandbox and production keep separate connections, so switching between them loses neither.

## What you get

Vismaz is **$79**, with a **$59/year** renewal for continued updates and support. There is no
feature-gated tier: one price, everything switched on.

| | |
|---|---|
| Connect to Visma (OAuth2), connection log | ✓ |
| Invoice mode — one customer invoice per order | ✓ |
| Voucher mode — periodic summary journals | ✓ |
| Customer and article sync | ✓ |
| Swedish VAT mapping and öresavrundning | ✓ |
| Reverse charge with VIES validation | ✓ |
| OSS destination VAT + per-country report | ✓ |
| Payment-method → ledger account mapping, processor fees | ✓ |
| Credit notes from refunds | ✓ |
| SIE 4 export | ✓ |
| Automatic send on order completion | ✓ |
| Console commands | ✓ |

The renewal is optional. The plugin keeps working when it lapses; you stop receiving updates.

## Console commands

```sh
# What would be sent, without sending it — needs no connection, creates nothing.
php craft vismaz/sync/orders --dryRun --from=2026-08-01 --to=2026-08-31

# Send unsynced orders.
php craft vismaz/sync/orders --from=2026-08-01 --to=2026-08-31

# Build a period's summary voucher. --dryRun prints the journal as debits and credits.
php craft vismaz/sync/voucher --from=2026-08-01 --to=2026-08-31 --dryRun

# Retry everything that failed. Documents are rebuilt from the order as it is *now*,
# not replayed from a stale payload.
php craft vismaz/sync/retry

# SIE 4 file for an accountant on desktop Visma.
php craft vismaz/sie/export --from=2026-08-01 --to=2026-08-31 --path=./august.se

# Is the connection alive, and to which company?
php craft vismaz/auth/status

php craft vismaz/log/prune
```

## What Vismaz guarantees

**It cannot double-post.** Every document carries an idempotency key, with a unique database index
on it. The queue can retry, the merchant can mash the button and the console can run — all at
once — and only one document exists at the end of it. An order likewise cannot be swept into two
different summary vouchers.

**The preview is the push.** The CP preview, the console dry run, the real send and the SIE writer
all run the same builder and consume the same document. A preview is not an approximation of what
will be sent; it is what will be sent.

**It fails open.** Order completion queues a job; it never calls Visma inline. A Visma outage, an
expired token or a VIES timeout can never stop a customer paying.

**When in doubt, it charges the VAT.** A VAT number that VIES cannot confirm — whether because it
is wrong or because VIES is down — leaves the sale taxed. Zero-rating a sale on the strength of a
timeout is the merchant's liability.

**It checks Visma's answer.** After every invoice, the total Visma actually booked is compared
with the total that was sent. A disagreement — Visma's own invoice rounding, a mapping that is
subtly wrong — marks the document **mismatched** and says so, instead of reporting success. A bad
mapping does not throw; it just books the wrong number, and nobody finds out until a bank
reconciliation months later.

**Tokens are encrypted at rest.** A Visma refresh token is valid for two years.

**Unbalanced journals are refused, not sent.** A voucher whose debits and credits differ is caught
before it leaves, with the difference named, rather than being rejected by Visma with a message
that does not say which line is wrong.

## Twig

```twig
{% if craft.vismaz.isConnected() %}
  {% set treatment = craft.vismaz.treatment(order) %}
  {{ treatment.getLabel() }} — {{ treatment.reason }}
  {% for document in craft.vismaz.documentsForOrder(order) %}
    {{ document.type }}: {{ document.vismaNumber ?? 'pending' }}
  {% endfor %}
{% endif %}
```

## Ledger accounts

Vismaz ships BAS defaults that most Swedish shops can leave alone, all overridable in settings:
`3001/3002/3003/3004` domestic sales at 25/12/6/0 %, `3105` goods outside the EU, `3106/3108`
goods to another EU country, `3305` services *outside* the EU and `3308` services *to* another EU
country, `3520` invoiced freight, `3540` invoicing fees, `3740` öres- och kronutjämning, `1510`
receivables, `1580` card receivables, `2610/2620/2630` output VAT, `6570` bank charges.

These matter for summary vouchers and SIE files. Invoice mode takes its accounts from Visma's own
article coding.

## Licence

Proprietary. © Justin Holt.
