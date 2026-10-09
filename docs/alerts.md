---
title: Alerts
slug: alerts
order: 35
summary: One email (and optionally a Slack or Teams message) when sending to Visma goes wrong, one when it recovers — and a Dashboard widget.
---

# Alerts

The Documents screen knows everything that has gone wrong, but nobody opens an integration's
screen on a day it seems to be working. A mapping that books the wrong total, or a Visma password
change that silently kills the connection, is otherwise found at the next bank reconciliation.
Vismaz tells you instead, when one of five things happens:

| Incident | Opens when | Clears when |
|---|---|---|
| **Orders failing to reach Visma** | `alertFailureThreshold` invoices, credit notes or vouchers (default 1) fail inside `alertWindowMinutes` (default 60) | a whole window passes with none |
| **Documents booked at the wrong total** | Visma accepts a document at a different total from the one sent (it is marked *mismatched*) | a whole window passes with none |
| **Payments not registered in Visma** | `alertFailureThreshold` Commerce payments cannot be registered against their Visma invoice inside the window | a whole window passes with none |
| **Visma refused the connection** | Visma refuses the refresh token, or still answers 401 after Vismaz has renewed the access token | the next authenticated request succeeds |
| **Sending to Visma has stalled** | for `alertStallHours` (default 6): a completed order has no invoice while automatic sending is on, a send has been stuck as pending, or a payment's job never ran | the backlog is gone |

You get **one message when an incident starts and one when it clears**, never one per failure.
Each incident has a single latch row in the database: checking it a hundred times while it is
still open sends nothing new. One that keeps flapping is held by `alertCooldownMinutes` (default
60): if it reopens within that long of its recovery message, you hear about it when the quiet
period ends, and only if it is still happening.

A recovery is not "everything is fixed". It says what is still waiting — "Nothing new has failed
in the last 60 minutes. 3 documents still show as failed on the Documents screen."

A network failure is not an authentication failure, and a 5xx from Visma's identity server is not
one either. A refused *authorization code* — somebody pressing **Connect** with the wrong
company — is not signalled: they are looking at the error already.

## Setting it up

**Settings → Plugins → Vismaz → Alerts.**

| Setting | Default | |
|---|---|---|
| `alertRecipients` | empty | Addresses separated by commas, or an `$ENV` reference. Empty means no email |
| `alertWebhookUrl` | empty | A Slack or Teams incoming-webhook URL, or an `$ENV` reference. Keep it in an environment variable: the URL is the credential |
| `alertWebhookFormat` | `slack` | `slack`, `teams`, or `json` for your own receiver |
| `alertWebhookSecret` | empty | When set, each webhook carries `X-Vismaz-Timestamp` and `X-Vismaz-Signature: sha256=<hmac>` over `timestamp.body` |
| `alertOnFailures` | on | |
| `alertOnPayments` | on | |
| `alertFailureThreshold` | 1 | Counted separately for documents and for payments |
| `alertWindowMinutes` | 60 | |
| `alertOnMismatch` | on | |
| `alertOnAuthFailure` | on | |
| `alertStallHours` | 6 | 0 switches stall alerts off |
| `alertCooldownMinutes` | 60 | |
| `allowPrivateAlertWebhookHosts` | off | Config file only — see below |

Mail goes through Craft's own mailer, so it uses whatever **Settings → Email** is set to. Press
**Send a test alert** on the settings screen (or run `php craft vismaz/alerts/test`) after saving
to check that both channels arrive.

Nothing here is required. A site with no recipients and no webhook still records incidents, and
shows them on the Dashboard widget. If you add a recipient later, any incident that is still open
is sent at the next check. Nothing is checked until Vismaz is connected: an install that was never
connected is not an incident, and disconnecting is neither an incident nor a recovery.

## When alerts are checked

- **At the end of every push** — the queue job, the order panel's button, the console, the Orders
  index bulk action: failures and mismatches.
- **After every payment registration**: failed payments.
- **The moment Visma refuses the credentials**: authentication.
- **`php craft vismaz/sync/retry`** and **`php craft vismaz/sync/payments`**, after their retries,
  and **`php craft vismaz/alerts/check`** on its own: everything.

A stall is the absence of work, so only the last of these can notice one. Run the check from cron:

```sh
*/15 * * * * cd /path/to/site && php craft vismaz/alerts/check >> /dev/null 2>&1
```

The stall check looks at orders placed in the last week, and never at orders from before Vismaz
was installed — a shop's history is what `vismaz/sync/orders --from=…` is for, not news. With
**Send automatically when an order completes** off, an order without an invoice is your choice,
not a stall; only stuck sends and unrun payment jobs count then. Voucher mode posts a period from
cron, so orders are not counted there either.

## What an alert says

A plain-text email: the site, the incident, what was seen — the order, the Visma invoice number,
Visma's own error — what to do about it, and links straight to the right screen: **Vismaz →
Connection** for authentication, the Documents screen filtered to failed or mismatched documents,
the error log for payments, and Craft's queue manager for a stall. The Slack message, Teams card
and JSON event carry the same.

What was seen is redacted before it leaves the site: the client secret and the stored access and
refresh tokens are removed by value, anything shaped like a credential (`Bearer …`,
`password=…`, `"client_secret": …`) by pattern, markup is stripped and the line is capped at 500
characters. Alerts never include a document body, a customer or an address.

## The webhook

Slack and Teams incoming webhooks work as they are. The `json` format posts:

```json
{
  "event": "vismaz.alert.opened",
  "incident": "payments",
  "site": "My Store",
  "title": "Payments not registered in Visma",
  "detail": "1 payments could not be registered in Visma in the last 60 minutes; …",
  "url": "https://example.com/admin/vismaz/log?level=error",
  "syncUrl": "https://example.com/admin/vismaz/documents",
  "at": "2026-10-09T08:15:00+00:00"
}
```

`event` is `vismaz.alert.recovered` when it clears. `incident` is one of `failures`,
`mismatched`, `payments`, `auth` and `stalled`.

The URL is checked every time it is used, not only when it is saved. It must be `http` or
`https` with no username or password in it, every address the host resolves to must be public —
not private, loopback, link-local (the cloud metadata service) or carrier-grade NAT — and the
request is pinned to those addresses so DNS cannot be switched between the check and the send.
Redirects are never followed. For a self-hosted Mattermost on your own network, set
`allowPrivateAlertWebhookHosts` in `config/vismaz.php`. The scheme and redirect rules still apply.

If every channel fails, the alert is not marked sent, and the next check tries again.

## The Dashboard widget

**Dashboard → New widget → Visma health** shows the connected company, how many documents are
sent, failed, mismatched and pending, payments not registered (and how many are waiting for their
invoice), when the last document went out, and any open incident. Hover an incident to see what
was seen. It reads the same latch rows the alerts come from, so the widget and your inbox cannot
disagree. Only people with *View Visma documents* can add it.

## Changing or suppressing an alert

```php
use justinholtweb\vismaz\events\AlertEvent;
use justinholtweb\vismaz\services\Alerts;
use yii\base\Event;

Event::on(Alerts::class, Alerts::EVENT_BEFORE_NOTIFY, function(AlertEvent $e) {
    // $e->incident, $e->recovered, $e->detail, $e->subject, $e->body, $e->payload
    $e->subject = '[Bokföring] ' . $e->subject;

    // Swallow it. The latch still counts it as sent.
    if ($e->incident === Alerts::INCIDENT_MISMATCHED) {
        $e->isValid = false;
    }
});
```
