# Handoff: JustPush notifier module for Magento 2

**Status (2026-09-30):** v1 built and tested on Magento 2.4.7-p9 / PHP 8.3 (Docker test shop in
`~/Sites/magento-dev`, see "Test shop" at the bottom). Real payloads are in `docs/payloads/`.
Not a git repo yet, not on Packagist yet. Next step: the Studio-Recipes side.
Written 2026-09-30.

**Goal:** a small Magento 2 module that sends a JSON POST to JustPush Studio when something
happens in a shop: an order, a refund, a new customer or an admin login. The matching Studio
recipes live in the **Studio-Recipes** repo (`../../../Studio-Recipes/`, company folder
`magento/`, still to be built). They turn each POST into a push notification. This module
decides the payload, so **the payload contract below is the interface between the two
repos**. Change it only on purpose.

## Why a module (already decided)

Magento Open Source, which most shops run, has no outgoing webhooks at all. Adobe Commerce
webhooks are synchronous and built to validate or change data. Adobe I/O Events only works
on Adobe Commerce and needs a challenge handshake. Third-party webhook extensions each send
their own payload. A small module of our own works on Open Source and Adobe Commerce alike,
the same way the WordPress recipes ship a must-use plugin.

## Decisions (defaults; change them only with a reason)

| Question | Decision | Why |
| --- | --- | --- |
| How it's distributed | A Composer package: `composer require justpush/magento-module-notify` then `bin/magento setup:upgrade`. Name it `justpush/magento-module-notify` (Magento's `vendor/module-name` convention) with module `JustPush_Notify`. Also support copying it to `app/code/JustPush/Notify/`. | Too many files to paste into a recipe's INSTALL.md. |
| Sending | **Async.** Observers only publish a message to a MySQL-backed message queue (`db` connection, so no RabbitMQ needed). A consumer sends the HTTP request. Magento's `consumers_runner` cron starts consumers by default, so the only requirement is working cron, which Magento needs anyway. | JustPush being slow or down must never slow down or break checkout. |
| Queue fallback | A config switch "Send immediately (no cron)" that POSTs inline with a **2 s total timeout** inside `try/catch (\Throwable)`. | For shops with broken cron. Off by default. |
| Configuration | **Stores → Configuration → Services → JustPush**: an Enabled switch, one "Webhook URL" field per event (empty means off), website scope. Add a **Send test** button that POSTs `test.ping` to every filled-in URL. | Each Studio recipe is its own integration with its own URL. |
| Signing | None in v1. Studio can't verify custom signatures yet, so the recipes use `verify: "none"`. | Keep the scope small. Add an `X-JustPush-Signature` HMAC later if Studio gains a generic verifier. |
| Logging | Failures go to `var/log/justpush.log` (own logger via a virtual type). Never throw into Magento. | |
| Supported versions | Magento 2.4.6+ with PHP 8.1–8.3. Say so in `composer.json`. | 2.4.4/2.4.5 are out of support. |

## Events (verify each one on a real install before relying on it)

| Payload `event` | Studio recipe | Candidate Magento event | Notes |
| --- | --- | --- | --- |
| `order.placed` | `magento/order-placed` | `sales_model_service_quote_submit_success` | Should cover frontend checkout, REST, GraphQL and admin-created orders, since all four go through `QuoteManagement::submit`. **Verify** this for each channel. `checkout_submit_all_after` misses REST/GraphQL. `sales_order_place_after` fires before commit. Multishipping creates several orders, so send one message per order. |
| `invoice.paid` | `magento/invoice-paid` (optional) | `sales_order_invoice_pay` | Means the money was actually captured. More useful than `order.placed` for bank transfer or other offline methods. |
| `order.refunded` | `magento/order-refunded` | `sales_order_creditmemo_refund` | Fires once per credit memo. If you fall back to `*_save_after`, only send for new objects. |
| `customer.registered` | `magento/customer-registered` | `customer_register_success` + `customer_save_after_data_object` (new customers only) | The first is frontend only. The second catches admin and API customers. Don't send twice for one customer. |
| `admin.login` / `admin.login_failed` | `magento/admin-login` | `backend_auth_user_login_success` / `backend_auth_user_login_failed` | Throttle failed logins: at most one message per 15 min per username, carrying an `attempts` count (use the cache for the counter). |
| `test.ping` | all recipes | Send test button | Recipes answer it with a quiet "🔔 Magento connected". |
| (phase 2) `stock.low` | `magento/stock-alert` | none | No clean core event with MSI. Probably a daily cron comparing salable qty to "Notify for Quantity Below". Leave it out of v1. |

