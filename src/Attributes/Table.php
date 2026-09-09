<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

/**
 * Overrides table-level metadata for a model.
 *
 * Fully optional: a model without it gets the snake-cased plural of its
 * short class name (see {@see \BlueprintAU\Radiant\Metadata\MetadataFactory::defaultTableName()}), and
 * `name` itself is optional so the attribute can carry ONLY other
 * table-level settings without duplicating the default name — duplicating
 * it would silently drift when the class is renamed. When `name` is null
 * the convention applies; when it is set it must be non-empty (an empty
 * string is a mis-declaration and fails fast at metadata build).
 *
 * **Dynamic table names: deliberately unsupported.** A model-level hook
 * (e.g. a static `resolveTableName()`) would need per-request state (tenant
 * id, date bucket) to reach a static method through globals — the
 * service-locator pattern this package forbids — and caching the result
 * would cross tenants. Static names only, via `name` or the convention.
 * Real sharding is a CONNECTION-layer concern: point a tenant at its own
 * entry in the DatabaseManager map (explicit wiring, no hidden state).
 *
 * **What else `#[Table]` should own (when those features land):** `schema`
 * (the database/schema qualifier for cross-database joins), `comment` (a
 * table COMMENT in the DDL), and `charset`/`collation` (MySQL table-level
 * defaults — only if a real model needs a per-table override).
 *
 * Composite constraints (unique / foreign key / index) do NOT live here —
 * they are separate class-level attributes ({@see Unique},
 * {@see ForeignKey}, {@see CompositeIndex}). `#[Table]` stays a
 * naming/placement concern; constraints are a vocabulary of their own.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Table
{
    /**
     * Create a table override.
     *
     * @param string|null $name The table name override. Null (the default)
     *        keeps the snake-cased plural convention; a non-empty string
     *        replaces it; an empty string throws at metadata build.
     */
    public function __construct(
        public string|null $name = null,
    ) {}
}
