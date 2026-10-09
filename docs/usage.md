---
title: Usage
slug: usage
order: 30
summary: Sending orders, the Orders index column and bulk action, registering payments, summary vouchers, refunds, the OSS report and SIE export.
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

## Payments

In invoice mode, every successful capture or purchase on an order is **registered as a payment
against its Visma invoice**, so the invoice is closed in Visma's receivables and Visma's reminders
never chase a customer who paid at checkout.

- **One Commerce transaction, one Visma payment.** A split or partial payment registers as a
  partial payment; the one that settles the order registers as complete.
- The **amount and currency** are the transaction's. The **date** is the day the money was taken,
  in the site's time zone (Visma reads it in the company's, and refuses a date in the future).
- The payment goes to the **Visma bank account mapped for its gateway** under
  [Payment methods](configuration#payment-methods), or the default.
- A payment made **before the invoice exists** — the usual case, since paying is what completes the
  order — shows as *waiting*, and is registered the moment the invoice is in Visma.

Registration is queued when Commerce records the transaction, never inline. A Visma that is down
or answering 5xx is retried by the queue, up to five times; a refusal (a 4xx, no bank account
mapped, a currency that is not the invoice's) is recorded and left for you, with the reason.

The order panel lists each payment with its status and the reference Visma has for it, and
**Register payments** re-runs every payment on the order that is not yet in Visma. A payment
already registered is skipped — pressing it twice sends nothing twice. Payments that failed or are
still waiting can also be re-run from the console:

```sh
php craft vismaz/sync/payments
```

Every attempt is in **Vismaz → Log**, against the order and the invoice, and a payment log entry
has its own **Register this order's payments again** button.

Before each payment Vismaz asks Visma how much is still open on the invoice. A payment that would
overpay it by more than half a krona is **refused, not sent** — the guard against registering one
twice after an attempt reached Visma but its answer was lost. Up to half a krona over is allowed,
because an invoice settled to whole kronor can be that much under what the customer paid.

Voucher mode registers no payments: the summary voucher already books each order to its
gateway's settlement account.

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

## The Orders index

Commerce's **Orders** index gets three things from Vismaz:

- **A Visma column.** Add it from the index's column settings. Each order reads **Synced**,
  **Mismatched**, **Failed**, **Pending** or **Not sent** — the order as a whole: a failed credit
  note or a payment that could not be registered marks it **Failed** even though its invoice is
  in Visma, and a mismatch outranks a clean send. The column is filled for a whole page in a few
  queries, and only shows to people with *View Visma documents*.
- **A Visma status filter.** The same five statuses as a condition rule, so **Failed in Visma** or
  **Not sent** can be a saved custom source. The filter and the column are built from the same
  rules and cannot disagree.
- **Send to Visma**, a bulk action for people with *Send orders to Visma*. It queues a push for
  every selected completed order (carts are skipped) through the same code as the order panel's
  button. A document already in Visma — sent or mismatched — is reported, never posted again, so
  selecting an order that is already there is harmless; a failed one is rebuilt from the order as
  it is now and retried.

Voucher mode: an order belongs to its period's voucher only once the voucher is in Visma, so a
voucher that failed does not mark its orders — look for it on the Documents screen.

## Alerts

One email (and optionally a Slack or Teams message) when documents fail, are booked at the wrong
total, payments cannot be registered, Visma refuses the connection or sending stalls — and one when
it clears. A **Visma health** Dashboard widget shows the same. See [Alerts](alerts.md).

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
php craft vismaz/sync/payments [--limit=100]
php craft vismaz/sync/bank-accounts
php craft vismaz/sie/export --from=… --to=… [--path=…] [--includeSynced]
php craft vismaz/auth/status
php craft vismaz/auth/refresh
php craft vismaz/log/prune [--days=30]
php craft vismaz/alerts/check
php craft vismaz/alerts/test
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
