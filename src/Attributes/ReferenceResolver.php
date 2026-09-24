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
 * table name contains a backslash, and every class-string does.
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
            return $reference;
        }

        if (!class_exists($reference)) {
            throw new \InvalidArgumentException(
                "A foreign key references [{$reference}], which looks like a model "
                . 'class-string but does not exist.'
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
