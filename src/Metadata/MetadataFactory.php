<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Metadata;

use BlueprintAU\Radiant\Attributes\Backfill;
use BlueprintAU\Radiant\Attributes\Check;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\ModelScope;
use BlueprintAU\Radiant\Attributes\WriteHook;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Attributes\Morphs;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ScopeCondition;
use BlueprintAU\Radiant\SoftDeletes;
use BlueprintAU\Radiant\Timestamps;
use BlueprintAU\Radiant\Attributes\Index;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Attributes\Unique;

/**
 * Caches per-class metadata (attributes + `ReflectionProperty`s +
 * inheritance chains).
 *
 * The static cache is justified: class metadata is immutable, so it is
 * built at most once per class per process and never invalidated. A
 * class's metadata is derived only from its own reflection plus its
 * ancestors', never its descendants; whichever class of a chain is touched
 * first builds exactly itself, and the engine does the chain merge inside
 * {@see MetadataFactory::build()}.
 */
final class MetadataFactory
{
    /**
     * Per-class metadata cache.
     *
     * @var array<class-string, ClassMetadata>
     */
    private static array $metadataCache = [];

    /**
     * The single entry point: a class's (cached) metadata.
     *
     * @param  class-string<Model>  $class
     * @return ClassMetadata
     */
    public static function for(string $class): ClassMetadata
    {
        return self::$metadataCache[$class] ??= self::build($class);
    }

