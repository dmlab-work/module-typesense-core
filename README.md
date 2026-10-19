# DmLab_TypesenseCore

[![CI](https://github.com/dmlab-work/module-typesense-core/actions/workflows/ci.yml/badge.svg)](https://github.com/dmlab-work/module-typesense-core/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/dmlab-work/module-typesense-core/graph/badge.svg)](https://codecov.io/gh/dmlab-work/module-typesense-core) [![Packagist Version](https://img.shields.io/packagist/v/dmlab/module-typesense-core)](https://packagist.org/packages/dmlab/module-typesense-core) [![Downloads](https://img.shields.io/packagist/dt/dmlab/module-typesense-core)](https://packagist.org/packages/dmlab/module-typesense-core) [![PHP](https://img.shields.io/packagist/dependency-v/dmlab/module-typesense-core/php)](https://packagist.org/packages/dmlab/module-typesense-core) [![License](https://img.shields.io/packagist/l/dmlab/module-typesense-core)](LICENSE) ![Magento](https://img.shields.io/badge/Magento-2.4-orange)

The Typesense access layer for Magento 2 (L0 of the Typesense suite): HTTP client, collection
lifecycle, aliases, document writes, synonym sets, schema reconciler and a diagnostic CLI. Pure transport — it
knows nothing about the catalog and reads no configuration of its own. Installed transitively by
`typesense-indexer`; you rarely require it directly.

## What it provides

- **HTTP client** — node failover, typed errors; `magento/framework` only, no SDK.
- **Health check** — cached and non-throwing, over `GET /health`; `verifyConnection()` returns an
  uncached `ConnectionStatus` (`ok` / `unreachable` / `unauthorized`) and checks the key against
  `GET /debug`, since `/health` accepts any key. Pass a `ConnectionSettingsInterface` to check
  settings other than the injected ones (e.g. unsaved admin input).
- **Collections** — create / get / exists / drop / PATCH / `getList()`, plus zero-downtime alias `swap()` (per-alias lock; refuses a missing target).
- **Documents** — upsert, delete, delete-by-filter, chunked import; an unencodable document is a
  per-document failure, not an aborted batch.
- **Synonyms** — `SynonymSetManager` upsert / delete / exists over Typesense synonym sets.
- **Schema reconciler** — decides in-place PATCH vs rebuild; holds no documents itself.
- **CLI**:
  - `dmlab:typesense:ping` — per node `OK` / `DOWN` / `UNHEALTHY` / `KEY REJECTED`; without a key it
    still probes `/health` and exits non-zero.
  - `dmlab:typesense:alias:status` — aliases, their collections and an orphan hint.
  - `dmlab:typesense:collections:prune [--dry-run] [--min-age=86400]` — drops versioned
    `<alias>_<ts>_<hex>` collections no alias points to. The min age protects a rebuild still waiting
    for its swap; collections of a removed alias are never touched. Each candidate is rechecked against
    the aliases just before its drop, under the same per-alias lock `AliasManager::swap()` takes.

## Resilience

- **Deadline** per call: `deadline` option, default `timeout × 2`; each attempt's timeout is capped by
  what remains.
- **Timeouts** are retried at most once per node.
- **Node memory**: a failed node is cached as down for 30 s and meanwhile tried once per call, only
  after every unmarked node has failed.
- **Backoff**: jittered sleep between rounds (100 ms base, 1 s cap).
- **Logging**: one warning per node failure (URL + status/error, never headers).
- **Writes** fail over only when the request provably never left (curl errno 6/7 or a connect-phase
  timeout); otherwise a `POST`/`PATCH` is sent once, as a replay could 409 a create that succeeded.
  `replaySafe` is tri-state: `null` = by method (`GET`/`HEAD`/`PUT`/`DELETE` retry), `true` = opt in,
  `false` = opt out. Upserts and `upsert`/`update`/`emplace` imports opt in when every document carries
  a string `id` (otherwise the engine generates one per delivery, so a replay duplicates).

## Config seams

Core reads no config; everything is injected by `typesense-indexer`:

- **`Api\ConnectionSettingsInterface`** — nodes (each with its own `http`/`https`), decrypted API
  key, timeouts, retry, health-cache TTL. Core binds a `NotConfigured` default that throws until the
  indexer supplies the real reader.
- **`Model\Schema\ReconcilePolicy`** — `Reconciler::reconcile()` receives its rebuild threshold and
  decision override as an argument, not from config.

## Extending the schema

`FieldSpec` models `name`/`type`/`facet`/`index`/`sort`/`optional`; any other Typesense field
property (`embed`, `num_dim`, `hnsw_params`, …) passes through its `extra` map verbatim, so a
consumer can declare engine features without forking core. There is deliberately no per-field weight
— Typesense weights are a query-time concern.

## Requirements

Magento 2.4.x · PHP 8.3–8.5 · `ext-curl` · `magento/framework ^103.0.9` · Typesense ≥ 30.0
(synonym sets API), verified on 30.2.

## Integration tests

`Test/Integration/` runs against a real Typesense and is skipped unless `TYPESENSE_TEST_URL` and
`TYPESENSE_TEST_API_KEY` are set:

```bash
docker exec -w /var/www/html/m2-modules/module-typesense-core -e MAGENTO_ROOT=/var/www/html \
  -e TYPESENSE_TEST_URL=http://typesense:8108 -e TYPESENSE_TEST_API_KEY=<key> \
  magento-app php /var/www/html/vendor/bin/phpunit -c Test/Integration/phpunit.xml
```

## License

OSL-3.0 © DMLab.
