<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Exceptions;

use RuntimeException;

/**
 * Thrown when a bare table name exists in more than one schema and the caller
 * did not say which one it meant. Silently picking the first match is how a
 * cross-schema build ends up wired to the wrong model.
 */
class AmbiguousModelException extends RuntimeException
{
    /**
     * @param  array<int, string>  $candidates  FQCNs that matched
     */
    public static function for(string $table, array $candidates): self
    {
        sort($candidates);

        return new self(sprintf(
            'Table [%s] exists in more than one schema and resolves to %d models (%s). '
                .'Pass the owning schema when resolving the model for this table.',
            $table,
            count($candidates),
            implode(', ', $candidates),
        ));
    }
}