    /**
     * Resolve a class's metadata, returning null instead of throwing when
     * it cannot be built.
     *
     * A failed build is never cached, so a later strict {@see for()} call
     * on the same class still throws.
     *
     * @param  class-string<Model>  $class
     * @return ClassMetadata|null  Null when the class is missing or its metadata fails validation.
     */
    public static function tryFor(string $class): ?ClassMetadata
    {
        try {
            return self::for($class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Invalidate cached metadata.
     *
     * @param  string|null  $class  Null clears the whole cache.
     * @return void
     */
    public static function clear(?string $class = null): void
    {
        if ($class === null) {
            self::$metadataCache = [];
            return;
        }

        unset(self::$metadataCache[$class]);
    }

    /**
     * The canonical table inventory: every table-owning model class mapped
     * to its resolved table name.
     *
     * @param  list<class-string<Model>>  $models
     * @param  bool  $skipBroken  Whether classes whose metadata cannot be resolved are skipped instead of throwing.
     * @return array<class-string<Model>, string>
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public static function tables(array $models, bool $skipBroken = false): array
    {
        $tables = [];

        foreach ($models as $model) {
            $metadata = $skipBroken ? self::tryFor($model) : self::for($model);

            if ($metadata === null || $metadata->tableName === null) {
                continue; // unresolvable, or no columns of its own — no table (rule 4)
            }

            $tables[$model] = $metadata->tableName;
        }

        return $tables;
    }

    /**
     * Build a class's metadata from its reflection.
     *
     * ONE pass over the leaf's `getProperties()` — which returns inherited
     * properties too, each carrying its true declaring class. Leaf-wins is
     * structural: a redeclared property surfaces exactly once here, as the
     * leaf's property with the leaf's attributes.
     *
     * @param  class-string<Model>  $class
     * @return ClassMetadata
     * @throws \InvalidArgumentException
     */
    private static function build(string $class): ClassMetadata
    {
        $reflection = new \ReflectionClass($class);

        $properties = self::collectProperties($reflection, $class);
        $softDeleteColumn = self::applySoftDeletes($reflection, $class, $properties);
        self::applyTimestamps($reflection, $class, $properties);
        self::applyMorphs($reflection, $class, $properties);
        [$tableName, $parentModel] = self::resolveTableName($reflection, $class, $properties);

        [$uniques, $indexes, $foreignKeys, $checks] = self::collectConstraints($reflection, $class, $properties);

        if ($parentModel !== null) {
            $properties = self::deriveMtiChildKey($reflection, $class, $properties, $parentModel);
        }

        // The column → owning-table partition map, precomputed HERE rather
        // than in the ClassMetadata constructor: resolving an owner's table
        // consults the metadata cache, and the class's OWN entry is not
        // seeded until construction returns — a constructor-side lookup for
        // a self-owned column would recurse infinitely. The factory already
        // knows `$tableName`, so the self-reference resolves locally and
        // only genuinely foreign owners hit the cache (their entries are
        // complete by construction order — ancestors build before or
        // independently of descendants).
        $partitions = [];

        foreach ($properties as $mapping) {
            if ($mapping->owner === $class) {
                if ($tableName !== null) {
                    $partitions[$mapping->columnName] = $tableName;
                }
                continue;
            }

            $table = self::for($mapping->owner)->tableName;

            if ($table === null) {
                continue; // abstract owner — merged into a descendant's table
            }

            $partitions[$mapping->columnName] = $table;
        }

        return new ClassMetadata(
            tableName: $tableName,
            properties: $properties,
            primaryKeys: array_values(array_map(
                fn (PropertyMapping $mapping) => $mapping->column,
                array_filter($properties, fn (PropertyMapping $m) => $m->column->primaryKey),
            )),
            uniques: $uniques,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
            checks: $checks,
            softDeleteColumn: $softDeleteColumn,
            parentModel: $parentModel,
            tablePartitions: $partitions,
            traitScopes: self::collectTraitScopes($reflection, $class, $properties),
            writeHooks: self::collectWriteHooks($reflection, $class),
        );
    }

    /**
     * Collect the trait-declared query scopes for a class.
     *
     * Walks the class's traits recursively (declaration order, then
     * ancestors) and invokes every `#[ModelScope]`-annotated static
     * method. Columns are validated against the merged metadata — an
     * unknown scope column fails fast at build.
     *
     * @param  \ReflectionClass<Model>  $reflection
     * @param  class-string<Model>  $class
     * @param  PropertyMapping[]  $properties  The class's merged column mappings (collected earlier in build()) — validating against these avoids a self::for() call, which would recurse (build() is what invokes this).
     * @return list<array{trait: class-string, condition: \BlueprintAU\Radiant\ScopeCondition}>
     * @throws \InvalidArgumentException
     */
    private static function collectTraitScopes(\ReflectionClass $reflection, string $class, array $properties): array
    {
        $scopes = [];

        foreach (self::traitsOf($reflection) as $trait) {
            foreach ($trait->getMethods() as $method) {
                $attributes = $method->getAttributes(ModelScope::class);

                if ($attributes === []) {
                    continue;
                }

                if (!$method->isStatic() || $method->getNumberOfParameters() > 0) {
                    throw new \InvalidArgumentException(
                        "The #[ModelScope] method [{$trait->name}::{$method->name}] must be static "
                        . 'and take no parameters.'
                    );
                }

                // Invoke through the MODEL class, not the trait — the
                // method's `self::` calls must late-bind to the using class
                // (e.g. SoftDeletes::deletedAtColumn() overrides).
                $conditions = $class::{$method->name}();

                if (!is_array($conditions)) {
                    throw new \InvalidArgumentException(
                        "The #[ModelScope] method [{$trait->name}::{$method->name}] must return an array "
                        . 'of ScopeCondition instances.'
                    );
                }

                foreach ($conditions as $condition) {
                    if (!$condition instanceof ScopeCondition) {
                        throw new \InvalidArgumentException(
                            "The #[ModelScope] method [{$trait->name}::{$method->name}] must return an array "
                            . 'of ScopeCondition instances; got ' . get_debug_type($condition) . '.'
                        );
                    }

                    $known = false;

                    foreach ($properties as $mapping) {
                        if ($mapping->columnName === $condition->column) {
                            $known = true;
                            break;
                        }
                    }

                    if (!$known) {
                        throw new \InvalidArgumentException(
                            "The #[ModelScope] on [{$trait->name}] declares the column [{$condition->column}]"
                            . ", which does not exist on model [{$class}]."
                        );
                    }

                    $scopes[] = ['trait' => $trait->name, 'condition' => $condition];
                }
            }
        }

        return $scopes;
    }

    /**
     * Collect the trait-declared write hooks for a class.
     *
     * Walks the class's traits recursively (declaration order, then
     * ancestors). Within one trait, methods run in declaration order.
     * `Hook::Destroy` methods must return void — the hard DELETE is
     * unclaimable.
     *
     * @param  \ReflectionClass<Model>  $reflection
     * @param  class-string<Model>  $class
     * @return list<array{trait: class-string, hook: Hook, method: string}>
     * @throws \InvalidArgumentException
     */
    private static function collectWriteHooks(\ReflectionClass $reflection, string $class): array
    {
        $hooks = [];

        foreach (self::traitsOf($reflection) as $trait) {
            foreach ($trait->getMethods() as $method) {
                foreach ($method->getAttributes(WriteHook::class) as $attribute) {
                    /** @var WriteHook $writeHook */
                    $writeHook = $attribute->newInstance();

                    if ($method->isStatic()) {
                        throw new \InvalidArgumentException(
                            "The #[WriteHook] method [{$trait->name}::{$method->name}] must be an instance method."
                        );
                    }

                    if (
                        $writeHook->hook === Hook::Destroy
                        && $method->hasReturnType()
                        && (string) $method->getReturnType() !== 'void'
                    ) {
                        throw new \InvalidArgumentException(
                            "The #[WriteHook(Hook::Destroy)] method [{$trait->name}::{$method->name}] must "
                            . 'return void — the hard DELETE is unclaimable.'
                        );
                    }

                    $hooks[] = ['trait' => $trait->name, 'hook' => $writeHook->hook, 'method' => $method->name];
                }
            }
        }

        return $hooks;
    }

    /**
     * The class's traits, recursively — declaration order on the class,
     * then ancestors. Deduplicated.
     *
     * @param  \ReflectionClass<Model>  $reflection
     * @return list<\ReflectionClass<object>>
     */
    private static function traitsOf(\ReflectionClass $reflection): array
    {
        $traits = [];
        $seen = [];

        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getTraitNames() as $traitName) {
                if (isset($seen[$traitName])) {
                    continue;
                }

                $seen[$traitName] = true;
                $traits[] = new \ReflectionClass($traitName);

                // Traits used BY the trait count too.
                foreach (class_uses($traitName) ?: [] as $nested) {
                    if (isset($seen[$nested])) {
                        continue;
                    }

                    $seen[$nested] = true;
                    $traits[] = new \ReflectionClass($nested);
                }
            }
        }

        return $traits;
    }

    /**
     * Collect the merged column mappings for a class.
     *
     * @param  \ReflectionClass<Model>  $reflection
     * @param  class-string<Model>  $class
     * @return PropertyMapping[]
     * @throws \InvalidArgumentException
     */
    private static function collectProperties(\ReflectionClass $reflection, string $class): array
    {
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            $columnAttr = $property->getAttributes(Column::class)[0] ?? null;

            if ($columnAttr === null) {
                // A #[Backfill] without a #[Column] can never be consumed —
                // a backfill rides the column's ADD. Fail fast here.
                if ($property->getAttributes(Backfill::class) !== []) {
                    throw new \InvalidArgumentException(
                        "Model [{$class}] property [{$property->getName()}] declares #[Backfill] "
                        . 'without a #[Column]; a backfill only applies to a declared column — '
                        . 'add #[Column] or drop #[Backfill].'
                    );
                }

                continue;
            }

            $column = $columnAttr->newInstance();

            // The cast is driven by the PHP property type — capture the type
            // NAME here, not the ReflectionType. Unions/intersections on a
            // #[Column] property are a metadata error — fail fast here
            // rather than at first hydration.
            $type = $property->getType();

            if ($type !== null && !($type instanceof \ReflectionNamedType)) {
                throw new \InvalidArgumentException(
                    "Model [{$class}] property [{$property->getName()}] has a "
                    . 'union/intersection type; a column property must be a single named type.'
                );
            }

            $propertyType = $type?->getName();

            // Fail fast when the column type cannot store the field type —
            // an array on an int column would decode garbage; an untyped
            // property has no cast contract. Also enforces the string
            // column's required length.
            $column->assertTypeCompatible($propertyType, $class, $property->getName());

            // Fail fast when a PHP property default would silently shadow
            // the declared column default — an initialized property is
            // always INSERTed explicitly, so a divergent pair writes one
            // value from models and another from raw SQL.
            $column->assertDefaultConsistent($property, $class);

            // Resolve the DB column name BEFORE constructing the mapping, so
            // every consumer downstream (primary-key handling, DDL
            // emission, query building) reads a concrete `$column->name`
            // instead of re-deriving the property-name default. The
            // resolution lands on a REBUILT attribute (explicit construction
            // — the attribute is immutable after construction), so
            // `primaryKeys` consumers reading `$column->name` see the
            // resolved name too.
            $columnName = $column->name ?? $property->getName();

            // The reserved `radiant_` prefix is the ORM's internal alias
            // namespace — a declared column with it would collide with the
            // row lift the moment the column rides an alias-bearing select.
            // Fail fast HERE, at build, not at first pivot load.
            Model::assertNotReservedPrefix($columnName, 'column');

            $column = new Column(
                type: $column->type,
                name: $columnName,
                primaryKey: $column->primaryKey,
                autoIncrement: $column->autoIncrement,
                nullable: $column->nullable,
                unique: $column->unique,
                index: $column->index,
                default: $column->default,
                length: $column->length,
                precision: $column->precision,
                foreign: $column->foreign,
                onDelete: $column->onDelete,
                onUpdate: $column->onUpdate,
            );

            $backfillAttr = $property->getAttributes(Backfill::class)[0] ?? null;

            if ($backfillAttr !== null && $backfillAttr->newInstance()->value === null) {
                throw new \InvalidArgumentException(
                    "Model [{$class}] property [{$property->getName()}] declares #[Backfill(null)] — "
                    . 'null is not a backfill value: it cannot fill a NOT NULL column and is a '
                    . 'no-op on a nullable one. Drop #[Backfill] or declare a real value.'
                );
            }

            $mapping = new PropertyMapping(
                propertyName: $property->getName(),
                columnName: $columnName,
                column: $column,
                property: $property,
                owner: $property->getDeclaringClass()->getName(),
                propertyType: $propertyType,
                backfill: $backfillAttr === null ? null : $backfillAttr->newInstance()->value,
            );

            $properties[$mapping->propertyName] = $mapping;
        }

        return $properties;
    }

