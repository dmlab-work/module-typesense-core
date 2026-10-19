# Changelog

## 0.2.0

- Request deadline, timeout retried once per node, 30 s node-health memory, jittered backoff, per-node warnings.
- Write failover when a request provably never left; tri-state `replaySafe`; idempotent imports fail over.
- Unencodable documents are per-document import failures.
- `HealthChecker::verifyConnection()` + `ConnectionStatus`; the key is checked on every healthy node.
- `TransportInterface::send()` takes a float timeout; an attempt never outlives the call deadline.
- `ping` works without an API key and flags a rejected key.
- `SynonymSetManager` (Typesense ≥ 30 synonym sets).
- `CollectionManager::getList()`, `dmlab:typesense:collections:prune`, orphan hint in `alias:status`.
- `ReconcilePolicy` validates its input. HEAD requests no longer hang. API key marked `#[\SensitiveParameter]`.
- Packaging: dropped `magento/module-config`, bounded constraints, OSL headers, integration tests + CI job.

## 0.1.1

- Version aligned with the rest of the Typesense suite.

## 0.1.0

- First release under the `dmlab` vendor and `DmLab` namespace.
