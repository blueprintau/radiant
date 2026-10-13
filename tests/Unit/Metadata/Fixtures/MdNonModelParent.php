<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\MorphDelete;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: #[MorphDelete] against a class that is not a
 * model.
 *
 * This file is excluded from PHPStan's analyseAndScan (phpstan.neon): the
 * attribute argument is deliberately NOT a model class-string, which the
 * attribute docblock's class-string<Model> contract rejects statically —
 * the runtime is_a gate in MetadataFactory::collectMorphDeletes() is the
 * real check this fixture exercises.
 */
#[MorphDelete('DateTimeImmutable', 'attachable')]
class MdNonModelParent extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;
}