    /**
     * Auto-declare the soft-delete column when the class uses SoftDeletes.
     *
     * A user-declared `#[Column]` of the same name wins; a non-null
     * `deletedAtColumn()` override must match a declared column, and a
     * non-datetime declared type is a fail-fast error.
     *
     * @param  \ReflectionClass<Model>  $reflection
     * @param  class-string<Model>  $class
     * @param  PropertyMapping[]  $properties
     * @return string|null
     * @throws \InvalidArgumentException
     */
    private static function applySoftDeletes(\ReflectionClass $reflection, string $class, array &$properties): ?string
    {
        if (!self::usesTrait($class, SoftDeletes::class)) {
            return null;
        }

        // The trait guarantees the method, but $class is typed
        // class-string<Model> and deletedAtColumn() lives on the trait —
        // assert the method exists so PHPStan sees a verified call.
        if (!\method_exists($class, 'deletedAtColumn')) {
            throw new \LogicException(
                "Model [{$class}] uses SoftDeletes but defines no deletedAtColumn()."
            );
        }

        /** @var callable(): (string|null) $resolver */
        $resolver = [$class, 'deletedAtColumn'];
        // Resolve ONCE — the raw value distinguishes "default" (null) from
        // "override" (non-null) and the resolved name is derived from it;
        // calling the resolver again would re-invoke user code and could
        // disagree with the name used for the lookup.
        $override = $resolver();
        $columnName = $override ?? 'deleted_at';

        $declared = null;

        foreach ($properties as $mapping) {
            if ($mapping->columnName === $columnName) {
                $declared = $mapping;
                break;
            }
        }

        if ($declared === null) {
            if ($override !== null) {
                // An override is an explicit claim that the column is
                // declared — a renamed column with no matching #[Column]
                // would otherwise be silently injected as a phantom.
                throw new \InvalidArgumentException(
                    "Model [{$class}] overrides deletedAtColumn() to [{$columnName}] "
                    . "but declares no #[Column] with that name — declare it "
                    . "(datetime, nullable) or return null for the default."
                );
            }

            // No PHP property exists for a synthetic column, so the cast
            // pipeline has no property type to drive from — pin it to the
            // column type so consumers see a consistent datetime column.
            // The shape comes from the softDeletes() helper itself, so the
            // trait and the DDL helper cannot drift.
            $properties[$columnName] = new PropertyMapping(
                propertyName: $columnName,
                columnName: $columnName,
                column: self::columnsFromHelperShape(
                    fn (Blueprint $blueprint) => $blueprint->softDeletes(),
                )['deleted_at']
                    ?? throw new \LogicException(
                        'The softDeletes() helper emitted no [deleted_at] column; the '
                        . 'trait auto-declaration contract is broken.'
                    ),
                property: null,
                owner: $class,
                propertyType: ColumnType::DateTime->value,
            );
        } elseif ($declared->column->type !== ColumnType::DateTime) {
            throw new \InvalidArgumentException(
                "Model [{$class}] declares soft-delete column [{$columnName}] "
                . "as [{$declared->column->type->value}], but SoftDeletes requires datetime."
            );
        }

        return $columnName;
    }

