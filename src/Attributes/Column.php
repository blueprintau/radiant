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
     * @param  int|null  $precision  Fractional-seconds digits (1–6) or null (store whole seconds) for datetime columns; total digits (1–65) for decimal columns.
     * @param  int|null  $scale  Fractional digits for a decimal column (0–`precision`).
     * @param  list<string>|class-string<\UnitEnum>|null  $values  The allowed values for an enum column — a literal list, or an enum class-string resolved to its cases at metadata build (the column always matches the enum; migrations keep literal lists so schema history stays reproducible).
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
        public ?int $precision = null,
        public ?int $scale = null,
        public array|string|null $values = null,
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

        if ($this->primaryKey && !$this->type->primaryKeyCapable()) {
            throw new \InvalidArgumentException(
                "Model [{$class}] property [{$property}] declares a [{$this->type->value}] "
                . 'column as the primary key; a primary key requires an integer, string, char, '
                . 'or uuid type.'
            );
        }

        $compatible = self::typeCompatibility()[$propertyType]
            ?? $this->enumCompatibility($propertyType)
            ?? $this->objectCompatibility($propertyType)
            ?? [];

        if (in_array($this->type, $compatible, true)) {
            if ($this->type === ColumnType::String && $this->length === null) {
                throw new \InvalidArgumentException(
                    "Model [{$class}] property [{$property}] declares a string "
                    . 'column without a length; declare `length:` (mirroring the schema '
                    . 'layer, where a string column requires one).'
                );
            }

            // An enum column's storage length defaults to its longest
            // value — mirroring Blueprint::enum(). A class-string values
            // source resolves to its case values (backed) or names (unit)
            // at metadata build, so the column always matches the enum.
            if ($this->type === ColumnType::Enum) {
                $values = $this->resolvedEnumValues();

                if ($values === []) {
                    throw new \InvalidArgumentException(
                        "Model [{$class}] property [{$property}] declares an enum "
                        . 'column without values; declare `values:` with the allowed strings '
                        . 'or an enum class-string.'
                    );
                }

                $this->length ??= max(array_map(strlen(...), $values));
            }

            $this->assertPrecisionCompatible($propertyType, $class, $property);

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
     * The compatible column types for a PHP enum property type — an
     * int-backed enum stores in an integer column, a string-backed or
     * unit enum in a string-family column.
     *
     * @param  string  $propertyType
     * @return list<ColumnType>|null Null when the type is not an enum.
     */
    private function enumCompatibility(string $propertyType): array|null
    {
        if (!enum_exists($propertyType)) {
            return null;
        }

        if (is_a($propertyType, \BackedEnum::class, true)) {
            $backing = $propertyType::cases()[0]->value;

            return is_int($backing)
                ? [ColumnType::Int, ColumnType::BigInt]
                : [ColumnType::String, ColumnType::Char, ColumnType::Enum];
        }

        return [ColumnType::String, ColumnType::Char, ColumnType::Enum];
    }

    /**
     * The compatible column types for an object property type — a
     * JsonStorable class-string stores in a Json column.
     *
     * @param  string  $propertyType
     * @return list<ColumnType>|null Null when the type is not a class.
     */
    private function objectCompatibility(string $propertyType): array|null
    {
        if (!class_exists($propertyType) || interface_exists($propertyType)) {
            return null;
        }

        if (!is_a($propertyType, \BlueprintAU\Radiant\Database\Query\JsonStorable::class, true)) {
            return null;
        }

        return [ColumnType::Json];
    }

    /**
     * The enum column's allowed values, resolved from the declared
     * source — a literal list passes through; an enum class-string
     * resolves to its case values (backed) or case names (unit).
     *
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    public function resolvedEnumValues(): array
    {
        if (is_string($this->values)) {
            if (!enum_exists($this->values)) {
                throw new \InvalidArgumentException(
                    "The enum column values source [{$this->values}] is not an enum class-string."
                );
            }

            $cases = $this->values::cases();

            if ($cases === []) {
                throw new \InvalidArgumentException(
                    "The enum [{$this->values}] declares no cases; an enum column needs at least one value."
                );
            }

            return array_map(
                fn (\UnitEnum $case): string => $case instanceof \BackedEnum ? (string) $case->value : $case->name,
                $cases,
            );
        }

        return $this->values ?? [];
    }

    /**
     * Assert a declared fractional-seconds precision is usable.
     *
     * Unix timestamps are whole seconds, so precision is meaningless on an
     * int-typed Timestamp column; no portable dialect stores more than 6
     * fractional digits.
     *
     * @param  string|null  $propertyType
     * @param  class-string<\BlueprintAU\Radiant\Model>  $class
     * @param  string  $property
     * @return void
     * @throws \InvalidArgumentException
     */
    private function assertPrecisionCompatible(?string $propertyType, string $class, string $property): void
    {
        if ($this->precision === null) {
            return;
        }

        if ($this->precision < 1 || $this->precision > 6) {
            throw new \InvalidArgumentException(
                "Model [{$class}] property [{$property}] declares datetime precision "
                . "[{$this->precision}], which is out of range; use null for whole seconds "
                . 'or an integer between 1 and 6 for fractional seconds.'
            );
        }

        if ($this->type === ColumnType::Timestamp && $propertyType === 'int') {
            throw new \InvalidArgumentException(
                "Model [{$class}] property [{$property}] declares precision on an int "
                . 'Unix-timestamp column; Unix timestamps are whole seconds, so fractional '
                . 'precision is meaningless there. Use a datetime column with a '
                . 'DateTimeInterface-typed property to store fractional seconds.'
            );
        }
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

        /** @var array<string, list<ColumnType>> */
        static $matrix = [
            'int' => [ColumnType::Int, ColumnType::BigInt, ColumnType::Timestamp],
            'float' => [ColumnType::Float],
            'string' => [
                ColumnType::String,
                ColumnType::Char,
                ColumnType::Text,
                ColumnType::Decimal,
                ColumnType::Date,
                ColumnType::DateTime,
                ColumnType::Timestamp,
                ColumnType::Json,
                ColumnType::Enum,
                ColumnType::Binary,
                ColumnType::Uuid,
            ],
            'bool' => [ColumnType::Boolean],
            'array' => [ColumnType::Json],
            'Carbon\Carbon' => [...$dateTimeTypes, ColumnType::Date],
            'Carbon\CarbonImmutable' => [...$dateTimeTypes, ColumnType::Date],
            'DateTime' => [...$dateTimeTypes, ColumnType::Date],
            'DateTimeImmutable' => [...$dateTimeTypes, ColumnType::Date],
        ];

        return $matrix;
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

        if ($propertyType !== null && $this->isEnumPropertyType($propertyType)) {
            return $this->decodeEnum($value, $propertyType);
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
            default => $this->type === ColumnType::Json && $propertyType !== null && class_exists($propertyType)
                ? $this->decodeJsonObject($value, $propertyType)
                : $this->decodeString($value),
        };
    }

    /**
     * Decode a string-typed property value — the column type
     * disambiguates the stored form.
     *
     * A Date column stores `Y-m-d`; decoding re-parses it so a corrupt
     * cell fails loudly with the column named (mirroring the datetime
     * path). Other string-family columns pass through untouched — the
     * DB's string IS the property's string.
     *
     * @param  mixed  $value
     * @return mixed
     * @throws \RuntimeException
     */
    private function decodeString(mixed $value): mixed
    {
        if ($this->type !== ColumnType::Date || !is_string($value)) {
            return $value;
        }

        try {
            return \Carbon\Carbon::parse($value)->startOfDay();
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Column [' . ($this->name ?? 'date') . '] could not decode the value ['
                . var_export($value, true) . '] as a date: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Encode a Json column cell — strict, with the failure named.
     *
     * An array encodes directly; a JsonSerializable object encodes via
     * jsonSerialize(); an already-encoded string passes through.
     *
     * @param  mixed  $value
     * @param  string|null  $propertyType
     * @return string
     * @throws \RuntimeException
     */
    private function encodeJson(mixed $value, ?string $propertyType = null): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($propertyType !== null && class_exists($propertyType) && !$value instanceof \JsonSerializable) {
            throw new \InvalidArgumentException(
                'Column [' . ($this->name ?? $propertyType) . '] expects a '
                    . $propertyType . ' value; got ' . get_debug_type($value) . '.'
            );
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'Column [' . ($this->name ?? $propertyType) . '] could not encode the value ['
                    . get_debug_type($value) . '] as JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Encode an object value for a Json column — the value must be an
     * instance of the property's class.
     *
     * @param  mixed  $value
     * @param  string  $propertyType
     * @return string
     * @throws \InvalidArgumentException
     */
    private function encodeJsonObject(mixed $value, string $propertyType): string
    {
        // Idempotent on already-encoded input — the builder's write path
        // re-encodes values that getColumnValues() already encoded (the
        // same trade encodeJson() makes: an encoded string cell is
        // indistinguishable from a raw one).
        if (is_string($value)) {
            return $value;
        }

        if (!$value instanceof $propertyType) {
            throw new \InvalidArgumentException(
                'Column [' . ($this->name ?? $propertyType) . '] expects a '
                    . $propertyType . ' value; got ' . get_debug_type($value) . '.'
            );
        }

        return $this->encodeJson($value, $propertyType);
    }

    /**
     * Decode a JSON column cell — strict, with the failure named.
     *
     * @param  mixed  $value
     * @param  bool  $associative  True returns arrays for objects; false returns stdClass instances.
     * @return mixed
     * @throws \RuntimeException
     */
    private function decodeJson(mixed $value, bool $associative = true): mixed
    {
        try {
            return json_decode((string) $value, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(
                'Column [' . ($this->name ?? 'json') . '] could not decode the value ['
                    . var_export($value, true) . '] as JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Decode a JSON column cell to the property's JsonStorable type.
     *
     * @param  mixed  $value
     * @param  string  $propertyType
     * @return object
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    private function decodeJsonObject(mixed $value, string $propertyType): object
    {
        if (!is_a($propertyType, \BlueprintAU\Radiant\Database\Query\JsonStorable::class, true)) {
            throw new \InvalidArgumentException(
                'Column [' . ($this->name ?? $propertyType) . '] declares the object type ['
                    . $propertyType . '], which does not implement JsonStorable.'
            );
        }

        // The shape check rides the same decode the hydration uses: a JSON
        // object yields stdClass, a JSON array yields a list.
        $payload = $this->decodeJson($value, associative: false);

        if (!$payload instanceof \stdClass) {
            throw new \RuntimeException(
                'Column [' . ($this->name ?? $propertyType) . '] holds the value ['
                    . var_export($value, true) . '], which does not decode to a JSON object.'
            );
        }

        return $propertyType::jsonDeserialize($payload);
    }

    /**
     * Typed property value → bindable value (write path; DB-agnostic).
     *
     * A column with declared precision formats its own datetime string
     * (UTC, exactly `$precision` fractional digits) — the codec has no
     * per-column knowledge, so a `datetime(3)` column would otherwise
     * receive a second-precision string and lose its milliseconds.
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
            if ($this->type === ColumnType::Date) {
                // A date column stores the calendar day — UTC midnight,
                // `Y-m-d`, no time component.
                return \DateTimeImmutable::createFromInterface($value)
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d');
            }

            if ($this->precision !== null) {
                return $this->encodePrecisionDatetime($value);
            }

            return $value;
        }

        if ($propertyType !== null && $this->isEnumPropertyType($propertyType)) {
            return $this->encodeEnum($value, $propertyType);
        }

        if ($propertyType !== null && $this->type === ColumnType::Json && class_exists($propertyType)) {
            return $this->encodeJsonObject($value, $propertyType);
        }

        if ($this->type === ColumnType::Uuid && is_string($value)) {
            $this->assertUuid($value);
        }

        return match ($propertyType) {
            'array' => $this->encodeJson($value),
            default => $value,
        };
    }

    /**
     * Whether a property type is a PHP enum class-string.
     *
     * @param  string  $propertyType
     * @return bool
     */
    private function isEnumPropertyType(string $propertyType): bool
    {
        return enum_exists($propertyType);
    }

    /**
     * Encode a PHP enum value to its storable form — a backed enum's
     * backing value, a unit enum's case name.
     *
     * @param  mixed  $value
     * @param  string  $propertyType
     * @return int|string
     * @throws \InvalidArgumentException
     */
    private function encodeEnum(mixed $value, string $propertyType): int|string
    {
        // Idempotent on already-encoded input — the builder's write path
        // re-encodes values that getColumnValues() already encoded (the
        // same trade encodeJson() makes: an encoded string cell is
        // indistinguishable from a raw one).
        if (is_a($propertyType, \BackedEnum::class, true)) {
            if ($value instanceof \BackedEnum) {
                return $value->value;
            }

            if (is_string($value) || is_int($value)) {
                if ($propertyType::tryFrom($value) !== null) {
                    return $value;
                }
            }
        } else {
            if ($value instanceof \UnitEnum) {
                return $value->name;
            }

            if (is_string($value)) {
                foreach ($propertyType::cases() as $case) {
                    if ($case->name === $value) {
                        return $value;
                    }
                }
            }
        }

        throw new \InvalidArgumentException(
            'Column [' . ($this->name ?? $propertyType) . '] expects an enum value of type ['
            . $propertyType . ']; got ' . get_debug_type($value) . '.'
        );
    }

    /**
     * Decode a stored value back to its enum case — fail-fast with the
     * column named when the stored value matches no case.
     *
     * @param  mixed  $value
     * @param  string  $propertyType
     * @return \BackedEnum|\UnitEnum
     * @throws \InvalidArgumentException
     */
    private function decodeEnum(mixed $value, string $propertyType): \BackedEnum|\UnitEnum
    {
        if (is_a($propertyType, \BackedEnum::class, true)) {
            $case = $propertyType::tryFrom($value);

            if ($case === null) {
                throw new \InvalidArgumentException(
                    'Column [' . ($this->name ?? $propertyType) . '] holds the value ['
                    . (is_scalar($value) ? var_export($value, true) : get_debug_type($value))
                    . '], which is not a case of the enum [' . $propertyType . '].'
                );
            }

            return $case;
        }

        foreach ($propertyType::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(
            'Column [' . ($this->name ?? $propertyType) . '] holds the value ['
            . (is_scalar($value) ? var_export($value, true) : get_debug_type($value))
            . '], which is not a case of the enum [' . $propertyType . '].'
        );
    }

    /**
     * Assert a value is a well-formed RFC 4122 UUID.
     *
     * @param  string  $value
     * @return void
     * @throws \InvalidArgumentException
     */
    private function assertUuid(string $value): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new \InvalidArgumentException(
                'Column [' . ($this->name ?? 'uuid') . '] requires a valid RFC 4122 UUID; got [' . $value . '].'
            );
        }
    }

    /**
     * Format a datetime to the column's declared precision — UTC, exactly
     * `$precision` fractional digits.
     *
     * @param  \DateTimeInterface  $value
     * @return string
     */
    private function encodePrecisionDatetime(\DateTimeInterface $value): string
    {
        $utc = \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'));

        // One format pass — 'Y-m-d H:i:s.u' is fixed-width (26 chars: 19
        // date chars + the dot + 6 fraction digits), so truncating to
        // 20 + precision keeps the fraction exact and never cuts the date.
        return substr($utc->format('Y-m-d H:i:s.u'), 0, 20 + ($this->precision ?? 6));
    }
}
