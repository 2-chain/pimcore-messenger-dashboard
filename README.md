# Pimcore Messenger Dashboard

[![Latest Stable Version](https://img.shields.io/packagist/v/2chain/pimcore-messenger-dashboard.svg)](https://packagist.org/packages/2chain/pimcore-messenger-dashboard)
[![Total Downloads](https://img.shields.io/packagist/dt/2chain/pimcore-messenger-dashboard.svg)](https://packagist.org/packages/2chain/pimcore-messenger-dashboard)
[![License](https://img.shields.io/packagist/l/2chain/pimcore-messenger-dashboard.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/packagist/php-v/2chain/pimcore-messenger-dashboard.svg)](composer.json)

A Pimcore admin dashboard for Symfony Messenger queues. View pending and failed
messages across every configured transport, inspect message bodies, delete
single or in bulk, re-queue failed messages, and watch handler throughput over
time — all from a permission-gated tab inside Pimcore's classic admin UI.

|||
|-|--|
| Package | `2chain/pimcore-messenger-dashboard` |
| PHP namespace | `TwoChain\PimcoreMessengerDashboardBundle\` |
| Bundle class | `TwoChain\PimcoreMessengerDashboardBundle\PimcoreMessengerDashboardBundle` |
| Pimcore | `^11.0 \|\| ^12.0` |
| Symfony Messenger | `^6.4 \|\| ^7.0` |
| License | [GPL-3.0-or-later](LICENSE.md) |

## Features

- **Transport sidebar** lists every transport configured under
  `framework.messenger.transports`, with a live pending count and capability
  icons. Auto-refreshes every 10 seconds (configurable).
- **Message grid** per transport with checkbox selection, inline body preview,
  hover-tooltip showing the full body, and an info icon that opens a modal
  with all fields and a JSON-pretty-printed body.
- **Bulk operations**: delete selected, delete all in transport, purge.
- **Per-transport search**: substring search across message body and class with
  SQL `LIKE`-style wildcards (`%`, `_`). See [Searching messages](#searching-messages).
- **Failed transport view**: same grid plus per-row and bulk **Requeue**.
- **Statistics**: handled vs failed counts per transport over the last 1h /
  12h / 24h, sourced from an opt-out audit table written by a Messenger event
  subscriber.
- **Permissions**: two installable user permissions (view + edit). Admins
  bypass.
- **Transport-agnostic**: doesn't assume a specific table name, queue name,
  or transport type. Reads everything through Symfony's transport API.

## Supported transports

The dashboard wraps every Symfony Messenger transport through a small adapter
layer that advertises a capability set. The UI hides or disables controls the
underlying transport can't support, so the same dashboard works against any
mix of transport types.

**Listing is the gate for every interactive feature.** If a transport can't
enumerate its messages (`canList = false`), the dashboard treats it as fully
read-only: the sidebar shows `(unsupported)` instead of a count, the detail
panel renders only the "this transport does not support listing messages"
notice (no stats bar, no action buttons), and the Statistics table groups
the transport at the bottom with a `not supported` cell. The underlying
transport API may technically expose delete or purge primitives, but
operating on messages you can't see is unsafe and confusing — the UI hides
those controls deliberately.

The audit subsystem (`messenger_dashboard_stats`) still records handled /
failed events for unsupported transports — those rows just don't surface
in the per-transport stats view.

| DSN scheme | Symfony package | Listed in dashboard | Count | List / inspect | Delete single | Bulk delete | Purge | Requeue (failed) |
|---|---|---|---|---|---|---|---|---|
| `doctrine://` | symfony/doctrine-messenger | ✓ supported | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `redis://` / `rediss://` | symfony/redis-messenger | ✓ supported | ✓ | ✓ via `XRANGE` <sup>[1]</sup> | ✓ via `XDEL` | — | ✓ via `XTRIM` | ✓ |
| `in-memory://` | core | ✓ supported | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Any other receiver implementing `ListableReceiverInterface` | — | ✓ supported | ✓ | ✓ | ✓ | — | — | — |
| `amqp://` / `amqps://` | symfony/amqp-messenger | ✗ unsupported <sup>[4]</sup> | ✓ | — | — | — | — | — |
| `beanstalkd://` | symfony/beanstalkd-messenger | ✗ unsupported <sup>[2]</sup> | ✓ | — | — | — | — | — |
| `sqs://` / `https://` | symfony/amazon-sqs-messenger | ✗ unsupported <sup>[3]</sup> | ✓ | — | — | — | — | — |
| `sync://` | core | — | n/a (always 0) | — | — | — | — | — |
| Any other receiver implementing only `MessageCountAwareInterface` | — | ✗ unsupported | ✓ | — | — | — | — | — |
| Anything else | — | ✗ unsupported | 0 | — | — | — | — | — |

<sup>[1]</sup> Symfony's Redis bridge doesn't implement `ListableReceiverInterface`, but Redis Streams natively support read-only enumeration via the `XRANGE` command. The adapter reaches the underlying Redis client via reflection and calls `XRANGE` directly — read-only, no consumer-state mutation, no worker races. Caveat: `XRANGE` returns every entry currently in the stream; Symfony's transport `XACK`s handled messages but doesn't immediately `XDEL` them, so the listed entries may include already-handled messages. The transport's own `getMessageCount()` (shown in the sidebar) remains authoritative for "currently pending".

<sup>[2]</sup> Beanstalkd's wire protocol has no enumeration command. `peek-ready` returns only the next single job in each state; full listing would require `reserve → release` loops that briefly hide jobs from workers (racy). Count is available; per-job operations are not.

<sup>[3]</sup> Amazon SQS has no native peek-without-consume. `ReceiveMessage` with `VisibilityTimeout=0` is technically possible but produces inconsistent listings (other workers receive the same messages between refreshes). Count is available via `ApproximateNumberOfMessages`; per-message operations are not.

<sup>[4]</sup> AMQP brokers don't support non-destructive message enumeration in the wire protocol. RabbitMQ's HTTP Management API does (separate plugin + credentials), but the dashboard doesn't currently integrate with it.

Detection is **FQCN-based**. The bundle has zero hard dependencies on the
optional bridges — `symfony/redis-messenger` etc. don't need to be in your
project for the bundle to compose / boot. When a bridge isn't installed, its
FQCN simply never matches.

Transports whose backend is unreachable (e.g. AMQP broker offline, SQS
network error) degrade to `count: unavailable` in the sidebar — they don't
crash the dashboard.

**Not supported as DSN schemes:** `failover://` and `roundrobin://` are
sometimes referenced in older docs but are not actually Symfony Messenger
transport schemes (Symfony composes multiple transports via the routing
config, not via a wrapping DSN). The dashboard treats each underlying
transport as its own entry, which is the correct mental model.

**Third-party transports** (e.g. Enqueue's Kafka or Google Pub/Sub bridges via
`sroze/messenger-enqueue-transport`) work via the generic fallback: full
features if the receiver implements `ListableReceiverInterface`, count-only
if it implements just `MessageCountAwareInterface`.

## Requirements

- PHP 8.3+
- Pimcore 11.x or 12.x with the Classic Admin UI bundle enabled
- Doctrine ORM 2.17+ or 3.x (Pimcore 12 ships ORM 3)
- MariaDB 10.11+ or MySQL 8.0+ if you use the audit subsystem

The bundle auto-configures Doctrine ORM mapping for its own audit entity, so
you don't need ORM enabled in your project for anything else.

## Installation

```bash
composer require 2chain/pimcore-messenger-dashboard
```

Pimcore's bundle auto-loader picks up the `pimcore-bundle` composer type. If
your project has bundle auto-loading disabled, add it manually to
`config/bundles.php`:

```php
return [
    // ... your existing bundles
    \PimcoreMessengerDashboardBundle::class => ['all' => true],
];
```

Run the install command (registers the permissions, applies the audit table
migration):

```bash
bin/console pimcore:bundle:install PimcoreMessengerDashboardBundle
bin/console doctrine:migrations:migrate --no-interaction
bin/console assets:install public --symlink --relative
```

Grant access through **Settings → Users & Roles**. Two new checkboxes appear
under the "Messenger Dashboard" group:

- `messenger_dashboard_view` — see the dashboard
- `messenger_dashboard_edit` — delete, requeue, purge (implies view)

Pimcore admins (`User::isAdmin() === true`) bypass both.

Reload the admin UI. **Tools → Messenger Dashboard** opens the tab.

## Configuration

Drop a `twochain_messenger_dashboard.yaml` into `config/packages/` to override
any default:

```yaml
twochain_messenger_dashboard:
    stats:
        enabled: true            # set false to skip the audit subscriber entirely
        retention_days: 30       # how long to keep rows in messenger_dashboard_stats
    failed_transport:
        auto_configure: true     # prepend a default `pimcore_failed` Doctrine transport
                                 # if framework.messenger.failure_transport isn't already set
    ui:
        polling_interval_ms: 10000   # sidebar refresh interval; min 1000
```

### `stats.enabled` (bool, default `true`)

When `true`, the bundle registers `MessengerAuditSubscriber` against Symfony's
worker events and writes one row per handled or finally-failed message to
`messenger_dashboard_stats`. This populates the **Statistics** panel and the
per-transport "Last processed" timestamp.

When `false`, the subscriber short-circuits in `getSubscribedEvents()` — zero
runtime cost. The Statistics panel hides itself with a hint.

### `stats.retention_days` (int ≥ 1, default `30`)

Rows older than this are deleted by
`bin/console twochain:messenger-dashboard:stats:prune`. The command takes an
optional `--retention-days=N` override and a `--dry-run` flag. Schedule it via
crontab (the bundle does **not** add a cron entry itself):

```cron
0 3 * * * /var/www/html/bin/console twochain:messenger-dashboard:stats:prune
```

### `failed_transport.auto_configure` (bool, default `true`)

If `true` and your `framework.messenger.failure_transport` is unset, the
bundle prepends a default failed transport:

```yaml
framework:
    messenger:
        failure_transport: pimcore_failed
        transports:
            pimcore_failed: 'doctrine://default?queue_name=pimcore_failed'
```

If your project already sets `failure_transport`, this is a no-op. Set
`auto_configure: false` to disable the prepend entirely.

### `ui.polling_interval_ms` (int ≥ 1000, default `10000`)

How often the sidebar re-queries the transports endpoint. Polling pauses
while the user is interacting with a grid (no flicker mid-selection).

## Searching messages

Every listable transport detail view (plus the **Failed** view) has a search
field above the grid. The query is matched against:

- the fully qualified message class (e.g. `App\Message\ImportProduct`),
- the body preview (the JSON-serialized public properties for non-`\Stringable`
  messages, or the `__toString()` value otherwise), and
- on messages from the failure transport, the exception class and exception
  message carried on `ErrorDetailsStamp` — so you can find a failed message by
  the text of its failure (`Connection refused`, `SQLSTATE[23000]…`, etc.).

The match uses SQL `LIKE` semantics: substring by default, with `%` and `_` as
wildcards. To match a literal `%` or `_`, prefix it with a backslash (`\%`,
`\_`). The search field debounces 300 ms; press Enter to apply immediately,
Esc or the clear-trigger to reset.

**Scope is per transport.** Search box on the per-transport detail view filters
that transport only; the box on the Failed view filters the failure store and
combines with the class filter.

**Backend behavior** depends on the underlying transport:

- **Doctrine**: parameterized `LIKE … ESCAPE '\\'` against the messenger table.
  Cheap; works at any backlog size.
- **In-memory and other listable transports**: same wildcards, applied in PHP
  via a regex. Caps at 5000 envelopes pulled per request — beyond that, search
  is best-effort.
- **AMQP, Beanstalkd, SQS**: not searchable (listing isn't supported on these
  brokers).
- **Redis**: the search field is shown for UI consistency but ignored; results
  come back unfiltered.

A query longer than 1024 characters is rejected with a `query_too_long`
error.

## CLI commands

| Command | Purpose |
|---|---|
| `twochain:messenger-dashboard:stats:prune [--retention-days=N] [--dry-run]` | Delete audit rows older than the retention window. |
| `twochain:messenger-dashboard:debug:transports` | List every transport with type, capability matrix, and live count. Useful for verifying configuration outside the UI. |

## Translations

Translations live in `Resources/translations/admin.<locale>.yaml` and are
auto-discovered by Symfony's translator. Pimcore's admin UI reads from the
same catalogue.

The bundle ships English (`en`) and German (`de`). To add a new locale, drop
a new YAML file next to the existing ones — no code changes required:

```yaml
# Resources/translations/admin.fr.yaml
messenger_dashboard: Tableau de bord Messenger
messenger_dashboard_transports: Transports
# ... etc
```

Operator overrides through **Settings → Translations → Admin Translations**
take precedence over the bundle's YAML defaults via Pimcore's translation
catalogue merge — bundle upgrades won't clobber custom translations.

## Uninstall

```bash
bin/console pimcore:bundle:uninstall PimcoreMessengerDashboardBundle
composer remove 2chain/pimcore-messenger-dashboard
```

The uninstall step removes the user permissions. The audit table
(`messenger_dashboard_stats`) is left in place — drop it manually with
`bin/console doctrine:migrations:migrate prev` (or directly via
`DROP TABLE messenger_dashboard_stats`) if you want it gone.

## License

This bundle is licensed under the **GNU General Public License v3.0 or later**.
See [LICENSE.md](LICENSE.md) for the full license text.
