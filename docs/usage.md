---
title: Usage
slug: usage
order: 30
summary: Sending orders, running summary vouchers, refunds, the OSS report and SIE export.
---

## Previewing an order

Every completed order gets a **Vismaz** panel on Commerce's own order screen, showing how the
order is taxed, *why* it was taxed that way, and which accounts it books to.

**Preview** builds the document without sending it. It runs the same builder the real send runs
with remote lookups switched off, so it creates nothing in Visma. What you see is what would be
posted — not an approximation of it.

## Sending

**Send to Visma** posts the document now. In voucher mode a single order is swept into its
period's journal instead, since that is where it belongs.

Turn on **Send automatically when an order completes** and Vismaz queues a job on completion. It
is queued rather than inline, deliberately: a Visma outage, an expired token or a slow VIES lookup
must never be able to stop a customer paying.

## Summary vouchers

From **Vismaz → Documents**, pick a date range and press **Send summary voucher**. Or from the
console, where you can rehearse first:

```sh
php craft vismaz/sync/voucher --from=2026-08-01 --to=2026-08-31 --dryRun
```

The dry run prints the journal as debits and credits and tells you whether it balances:

```
Webshop sales 2026-08-01 – 2026-08-31 (412 orders)

  Account                                            Debit         Credit
  1580     Settlement                            512 340,90
  6570     Payment fees                            7 685,10
  1580     Payment fees                                          7 685,10
  3001     Försäljning varor inom Sverige, 25 %                409 872,00
  2610     Utgående moms, 25 %                                 102 468,00
  3740     Öresavrundning                                            0,90

  Balances.
```

An unbalanced voucher is **refused rather than sent**, with the difference named. Visma rejects
one too, but its error does not say which line is wrong.

## Refunds

**Credit refunds** raises a credit note for the amount actually refunded. It is keyed on the
refund transactions rather than the order, so a second partial refund produces a second credit
note while a retry of the first does not.

## What happens after a send

Vismaz records the invoice or verification number Visma assigns, then **compares the total Visma
booked with the total that was sent**. If they disagree — Visma's own invoice rounding, a mapping
that is subtly wrong — the document is marked **mismatched** rather than reported as sent.

This matters because a bad mapping does not throw. It books the wrong number, quietly, and nobody
finds out until a bank reconciliation months later.

A mismatched document is already in Visma, so Vismaz does not offer to retry it — retrying would
post it a second time. Reconcile it by hand.

## Retrying failures

```sh
php craft vismaz/sync/retry
```

Failed documents are **rebuilt from the order as it is now**, not replayed from the stored
payload. An order corrected after a failure pushes as corrected.

## The OSS report

**Vismaz → OSS report** breaks a quarter down by destination country and VAT rate — the shape the
declaration asks for. It reads the same tax decisions the ledger was built from, so the report and
the books cannot disagree with each other.

## SIE 4 export

For merchants on desktop **Visma Administration**, which has no public API. The same journal that
would have been posted as a voucher is written to a `.se` file the accountant imports by hand.

```sh
php craft vismaz/sie/export --from=2026-08-01 --to=2026-08-31 --path=./august.se
```

Or download it from **Vismaz → Documents**. Orders already sent to Visma are **excluded by
default** — including them as well is how a merchant books a month twice.

## Console reference

```sh
php craft vismaz/sync/orders --dryRun --from=2026-08-01 --to=2026-08-31
php craft vismaz/sync/orders --from=2026-08-01 --to=2026-08-31
php craft vismaz/sync/voucher --from=2026-08-01 --to=2026-08-31 [--dryRun]
php craft vismaz/sync/retry
php craft vismaz/sie/export --from=… --to=… [--path=…] [--includeSynced]
php craft vismaz/auth/status
php craft vismaz/auth/refresh
php craft vismaz/log/prune [--days=30]
```

A dry run needs no Visma connection at all. That is rather the point of it.

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
