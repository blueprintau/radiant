<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Metadata;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;
use BlueprintAU\Radiant\Attributes\Index;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Attributes\Unique;

/**
 * Caches per-class metadata (attributes + `ReflectionProperty`s +
 * inheritance chains).
 *
 * The static cache is justified: class metadata is immutable, so it is
 * built at most once per class per process and never invalidated.
 *
 * Three guarantees the cache and {@see MetadataFactory::build()} together
 * provide:
 *
 * 1. Per-class isolation: a class's metadata is derived only from its own
 *    reflection plus its ANCESTORS', never its descendants. A child
 *    redeclaring a column cannot leak into the parent's metadata —
 *    `ReflectionClass($parent)` structurally cannot see subclasses — and
 *    the child's own declaration wins in its own metadata (single-slot
 *    properties; see build()).
 * 2. Build-once, order-independent: whichever class of a chain is touched
 *    first builds exactly ITSELF and caches it; later calls for any other
 *    class build at most once each and repeat calls are pure cache hits.
 *    No call ever invalidates another.
 * 3. The engine does the chain merge: build() reflects only the leaf —
 *    `getProperties()` returns the fully merged ancestor view. No explicit
 *    chain build exists; the ONLY cross-class work is rule 1's
 *    {@see MetadataFactory::nearestAncestorTable()} lookup, and only for
 *    behavior-only subclasses, lazily, through this cache.
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
     * There is deliberately no public chain API — table resolution,
     * query building, DDL emission, and tables() all consume `for()` alone.
     * Ancestor columns merge inside {@see MetadataFactory::build()}; a
     * public chain surface can return when a real consumer exists — the
     * deferred STI/JOINED inheritance strategies.
     *
     * @param class-string<Model> $class The model class.
     * @return ClassMetadata The class's metadata.
     */
    public static function for(string $class): ClassMetadata
    {
        return self::$metadataCache[$class] ??= self::build($class);
    }

    /**
     * The canonical table inventory: every table-owning model class mapped
     * to its resolved table name.
     *
     * The single authoritative source for "where tables are created" — the host
     * migrator consumes it to build its schema, and an inventory test
     * asserts the full model→table map so an accidental table creation
     * shows up in CI immediately. Computed FROM the metadata rather than
     * duplicated onto the classes. The root convention (rule 5) is the ONLY
     * silent naming rule; every non-conventional table is traceable to an
     * explicit `#[Table(name: ...)]` declaration visible right here.
     *
     * @param list<class-string<Model>> $models The model classes to inventory.
     * @return array<class-string<Model>, string> class => resolved table name
     */
    public static function tables(array $models): array
    {
        $tables = [];

        foreach ($models as $model) {
            $tableName = self::for($model)->tableName;

            if ($tableName === null) {
                continue; // no columns of its own — no table (rule 4)
            }

            $tables[$model] = $tableName;
        }

        return $tables;
    }

    /**
     * Build a class's metadata from its reflection.
     *
     * ONE pass over the leaf's `getProperties()` — which returns inherited
     * properties too, each carrying its true declaring class. Leaf-wins is
     * structural, not incidental: a redeclared property surfaces EXACTLY
     * ONCE here, as the leaf's property with the leaf's attributes (PHP
     * properties are single-slot — a child redeclaration REPLACES the
     * parent's declaration). So no ordering assumptions, no `isset()`
     * guards, no subclass-of comparisons, no displaced primary keys.
     *
     * @param class-string<Model> $class The model class (see
     *         for()).
     * @return ClassMetadata The freshly built metadata.
     * @throws \InvalidArgumentException On any metadata error: union or
     *         intersection column types, invalid soft-delete declarations,
     *         inheritance rule violations (rules 2/3), empty `#[Table]`
     *         names, unknown constraint columns, FK arity mismatches,
     *         duplicate constraint declarations, or string columns without
     *         a length.
     */
    private static function build(string $class): ClassMetadata
    {
        $reflection = new \ReflectionClass($class);

        $properties = self::collectProperties($reflection, $class);
        $softDeleteColumn = self::applySoftDeletes($reflection, $class, $properties);
        [$tableName, $parentModel] = self::resolveTableName($reflection, $class, $properties);

        [$uniques, $indexes, $foreignKeys] = self::collectConstraints($reflection, $class, $properties);

        if ($parentModel !== null) {
            $properties = self::deriveMtiChildKey($reflection, $class, $properties, $parentModel);
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
            softDeleteColumn: $softDeleteColumn,
            parentModel: $parentModel,
        );
    }

    /**
     * Collect the merged column mappings for a class.
     *
     * One reflection pass over the leaf's properties (inherited included).
     * Every mapping records its owning class via `getDeclaringClass()` —
     * the ownership record table resolution and the deferred JOINED
     * strategy both derive from.
     *
     * @param \ReflectionClass<Model> $reflection The leaf class.
     * @param class-string<Model> $class The leaf class name.
     * @return PropertyMapping[] Merged mappings keyed by property name.
     * @throws \InvalidArgumentException When a column property has a union
     *         or intersection type, or a string column lacks a length.
     */
    private static function collectProperties(\ReflectionClass $reflection, string $class): array
    {
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            $columnAttr = $property->getAttributes(Column::class)[0] ?? null;

            if ($columnAttr === null) {
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

            // Fail fast when the column type cannot store the field type —
            // an array on an int column would decode garbage; an untyped
            // property has no cast contract. Also enforces the string
            // column's required length.
            $column->propertyType = $type?->getName();
            $column->assertTypeCompatible($column->propertyType, $class, $property->getName());

            // Fail fast when a PHP property default would silently shadow
            // the declared column default — an initialized property is
            // always INSERTed explicitly, so a divergent pair writes one
            // value from models and another from raw SQL.
            $column->assertDefaultConsistent($property, $class);

            // Resolve the DB column name ONTO the column at build time, so
            // every consumer downstream (primary-key handling, DDL
            // emission, query building) reads a concrete `$column->name`
            // instead of re-deriving the property-name default.
            $column->name ??= $property->getName();

            $mapping = new PropertyMapping(
                propertyName: $property->getName(),
                columnName: $column->name,
                column: $column,
                property: $property,
                owner: $property->getDeclaringClass()->getName(),
            );

            $properties[$mapping->propertyName] = $mapping;
        }

        return $properties;
    }

    /**
     * Auto-declare the soft-delete column when the class uses SoftDeletes.
     *
     * Uses `$class::deletedAtColumn()` — so a renamed column gets the right
     * metadata; no unused phantom column. A user declaration wins; a
     * non-datetime declared type is a fail-fast error. The synthetic
     * mapping is visible by default, so users can read
     * the deleted time.
     *
     * @param \ReflectionClass<Model> $reflection The leaf class.
     * @param class-string<Model> $class The leaf class name.
     * @param PropertyMapping[] $properties The merged mappings (mutated in
     *        place when the synthetic column is injected).
     * @return string|null The soft-delete column name, or null when the
     *         class does not use SoftDeletes.
     * @throws \InvalidArgumentException When the class declares the
     *         soft-delete column with a non-datetime type.
     */
    private static function applySoftDeletes(\ReflectionClass $reflection, string $class, array &$properties): ?string
    {
        if (!self::usesSoftDeletes($class)) {
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

        /** @var callable(): string $resolver */
        $resolver = [$class, 'deletedAtColumn'];
        $columnName = $resolver();

        $declared = null;

        foreach ($properties as $mapping) {
            if ($mapping->columnName === $columnName) {
                $declared = $mapping;
                break;
            }
        }

        if ($declared === null) {
            $column = new Column(
                type: ColumnType::DateTime,
                name: $columnName,
                nullable: true,
            );
            // No PHP property exists for a synthetic column, so the cast
            // pipeline has no property type to drive from — pin it to the
            // column type so consumers see a consistent datetime column.
            $column->propertyType = ColumnType::DateTime->value;

            $properties[$columnName] = new PropertyMapping(
                propertyName: $columnName,
                columnName: $columnName,
                column: $column,
                property: null,
                owner: $class,
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
     * Resolve the table name for a class (rules 1–5).
     *
     * The rules, validated HERE at metadata build:
     *
     * - Rule 4 — a class with NO columns anywhere in its chain owns NO
     *   table (nothing to store, nothing to sync — a computed phantom
     *   table would pollute the tables() inventory and shadow the
     *   conventional name the first column-bearing descendant should
     *   compute for itself). Abstract intermediates own no table too —
     *   they are never instantiated; their columns merge into the first
     *   concrete descendant's table.
     * - Rule 2 — a concrete subclass of a TABLE-OWNING ancestor that adds
     *   columns of its own but declares NO `#[Table]` is a build error:
     *   the columns have nowhere to go (sharing the ancestor's table with
     *   new columns is STI, which needs a discriminator). The error names
     *   the exits: declare `#[Table]` for multi-table inheritance, model
     *   the link with composition, or make the subclass behavior-only.
     * - Rule 3 (MTI) — a concrete subclass of a table-owning ancestor that
     *   declares its own `#[Table]` is a multi-table-inheritance child:
     *   the child table holds the child's own columns, the ancestor's
     *   table keeps the inherited ones, and the tables link through the
     *   shared primary key ({@see MetadataFactory::deriveMtiChildKey()}
     *   derives the key; the schema layer emits the FK). The returned
     *   parent model drives the joined read path and the split write path.
     *   A behavior-only subclass (no own columns) declaring `#[Table]`
     *   stays a build error — a second table with nothing in it is a
     *   mis-modeling, not an inheritance strategy.
     * - Rule 1 — a behavior-only subclass of a table-owning ancestor
     *   (inherits columns, adds none, declares no `#[Table]`) is provably
     *   interchangeable with its ancestor and simply inherits its table.
     * - Rule 5 — otherwise the snake-cased PLURAL of the short class name.
     *   This root convention is the ONLY silent naming rule.
     *
     * @param \ReflectionClass<Model> $reflection The leaf class.
     * @param class-string<Model> $class The leaf class name.
     * @param PropertyMapping[] $properties The merged mappings.
     * @return array{string|null, class-string<Model>|null} The resolved
     *         table name (null for a column-less class or an abstract
     *         class, rule 4) paired with the MTI parent model (null for
     *         every non-MTI class).
     * @throws \InvalidArgumentException On a rule 2 violation, an MTI
     *         mis-declaration, or an empty `#[Table]` name.
     */
    private static function resolveTableName(
        \ReflectionClass $reflection,
        string $class,
        array $properties,
    ): array {
        // Rule 4 — no columns anywhere in the chain, no table (abstract or
        // a deliberately concrete organizational base alike).
        if ($properties === []) {
            return [null, null];
        }

        // Rule 4 — abstract classes are never instantiated; their columns
        // belong to the first concrete descendant.
        if ($reflection->isAbstract()) {
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
     * Collect and validate the composite constraints from the class hierarchy.
     *
     * The chain, most-derived first — constraints are inherited the same
     * way columns are (an abstract base's `#[Unique]` constrains the
     * descendant's table). All validation is fail-fast at build:
     *
     * - every referenced column name must exist in `$properties` — a
     *   renamed property fails loudly here, not as a broken constraint in
     *   the database;
     * - `#[ForeignKey]`'s columns/referencesColumns must have matching
     *   arity (mirroring `Blueprint::foreignKey()`);
     * - the duplicate-declaration rule: a `#[Column]` single-column flag
     *   (unique/index/foreign) and a class-level attribute covering the
     *   SAME single column fail fast — the two mechanisms can never
     *   silently double-declare.
     *
     * @param \ReflectionClass<Model> $reflection The leaf class.
     * @param class-string<Model> $class The leaf class name.
     * @param PropertyMapping[] $properties The merged mappings.
     * @return array{list<Unique>, list<Index>, list<ForeignKey>}
     *         The uniques, indexes, and foreign keys, most-derived first.
     * @throws \InvalidArgumentException On unknown columns, arity
     *         mismatches, or duplicate declarations.
     */
    private static function collectConstraints(
        \ReflectionClass $reflection,
        string $class,
        array $properties,
    ): array {
        $uniques = [];
        $indexes = [];
        $foreignKeys = [];

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
        }

        return [$uniques, $indexes, $foreignKeys];
    }

    /**
     * Assert every constraint column name resolves to a declared column.
     *
     * @param list<string> $columns The constraint's column names.
     * @param PropertyMapping[] $properties The merged mappings.
     * @param class-string<Model> $class The leaf class name (for the message).
     * @param string $constraintKind The constraint kind (for the message).
     * @throws \InvalidArgumentException When a column name is unknown.
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
     * A flag (`unique:`/`index:`/`foreign:`) and a class-level attribute
     * covering the same single column is a duplicate declaration — fail
     * fast rather than silently double-declaring the constraint.
     *
     * @param list<string> $columns The attribute's column names.
     * @param PropertyMapping[] $properties The merged mappings.
     * @param class-string<Model> $class The leaf class name (for the message).
     * @param string $flag The flag name (`unique`, `index`, `foreign`).
     * @throws \InvalidArgumentException On a duplicate declaration.
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
    }

    /**
     * Whether a class (or any of its ancestors) uses the SoftDeletes trait.
     *
     * The trait can live at any level of the hierarchy, so the walk covers
     * the parent chain and every trait's own `use` list — the recursive
     * form of `class_uses()`.
     *
     * @param class-string<Model> $class The class to check (the walk reaches non-model ancestors).
     * @return bool True when SoftDeletes is used anywhere up the chain.
     */
    private static function usesSoftDeletes(string $class): bool
    {
        for ($current = $class; $current !== false; $current = get_parent_class($current)) {
            $traits = class_uses($current);

            if ($traits === false) {
                continue;
            }

            foreach ($traits as $trait) {
                if ($trait === SoftDeletes::class || self::usesSoftDeletesTrait($trait)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a trait (directly or via another trait) uses SoftDeletes.
     *
     * @param string $trait The trait to check.
     * @return bool True when SoftDeletes is reachable from the trait.
     */
    private static function usesSoftDeletesTrait(string $trait): bool
    {
        $traits = class_uses($trait);

        if ($traits === false) {
            return false;
        }

        foreach ($traits as $nested) {
            if ($nested === SoftDeletes::class || self::usesSoftDeletesTrait($nested)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk up the parent chain for the nearest resolved table name (rule 1)
     * — lazily, only when a behavior-only subclass actually needs it.
     *
     * No reflection here: ancestors resolve through `for()`'s cache, so
     * each ancestor's metadata is built at most once app-wide. Abstract
     * intermediates resolve to null (rule 4) and are stepped over.
     *
     * @param class-string<Model> $class The subclass whose ancestors to walk (ric — get_parent_class results are plain class-strings).
     * @return string|null The nearest ancestor's table name, or null when
     *         no ancestor owns a table.
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
     * name (`User` → `users`, `EmailVerificationToken` →
     * `email_verification_tokens`).
     *
     * Naive English pluralization only — irregulars (`Person` → `persons`,
     * not `people`) are not special-cased; declare `#[Table(name: ...)]`
     * for those.
     *
     * @param string $shortName The class's short name.
     * @return string The default table name.
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
     * The child declares NO key of its own — the shared PK IS the link
     * between the two tables. The parent's PK mapping is cloned per-class
     * (the cached parent metadata is never mutated) with `autoIncrement`
     * overridden to false: only the ROOT table generates the id; the child
     * receives it via the write path. The parent's PK property type drives
     * the child's hydration/encode — same id, same cast.
     *
     * The child's own-columns pass must NOT redeclare the key — a second
     * `id` on the child would mean two tables claiming the same column.
     * The factory applies the parent's PK as a synthetic child-side
     * mapping (owner = the child class, no property slot beyond the
     * parent's — the property IS inherited by PHP, so the child hydrates
     * it through the same ReflectionProperty).
     *
     * @param \ReflectionClass<Model> $reflection The child class.
     * @param class-string<Model> $class The child class name.
     * @param PropertyMapping[] $properties The child's merged mappings.
     * @param class-string<Model> $parentModel The MTI parent model.
     * @return PropertyMapping[] The merged mappings with the derived key
     *         injected (when the child does not shadow it).
     * @throws \InvalidArgumentException When the parent's key is not a
     *         single-column key, or the child redeclares an inherited
     *         column.
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

        $derived = clone $parentKeys[0];
        $derived->autoIncrement = false;

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
