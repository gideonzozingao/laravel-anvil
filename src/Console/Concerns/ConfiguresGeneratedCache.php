<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console\Concerns;

use Illuminate\Console\Command;
use Zuqongtech\LaravelAnvil\Runtime\Cache\CachePolicy;

/**
 * The --cache family of options, shared by anvil:generate-api,
 * anvil:generate-web and anvil:generate.
 *
 * Laravel builds a signature-based command's definition from the $signature
 * string and never calls getOptions(), so the flags themselves cannot be
 * injected from here. Paste SIGNATURE_BLOCK (below) into each command's
 * signature; this trait owns validation, config projection and reporting so
 * those three commands cannot drift apart on cache semantics.
 *
 * A command opts in with four calls:
 *
 *   if (($e = $this->validateCacheFlags()) !== null) { $this->error($e); return self::FAILURE; }
 *   $values = array_merge($values, $this->cacheRuntimeConfig());       // in applyRuntimeConfig()
 *   $payload = array_merge($payload, $this->cacheOptionPayload());     // in buildOptions()
 *   $rows = array_merge($rows, $this->cacheSummaryRows());             // in summarise()
 *
 * THE FLAGS TO PASTE
 *
 *   {--cache                 : Generate services that cache query results}
 *   {--no-cache              : Force caching off, overriding anvil.cache.enabled}
 *   {--cache-ttl=            : TTL seconds — "300" for every profile, or "single=300,list=60"}
 *   {--cache-store=          : Cache store to use (default: the app default store)}
 *   {--cache-scope=          : Result isolation: auth|tenant|none (default: auth)}
 *   {--cache-stale=          : Seconds a stale value may be served while refreshing; 0 disables}
 *   {--cache-jitter=         : TTL randomisation as a fraction, e.g. 0.1 for +/-10%}
 *   {--cache-profile=        : Default volatility profile for every model}
 *   {--cache-model=*         : Per-model override: "Category:reference", "PriceHistory:off", "Product:list=30"}
 *   {--cache-bypass          : Allow callers to request uncached reads (never in production)}
 *   {--etag                  : Emit ETag/If-None-Match handling and document 304 in the spec}
 *
 * @mixin Command
 */
trait ConfiguresGeneratedCache
{
    /** @var list<string> */
    private const CACHE_PROFILES = [
        CachePolicy::PROFILE_SINGLE,
        CachePolicy::PROFILE_LIST,
        CachePolicy::PROFILE_AGGREGATE,
        CachePolicy::PROFILE_REFERENCE,
    ];

    /** @var list<string> */
    private const CACHE_SCOPES = ['auth', 'tenant', 'none'];

    /**
     * A TTL nobody meant to type. Twelve hours of stale API responses is a
     * support ticket, not a configuration.
     */
    private const CACHE_TTL_CEILING = 86400;

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    /**
     * Reject bad cache flags before a single file is written.
     *
     * Returns the message to print, or null when everything checks out.
     */
    protected function validateCacheFlags(): ?string
    {
        if (! $this->hasCacheOptions()) {
            return null;
        }

        if ($this->boolOption('cache') && $this->boolOption('no-cache')) {
            return '--cache and --no-cache are mutually exclusive.';
        }

        // Every dependent flag is meaningless with caching off, and silently
        // ignoring them hides a typo'd invocation.
        if ($this->boolOption('no-cache')) {
            foreach (['cache-ttl', 'cache-store', 'cache-scope', 'cache-stale', 'cache-jitter', 'cache-profile'] as $flag) {
                if ($this->stringOption($flag) !== '') {
                    return "--{$flag} has no effect with --no-cache. Remove one of them.";
                }
            }

            if ($this->arrayOption('cache-model') !== []) {
                return '--cache-model has no effect with --no-cache. Remove one of them.';
            }
        }

        if (($error = $this->validateTtlSpec($this->stringOption('cache-ttl'), '--cache-ttl')) !== null) {
            return $error;
        }

        $scope = strtolower($this->stringOption('cache-scope'));

        if ($scope !== '' && ! in_array($scope, self::CACHE_SCOPES, true)) {
            return sprintf('Unknown --cache-scope "%s". Expected one of: %s.', $scope, implode(', ', self::CACHE_SCOPES));
        }

        // Worth being loud about: 'none' means one caller's rows are served to
        // every other caller. Correct for public read-only data, catastrophic
        // for anything filtered by ownership, tenancy or a policy.
        if ($scope === 'none') {
            $this->components->warn(
                '--cache-scope=none shares cached results across ALL callers. Only use it when every cached read is '
                    .'public and unfiltered — a policy-scoped or tenant-scoped listing will leak between users.'
            );
        }

        $profile = strtolower($this->stringOption('cache-profile'));

        if ($profile !== '' && ! in_array($profile, self::CACHE_PROFILES, true)) {
            return sprintf('Unknown --cache-profile "%s". Expected one of: %s.', $profile, implode(', ', self::CACHE_PROFILES));
        }

        $stale = $this->stringOption('cache-stale');

        if ($stale !== '' && (! ctype_digit((string) $stale) || (int) $stale > self::CACHE_TTL_CEILING)) {
            return sprintf('--cache-stale must be a non-negative integer no greater than %d.', self::CACHE_TTL_CEILING);
        }

        $jitter = $this->stringOption('cache-jitter');

        if ($jitter !== '') {
            if (! is_numeric($jitter) || (float) $jitter < 0.0 || (float) $jitter > 0.5) {
                return '--cache-jitter must be a number between 0 and 0.5 (a fraction of the TTL).';
            }
        }

        foreach ($this->arrayOption('cache-model') as $spec) {
            if (($error = $this->validateModelSpec((string) $spec)) !== null) {
                return $error;
            }
        }

        if ($this->boolOption('cache-bypass')) {
            $this->components->warn(
                '--cache-bypass lets a caller force every request past the cache. It stays disabled in production '
                    .'unless anvil.cache.allow_bypass_in_production is also true.'
            );
        }

        return null;
    }

