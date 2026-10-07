---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and connecting Vismaz to your Visma company.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later
- A Visma developer client — a `client_id` and `client_secret`. Sandbox registration is
  self-service at the Visma developer portal.

## Install

```sh
composer require justinholtweb/craft-vismaz
php craft plugin/install vismaz
```

Or find **Vismaz** in the Craft Plugin Store and install it from there.

## Nothing is sent until you say so

Installing Vismaz changes nothing about your orders. Automatic sending is **off** by default, and
until you connect a Visma company there is nothing to send to. You can install it, connect a
sandbox company, and look at exactly what *would* be posted before anything touches real books.

## Register the redirect URI

Vismaz's settings screen shows a **Redirect URI**. Register it on your Visma app **exactly**,
character for character — including the scheme and any port. Visma rejects a mismatched redirect
before your browser ever reaches a sign-in screen, and the error it gives does not say which part
disagreed.

## Connect

1. Go to **Settings → Plugins → Vismaz**.
2. Choose **Sandbox** or **Production**. These are entirely separate hosts, credentials and
   companies, and Vismaz keeps a connection for each — so moving between them loses neither.
3. Paste the **Client ID** and **Client secret**. Both accept environment variables, and on a real
   site they should be environment variables.
4. Save, then go to **Vismaz → Connection**, press **Connect to Visma** and pick the company.

The Connection screen is separate from the settings on purpose. Plugin settings are read-only on a
production site, where `allowAdminChanges` is off, but the connection isn't a setting: its token is
stored in the database. So a revoked token can be reconnected in production, by an admin or by
anyone with the **Connect, test and disconnect Visma** permission. Set the client ID and secret as
environment variables there, since the settings screen won't save them.

Vismaz always asks Visma which company to use rather than accepting the last one you signed into.
A merchant with a trading company and a holding company under the same login would otherwise
connect the wrong books without any visible sign of it.

## Check it before you trust it

Press **Test connection**. It names the company it reached, which is the only confirmation worth
having.

Then open any completed order and press **Preview** in the Vismaz panel. That runs the same
builder the real send runs, with remote lookups switched off, so it creates nothing in Visma and
shows you the exact payload that would be posted.

## Which mode to choose

| | Invoice mode | Voucher mode |
|---|---|---|
| What Visma receives | One customer invoice per order | One journal entry per period |
| Suits | B2B, low volume, customers who need statements | Consumer shops with real volume |
| Visma reminders and statements | Work as normal | Not applicable |
| Customer ledger | One entry per buyer | Untouched |

A busy consumer shop wants **voucher mode**. Nine hundred orders a day become one verification
with a handful of postings, instead of nine hundred invoices in a customer ledger that will never
be looked at.

## Before you send anything real

Check the ledger accounts against the merchant's own chart of accounts. Vismaz ships BAS defaults
that are right for most Swedish shops, but "most" is not "yours", and a wrong account number does
not fail loudly — it quietly misfiles revenue until someone reconciles the year.

See [Configuration](configuration) for what each account does.
