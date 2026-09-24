<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction;

/**
 * Declares a model property as a database column.
 *
 * One attribute drives both halves of the column's life: the model's PHP
 * behaviour (the cast between the typed property value and a bindable value,
 * {@see Column::decode()} / {@see Column::encode()}) and the DB schema
 * (the type, constraints, and FK actions the DDL is compiled from). The
 * cast is owned by the field type — the PHP property type drives
 * `decode()`/`encode()`, with the column type used to disambiguate.
 * Incompatible combos fail fast at metadata build via
 * {@see Column::assertTypeCompatible()}.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Column
{
    /**
     * Create a column declaration.
     *
     * @param  ColumnType  $type
     * @param  string|null  $name
     * @param  bool  $primaryKey
     * @param  bool  $autoIncrement
     * @param  bool  $nullable
     * @param  bool  $unique
     * @param  bool  $index
     * @param  int|null  $length
     * @param  mixed  $default
     * @param  string|null  $foreign
     * @param  ForeignKeyAction|string|null  $onDelete
     * @param  ForeignKeyAction|string|null  $onUpdate
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


    // ---- Type compatibility (fail-fast at metadata build) ----

    /**
     * Assert the column type can store the field type — and that the
     * column carries everything the field needs.
     *
     * @param  string|null  $propertyType
     * @param  class-string<\BlueprintAU\Radiant\Model>  $class
     * @param  string  $property
     * @return void
     * @throws \InvalidArgumentException
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
     * Assert the PHP property default does not silently shadow the column
     * default.
     *
     * A property with a PHP default is always initialized after `new`, so
     * its value is INSERTed explicitly and the column default is never
     * reached — when the two differ, the schema and the model's inserts
     * disagree silently. Comparison is strict (`===`); a `null` attribute
     * default means "no declared default" and never conflicts.
     *
     * @param  \ReflectionProperty  $property
     * @param  class-string<\BlueprintAU\Radiant\Model>  $class
     * @return void
     * @throws \InvalidArgumentException
     */
    public function assertDefaultConsistent(\ReflectionProperty $property, string $class): void
    {
        if (!$property->hasDefaultValue()) {
            return; // uninitialized after `new` — the DB default applies
        }

        if ($this->default === null) {
            return; // no declared column default — nothing to shadow
        }

        $phpDefault = $property->getDefaultValue();

        if ($phpDefault === $this->default) {
            return; // redundant but harmless — the two agree
        }

        throw new \InvalidArgumentException(sprintf(
            "Model [%s] property [%s] declares a PHP default [%s] that differs from the "
                . "declared column default [%s]. A property with a PHP default is always "
                . "initialized after `new`, so its value is INSERTed explicitly and the "
                . "column default is never reached — the two silently diverge for raw SQL "
                . "and other clients. Either drop the PHP default (letting the column "
                . "default apply), align it with the column default, or drop the column "
                . "default.",
            $class,
            $property->getName(),
            var_export($phpDefault, true),
            $this->default instanceof \BlueprintAU\Radiant\Database\Query\Expression
                ? $this->default->value
                : var_export($this->default, true),
        ));
    }

    /**
     * The field-type → compatible-column-types matrix.
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
     * validates. Null passes through.
     *
     * @param  mixed  $value
     * @param  string|null  $propertyType
     * @return mixed
     */
    public function decode(mixed $value, ?string $propertyType = null): mixed
    {
        if ($value === null) {
            return null;
        }

        if (
            $propertyType !== null
            && !in_array($propertyType, ['int', 'float', 'bool', 'string', 'array'], true)
            && is_a($propertyType, \DateTimeInterface::class, true)
        ) {
            // Any DateTimeInterface implementation: Carbon::parse returns
            // a Carbon, which IS a DateTimeInterface — the model layer
            // re-bases when the property's concrete class differs. Parse
            // failures (corrupt cells, legacy zero-dates like
            // '0000-00-00', garbage) fail LOUDLY with the column named —
            // an un-actionable Carbon exception from deep inside hydration
            // violates the fail-fast contract.
            try {
                return \Carbon\Carbon::parse($value);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Column [' . ($this->name ?? $propertyType) . '] could not decode the value ['
                    . (is_scalar($value) ? var_export($value, true) : get_debug_type($value))
                    . '] as a datetime: ' . $e->getMessage(),
                    0,
                    $e,
                );
            }
        }

        return match ($propertyType) {
            'int' => $this->type === ColumnType::Timestamp ? strtotime((string) $value) : (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'array' => $this->decodeJson($value),
            default => $value,
        };
    }

    /**
     * Decode a JSON column cell — strict, with the failure named.
     *
     * @param  mixed  $value
     * @return mixed
     * @throws \RuntimeException
     */
    private function decodeJson(mixed $value): mixed
    {
        $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Typed property value → bindable value (write path; DB-agnostic).
     *
     * `\DateTimeInterface` passes through untouched — the connection's
     * codec formats it at bind time.
     *
     * @param  mixed  $value
     * @param  string|null  $propertyType
     * @return mixed
     */
    public function encode(mixed $value, ?string $propertyType = null): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value;
        }

        return match ($propertyType) {
            'array' => $this->encodeJson($value),
            default => $value,
        };
    }

    /**
     * Encode a JSON column value — idempotent on already-encoded input.
     *
     * @param  mixed  $value
     * @return string
     * @throws \JsonException
     */
    private function encodeJson(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