    private function validateTtlSpec(string $spec, string $flag): ?string
    {
        if ($spec === '') {
            return null;
        }

        // Bare integer: applies to every profile.
        if (ctype_digit($spec)) {
            return (int) $spec > self::CACHE_TTL_CEILING
                ? sprintf('%s of %ds exceeds the %ds ceiling.', $flag, (int) $spec, self::CACHE_TTL_CEILING)
                : null;
        }

        foreach (explode(',', $spec) as $pair) {
            $pair = trim($pair);

            if ($pair === '') {
                continue;
            }

            if (! str_contains($pair, '=')) {
                return sprintf(
                    '%s segment "%s" is not understood. Use a bare integer, or key=seconds pairs (%s).',
                    $flag,
                    $pair,
                    implode('|', self::CACHE_PROFILES),
                );
            }

            [$key, $value] = array_map(trim(...), explode('=', $pair, 2));
            $key = strtolower($key);

            if (! in_array($key, self::CACHE_PROFILES, true)) {
                return sprintf('%s: unknown profile "%s". Expected one of: %s.', $flag, $key, implode(', ', self::CACHE_PROFILES));
            }

            if (! ctype_digit($value)) {
                return sprintf('%s: "%s" must be a non-negative integer number of seconds.', $flag, $pair);
            }

            if ((int) $value > self::CACHE_TTL_CEILING) {
                return sprintf('%s: %ds for "%s" exceeds the %ds ceiling.', $flag, (int) $value, $key, self::CACHE_TTL_CEILING);
            }
        }

        return null;
    }

    private function validateModelSpec(string $spec): ?string
    {
        if (! str_contains($spec, ':')) {
            return sprintf(
                '--cache-model="%s" is missing its setting. Use Model:profile, Model:off, or Model:key=seconds.',
                $spec,
            );
        }

        [$model, $setting] = array_map(trim(...), explode(':', $spec, 2));

        if ($model === '') {
            return sprintf('--cache-model="%s" has no model name.', $spec);
        }

        if ($setting === '') {
            return sprintf('--cache-model="%s" has no setting after the colon.', $spec);
        }

        $lower = strtolower($setting);

        if (in_array($lower, ['off', 'false', 'no', 'none', 'on', 'true', 'yes'], true)) {
            return null;
        }

        if (in_array($lower, self::CACHE_PROFILES, true)) {
            return null;
        }

        // A single bare word is almost always a misspelled profile or on/off —
        // pointing at "key=seconds pairs" would be technically true and useless.
        if (! str_contains($setting, '=') && ! str_contains($setting, ',') && ! ctype_digit($setting)) {
            return sprintf(
                '--cache-model="%s": "%s" is not a profile. Expected one of: %s, off, on — or a TTL like "list=30".',
                $spec,
                $setting,
                implode(', ', self::CACHE_PROFILES),
            );
        }

        return $this->validateTtlSpec($setting, "--cache-model=\"{$spec}\"");
    }

    // -----------------------------------------------------------------------
    // Config projection
    // -----------------------------------------------------------------------

