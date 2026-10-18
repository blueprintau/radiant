<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;

/**
 * A hand-authored callable data step — the sibling of the SQL DataStep
 * change ({@see SchemaChange} carrying `SchemaOperation::DataStep`).
 *
 * Where the SQL step carries its transform as readonly plan data
 * (printable, serializable, dry-run renders the statements), a callable
 * step carries it as runtime behavior: the plan holds only the
 * description and the destructiveness flag, and the dry run can show
 * nothing but the description — review happens at the definition site,
 * not the plan.
 *
 * Choose the callable form when the transform's intelligence lives in
 * PHP: class-string constants (morph aliases), parameter-bound writes,
 * conditionals, and veto gates as plain exceptions instead of dialect
 * forks (`RAISE` / `SIGNAL`). Choose the SQL step for pure row
 * transforms — the visible-by-default form stays the default.
 *
 * @see SchemaChange
 *
 * @phpstan-type Step \BlueprintAU\Radiant\Database\Schema\SchemaChange|DataStep
 */
final class DataStep
{
    /**
     * Create a callable data step.
     *
     * @param  string  $table  Display and lock-scoping label — cosmetic, as with the SQL step's dominant-table guess.
     * @param  string  $description  Required human description of effects — all a dry run can show for a callable step.
     * @param  bool  $destructive  Explicit — no default-true magic; the confirm gate reads this exactly as it reads {@see SchemaChange::$destructive}.
     * @param  \Closure(SqlConnection): void  $run  Invoked through the same apply dispatch as statement steps — inside the plan's lock
     *      and transaction discipline. Throws to veto: a throw aborts the plan with the identical rollback semantics a statement
     *      failure already has.
     */
    public function __construct(
        public readonly string $table,
        public readonly string $description,
        public readonly bool $destructive,
        public readonly \Closure $run,
    ) {}
}
