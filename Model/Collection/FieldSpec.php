<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Collection;

/**
 * One field of a Typesense collection schema.
 *
 * The engine's field schema is far richer than the properties modelled here
 * (`embed`, `model_config`, `num_dim`, `vec_dist`, `hnsw_params`, `infix`, `stem`,
 * `range_index`, `drop`, …). Anything unmodelled travels in `$extra` and is carried
 * verbatim into the payload, so a consumer can declare engine properties this layer
 * does not know about without forking it.
 *
 * There is deliberately no `weight`: Typesense has no per-field weight. Search weights
 * are a query-time parameter (`query_by_weights`) owned by `typesense-search`.
 *
 * @api
 */
class FieldSpec
{
    /**
     * Properties this class models; everything else in a schema array is `extra`.
     */
    private const MODELLED = ['name', 'type', 'facet', 'index', 'sort', 'optional'];

    /**
     * A null flag is omitted from the payload, leaving the engine's default in place.
     *
     * @param string $name
     * @param string $type Typesense field type, e.g. `string`, `int64`, `string[]`, `auto`
     * @param bool|null $facet
     * @param bool|null $index
     * @param bool|null $sort
     * @param bool|null $optional
     * @param array<string,mixed> $extra unmodelled engine properties, passed through untouched
     */
    public function __construct(
        private readonly string $name,
        private readonly string $type,
        private readonly ?bool $facet = null,
        private readonly ?bool $index = null,
        private readonly ?bool $sort = null,
        private readonly ?bool $optional = null,
        private readonly array $extra = []
    ) {
    }

    /**
     * Build a spec from a schema array as the engine returns it.
     *
     * Keys this class does not model land in `extra`, which is what makes
     * `fromArray(toArray())` lossless.
     *
     * @param array<string,mixed> $data
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a value object is never a DI subject
    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['name'] ?? ''),
            (string)($data['type'] ?? ''),
            isset($data['facet']) ? (bool)$data['facet'] : null,
            isset($data['index']) ? (bool)$data['index'] : null,
            isset($data['sort']) ? (bool)$data['sort'] : null,
            isset($data['optional']) ? (bool)$data['optional'] : null,
            array_diff_key($data, array_flip(self::MODELLED))
        );
    }

    /**
     * A field removal for `CollectionManager::patch()`: `{"name": …, "drop": true}`.
     *
     * A drop carries no type — re-adding the same name in the same PATCH is how a
     * type change is expressed.
     *
     * @param string $name
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a value object is never a DI subject
    public static function drop(string $name): self
    {
        return new self($name, '', extra: ['drop' => true]);
    }

    /**
     * This spec with every property the override declares applied on top.
     *
     * A change is applied as a drop plus a re-add, so the re-added spec has to carry the
     * properties the override never declared: to the differ a null flag means "don't care",
     * but in a payload it means "reset to the engine default".
     *
     * @param FieldSpec $override
     */
    public function withOverridesFrom(self $override): self
    {
        return new self(
            $override->name,
            $override->type !== '' ? $override->type : $this->type,
            $override->facet ?? $this->facet,
            $override->index ?? $this->index,
            $override->sort ?? $this->sort,
            $override->optional ?? $this->optional,
            self::mergeExtra($this->extra, $override->extra)
        );
    }

    /**
     * Extra properties merged at every depth, the override winning per leaf.
     *
     * Nested maps merge rather than replace, mirroring how `SchemaDiffer` compares them:
     * an override declaring `hnsw_params: {M: 16}` must not drop the engine's own
     * `ef_construction` from the re-add payload and reset it to the default.
     *
     * Lists replace whole — a declared list is a complete value, not a subset of one.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a value object is never a DI subject
    private static function mergeExtra(array $base, array $override): array
    {
        $merged = $base;
        foreach ($override as $key => $value) {
            $current = $merged[$key] ?? null;
            $merged[$key] = is_array($value) && is_array($current)
                && !array_is_list($value) && !array_is_list($current)
                ? self::mergeExtra($current, $value)
                : $value;
        }

        return $merged;
    }

    /**
     * Field name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Typesense field type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Whether the field is facetable; null means the engine default.
     */
    public function getFacet(): ?bool
    {
        return $this->facet;
    }

    /**
     * Whether the field is indexed; null means the engine default.
     */
    public function getIndex(): ?bool
    {
        return $this->index;
    }

    /**
     * Whether the field is sortable; null means the engine default.
     */
    public function getSort(): ?bool
    {
        return $this->sort;
    }

    /**
     * Whether the field may be absent from a document; null means the engine default.
     */
    public function getOptional(): ?bool
    {
        return $this->optional;
    }

    /**
     * Unmodelled engine properties.
     *
     * @return array<string,mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }

    /**
     * Payload for this field: modelled properties that are set, plus `extra` verbatim.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = ['name' => $this->name];
        if ($this->type !== '') {
            $data['type'] = $this->type;
        }
        $flags = [
            'facet' => $this->facet,
            'index' => $this->index,
            'sort' => $this->sort,
            'optional' => $this->optional,
        ];
        foreach ($flags as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $data + $this->extra;
    }
}