## Payload contract (v1)

Rules: JSON, flat and stable. Money is a **decimal string** in the order's currency, e.g.
`"49.00"`, with `currency` as an ISO code. Timestamps are ISO 8601 in UTC. Leave a field out,
or set it to `null`, when unknown; never send an empty object. Every body has `event`,
`version: 1`, `sent_at` and `store`.

```json
{
    "event": "order.placed",
    "version": 1,
    "sent_at": "2026-09-30T12:34:56Z",
    "store": {
        "website": "Main Website",
        "store_view": "Default Store View",
        "url": "https://shop.example.com/"
    },
    "order": {
        "increment_id": "000000123",
        "grand_total": "49.00",
        "currency": "EUR",
        "customer_name": "Jane Doe",
        "customer_email": "jane@example.com",
        "is_guest": false,
        "item_count": 3,
        "payment_method": "iDEAL",
        "shipping_method": "Flat Rate",
        "status": "pending",
        "source": "frontend",
        "admin_url": "https://shop.example.com/admin/sales/order/view/order_id/123/"
    }
}
```

- **`source`:** one of `frontend`, `admin`, `api`, or `null` if it can't be told apart.
- **`payment_method`** and **`shipping_method`** are the titles the customer saw, not the codes.
- **`invoice.paid`:** replace `order` with `invoice: { increment_id, order_increment_id, grand_total, currency, customer_name, admin_url }`.
- **`order.refunded`:** replace it with `creditmemo: { increment_id, order_increment_id, grand_total, order_grand_total, currency, customer_name, admin_url }`.
- **`customer.registered`:** replace it with `customer: { name, email, group, source, admin_url }`.
- **`admin.login` / `admin.login_failed`:** replace it with `admin: { username, name, ip, attempts, admin_url }`. `name` is only for a successful login. `attempts` is only for failed logins. `ip` is `REMOTE_ADDR`, so behind a proxy or CDN it's the proxy's address; say so in the README.
- **`test.ping`:** `store` plus `message: "Test from Magento"`.

Put a real captured payload for every event in `docs/payloads/<event>.json` in this repo. The
recipes copy those into their `sample.json`.

## Gotchas

- **Admin links and secret keys:** with **Add Secret Key to URLs** on (the default), a link
  without the key redirects to the dashboard. Build `admin_url` with
  `Magento\Backend\Model\UrlInterface` using `_nosecret`. That respects the custom admin path
  (`/admin_xyz/`), but it still lands on the dashboard when keys are on. Document this; don't
  try to work around it.
- **Multi-store:** read config at website scope from the order's store, and always fill `store`.
- **The observer must stay tiny:** collect scalars and publish. Don't load extra collections, so
  checkout stays fast.
- **Queue in a transaction:** if the observer runs inside the order's DB transaction, the
  queued message rolls back with it. That's the behaviour we want.
- **Personal data:** only the fields above. No addresses, phone numbers or line items.
  Mention GDPR in the README (the shop owner is the controller).
- Don't store the webhook URLs encrypted. They aren't secrets in Magento's sense, and the plain
  config makes debugging easier.

## Suggested layout

```
registration.php
composer.json
etc/module.xml
etc/config.xml                 defaults (all off)
etc/adminhtml/system.xml       Services > JustPush section
etc/acl.xml
etc/events.xml                 global area (frontend, admin, webapi_rest, graphql)
etc/adminhtml/events.xml       admin login events
etc/communication.xml, queue_topology.xml, queue_publisher.xml, queue_consumer.xml
etc/di.xml                     logger virtual type
Observer/*.php                 one per event
Model/PayloadBuilder.php       builds the arrays above
Model/Publisher.php            queue or inline, based on config
Model/Consumer.php             POSTs with Magento\Framework\HTTP\Client\Curl, 5 s timeout
Block/Adminhtml/System/Config/TestButton.php + controller for Send test
docs/payloads/*.json
README.md, LICENSE (MIT), CHANGELOG.md
```

