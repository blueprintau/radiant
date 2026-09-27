<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;

/**
 * One trait-declared query scope condition.
 *
 * Collected from `#[ModelScope]`-annotated trait methods; a trait's
 * conditions group in ONE nested where group marked with the trait, so
 * an opt-out strips the trait's whole scope atomically. The boolean
 * joins a condition to the PREVIOUS one within that group (the first
 * condition's boolean is ignored); between traits the groups always
 * AND — each trait's scope is a hard filter, never an OR escape.
 */
final class ScopeCondition
{
    /**
     * Create a scope condition.
     *
     * @param  string  $column  The DB column name (validated at metadata build).
     * @param  WhereOperator  $operator  The operator (Null/NotNull/eq-style).
     * @param  mixed  $value  The comparison value; encoded through the column's cast at apply time.
     * @param  WhereBoolean  $boolean  How this condition joins the previous one in the same trait's group.
     */
    final public function __construct(
        public readonly string $column,
        public readonly WhereOperator $operator,
        public readonly mixed $value = null,
        public readonly WhereBoolean $boolean = WhereBoolean::And,
    ) {
    }
}
