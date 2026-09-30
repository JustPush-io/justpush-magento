# Changelog

## 1.0.0 (unreleased)

- First version: `order.placed`, `invoice.paid`, `order.refunded`, `customer.registered`,
  `admin.login`, `admin.login_failed` and `test.ping`, payload contract v1.
- Sending through the MySQL message queue (`justpush.notify`), with an optional inline mode.
- Stores > Configuration > Services > JustPush with a Send test button.