## Testing plan

1. Run Magento 2.4.7 with PHP 8.3 locally via `markshust/docker-magento`. Docker is installed but
   the daemon may need starting. Homebrew PHP on this machine is broken (missing `icu4c@77`), so
   lint and run `bin/magento` inside Docker.
2. Install the module from this path with a Composer `path` repository.
3. Point every URL at webhook.site and capture the payloads into `docs/payloads/`.
4. Trigger each event, including every channel for orders: frontend, REST
   (`/V1/guest-carts/.../payment-information`), GraphQL `placeOrder`, and the admin.
   Also test a credit memo, a frontend and an admin customer, and good and bad admin logins
   (including 10 bad ones in a row to check the throttle).
5. Point a URL at an endpoint that sleeps 30 s and confirm checkout time doesn't change, in
   both queue mode and inline mode.
6. Run `bin/magento setup:di:compile` and PHPStan level 6 or higher, with
   `magento/magento-coding-standard` for PHPCS.

## Then, in Studio-Recipes

Build the `magento/` recipes by following `Studio-Recipes/_handoffs/recipe-guide.md`.
`Studio-Recipes/_handoffs/magento-module.md` has the recipe-side notes. INSTALL.md becomes
"install the package, paste `{{webhook_url}}` into the matching field". Tick Magento in
`_handoffs/recipe-backlog.md` once they're merged.

## What testing showed (2026-09-30)

- `order.placed` checked for every channel: storefront checkout in Chrome (`source: frontend`),
  REST guest cart (`api`), GraphQL `placeOrder` (`api`), and the admin order screen (`admin`).
  Luma's checkout places orders through REST from the browser, so `source` uses the
  `X-Requested-With: XMLHttpRequest` header to tell storefront REST calls from headless ones.
- Multishipping doesn't go through `QuoteManagement::submit`. It's covered by
  `checkout_submit_all_after` with an `orders` list (the single-`order` form is ignored). This was
  checked by dispatching that event with real orders, not by a full multishipping checkout: the
  storefront login in the test browser kept failing.
- `sales_order_invoice_pay` and `sales_order_creditmemo_refund` fire before the entity is saved
  (no increment ID yet), so the observers flag the entity and publish from `*_save_after`.
  Checked through both REST and the admin UI.
- `customer_save_after_data_object` alone covers storefront, admin and REST sign-ups, once each.
- Throttle: 10 bad logins in a row → one message (`attempts: 1`). The next failure after
  15 minutes reports everything since, including itself.
- A webhook that sleeps 30 s: queue mode leaves checkout time unchanged (~1 s); inline mode adds
  exactly 2 s and logs the timeout. `consumers_runner` cron picked up and drained the queue.
- `setup:di:compile`, PHPStan level 6 (`phpstan.neon.dist`) and PHPCS `Magento2` all pass, apart
  from line-length warnings on the comment text in `etc/adminhtml/system.xml`.
- `composer.json` allows PHP 8.4 too, because Magento 2.4.8 runs on it.

## Test shop

`~/Sites/magento-dev` is a trimmed `markshust/docker-magento` setup (original compose files kept
as `*.orig`): Magento 2.4.7-p9 from the Mage-OS mirror, at **http://localhost:8081/** (Valet owns
80/443), admin `john.smith` / `password123`, 2FA off. This repo is bind-mounted at
`/var/www/html/packages/justpush-magento` and installed through a Composer path repository.
Start it with `docker compose -f compose.yaml -f compose.dev.yaml up -d` in that folder.

## Open questions

- The Packagist and GitHub location for the package, and whether it goes on the Adobe
  Marketplace later (that needs their review and coding-standard pass).
- Whether `order.placed` should skip orders created in `pending_payment` until they're paid,
  e.g. iDEAL orders that are abandoned. Maybe add a config option "Only notify for paid
  orders" that uses `invoice.paid` instead.
