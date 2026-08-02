<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Exceptions;

use RuntimeException;

/**
 * Thrown when a dependent generator asks for a model that the model phase never
 * produced. Failing here is the entire point: the alternative is emitting
 * `use App\Models\User;` as a guess and shipping a broken application.
 */
class ModelNotRegisteredException extends RuntimeException
{
    public static function for(string $table, ?string $schema = null): self
    {
        $target = $schema !== null && $schema !== '' ? $schema.'.'.$table : $table;

        return new self(sprintf(
            'No generated model is registered for table [%s]. Generate models first '
                .'(php artisan anvil:generate --models --schema=%s), then re-run this command. '
                .'Dependent classes are never emitted with a guessed model namespace.',
            $target,
            $schema !== null && $schema !== '' ? $schema : 'all',
        ));
    }
}
