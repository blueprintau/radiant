<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Attributes\Table;

/**
 * A rule-3 mis-declaration: a behavior-only subclass of a table-owning
 * model that declares its own #[Table] — a second table holding none of
 * the columns is a mis-modeling, so this is the rule-3 build error.
 */
#[Table(name: 'admins')]
class TableRedeclaringAdmin extends User
{
}