    /**
     * The anvil.cache.* values this invocation sets.
     *
     * Only keys the operator actually supplied are returned, so a bare
     * `anvil:generate-api --cache` does not stomp a carefully tuned config file
     * with flag defaults — the same discipline versionProfileValues() already
     * applies to the per-version profile.
     *
     * @return array<string, mixed>
     */
    protected function cacheRuntimeConfig(): array
    {
        if (! $this->hasCacheOptions()) {
            return [];
        }

        $values = [];

        if ($this->boolOption('no-cache')) {
            return ['cache.enabled' => false];
        }

        if ($this->boolOption('cache')) {
            $values['cache.enabled'] = true;
        }

        if (($ttl = $this->resolvedTtlMap()) !== []) {
            // Merged rather than replaced: --cache-ttl=list=30 must not silently
            // drop the configured single/aggregate values.
            $values['cache.ttl'] = array_replace(
                (array) config('anvil.cache.ttl', []),
                $ttl,
            );
        }

        if (($store = $this->stringOption('cache-store')) !== '') {
            $values['cache.store'] = $store;
        }

        if (($scope = strtolower($this->stringOption('cache-scope'))) !== '') {
            $values['cache.scope'] = $scope;
        }

        if (($stale = $this->stringOption('cache-stale')) !== '') {
            $values['cache.stale_while_revalidate'] = (int) $stale;
        }

        if (($jitter = $this->stringOption('cache-jitter')) !== '') {
            $values['cache.jitter'] = (float) $jitter;
        }

        if ($this->boolOption('cache-bypass')) {
            $values['cache.allow_bypass'] = true;
        }

        if (($profile = strtolower($this->stringOption('cache-profile'))) !== '') {
            $values['cache.default_profile'] = $profile;
        }

        if (($models = $this->resolvedModelOverrides()) !== []) {
            $values['cache.models'] = array_replace_recursive(
                (array) config('anvil.cache.models', []),
                $models,
            );
        }

        if ($this->boolOption('etag')) {
            $values['cache.http'] = true;
            // The spec must document what the controllers actually send, or the
            // 304 responses clients receive are undocumented.
            $values['openapi.document_http_cache'] = true;
        }

        return $values;
    }

    /**
     * Keys for GenerationOptions, so generators can gate on the DTO.
     *
     * The runtime config above is the reliable channel — these are the belt to
     * its braces, exactly as the openApi flags are handled.
     *
     * @return array<string, mixed>
     */
    protected function cacheOptionPayload(): array
    {
        if (! $this->hasCacheOptions()) {
            return [];
        }

        return [
            'cache' => $this->generatesCache(),
            'httpCache' => $this->boolOption('etag'),
            'cacheTtl' => $this->effectiveTtlMap(),
        ];
    }

    /** Whether generated services should cache at all. */
    protected function generatesCache(): bool
    {
        if (! $this->hasCacheOptions()) {
            return (bool) config('anvil.cache.enabled', false);
        }

        if ($this->boolOption('no-cache')) {
            return false;
        }

        if ($this->boolOption('cache')) {
            return true;
        }

        // No flag either way: whatever the config file says.
        return (bool) config('anvil.cache.enabled', false);
    }

    /**
     * TTL overrides from the flag only.
     *
     * @return array<string, int>
     */
    private function resolvedTtlMap(): array
    {
        $spec = $this->stringOption('cache-ttl');

        if ($spec === '') {
            return [];
        }

        if (ctype_digit((string) $spec)) {
            // A bare integer means "all profiles", which is why the summary
            // prints the resolved map — so nobody has to guess.
            return array_fill_keys(self::CACHE_PROFILES, (int) $spec);
        }

        $map = [];

        foreach (explode(',', (string) $spec) as $pair) {
            $pair = trim($pair);

            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = array_map(trim(...), explode('=', $pair, 2));
            $map[strtolower($key)] = (int) $value;
        }

        return $map;
    }

    /**
     * The TTLs that will actually apply — flag over config over built-in default.
     *
     * @return array<string, int>
     */
    protected function effectiveTtlMap(): array
    {
        $flag = $this->resolvedTtlMap();
        $defaults = [
            CachePolicy::PROFILE_SINGLE => 300,
            CachePolicy::PROFILE_LIST => 60,
            CachePolicy::PROFILE_AGGREGATE => 30,
            CachePolicy::PROFILE_REFERENCE => 3600,
        ];

        $map = [];

        foreach (self::CACHE_PROFILES as $profile) {
            $map[$profile] = (int) (
                $flag[$profile]
                ?? config("anvil.cache.ttl.{$profile}", $defaults[$profile])
            );
        }

        return $map;
    }

    /**
     * Per-model overrides parsed from --cache-model.
     *
     * A bare class name is stored as given; CachePolicy falls back to
     * class_basename() when looking up, so "Category" matches
     * App\Models\Category without the operator typing the FQCN.
     *
     * @return array<string, array<string, mixed>>
     */
    private function resolvedModelOverrides(): array
    {
        $overrides = [];

        foreach ($this->arrayOption('cache-model') as $spec) {
            $spec = (string) $spec;

            if (! str_contains($spec, ':')) {
                continue;
            }

            [$model, $setting] = array_map(trim(...), explode(':', $spec, 2));
            $lower = strtolower($setting);

            $overrides[$model] = match (true) {
                in_array($lower, ['off', 'false', 'no', 'none'], true) => ['enabled' => false],
                in_array($lower, ['on', 'true', 'yes'], true) => ['enabled' => true],
                in_array($lower, self::CACHE_PROFILES, true) => ['profile' => $lower],
                default => ['ttl' => $this->parseTtlPairs($setting)],
            };
        }

        return $overrides;
    }

