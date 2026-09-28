<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Morphs;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A model whose #[Morphs] keyType cannot hold a primary-key value — the
 * keyType-capability fail-fast path.
 */
#[Morphs(name: 'taggable', keyType: ColumnType::Json)]
class JsonKeyMorphs extends Model
{
}
