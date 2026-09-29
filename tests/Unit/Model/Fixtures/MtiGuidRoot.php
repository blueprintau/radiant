<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: the MTI Guid root — a caller-assigned string PK.
 */
class MtiGuidRoot extends Model
{
    /**
     * The caller-assigned UUID key (NOT auto-increment).
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 36, primaryKey: true)]
    public string $uuid;

    /**
     * The email.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $email;
}
