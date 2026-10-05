<p align="center"><img src="https://cdn.justpush.io/core/app%20icon_nobackground.svg" width="120" height="auto"></p>

# JustPush for Magento 2

Get a push notification on your phone when something happens in your Magento shop: a new
order, a paid invoice, a refund, a new customer or an admin login.

This module sends a small JSON message to [JustPush Studio](https://studio.justpush.io) for each
event. A Magento recipe in Studio turns that message into a push notification.

- Works on Magento Open Source and Adobe Commerce **2.4.6 and later**, with the PHP versions
  those releases support (8.1 to 8.4).
- Sends in the background through Magento's MySQL message queue, so a slow or unreachable
  JustPush never slows down or breaks checkout. RabbitMQ isn't needed.
- Sends only the fields listed under [Payloads](#payloads). No addresses, phone numbers or
  order lines.

## Install

With Composer (recommended):

```bash
composer require justpush/magento-module-notify
bin/magento module:enable JustPush_Notify
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode only
bin/magento cache:flush
```

Without Composer, copy this repository to `app/code/JustPush/Notify/` and run the same
`bin/magento` commands.

## Set up

1. In JustPush Studio, install a Magento recipe (for example **Magento: order placed**) and
   copy its webhook URL.
2. In the Magento admin, open **Stores → Configuration → Services → JustPush**.
3. Set **Enabled** to **Yes**, paste the URL into the matching field, e.g. **Order placed**,
   and click **Save Config**.
4. Click **Send test**. Each recipe answers with a quiet "🔔 Magento connected".

Each event has its own URL field; leave a field empty to turn that event off. All settings can
be changed per website (switch the **Scope** at the top left), except **Admin login**, which
only exists at Default Config because admin logins don't belong to a website.

### Cron and the message queue

By default the module only puts a message on the `justpush.notify` queue. Magento's
`consumers_runner` cron job starts the consumer that sends it, so all you need is working
Magento cron, which Magento needs anyway. Messages usually go out within a minute.

To send by hand, or to check that the queue works:

```bash
bin/magento queue:consumers:start justpush.notify --max-messages=100
```

If your shop has no working cron, set **Send immediately (no cron)** to **Yes**. Messages are
then sent during the request itself with a 2-second limit, so a slow JustPush can add up to
2 seconds to that request. Failures are still caught and logged.

A message that can't be delivered is logged and dropped, not retried.

### Logging

Failures are written to `var/log/justpush.log`. The module never throws errors into Magento.

## Events

| Event | Sent when | Magento event |
| --- | --- | --- |
| `order.placed` | An order is placed from the storefront, the admin, REST or GraphQL. Multishipping sends one message per order. | `sales_model_service_quote_submit_success`, plus `checkout_submit_all_after` for multishipping |
| `invoice.paid` | The payment for an invoice is captured. For bank transfer and other offline methods this happens when you create the invoice. | `sales_order_invoice_pay`, sent once the invoice is saved |
| `order.refunded` | A credit memo is created (once per credit memo). | `sales_order_creditmemo_refund`, sent once the credit memo is saved |
| `customer.registered` | A new customer account is created from the storefront, the admin or the API. | `customer_save_after_data_object` (new customers only) |
| `admin.login` | Someone logs in to the admin. | `backend_auth_user_login_success` |
| `admin.login_failed` | An admin login fails. At most one message per 15 minutes per username; `attempts` counts the failures since the previous message. | `backend_auth_user_login_failed` |
| `test.ping` | You click **Send test**. | none |

## Payloads

Every message is a JSON `POST` with `Content-Type: application/json` and the user agent
`JustPush-Magento/1.0.0`. It always contains `event`, `version` (currently `1`), `sent_at`
(ISO 8601, UTC) and `store`. Money is a decimal string such as `"49.00"` in the order's
currency, with `currency` as an ISO code. A field that isn't known is `null`.

Real examples of every event are in [`docs/payloads/`](docs/payloads/). They were captured
from a Magento 2.4.7-p9 test shop running at `http://localhost:8081/`.

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
        "shipping_method": "Flat Rate - Fixed",
        "status": "pending",
        "source": "frontend",
        "admin_url": "https://shop.example.com/admin/sales/order/view/order_id/123/"
    }
}
```

- `source` is `frontend`, `admin`, `api` (REST, SOAP or GraphQL), or `null` when Magento
  can't tell, e.g. for an order created by cron. Magento's standard (Luma) checkout places
  orders through the REST API from the browser, so a REST call that the browser marks as
  AJAX (`X-Requested-With: XMLHttpRequest`) counts as `frontend`. Headless storefronts that
  use GraphQL, such as PWA Studio, show up as `api`.
- `payment_method` and `shipping_method` are the titles the customer saw, not the codes.
- `invoice.paid` has `invoice: { increment_id, order_increment_id, grand_total, currency, customer_name, admin_url }`
  instead of `order`.
- `order.refunded` has `creditmemo: { increment_id, order_increment_id, grand_total, order_grand_total, currency, customer_name, admin_url }`.
- `customer.registered` has `customer: { name, email, group, source, admin_url }`.
- `admin.login` has `admin: { username, name, ip, admin_url }`; `admin.login_failed` has
  `admin: { username, ip, attempts, admin_url }`.
- `test.ping` has `store` and `message: "Test from Magento"`.

### Admin links

`admin_url` points at the order, invoice, credit memo, customer or admin user. With Magento's
**Add Secret Key to URLs** setting on (the default, under Stores → Configuration → Advanced →
Admin → Security), Magento doesn't accept links without a secret key and sends you to the
dashboard instead. That's a Magento security feature, so the module doesn't work around it.
Custom admin paths such as `/admin_xyz/` are respected.

### IP addresses

`ip` in the admin events is PHP's `REMOTE_ADDR`. If your shop sits behind a proxy, load
balancer or CDN such as Cloudflare or Varnish, that's the proxy's address, not the visitor's.

## Privacy (GDPR)

The order, invoice, refund and customer messages contain the customer's name and email
address; admin messages contain the admin's username, name and IP address. As the shop owner
you're the data controller for sending these to JustPush. Leave an event's URL empty if you
don't want to send it. The module sends nothing else: no addresses, phone numbers, order lines
or payment details.

## Development

```bash
vendor/bin/phpcs --standard=Magento2 --extensions=php,phtml vendor/justpush/magento-module-notify
vendor/bin/phpstan analyse -c vendor/justpush/magento-module-notify/phpstan.neon.dist
```

## Uninstall

```bash
bin/magento module:disable JustPush_Notify
composer remove justpush/magento-module-notify
bin/magento setup:upgrade
```

## License

MIT, see [LICENSE](LICENSE).
