---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: What the common failures actually mean, and how to fix them.
---

The **connection log** at **Vismaz → Log** records every request, with bodies. When something is
wrong it is the first place to look, and usually the last.

## "Visma refused the connection"

Almost always the redirect URI. It must match what is registered on the Visma app **exactly** —
scheme, host, port and path. A local site on a non-standard port is the usual culprit.

## The connection stops working after an hour

The refresh token was never issued. Vismaz requests the `offline_access` scope, which is what
makes Visma issue one at all; if the app was approved before that scope was requested, the grant
does not have it. **Disconnect and reconnect.**

## "Vismaz is not connected to Visma"

Either there is genuinely no connection for the current environment, or the stored token could not
be decrypted — which happens if Craft's security key changed. Reconnect.

Remember that sandbox and production hold **separate** connections. Switching environment does not
lose the other one, but it does mean you are looking at a different connection.

## The wrong company

Vismaz asks Visma to prompt for a company on every connect, precisely so this is visible. If the
company shown on the Connection screen (**Vismaz → Connection**) is not the one you want, disconnect and reconnect.

## "Rate limited by Visma"

Visma allows 600 requests a minute per client, per endpoint. Vismaz backs off and retries, so this
is a delay rather than an error. If it happens constantly, switch off article or customer sync —
those are the calls that multiply with order volume.

## A document is "mismatched"

Visma accepted it, but the total it booked differs from the total sent. Open the document to see
both figures.

The usual causes are Visma applying its own invoice rounding, or a ledger account mapped to
something that behaves differently than expected. The document **is** in Visma — Vismaz does not
offer a retry, because that would post it twice.

## "The voucher does not balance"

Caught before sending, with the difference named. Run the dry run to see the journal:

```sh
php craft vismaz/sync/voucher --from=… --to=… --dryRun
```

The usual cause is a payment-method fee configured with an account that is also used for
settlement, so the fee and the settlement cancel each other.

## An EU sale was taxed when it should have been reverse charged

Check, in this order:

1. Is a **VAT number field handle** configured? Without one Vismaz has nothing to read.
2. Does the address actually carry a parseable EU VAT number? Text like `n/a` is rejected on
   purpose, so a customer cannot zero-rate their own order by typing something in the box.
3. Open the order panel and read the **reason**. It says which of these applied — including
   "VIES could not be reached, so the sale stays taxed."

That last one is deliberate, not a bug. See [Configuration](configuration#vies-validation).

## "EU sale … with no VAT charged and no zero-rating rule that applies"

Commerce charged no VAT and nothing in the rules zero-rated the sale. That is a gap in the tax
setup, not a decision Vismaz made, so it says so rather than silently booking a zero-rated sale.
Check the Commerce tax rules for that country.

## Orders are not being picked up

- Is the order **completed**? Carts are never synced.
- Is there an **order status filter** set that excludes them?
- Have they already been sent? An order already covered by a document is excluded from the
  unsynced list — that is what stops it being booked twice.

## A payment is stuck on "waiting"

The order was paid before its invoice was in Visma, which is normal — paying is what completes the
order. It is registered as soon as the invoice is sent. If the invoice is not going to be sent
automatically (automatic sending is off, or the order is outside the status filter), send it from
the order panel; its payments follow. `php craft vismaz/sync/payments` re-runs everything waiting.

## "No Visma bank account is mapped for the … gateway"

Visma registers a payment to one of its own bank accounts, and Vismaz will not guess which. Give
the gateway a **Visma bank account** under **Payment methods** in the settings, or set the default,
then press **Register payments** on the order. `php craft vismaz/sync/bank-accounts` lists what
Visma has.

## "Registering … would overpay it"

Visma says less is open on the invoice than the payment is for. Usually the payment is already
there — someone marked the invoice paid in Visma, or an earlier attempt reached Visma but its
answer never came back. Look at the invoice in Visma before doing anything; Vismaz deliberately
does not send it.

## SIE file imports with mangled account names

A SIE file is **CP437**, not UTF-8, and Vismaz writes it that way. If Å, Ä and Ö arrive mangled,
the file has been re-saved by something in between — an editor, a mail client, a cloud drive
preview. Send the file as generated.

## Nothing appears in the log at all

Body logging can be switched off in settings, but entries are still written. If there are no
entries whatsoever, the requests are not being made: check that automatic sending is on, or run a
console command and watch what appears.