    /**
     * Auto-declare the stamp columns when the class uses Timestamps.
     *
     * Mirrors {@see applySoftDeletes()}: a user-declared `#[Column]` of the
     * same name wins; a non-null `createdAtColumn()`/`updatedAtColumn()`
     * override must match a declared column; a non-datetime declared type
     * is a fail-fast error. Undeclared columns are injected as synthetic
     * NOT NULL datetime mappings shaped by the `timestamps()` helper.
     *
     * @param  \ReflectionClass<Model>  $reflection
     * @param  class-string<Model>  $class
     * @param  PropertyMapping[]  $properties
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function applyTimestamps(\ReflectionClass $reflection, string $class, array &$properties): void
    {
        if (!self::usesTrait($class, Timestamps::class)) {
            return;
        }

        foreach (['createdAtColumn', 'updatedAtColumn'] as $method) {
            if (!\method_exists($class, $method)) {
                throw new \LogicException("Model [{$class}] uses Timestamps but defines no {$method}().");
            }
        }

        /** @var callable(): (string|null) $createdResolver */
        $createdResolver = [$class, 'createdAtColumn'];
        /** @var callable(): (string|null) $updatedResolver */
        $updatedResolver = [$class, 'updatedAtColumn'];

        $overrides = [
            'createdAtColumn' => ['created_at', $createdResolver()],
            'updatedAtColumn' => ['updated_at', $updatedResolver()],
        ];

        $stamps = self::columnsFromHelperShape(
            fn (Blueprint $blueprint) => $blueprint->timestamps(),
        );

