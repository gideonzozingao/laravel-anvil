<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Single source of truth for the two "you cannot call it that" rules that every
 * generator has to obey:
 *
 *   1. PHP reserved words are not legal namespace segments, so a schema named
 *      "public" cannot become App\Models\Public\Tenant.
 *   2. Eloquent\Model and the SoftDeletes trait already declare real methods
 *      (created, updated, deleted, save, delete, fresh, toArray, ...). A relation
 *      method sharing one of those names redeclares it with an incompatible
 *      signature and PHP fatals at class-load time — which is exactly how a
 *      created_by / updated_by / deleted_by foreign key blows up.
 *
 * Both rules previously lived as copy-pasted private statics in ModelBuilder and
 * ModelMetadata. They must not be allowed to drift: the model file and the
 * resource/OpenAPI generators derive names from different objects and have to
 * land on the same string.
 */
final class ReservedNames
{
    /**
     * PHP keywords and soft-reserved words that cannot appear as a bare
     * namespace segment. Deliberately broader than the strict keyword list —
     * some of these parse but confuse static analysers.
     *
     * @var list<string>
     */
    private const NAMESPACE_SEGMENTS = [
        'public',
        'private',
        'protected',
        'static',
        'class',
        'interface',
        'trait',
        'enum',
        'function',
        'const',
        'namespace',
        'use',
        'new',
        'return',
        'list',
        'array',
        'default',
        'match',
        'fn',
        'readonly',
        'never',
        'void',
        'null',
        'true',
        'false',
        'int',
        'float',
        'string',
        'bool',
        'object',
        'iterable',
        'callable',
        'mixed',
        'parent',
        'self',
        'exit',
        'echo',
        'print',
        'require',
        'include',
        'global',
        'goto',
        'switch',
        'case',
        'do',
        'for',
        'foreach',
        'while',
        'if',
        'else',
        'elseif',
        'try',
        'catch',
        'finally',
        'throw',
        'clone',
        'yield',
        'and',
        'or',
        'xor',
        'as',
        'instanceof',
        'insteadof',
        'abstract',
        'final',
        'extends',
        'implements',
        'var',
        'unset',
        'isset',
        'empty',
        'eval',
        'declare',
        'endif',
        'endfor',
        'endforeach',
        'endwhile',
        'endswitch',
    ];

    public static function isReservedNamespaceSegment(string $segment): bool
    {
        return in_array(strtolower($segment), self::NAMESPACE_SEGMENTS, true);
    }

    /**
     * Turn a schema name into a legal StudlyCase namespace segment, or null when
     * there is nothing to add.
     *
     *   "billing"      → "Billing"
     *   "members-db"   → "MembersDb"
     *   "public"       → "PublicSchema"   (reserved word)
     *   "2024_archive" → "Schema2024Archive"  (cannot start with a digit)
     *
     * Callers are responsible for deciding *whether* to qualify at all — pass
     * the default schema through isDefaultSchema() first.
     */
    public static function namespaceSegment(?string $schema): ?string
    {
        if ($schema === null || trim($schema) === '') {
            return null;
        }

        $segment = Str::studly(str_replace(['.', '-', ' '], '_', $schema));
        $segment = (string) preg_replace('/[^A-Za-z0-9_]/', '', $segment);

        if ($segment === '') {
            return null;
        }

        if (self::isReservedNamespaceSegment($segment)) {
            return $segment.'Schema';
        }

        // A namespace segment may not begin with a digit.
        if (preg_match('/^\d/', $segment) === 1) {
            return 'Schema'.$segment;
        }

        return $segment;
    }

    /**
     * True when $schema is the connection's default schema (public / dbo / the
     * MySQL database name) and therefore must NOT produce a namespace segment.
     * Null or empty on either side counts as "the default".
     */
    public static function isDefaultSchema(?string $schema, ?string $defaultSchema): bool
    {
        if ($schema === null || trim($schema) === '') {
            return true;
        }

        if ($defaultSchema === null || trim($defaultSchema) === '') {
            return false;
        }

        return strcasecmp(trim($schema), trim($defaultSchema)) === 0;
    }

    /**
     * Every method name already taken by the Eloquent base class and the
     * SoftDeletes trait, lowercased. Read via reflection rather than a hardcoded
     * list so a future framework addition is caught too, not just the collisions
     * we have already hit.
     *
     * @return list<string>
     */
    public static function modelMethods(): array
    {
        static $methods = null;

        if ($methods === null) {
            $methods = array_values(array_unique(array_map(
                strtolower(...),
                array_merge(
                    get_class_methods(Model::class),
                    get_class_methods(SoftDeletes::class),
                ),
            )));
        }

        return $methods;
    }

    /**
     * A relation method name guaranteed not to redeclare a base-class method.
     *
     *   "tenant"  → "tenant"
     *   "created" → "createdRelation"
     */
    public static function safeMethodName(string $name, string $suffix = 'Relation'): string
    {
        if ($name === '') {
            return $name;
        }

        return in_array(strtolower($name), self::modelMethods(), true)
            ? $name.$suffix
            : $name;
    }
}