    /** @return array<string, int> */
    private function parseTtlPairs(string $spec): array
    {
        if (ctype_digit($spec)) {
            return array_fill_keys(self::CACHE_PROFILES, (int) $spec);
        }

        $map = [];

        foreach (explode(',', $spec) as $pair) {
            $pair = trim($pair);

            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }

            [$key, $value] = array_map(trim(...), explode('=', $pair, 2));
            $map[strtolower($key)] = (int) $value;
        }

        return $map;
    }

    // -----------------------------------------------------------------------
    // Reporting
    // -----------------------------------------------------------------------

    /**
     * Rows for the command's summary table.
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function cacheSummaryRows(): array
    {
        if (! $this->hasCacheOptions()) {
            return [];
        }

        if (! $this->generatesCache()) {
            return [['Query cache', 'disabled — services query the database directly']];
        }

        $ttl = $this->effectiveTtlMap();
        $store = $this->stringOption('cache-store') ?: (string) (config('anvil.cache.store', 'default'));
        $scope = strtolower($this->stringOption('cache-scope')) ?: (string) config('anvil.cache.scope', 'auth');
        $stale = $this->stringOption('cache-stale') !== ''
            ? (int) $this->stringOption('cache-stale')
            : (int) config('anvil.cache.stale_while_revalidate', 30);

        $rows = [
            ['Query cache', sprintf('enabled — store: %s, scope: %s', $store, $scope)],
            ['Cache TTLs', sprintf(
                'single %ds · list %ds · aggregate %ds · reference %ds',
                $ttl[CachePolicy::PROFILE_SINGLE],
                $ttl[CachePolicy::PROFILE_LIST],
                $ttl[CachePolicy::PROFILE_AGGREGATE],
                $ttl[CachePolicy::PROFILE_REFERENCE],
            )],
            ['Revalidation', $stale > 0
                ? sprintf('stale-while-revalidate %ds', $stale)
                : 'lock-and-recompute (no stale reads)'],
        ];

        if (($overrides = $this->resolvedModelOverrides()) !== []) {
            $rows[] = ['Cache overrides', implode(', ', array_map(
                static fn (string $model, array $config): string => $model.' → '.match (true) {
                    ($config['enabled'] ?? true) === false => 'off',
                    isset($config['profile']) => (string) $config['profile'],
                    default => implode('/', array_map(
                        static fn (string $k, int $v): string => "{$k}:{$v}s",
                        array_keys($config['ttl'] ?? []),
                        array_values($config['ttl'] ?? []),
                    )),
                },
                array_keys($overrides),
                array_values($overrides),
            ))];
        }

        $rows[] = ['HTTP cache', $this->boolOption('etag')
            ? 'ETag + If-None-Match, 304 documented in the spec'
            : 'not emitted (pass --etag)'];

        if ($scope === 'none') {
            $rows[] = ['⚠ Isolation', 'scope=none — cached results are shared across all callers'];
        }

        return $rows;
    }

    /**
     * Advice worth printing after a successful run, once, rather than burying
     * it in documentation nobody opens.
     */
    protected function reportCacheCaveats(): void
    {
        if (! $this->hasCacheOptions() || ! $this->generatesCache()) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=yellow>Cache invalidation covers Eloquent model events only.</>');
        $this->line('  <fg=gray>Bulk writes bypass them — Model::query()->update(), ->delete(), insert(),</>');
        $this->line('  <fg=gray>and raw DB statements. Call $this->flushCache() in any service doing those.</>');
    }

    // -----------------------------------------------------------------------
    // Option access
    // -----------------------------------------------------------------------

    /**
     * Whether this command declares the cache flags at all.
     *
     * Lets the trait be mixed into a command whose signature has not been
     * updated yet without every helper exploding on an undefined option.
     */
    protected function hasCacheOptions(): bool
    {
        return $this->getDefinition()->hasOption('cache');
    }

    private function boolOption(string $name): bool
    {
        return $this->getDefinition()->hasOption($name) && (bool) $this->option($name);
    }

    private function stringOption(string $name): string
    {
        if (! $this->getDefinition()->hasOption($name)) {
            return '';
        }

        return trim((string) ($this->option($name) ?? ''));
    }

    /** @return list<mixed> */
    private function arrayOption(string $name): array
    {
        if (! $this->getDefinition()->hasOption($name)) {
            return [];
        }

        $value = $this->option($name);

        return is_array($value) ? array_values($value) : [];
    }
}
