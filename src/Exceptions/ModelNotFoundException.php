<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Exceptions;

/**
 * Thrown by the fail-fast retrieval family (`firstOrFail()`, `findOrFail()`,
 * `sole()`) when a query that must produce a model row produces none.
 *
 * The exception carries the model class that was queried and, when the
 * caller looked up by key, that key — so the message identifies exactly
 * which lookup came up empty:
 *
 * - no key:   `No query results for model [App\Models\User].`
 * - scalar:   `No query results for model [App\Models\User] 42.`
 * - composite: `No query results for model [App\Models\Region] {"id":1,"country":"FR"}.`
 *
 * Composite keys render as the JSON encoding of the caller's column map
 * (order-preserving). Values pass through `json_encode` verbatim — keys
 * come from `whereKey()` validation, never from user SQL.
 *
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
final class ModelNotFoundException extends \RuntimeException
{
    /**
     * The class-string of the model that had no matching row.
     *
     * @var class-string<\BlueprintAU\Radiant\Model>
     */
    public readonly string $model;

    /**
     * The key used for the lookup: null when no key was involved, a scalar
     * or a composite column map otherwise.
     *
     * @var KeyValue
     */
    public readonly int|string|null|array $key;

    /**
     * @param  class-string<\BlueprintAU\Radiant\Model>  $modelClass
     * @param  KeyValue  $key  Null when no key was involved; a scalar or a column => value map otherwise.
     */
    public function __construct(string $modelClass, int|string|null|array $key = null)
    {
        $this->model = $modelClass;
        $this->key = $key;

        $message = 'No query results for model ['.$modelClass.']';

        if ($key !== null) {
            $message .= ' '.self::renderKey($key);
        }

        parent::__construct($message.'.');
    }

    /**
     * Renders the lookup key for the exception message.
     *
     * @param  KeyValue  $key
     * @return string
     */
    private static function renderKey(int|string|null|array $key): string
    {
        if (\is_array($key)) {
            return (string) json_encode($key);
        }

        return (string) $key;
    }
}
