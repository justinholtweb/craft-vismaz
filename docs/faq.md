---
title: FAQ
slug: faq
order: 50
summary: Common questions about putting Craft Commerce orders into Visma.
---

## Which Visma product is this?

**Visma eAccounting** — sold in Sweden as *Bokföring & Fakturering*, and before the 2025 rebrand
as *Visma eEkonomi*. The rebrand changed the name and the branding, not the API.

It is **not** for Visma.net ERP or Visma Business. Those are different products with different
APIs and almost no overlap with the Craft Commerce market.

## I use desktop Visma Administration. Is this any use?

Yes, through the SIE export. Visma Administration has no public API, so nothing can push to it —
but it imports SIE like every other Swedish accounting package. Vismaz writes the same journal it
would otherwise have posted, to a `.se` file your accountant imports.

## How much is it?

$79, with a $59/year renewal for continued updates and support. One price, everything switched on
— there is no feature-gated tier. The renewal is optional: the plugin keeps working when it
lapses, you just stop getting updates.

## Invoices or summary vouchers — which should I use?

If you sell B2B, or at low volume, or your customers need statements and reminders from Visma:
**invoices**.

If you run a consumer shop with real volume: **vouchers**. Nine hundred orders a day should be one
verification, not nine hundred invoices in a customer ledger nobody will ever read. This is what
most Swedish accountants will ask you for.

## Can it double-post an order?

No. Every document carries an idempotency key with a unique database index behind it. The queue
can retry, you can press the button twice and the console can run — all at the same moment — and
one document exists at the end of it. An order also cannot be swept into two different summary
vouchers.

## Is the preview really what gets sent?

Yes, literally. The preview, the console dry run, the real send and the SIE writer all run the
same builder and consume the same document object. A preview produced by different code from the
send would be a preview you could not trust, which would make it worse than none.

## Can a Visma problem break my checkout?

No. Order completion **queues** a job; nothing calls Visma during the request. An outage, an
expired token or a slow VIES lookup cannot reach the customer paying.

## What happens if VIES is down when an EU business orders?

The sale stays taxed. Vismaz will not zero-rate a sale on the strength of a timeout — if the
number cannot be confirmed, charging the VAT is the recoverable mistake and not charging it is
not. The order panel says exactly that, so you can correct it by hand if you know the number is
good.

## Does it handle öresavrundning?

Yes, as a real posting to 3740 rather than a rounding error absorbed into the last line's price.
It can be switched off if your Visma company handles it instead.

## Does it do OSS?

Yes. EU consumer sales are taxed at the destination rate, and the OSS report breaks a quarter down
by country and rate for the declaration. It reads the same tax decisions the ledger was built
from, so the report cannot disagree with the books.

## What about ROT and RUT deductions?

Not in this release. They need fields on the invoice that only make sense for services sold to
households, and a webshop selling goods will never use them.

## Will it change my existing Visma data?

It creates invoices, vouchers, customers and articles. Customers and articles are **matched
before anything is created**, by customer number and SKU, so a shop that already keeps its
register in Visma will not end up with a duplicate of it. Article and customer sync can both be
switched off entirely.

## Can I try it before it touches real books?

Yes, and you should. Visma's sandbox is free and self-service; connect that, run a preview or a
console dry run against your real orders, and read the payload. A dry run needs no connection at
all and creates nothing.

## What if the total Visma books is different from what I sent?

Vismaz compares them and marks the document **mismatched** instead of reporting success. That is
the failure mode worth catching: a subtly wrong mapping does not throw an error, it just books the
wrong number.

## Which versions are supported?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.
