<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\PHPStan\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\HasMany;

/**
 * A static-analysis fixture: the relation method declares the CONCRETE
 * generic (`HasMany<TypeFlowPost>`) and the model pins a named connection
 * seam. Analyzers (PHPStan + Intelephense) resolve `User::posts()`'s
 * element type through this declaration alone — no @var at the call site.
 */
class TypeFlowUser extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The user's name.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $name;

    /**
     * The user's posts — the generic payload under test.
     *
     * @return HasMany<TypeFlowPost>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(TypeFlowPost::class, 'user_id');
    }
}
