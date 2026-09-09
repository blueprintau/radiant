<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction;
use BlueprintAU\Radiant\Metadata\MetadataFactory;

/**
 * Declares a model property as a database column.
 *
 * One attribute drives BOTH halves of the column's life: the model's PHP
 * behaviour (the cast between the typed property value and a bindable value,
 * {@see Column::decode()} / {@see Column::encode()}) and the DB schema
 * (the type, constraints, and FK actions the DDL is compiled from).
 *
 * The cast is **owned by the field type** — the PHP property type
 * ({@see Column::$propertyType}, captured by the {@see MetadataFactory}
 * from the `ReflectionProperty`) drives `decode()`/`encode()`, with the
 * column type used to disambiguate (`int` on an `int` column is identity;
 * `int` on a `timestamp` column is a Unix timestamp cast). Incompatible
 * combos fail fast at metadata build via
 * {@see Column::assertTypeCompatible()}, never silently mis-cast.
 *
 * The two-layer cast pipeline: `decode()`/`encode()` convert between the
 * **typed property value** and a **bindable value** (scalars +
 * `\DateTimeInterface` + JSON strings) — DB-agnostic field semantics. The
 * connection's `ValueCodecInterface` then converts bindable value ↔ driver
 * bytes — dialect semantics. The cast never sees the dialect; the codec
 * never sees the field type.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Column
{
    /**
     * Create a column declaration.
     *
     * @param ColumnType $type The logical column type — mapped to the
     *        dialect's native type by the schema grammar.
     * @param string|null $name The DB column name; defaults to the property
     *        name when null.
     * @param bool $primaryKey Whether this column is (part of) the primary key.
     * @param bool $autoIncrement Whether the column auto-increments.
     * @param bool $nullable Whether the column allows null.
     * @param bool $unique Whether the column has a unique constraint.
     * @param bool $index Whether the column has a plain index.
     * @param int|null $length The column length (required for
     *        {@see ColumnType::String}).
     * @param mixed $default The column default.
     * @param string|null $foreign A foreign-key reference: `table.column`,
     *        a bare `table` (references its `id`), or a model class-string
     *        (resolved to its table + primary key through the
     *        {@see \BlueprintAU\Radiant\Attributes\ReferenceResolver} — the
     *        same convention as {@see ForeignKey}).
     * @param ForeignKeyAction|string|null $onDelete The FK ON DELETE action —
     *        validated via {@see ForeignKeyAction::fromChecked()} at the DDL
     *        boundary, so no raw string reaches compiled DDL.
     * @param ForeignKeyAction|string|null $onUpdate The FK ON UPDATE action.
     */
    final public function __construct(
        public ColumnType $type,
        public ?string $name = null,
        public bool $primaryKey = false,
        public bool $autoIncrement = false,
        public bool $nullable = false,
        public bool $unique = false,
        public bool $index = false,
        public mixed $default = null,
        public ?int $length = null,
        public ?string $foreign = null,
        public ForeignKeyAction|string|null $onDelete = null,
        public ForeignKeyAction|string|null $onUpdate = null,
    ) {
    }

    /**
     * The PHP property type name (set by {@see MetadataFactory}).
     *
     * Captured from the `ReflectionProperty` as a plain string — the cast
     * pipeline matches on names (`'Carbon\Carbon'`, `'int'`, `'array'`),
     * so storing the name where it is consumed spares every consumer a
     * null-check + `getName()` dance. Union/intersection types on a column
     * property are a metadata build error, so this is always a single named
     * type (or null for an untyped property).
     *
     * @var string|null
     */
    public ?string $propertyType = null;

    // ---- Type compatibility (fail-fast at metadata build) ----

    /**
     * Assert the column type can store the field type — and that the
     * column carries everything the field needs.
     *
     * The compatibility matrix (field type × column type). Anything not
     * listed is incompatible: an `array` on an `int` column would decode
     * garbage; an untyped property has no cast contract at all.
     *
     * @param string|null $propertyType The PHP property type name (null =
     *        untyped — always rejected for a column).
     * @param class-string $class The model class (for the message).
     * @param string $property The property name (for the message).
     * @return void
     * @throws \InvalidArgumentException When the combination cannot round-trip.
     */
    public function assertTypeCompatible(?string $propertyType, string $class, string $property): void
    {
        if ($propertyType === null) {
            throw new \InvalidArgumentException(
                "Model [{$class}] property [{$property}] is untyped; a #[Column] property "
                . 'must declare a single named type so the cast pipeline has a contract.'
            );
        }

        $compatible = self::typeCompatibility()[$propertyType] ?? [];

        if (in_array($this->type, $compatible, true)) {
            if ($this->type === ColumnType::String && $this->length === null) {
                throw new \InvalidArgumentException(
                    "Model [{$class}] property [{$property}] declares a string "
                    . 'column without a length; declare `length:` (mirroring the schema '
                    . 'layer, where a string column requires one).'
                );
            }

            return;
        }

        throw new \InvalidArgumentException(sprintf(
            "Model [%s] property [%s] declares a [%s] column, which cannot store the "
                . "field type [%s]. Compatible column types for [%s]: %s.",
            $class,
            $property,
            $this->type->value,
            $propertyType,
            $propertyType,
            $compatible === [] ? 'none' : implode(', ', array_map(fn (ColumnType $t) => $t->value, $compatible)),
        ));
    }

    /**
     * The field-type → compatible-column-types matrix.
     *
     * A single source of truth consumed by {@see Column::assertTypeCompatible()}.
     * A property typed as ANY `\DateTimeInterface` implementation (a custom
     * subclass of Carbon, for example) is checked via `is_a()` at build time,
     * so only the four concrete names appear here — everything else
     * DateTimeInterface-shaped resolves to the same two column types.
     *
     * @return array<string, list<ColumnType>>
     */
    private static function typeCompatibility(): array
    {
        static $dateTimeTypes = [ColumnType::DateTime, ColumnType::Timestamp];

        return [
            'int' => [ColumnType::Int, ColumnType::BigInt, ColumnType::Timestamp],
            'float' => [ColumnType::Float],
            'string' => [ColumnType::String, ColumnType::DateTime, ColumnType::Timestamp, ColumnType::Json],
            'bool' => [ColumnType::Boolean],
            'array' => [ColumnType::Json],
            'Carbon\Carbon' => $dateTimeTypes,
            'Carbon\CarbonImmutable' => $dateTimeTypes,
            'DateTime' => $dateTimeTypes,
            'DateTimeImmutable' => $dateTimeTypes,
        ];
    }

    // ---- Casting (owned by the field type) ----

    /**
     * Bindable value → typed property value (read path; DB-agnostic).
     *
     * Driven by the PHP property type; the column type disambiguates and
     * validates. Null passes through — a null column is always a null
     * property. Any `\DateTimeInterface`-typed property (Carbon, DateTime,
     * DateTimeImmutable, or a custom subclass) parses through Carbon —
     * the model layer re-bases to the property's concrete class when the
     * two differ.
     *
     * @param mixed $value The bindable value from the driver.
     * @return mixed The typed property value.
     */
    public function decode(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (
            $this->propertyType !== null
            && !in_array($this->propertyType, ['int', 'float', 'bool', 'string', 'array'], true)
            && is_a($this->propertyType, \DateTimeInterface::class, true)
        ) {
            // Any DateTimeInterface implementation: Carbon::parse returns
            // a Carbon, which IS a DateTimeInterface — the model layer
            // re-bases when the property's concrete class differs.
            return \Carbon\Carbon::parse($value);
        }

        return match ($this->propertyType) {
            'int' => $this->type === ColumnType::Timestamp ? strtotime((string) $value) : (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'array' => json_decode((string) $value, true),
            default => $value,
        };
    }

    /**
     * Typed property value → bindable value (write path; DB-agnostic).
     *
     * `\DateTimeInterface` passes through untouched — the connection's
     * codec formats it at bind time (dialect precision + timezone). Arrays
     * are encoded to JSON strings (they are not directly bindable). Scalars
     * pass through — the typed property already holds the right scalar.
     *
     * @param mixed $value The typed property value.
     * @return mixed The bindable value.
     */
    public function encode(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        return match ($this->propertyType) {
            'array' => json_encode($value),
            default => $value,
        };
    }
}