        foreach ($overrides as $method => [$defaultName, $override]) {
            $columnName = $override ?? $defaultName;

            $declared = null;

            foreach ($properties as $mapping) {
                if ($mapping->columnName === $columnName) {
                    $declared = $mapping;
                    break;
                }
            }

            if ($declared === null) {
                if ($override !== null) {
                    throw new \InvalidArgumentException(
                        "Model [{$class}] overrides {$method}() to [{$columnName}] "
                        . "but declares no #[Column] with that name — declare it "
                        . '(datetime, nullable) or return null for the default.'
                    );
                }

                $properties[$columnName] = new PropertyMapping(
                    propertyName: $columnName,
                    columnName: $columnName,
                    column: $stamps[$defaultName]
                        ?? throw new \LogicException(
                            "The timestamps() helper emitted no [{$defaultName}] column; the "
                            . 'trait auto-declaration contract is broken.'
                        ),
                    property: null,
                    owner: $class,
                    propertyType: ColumnType::DateTime->value,
                );
            } elseif ($declared->column->type !== ColumnType::DateTime) {
                throw new \InvalidArgumentException(
                    "Model [{$class}] declares stamp column [{$columnName}] "
                    . "as [{$declared->column->type->value}], but Timestamps requires datetime."
                );
            }
        }
    }

    /**
     * The columns a Blueprint helper emits, keyed by column name.
     *
     * The helper is invoked on a scratch blueprint and each emitted column
     * shape becomes a synthetic `Column` — the traits' auto-declared
     * columns and the DDL helpers share one source of truth, so they
     * cannot drift. Every shape field is carried over, so a helper that
     * grows a default, length, or flag keeps it on the synthetic column.
     *
     * @param  callable(Blueprint): Blueprint  $helper  Invoked with a scratch blueprint; must append the helper columns.
     * @return array<string, Column>
     */
    private static function columnsFromHelperShape(callable $helper): array
    {
        $columns = [];

        foreach ($helper(new Blueprint('radiant_helper'))->getColumns() as $shape) {
            $columns[$shape['name']] = new Column(
                type: $shape['type'],
                name: $shape['name'],
                primaryKey: $shape['primaryKey'],
                autoIncrement: $shape['autoIncrement'],
                nullable: $shape['nullable'],
                unique: $shape['unique'],
                index: $shape['index'],
                default: $shape['default'],
                length: $shape['length'],
                precision: $shape['precision'],
                foreign: $shape['foreign'],
                onDelete: $shape['onDelete'],
                onUpdate: $shape['onUpdate'],
            );
        }

        return $columns;
    }

    /**
     * Inject the synthetic morph columns declared by class-level
     * `#[Morphs]` attributes.
     *
     * Each attribute emits `{name}_type` (string) and `{name}_id` (the
     * attribute's keyType). A user-declared `#[Column]` with the same name
     * wins, but a declared column whose type cannot hold the morph value
     * fails fast.
     *
     * @param  \ReflectionClass<Model>  $reflection
     * @param  class-string<Model>  $class
     * @param  PropertyMapping[]  $properties
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function applyMorphs(\ReflectionClass $reflection, string $class, array &$properties): void
    {
        $attributes = $reflection->getAttributes(Morphs::class);

        if ($attributes === []) {
            return;
        }

        $seen = [];

        foreach ($attributes as $attribute) {
            /** @var Morphs $morphs */
            $morphs = $attribute->newInstance();

            if ($morphs->name === '') {
                throw new \InvalidArgumentException(
                    "Model [{$class}] declares #[Morphs] with an empty name; "
                    . 'a morph pair requires a non-empty name.'
                );
            }

            if (isset($seen[$morphs->name])) {
                throw new \InvalidArgumentException(
                    "Model [{$class}] declares #[Morphs(name: '{$morphs->name}')] twice; "
                    . 'a morph name must be unique per class.'
                );
            }

            $morphs->assertKeyTypeCapable();

            $seen[$morphs->name] = true;

            self::injectMorphColumn($class, $properties, $morphs->typeColumn(), ColumnType::String, 255, 'string', $morphs->nullable);
            self::injectMorphColumn($class, $properties, $morphs->keyColumn(), $morphs->keyType, null, $morphs->keyType->value, $morphs->nullable);
        }
    }

    /**
     * Inject (or validate) ONE morph column on the class.
     *
     * @param  class-string<Model>  $class
     * @param  PropertyMapping[]  $properties
     * @param  string  $columnName
     * @param  ColumnType  $type
     * @param  int|null  $length
     * @param  string  $typeLabel
     * @param  bool  $nullable
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function injectMorphColumn(
        string $class,
        array &$properties,
        string $columnName,
        ColumnType $type,
        ?int $length,
        string $typeLabel,
        bool $nullable,
    ): void {
        foreach ($properties as $mapping) {
            if ($mapping->columnName !== $columnName) {
                continue;
            }

            // A user declaration wins — but only if it can actually hold
            // the morph value. A morph relation writes class-strings and
            // PK values through these columns; a wrong type would corrupt
            // every round-trip.
            if ($mapping->column->type !== $type) {
                throw new \InvalidArgumentException(
                    "Model [{$class}] declares column [{$columnName}] as "
                    . "[{$mapping->column->type->value}], but the #[Morphs] pair "
                    . "requires {$typeLabel}."
                );
            }

            if ($type === ColumnType::String && ($mapping->column->length ?? 0) < ($length ?? 0)) {
                throw new \InvalidArgumentException(
                    "Model [{$class}] declares column [{$columnName}] with length "
                    . "[{$mapping->column->length}]; the #[Morphs] type column requires "
                    . "a length of at least {$length} (a full class-string must fit)."
                );
            }

            return;
        }

        // No PHP property exists for a synthetic column — pin the property
        // type to the column type so the cast pipeline sees a consistent
        // scalar (the same convention applySoftDeletes() applies).
        //
        // The morph name is caller-supplied (`#[Morphs(name: ...)]`) — the
        // same reserved-prefix guard as declared columns applies.
        Model::assertNotReservedPrefix($columnName, 'morph column');

        $properties[$columnName] = new PropertyMapping(
            propertyName: $columnName,
            columnName: $columnName,
            column: new Column(
                type: $type,
                name: $columnName,
                nullable: $nullable,
                length: $length,
            ),
            property: null,
            owner: $class,
            propertyType: $type->value,
        );
    }

    /**
     * Resolve the table name for a class (rules 1–5).
     *
     * Rule 4 — a class with no columns anywhere in its chain, and abstract
     * classes, own no table. Rule 2 — a concrete subclass of a table-owning
     * ancestor that adds columns but declares no `#[Table]` is a build
     * error. Rule 3 — a concrete subclass declaring its own `#[Table]` is a
     * multi-table-inheritance child. Rule 1 — a behavior-only subclass
     * inherits its ancestor's table. Rule 5 — otherwise the snake-cased
     * plural of the short class name.
     *
     * @param  \ReflectionClass<Model>  $reflection
     * @param  class-string<Model>  $class
     * @param  PropertyMapping[]  $properties
     * @return array{string|null, class-string<Model>|null}
     * @throws \InvalidArgumentException
     */
    private static function resolveTableName(
        \ReflectionClass $reflection,
        string $class,
        array $properties,
    ): array {
        // Rule 4 — abstract classes are never instantiated; their columns
        // belong to the first concrete descendant.
        if ($reflection->isAbstract()) {
            return [null, null];
        }

        // An explicit #[Table(name)] on a column-less concrete model IS a
        // declaration: the class names a table without owning columns —
        // pivot-table pointers (belongsToMany(table: Pivot::class)) above
        // all. Empty-name stays a mis-declaration.
        /** @var \ReflectionAttribute<Table>|null $explicitTable */
        $explicitTable = $reflection->getAttributes(Table::class)[0] ?? null;

        if ($properties === [] && $explicitTable !== null) {
            $name = $explicitTable->newInstance()->name;

            if ($name === '') {
                throw new \InvalidArgumentException(
                    "Model [{$class}] declares #[Table(name: '')] — an empty name is "
                    . 'a mis-declaration; omit the argument to keep the convention.'
                );
            }

            return [$name, null];
        }

        // Rule 4 — no columns anywhere in the chain, no table (abstract or
        // a deliberately concrete organizational base alike).
        if ($properties === []) {
            return [null, null];
        }

        $ownColumns = array_filter($properties, fn (PropertyMapping $m) => $m->owner === $class);
        $declaresOwnColumns = $ownColumns !== [];

        // Is there a concrete, TABLE-OWNING ancestor? A table-owning
        // ancestor makes this class a subclass of a table; an abstract
        // chain above makes it the first table owner in its chain.
        $ancestorTable = self::nearestAncestorTable($class);

        if ($ancestorTable !== null) {
            // Rule 2 — inheriting a table while adding columns of your own,
            // with no `#[Table]` to say where the columns go.
            if ($declaresOwnColumns) {
                $ownTable = $reflection->getAttributes(Table::class)[0] ?? null;

                if ($ownTable === null) {
                    throw new \InvalidArgumentException(
                        "Model [{$class}] inherits columns from an ancestor and declares columns of its own. "
                        . 'Declare #[Table(name: ...)] to give the new columns a table of their own '
                        . '(multi-table inheritance), model the link with composition (two '
                        . 'independent models + a plain FK column), or make the subclass '
                        . 'behavior-only (no new columns, no #[Table]).'
                    );
                }
            }

            $ownTable = $reflection->getAttributes(Table::class)[0] ?? null;

            // Rule 3 — MTI: own columns + own #[Table] = the child's own
            // table. A behavior-only subclass declaring #[Table] (a second
            // table with nothing in it) stays a build error.
            if ($ownTable !== null) {
                if (!$declaresOwnColumns) {
                    throw new \InvalidArgumentException(
                        "Model [{$class}] inherits ALL columns from an ancestor and declares its own #[Table]. "
                        . 'A second table holding none of the columns is a mis-modeling: '
                        . 'drop the #[Table] to share the ancestor\'s table, or add the columns '
                        . 'that belong on the new table.'
                    );
                }

                /** @var Table $tableAttribute */
                $tableAttribute = $ownTable->newInstance();
                $name = $tableAttribute->name;

                if ($name === '') {
                    throw new \InvalidArgumentException(
                        "Model [{$class}] declares #[Table(name: '')] — an empty name is "
                        . 'a mis-declaration; omit the argument to keep the convention.'
                    );
                }

                /** @var class-string<Model> $parentModel */
                $parentModel = get_parent_class($class);

                while (!is_a($parentModel, Model::class, true)
                    || self::for($parentModel)->tableName === null) {
                    $parentModel = get_parent_class($parentModel);

                    if ($parentModel === false) {
                        throw new \LogicException(
                            "Model [{$class}] resolved ancestor table [{$ancestorTable}] but no "
                            . 'table-owning ancestor model to pair it with.'
                        );
                    }
                }

                return [$name, $parentModel];
            }

            // Rule 1 — behavior-only subclass: provably interchangeable
            // with its ancestor; share its table.
            return [$ancestorTable, null];
        }

        // Intelephense mis-resolves the ReflectionAttribute template to the
        // reflection TARGET (Model) instead of the ATTRIBUTE class (Table) —
        // so newInstance() looks like it returns Model, which has no $name.
        // The @var pins the template to the attribute class; the runtime
        // truth is unchanged.
        /** @var \ReflectionAttribute<Table>|null $ownTable */
        $ownTable = $reflection->getAttributes(Table::class)[0] ?? null;

        if ($ownTable !== null) {
            // An explicit #[Table(name: '...')] wins. The name is optional —
            // #[Table] with no name keeps the convention — but an explicitly
            // EMPTY name is a mis-declaration: fail fast.
            $name = $ownTable->newInstance()->name;

            if ($name === '') {
                throw new \InvalidArgumentException(
                    "Model [{$class}] declares #[Table(name: '')] — an empty name is "
                    . 'a mis-declaration; omit the argument to keep the convention.'
                );
            }

            return [$name ?? self::defaultTableName($reflection->getShortName()), null];
        }

        return [self::defaultTableName($reflection->getShortName()), null]; // rule 5
    }

    /**
     * Collect and validate the composite constraints from the class
     * hierarchy, most-derived first.
     *
     * @param  \ReflectionClass<Model>  $reflection
     * @param  class-string<Model>  $class
     * @param  PropertyMapping[]  $properties
     * @return array{list<Unique>, list<Index>, list<ForeignKey>, list<Check>}
     * @throws \InvalidArgumentException
     */
    private static function collectConstraints(
        \ReflectionClass $reflection,
        string $class,
        array $properties,
    ): array {
        $uniques = [];
        $indexes = [];
        $foreignKeys = [];
        $checks = [];

        for ($current = $class; $current !== false; $current = get_parent_class($current)) {
            $level = new \ReflectionClass($current);

            foreach ($level->getAttributes(Unique::class) as $attribute) {
                /** @var Unique $unique */
                $unique = $attribute->newInstance();
                self::validateConstraintColumns($unique->columns, $properties, $class, 'unique');
                self::validateNoFlagDuplicates($unique->columns, $properties, $class, 'unique');
                $uniques[] = $unique;
            }

            foreach ($level->getAttributes(Index::class) as $attribute) {
                /** @var Index $index */
                $index = $attribute->newInstance();
                self::validateConstraintColumns($index->columns, $properties, $class, 'index');
                self::validateNoFlagDuplicates($index->columns, $properties, $class, 'index');
                $indexes[] = $index;
            }

            foreach ($level->getAttributes(ForeignKey::class) as $attribute) {
                /** @var ForeignKey $foreignKey */
                $foreignKey = $attribute->newInstance();

                if ($foreignKey->columns === []) {
                    throw new \InvalidArgumentException(
                        "Model [{$class}] declares a #[ForeignKey] with an empty column list; "
                        . 'a foreign key requires at least one column.'
                    );
                }

                if ($foreignKey->referencesColumns !== null && $foreignKey->referencesColumns === []) {
                    throw new \InvalidArgumentException(
                        "Model [{$class}] declares a #[ForeignKey] with an empty references column list; "
                        . 'a foreign key requires at least one referenced column.'
                    );
                }

                $resolvedReferencesColumns = $foreignKey->resolvedReferencesColumns();

                if ($resolvedReferencesColumns === []) {
                    throw new \InvalidArgumentException(
                        "Model [{$class}] declares a #[ForeignKey] referencing model "
                        . "[{$foreignKey->references}], which declares no primary key; a model "
                        . 'reference resolves its columns from the target PK — declare the '
                        . 'target\'s key, or reference a table name with explicit columns.'
                    );
                }

                if (count($foreignKey->columns) !== count($resolvedReferencesColumns)) {
                    throw new \InvalidArgumentException(
                        "Model [{$class}] declares a #[ForeignKey] whose columns and references "
                        . 'must have matching arity; got ' . count($foreignKey->columns) . ' and '
                        . count($resolvedReferencesColumns) . '.'
                    );
                }

                self::validateConstraintColumns($foreignKey->columns, $properties, $class, 'foreign key');
                self::validateNoFlagDuplicates($foreignKey->columns, $properties, $class, 'foreign');
                $foreignKey->resolvedReferences(); // resolves + validates model-class references
                $foreignKeys[] = $foreignKey;
            }

            foreach ($level->getAttributes(Check::class) as $attribute) {
                /** @var Check $check */
                $check = $attribute->newInstance();

                if (trim($check->expression) === '') {
                    throw new \InvalidArgumentException(
                        "Model [{$class}] declares a #[Check] with an empty expression; "
                        . 'a CHECK constraint requires a non-empty predicate.'
                    );
                }

                $checks[] = $check;
            }
        }

        return [$uniques, $indexes, $foreignKeys, $checks];
    }

    /**
     * Assert every constraint column name resolves to a declared column.
     *
     * @param  list<string>  $columns
     * @param  PropertyMapping[]  $properties
     * @param  class-string<Model>  $class
     * @param  string  $constraintKind
     * @throws \InvalidArgumentException
     */
    private static function validateConstraintColumns(
        array $columns,
        array $properties,
        string $class,
        string $constraintKind,
    ): void {
        $declared = [];

        foreach ($properties as $mapping) {
            $declared[$mapping->columnName] = true;
        }

        foreach ($columns as $column) {
            if (!isset($declared[$column])) {
                throw new \InvalidArgumentException(
                    "Model [{$class}] declares a {$constraintKind} constraint on unknown column "
                    . "[{$column}]. A constraint's columns must match the model's declared "
                    . 'column names.'
                );
            }
        }
    }

    /**
     * Assert a single-column attribute does not duplicate a `#[Column]` flag.
     *
     * The check walks the ancestor chain too: a redeclared column hides the
     * ancestor's mapping in `$properties`, but the ancestor's flag still
     * declares the constraint at its level.
     *
     * @param  list<string>  $columns
     * @param  PropertyMapping[]  $properties
     * @param  class-string<Model>  $class
     * @param  string  $flag
     * @throws \InvalidArgumentException
     */
    private static function validateNoFlagDuplicates(
        array $columns,
        array $properties,
        string $class,
        string $flag,
    ): void {
        if (count($columns) !== 1) {
            return; // multi-column constraints have no flag equivalent.
        }

        foreach ($properties as $mapping) {
            if (
                $mapping->columnName === $columns[0]
                && $mapping->column->{$flag} === true
            ) {
                throw new \InvalidArgumentException(
                    "Model [{$class}] declares column [{$columns[0]}] with `{$flag}: true` AND a "
                    . "class-level attribute covering the same column — a duplicate declaration. "
                    . "Use one mechanism: the flag for the simple single-column case, or the "
                    . 'attribute when you need a name/composite/actions.'
                );
            }
        }

        // Ancestor flags: a redeclared column hides the ancestor's mapping
        // in $properties, but the ancestor's `#[Column]` flag still declares
        // the constraint at its level. Walk the chain and check the raw
        // property attributes there too.
        for ($ancestor = get_parent_class($class); $ancestor !== false; $ancestor = get_parent_class($ancestor)) {
            if (!is_a($ancestor, Model::class, true)) {
                continue;
            }

            $level = new \ReflectionClass($ancestor);

            foreach ($level->getProperties() as $property) {
                foreach ($property->getAttributes(Column::class) as $attribute) {
                    /** @var Column $columnAttr */
                    $columnAttr = $attribute->newInstance();

                    $columnName = $columnAttr->name ?? $property->getName();

                    if ($columnName === $columns[0] && $columnAttr->{$flag} === true) {
                        throw new \InvalidArgumentException(
                            "Model [{$class}] declares a class-level {$flag} constraint on column [{$columns[0]}], "
                            . "but ancestor [{$ancestor}] already declares the same column with `{$flag}: true` — "
                            . 'a duplicate declaration across the inheritance chain. Keep the flag on the '
                            . 'declaring ancestor, or the attribute on the child, not both.'
                        );
                    }
                }
            }
        }
    }

    /**
     * Whether a class (or any ancestor) uses a trait — directly or nested
     * inside another trait.
     *
     * @param  class-string<Model>  $class
     * @param  string  $trait
     * @return bool
     */
    private static function usesTrait(string $class, string $trait): bool
    {
        for ($current = $class; $current !== false; $current = get_parent_class($current)) {
            $traits = class_uses($current);

            if ($traits === false) {
                continue;
            }

            foreach ($traits as $used) {
                if ($used === $trait || self::traitUses($used, $trait)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a trait (directly or via another trait) uses the given trait.
     *
     * @param  string  $trait
     * @param  string  $target
     * @return bool
     */
    private static function traitUses(string $trait, string $target): bool
    {
        $traits = class_uses($trait);

        if ($traits === false) {
            return false;
        }

        foreach ($traits as $nested) {
            if ($nested === $target || self::traitUses($nested, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk up the parent chain for the nearest resolved table name (rule 1).
     *
     * @param  class-string<Model>  $class
     * @return string|null
     */
    private static function nearestAncestorTable(string $class): ?string
    {
        for ($current = get_parent_class($class); $current !== false; $current = get_parent_class($current)) {
            // Invariant: every ancestor of a Model IS a Model subclass —
            // the guard makes the type flow provable instead of assumed.
            if (!is_a($current, Model::class, true)) {
                throw new \LogicException(
                    "Model [{$class}] extends [{$current}], which is not a "
                    . Model::class . ' subclass; the metadata engine requires it.'
                );
            }

            $table = self::for($current)->tableName;

            if ($table !== null) {
                return $table;
            }
        }

        return null;
    }

    /**
     * The default table name for a class: snake-cased plural of its short
     * name (`User` → `users`).
     *
     * @param  string  $shortName
     * @return string
     */
    private static function defaultTableName(string $shortName): string
    {
        // camelCase / PascalCase → snake_case: insert _ before each
        // uppercase that follows a lowercase or digit, then lowercase all.
        $snake = strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $shortName));

        // Naive plural: -es after s, x, z, ch, sh; consonant+y → -ies;
        // otherwise -s.
        if (preg_match('/(s|x|z|ch|sh)$/', $snake) === 1) {
            return $snake . 'es';
        }

        if (preg_match('/[^aeiou]y$/', $snake) === 1) {
            return substr($snake, 0, -1) . 'ies';
        }

        return $snake . 's';
    }

    // ---- Multi-table inheritance (MTI) derivation ----

    /**
     * Derive an MTI child's primary-key mapping from its parent's.
     *
     * The child declares no key of its own — the shared PK is the link
     * between the two tables. The parent's PK mapping is cloned per-class
     * with `autoIncrement` overridden to false: only the root table
     * generates the id.
     *
     * @param  \ReflectionClass<Model>  $reflection
     * @param  class-string<Model>  $class
     * @param  PropertyMapping[]  $properties
     * @param  class-string<Model>  $parentModel
     * @return PropertyMapping[]
     * @throws \InvalidArgumentException
     */
    private static function deriveMtiChildKey(
        \ReflectionClass $reflection,
        string $class,
        array $properties,
        string $parentModel,
    ): array {
        $parentMetadata = self::for($parentModel);
        $parentKeys = $parentMetadata->primaryKeys;

        if (count($parentKeys) !== 1 || $parentKeys[0]->name === null) {
            throw new \InvalidArgumentException(
                "Model [{$class}] extends table-owning [{$parentModel}], whose primary key is "
                . 'composite or unnamed. Multi-table inheritance requires a single named '
                . 'primary key on the root table.'
            );
        }

        $pkName = $parentKeys[0]->name;

        // The parent's key column must not already exist as a child-side
        // declaration — the child inherits the property through PHP and
        // the derived mapping below IS the child's record of it.
        foreach ($properties as $mapping) {
            if ($mapping->owner !== $class) {
                continue;
            }

            if ($mapping->columnName === $pkName) {
                throw new \InvalidArgumentException(
                    "Model [{$class}] redeclares primary-key column [{$pkName}]. The key is "
                    . "derived from [{$parentModel}] — the shared PK IS the table link; "
                    . 'declare only the new columns.'
                );
            }
        }

        // Explicit construction replaces the historical `clone $parentKeys[0]`
        // + post-construction `$derived->autoIncrement = false` write — the
        // attribute is immutable after construction.
        $derived = new Column(
            type: $parentKeys[0]->type,
            name: $pkName,
            primaryKey: $parentKeys[0]->primaryKey,
            autoIncrement: false,
            nullable: $parentKeys[0]->nullable,
            unique: $parentKeys[0]->unique,
            index: $parentKeys[0]->index,
            default: $parentKeys[0]->default,
            length: $parentKeys[0]->length,
            foreign: $parentKeys[0]->foreign,
            onDelete: $parentKeys[0]->onDelete,
            onUpdate: $parentKeys[0]->onUpdate,
        );

        $parentMapping = null;

        foreach ($parentMetadata->properties as $mapping) {
            if ($mapping->columnName === $pkName) {
                $parentMapping = $mapping;
                break;
            }
        }

        if ($parentMapping === null) {
            throw new \LogicException(
                "Model [{$parentModel}] declares primary key [{$pkName}] with no matching mapping."
            );
        }

        $properties[$parentMapping->propertyName] = new PropertyMapping(
            propertyName: $parentMapping->propertyName,
            columnName: $pkName,
            column: $derived,
            property: $parentMapping->property,
            owner: $class,
        );

        ksort($properties);

        return $properties;
    }
}
