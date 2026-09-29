<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

use BlueprintAU\Radiant\Metadata\MetadataFactory;

/**
 * Resolves a foreign-key reference to its table name.
 *
 * A reference is EITHER a plain table name (`roles`) OR a model
 * class-string (`App\Models\Role`) whose table resolves through the
 * {@see MetadataFactory} — the same convention the model itself uses, so a
 * renamed table never breaks the FK silently. Shared by
 * {@see ForeignKey::resolvedReferences()} (class-level composite
 * constraints) and the `Blueprint` schema layer (single-column `foreign:`
 * column flags) — one resolution rule, one place.
 *
 * The two forms are disambiguated by the presence of a `\`: no valid SQL
 * table name contains a backslash, and every namespaced class-string does.
 * A backslash-free input that names an EXISTING class is rejected — PHP's
 * `::class` constant never emits a leading backslash, so a global-namespace
 * class-string (`DateTimeImmutable::class`) would otherwise slip through
 * the gate and masquerade as a table name.
 *
 * @phpstan-type ForeignKeyReference class-string<\BlueprintAU\Radiant\Model>|string
 */
final class ReferenceResolver
{
    /**
     * Resolve a reference to its table name.
     *
     * @param  ForeignKeyReference  $reference
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function resolve(string $reference): string
    {
        if (!str_contains($reference, '\\')) {
            // A backslash-free input is a table name — unless it names an
            // existing class. PHP's `::class` constant has NO leading
            // backslash, so a global-namespace class-string would land here
            // and masquerade as a table (the FK would compile against a
            // nonexistent table and fail downstream with a confusing SQL
            // error). Reject it here, where the message can say why.
            if (class_exists($reference) && !is_a($reference, \BlueprintAU\Radiant\Model::class, true)) {
                throw new \InvalidArgumentException(
                    "A foreign key references [{$reference}], which is a class but not a "
                    . 'Radiant model; a foreign key must reference a table name or a '
                    . 'model class-string.'
                );
            }

            return $reference;
        }

        if (!class_exists($reference)) {
            throw new \InvalidArgumentException(
                "A foreign key references [{$reference}], which is neither a table name "
                . 'nor an existing model class-string.'
            );
        }

        if (!is_a($reference, \BlueprintAU\Radiant\Model::class, true)) {
            throw new \InvalidArgumentException(
                "A foreign key references [{$reference}], which is a class but not a "
                . 'Radiant model; a foreign key must reference a table name or a '
                . 'model class-string.'
            );
        }

        $table = MetadataFactory::for($reference)->tableName;

        if ($table === null) {
            throw new \InvalidArgumentException(
                "A foreign key references model [{$reference}], which owns no table "
                . '(no columns); a foreign key must reference a table-owning model or a '
                . 'plain table name.'
            );
        }

        return $table;
    }
}
