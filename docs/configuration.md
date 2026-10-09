---
title: Configuration
slug: configuration
order: 20
summary: Document modes, Swedish VAT rules, ledger accounts and payment-method mapping.
---

## Document mode

**Invoice mode** creates one `CustomerInvoice` per order. **Voucher mode** aggregates orders into
a periodic summary journal — daily, weekly or monthly — with lines grouped by ledger account.

Both go through the same builder, so the tax treatment, the rounding and the accounts are decided
identically either way. Only the shape of what reaches Visma differs.

In invoice mode, **Register payments against invoices** (on by default) registers each Commerce
payment against its invoice in Visma — see [Payments](usage#payments).

## Customers

A Visma invoice needs a customer. Two shapes are supported:

- **One Visma customer per buyer.** What a B2B shop wants — it makes Visma's own statements and
  payment reminders work.
- **A single shared webshop customer.** Set a customer number and Vismaz reuses it, creating it
  once if it does not exist. For a consumer shop this is almost always the right answer: a Visma
  customer register holding forty thousand one-time buyers is not a customer register, it is a
  performance problem with a data-protection liability attached.

## Articles

Optional. Switching article sync on gives you Visma's own sales-per-article reporting; leaving it
off means invoices use free-text rows, which is equally valid bookkeeping and far kinder to a
large catalogue. Articles are matched by SKU before anything is created, so a shop that already
keeps articles in Visma will not end up with a duplicate register.

## Address field handles

Two settings name custom fields on your Craft addresses:

- **Organisation number** — the Swedish organisationsnummer, written to the Visma customer.
- **VAT number** — the EU VAT number. **Without this, reverse charge can never apply**, because
  Vismaz has nowhere to read the number from.

## Swedish VAT

The decision, in order, for a seller in Sweden:

1. **Destination is Sweden** → domestic VAT at whatever rate Commerce charged.
2. **Another EU country, buyer gave a VAT number** → reverse charge. Zero-rated, booked to the
   EU-exempt account, and the invoice carries the required wording.
3. **Another EU country, no VAT number, OSS on** → destination-country VAT, tracked per country
   for the quarterly declaration.
4. **Outside the EU** → export, zero-rated.
5. **Otherwise** → domestic.

That last line is the disposition of the whole feature: **when in doubt, charge the VAT.** Taxing
a sale you should not have is recoverable. Zero-rating one you should have taxed is not.

### VIES validation

With validation on, a VAT number is checked against the EU's VIES service before a sale is
zero-rated. VIES is free and needs no key — and individual member states take their registries
offline without warning.

**A number VIES cannot confirm leaves the sale taxed**, whether because the number is wrong or
because VIES is unreachable. Zero-rating on the strength of a timeout is the merchant's liability,
not the buyer's. Results are cached: thirty days for a valid number, one day for an invalid one,
and a timeout is never cached at all, because it says nothing about the number.

### Öresavrundning

With rounding on, an invoice total settles to whole kronor and the difference is posted to the
rounding account as a real line — not absorbed into the last item's price, where it would quietly
corrupt that account instead.

## Ledger accounts

These matter for **voucher mode and SIE files**. Invoice mode takes its accounts from Visma's own
article coding.

| Setting | BAS default | |
|---|---|---|
| Sales 25 / 12 / 6 / 0 % | `3001` `3002` `3003` `3004` | Försäljning varor inom Sverige |
| Output VAT 25 / 12 / 6 % | `2610` `2620` `2630` | Utgående moms |
| Goods to another EU country, exempt | `3108` | |
| Goods outside the EU | `3105` | |
| Services to another EU country | `3308` | |
| Services outside the EU | `3305` | |
| Invoiced freight | `3520` | Fakturerade frakter |
| Discounts given | `3730` | |
| Öresavrundning | `3740` | Öres- och kronutjämning |
| Accounts receivable | `1510` | Kundfordringar |
| Default settlement | `1580` | Fordringar för kontokort och kuponger |
| Payment fees | `6570` | Bankkostnader |

Two pairs here are the reverse of the obvious guess, and both are worth checking against your own
chart before you rely on them:

- **`3305` is services sold *outside* the EU; `3308` is services *to* another EU country.**
- **`3520` is invoiced *freight*; `3540` is the invoicing fee.**

Note also that the `2614`/`2624`/`2634` reverse-charge VAT accounts are for *purchases* under
reverse charge. A reverse-charged **sale** has no output VAT posting at all, which is why Vismaz
does not write one.

## Payment methods

This is the setting that decides whether the books reconcile.

Klarna, Swish, card and invoice do not settle to the same account, and the processor's cut is a
cost posting in its own right rather than a discount on revenue. Map each Commerce gateway to:

- the account the money actually lands in,
- the account its fee is booked to,
- the fee as a percentage and/or a fixed amount,
- the **Visma bank account** its payments are registered to, in invoice mode.

A gateway with no mapping falls back to the default settlement account and books no fee.

### The Visma bank account

Visma registers an invoice payment to one of the **bank accounts set up in Visma**, not to a ledger
account directly. Give it either the bank account's ledger account number — `1930` — and Vismaz
looks up the bank account that books to it, or the bank account's Visma ID (a GUID). An environment
variable (`$STRIPE_VISMA_BANK`) works for either. To see what Visma has:

```sh
php craft vismaz/sync/bank-accounts
```

**Default Visma bank account** covers every gateway without one of its own. With neither, a
payment is not guessed onto some account: it is refused, and the order panel and the log say which
gateway needs one.

Before 5.1.0, mappings made in the CP were saved in a shape the settings never read back: every
gateway fell through to the default settlement account, and the table showed empty on reload. 5.1.0
reads them as saved, so a mapping you made then now takes effect — check them.

## Log

Every request to Visma is recorded, with bodies if you want them. Credentials, tokens and secrets
are stripped before anything is stored, so a support screenshot of the log is not a credential
leak. Set a retention in days, or `0` to keep everything.

## Alerts

Who is told when sending to Visma goes wrong — email recipients, a Slack or Teams webhook, which
incidents to alert on, the failure threshold and window, the stall time and a quiet period after a
recovery. Nothing is required. See [Alerts](alerts.md) for every setting.
