# DmLab_TypesenseCore

The Typesense access layer for Magento 2 (L0 of the Typesense suite): HTTP client, collection
lifecycle, aliases, document writes, schema reconciler and a diagnostic CLI. Pure transport — it
knows nothing about the catalog and reads no Magento config. Installed transitively by
`typesense-indexer`; you rarely require it directly.

## What it provides

- **HTTP client** — node failover, bounded retry, typed errors; `magento/framework` only, no SDK.
- **Health check** — cached and non-throwing, over `GET /health`.
- **Collections** — create / get / exists / drop / PATCH, plus zero-downtime alias `swap()`.
- **Documents** — upsert, delete, delete-by-filter, chunked import.
- **Schema reconciler** — decides in-place PATCH vs rebuild; holds no documents itself.
- **CLI** — `dmlab:typesense:ping`, `dmlab:typesense:alias:status`.

Retry/failover apply to `GET`/`PUT`/`DELETE` only; `POST`/`PATCH` are delivered once — a replay
could 409 a create that in fact succeeded.

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

Magento 2.4.x · PHP 8.3–8.5 · `magento/framework >=103.0` · Typesense 30.x (verified on 30.2).

## License

OSL-3.0 © DMLab.
