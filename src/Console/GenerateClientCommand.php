<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Support\ApiVersionProfile;
use Zuqongtech\LaravelAnvil\Support\DatabaseInspector;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;
use Zuqongtech\LaravelAnvil\Support\OpenApiLocator;

/**
 * Generates a typed TypeScript client for the versioned API.
 *
 *   php artisan anvil:generate-client
 *   php artisan anvil:generate-client --api-version=2 --output=resources/js/api
 *   php artisan anvil:generate-client --hooks         # React Query hooks too
 *
 * Output:
 *
 *   resources/js/api/v2/types.ts        interfaces + payload types per model
 *   resources/js/api/v2/client.ts       fetch wrapper, error type, pagination
 *   resources/js/api/v2/vehicles.ts     list/get/create/update/remove per resource
 *   resources/js/api/v2/index.ts        barrel
 *
 * Everything resolves through ApiVersionProfile, the same object the PHP requests
 * and resources use, so a camelCase v2 produces camelCase interfaces and a
 * ?perPage= query parameter. The types cannot drift from the API because both are
 * projections of one schema.
 *
 * This is the part that changes how teams work: the frontend has the contract
 * before the endpoint is finished, rather than waiting on a hand-written spec.
 */

class GenerateClientCommand extends Command
{


  protected $description = 'Generate a typed TypeScript client for the versioned JSON API';
  protected $signature = 'anvil:forge-client
                            {--api-version=1 : API version to target (1, v1, V1)}
                            {--output=       : Output directory (default: resources/js/api)}
                            {--stack=ts      : Client flavour: ts}
                            {--hooks         : Also emit React Query hooks}
                            {--connection=   : Database connection to introspect}
                            {--schema=       : Schema(s) to introspect}
                            {--tables=*      : Limit to specific tables}
                            {--ignore=*      : Exclude specific tables}
                            {--force         : Overwrite existing files}
                            {--dry-run       : Preview without writing files}';
  public function handle(): int
  {
    if (strtolower((string) $this->option('stack')) !== 'ts') {
      $this->error('Only --stack=ts is supported right now.');

      return self::FAILURE;
    }

    $connection = (string) ($this->option('connection') ?: config('database.default'));

    try {
      $inspector = new DatabaseInspector($connection);
    } catch (\Throwable $e) {
      $this->error('Could not connect to the database: ' . $e->getMessage());

      return self::FAILURE;
    }

    $version = OpenApiLocator::normaliseVersion($this->option('api-version'));
    $profile = ApiVersionProfile::for($version);

    $tables = $this->introspect($inspector);

    if ($tables === []) {
      $this->components->warn('No tables matched.');

      return self::SUCCESS;
    }

    $dir = rtrim((string) ($this->option('output') ?: base_path('resources/js/api')), '/') . '/' . $version;

    $files = [
      'client.ts' => $this->clientFile($profile),
      'types.ts' => $this->typesFile($tables, $profile),
      'index.ts' => $this->indexFile($tables),
    ];

    foreach ($tables as $meta) {
      $files[$this->moduleName($meta) . '.ts'] = $this->resourceFile($meta, $profile);
    }

    if ($this->option('hooks')) {
      $files['hooks.ts'] = $this->hooksFile($tables, $profile);
    }

    return $this->write($dir, $files, $profile, count($tables));
  }

  /**
   * @return array<string, ModelMetadata>
   */
  private function introspect(DatabaseInspector $inspector): array
  {
    $schema = $this->option('schema') ?: null;
    $only = array_map(strval(...), $this->option('tables') ?? []);
    $ignore = array_merge(
      (array) config('anvil.ignore_tables', []),
      array_map(strval(...), $this->option('ignore') ?? []),
    );

    $tables = [];

    foreach ($inspector->getAllSchemaTables($schema) as $row) {
      $table = (string) ($row['table'] ?? '');

      if ($table === '' || in_array($table, $ignore, true)) {
        continue;
      }

      if ($only !== [] && ! in_array($table, $only, true)) {
        continue;
      }

      try {
        $tables[$table] = ModelMetadata::fromTable($table, $inspector, $row['schema'] ?? $schema);
      } catch (\Throwable) {
        continue;
      }
    }

    ksort($tables);

    return $tables;
  }

  // -----------------------------------------------------------------------
  // client.ts
  // -----------------------------------------------------------------------

  private function clientFile(ApiVersionProfile $profile): string
  {
    $base = OpenApiLocator::apiBasePath($profile->version);
    $perPageParam = $profile->perPageParam();
    $pageParam = $profile->pageParam();
    $perPage = $profile->perPageDefault();
    $max = $profile->perPageMax();

    $meta = $this->paginationMetaType($profile);

    return <<<TS
/**
 * Generated by zuqongtech/laravel-anvil — do not edit by hand.
 *
 * A dependency-free fetch wrapper. Point `configure()` at your API and pass a
 * token getter; everything else in this directory builds on it.
 */

export interface ApiConfig {
  /** Base URL of the API host. Defaults to same-origin. */
  baseUrl: string;
  /** Called before every request; return null to send no Authorization header. */
  getToken: () => string | null | Promise<string | null>;
  /** Merged into every request. */
  headers: Record<string, string>;
  /** Milliseconds before a request is aborted. 0 disables the timeout. */
  timeout: number;
}

const config: ApiConfig = {
  baseUrl: '',
  getToken: () => null,
  headers: {},
  timeout: 30_000,
};

export function configure(options: Partial<ApiConfig>): void {
  Object.assign(config, options);
}

/** Path prefix for this API version. */
export const API_BASE = '{$base}';

export const PAGE_PARAM = '{$pageParam}';
export const PER_PAGE_PARAM = '{$perPageParam}';
export const PER_PAGE_DEFAULT = {$perPage};
/** The server clamps to this; asking for more is silently reduced. */
export const PER_PAGE_MAX = {$max};

{$meta}

export interface Paginated<T> {
  data: T[];
  meta: PaginationMeta;
}

export interface Envelope<T> {
  success: boolean;
  version: string;
  message?: string;
  data: T;
}

/** A 422 from the server, with errors keyed exactly as the client sent them. */
export interface ValidationProblem {
  message: string;
  errors: Record<string, string[]>;
}

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly body: unknown,
    message?: string,
  ) {
    super(message ?? \`Request failed with status \${status}\`);
    this.name = 'ApiError';
  }

  /** Narrowing helper: `if (error.isValidation()) error.errors.email` */
  isValidation(): this is ApiError & { errors: Record<string, string[]> } {
    return this.status === 422;
  }

  get errors(): Record<string, string[]> {
    const body = this.body as Partial<ValidationProblem> | null;

    return body?.errors ?? {};
  }
}

export type QueryValue = string | number | boolean | null | undefined;

export interface ListParams {
  [PAGE_PARAM]?: number;
  [PER_PAGE_PARAM]?: number;
  q?: string;
  sort?: string;
  direction?: 'asc' | 'desc';
  [key: string]: QueryValue;
}

function toQuery(params?: Record<string, QueryValue>): string {
  if (!params) return '';

  const search = new URLSearchParams();

  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue;
    search.append(key, String(value));
  }

  const query = search.toString();

  return query ? \`?\${query}\` : '';
}

export async function request<T>(
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  path: string,
  options: { body?: unknown; params?: Record<string, QueryValue>; signal?: AbortSignal } = {},
): Promise<T> {
  const token = await config.getToken();

  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...config.headers,
  };

  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = \`Bearer \${token}\`;

  // Caller-supplied signals win; the timeout is a fallback, not an override.
  const controller = new AbortController();
  const timer = config.timeout > 0 ? setTimeout(() => controller.abort(), config.timeout) : null;

  options.signal?.addEventListener('abort', () => controller.abort(), { once: true });

  try {
    const response = await fetch(\`\${config.baseUrl}\${API_BASE}\${path}\${toQuery(options.params)}\`, {
      method,
      headers,
      credentials: 'include',
      signal: controller.signal,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });

    // 204 carries no body, so parsing it would throw.
    if (response.status === 204) return undefined as T;

    const text = await response.text();
    const payload = text ? JSON.parse(text) : null;

    if (!response.ok) {
      throw new ApiError(response.status, payload, (payload as { message?: string })?.message);
    }

    return payload as T;
  } finally {
    if (timer) clearTimeout(timer);
  }
}

export const http = {
  get: <T>(path: string, params?: Record<string, QueryValue>, signal?: AbortSignal) =>
    request<T>('GET', path, { params, signal }),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, { body }),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, { body }),
  patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, { body }),
  delete: <T>(path: string) => request<T>('DELETE', path),
};

TS;
  }

  private function paginationMetaType(ApiVersionProfile $profile): string
  {
    $map = $profile->outboundMap(['current_page', 'last_page', 'per_page', 'total', 'from', 'to']);

    $lines = [];

    foreach (['current_page', 'last_page', 'per_page', 'total'] as $column) {
      $lines[] = '  ' . ($map[$column] ?? $column) . ': number;';
    }

    foreach (['from', 'to'] as $column) {
      $lines[] = '  ' . ($map[$column] ?? $column) . ': number | null;';
    }

    return "export interface PaginationMeta {\n" . implode("\n", $lines) . "\n}";
  }

    // -----------------------------------------------------------------------
    // types.ts
    // -----------------------------------------------------------------------

  /**
   * @param  array<string, ModelMetadata>  $tables
   */
  private function typesFile(array $tables, ApiVersionProfile $profile): string
  {
    $blocks = [];

    foreach ($tables as $meta) {
      $blocks[] = $this->modelInterface($meta, $profile);
    }

    $body = implode("\n\n", $blocks);

    return <<<TS
/**
 * Generated by zuqongtech/laravel-anvil — do not edit by hand.
 *
 * Keys follow the {$profile->version} response casing ({$profile->responseCase()}),
 * resolved from the same profile the PHP resources use — so these interfaces
 * cannot describe a shape the API does not return.
 *
 * Dates are ISO 8601 strings: JSON has no date type, and parsing is the caller's
 * choice.
 */

{$body}

TS;
  }

  private function modelInterface(ModelMetadata $meta, ApiVersionProfile $profile): string
  {
    $model = $meta->model;
    $readable = [];
    $writable = [];

    foreach ($meta->columns as $column) {
      $name = (string) $column['name'];

      if (! $profile->isHidden($name)) {
        $key = $profile->outboundMap([$name])[$name] ?? $name;
        $readable[] = '  ' . $key . ': ' . $this->tsType($column) . ';';
      }

      if ($profile->isReadOnly($name) || $name === $meta->primaryKey || $profile->isHidden($name)) {
        continue;
      }

      $key = array_flip($profile->inboundMap([$name]))[$name] ?? $name;
      $optional = ($column['nullable'] ?? false) || ($column['default'] ?? null) !== null ? '?' : '';
      $writable[] = '  ' . $key . $optional . ': ' . $this->tsType($column) . ';';
    }

    // Relations are only present when eager-loaded.
    $relations = [];

    foreach ($meta->foreignKeys as $fk) {
      $column = (string) ($fk['column'] ?? '');
      $method = $meta->belongsToName($column);

      if ($method === null) {
        continue;
      }

      $related = Helpers::tableToModelName((string) $fk['referenced_table']);
      $key = $profile->outboundMap([Str::snake($method)])[Str::snake($method)] ?? $method;
      $relations[] = '  ' . $key . '?: ' . $related . ';';
    }

    $readableBlock = implode("\n", array_merge($readable, $relations));
    $writableBlock = $writable === [] ? '  [key: string]: never;' : implode("\n", $writable);

    return <<<TS
export interface {$model} {
{$readableBlock}
}

/** Body accepted by create; update takes a Partial of this. */
export interface {$model}Input {
{$writableBlock}
}
TS;
  }

  /**
   * @param  array<string, mixed>  $column
   */
  private function tsType(array $column): string
  {
    $type = strtolower((string) preg_replace('/\(.*\)/', '', (string) ($column['type'] ?? 'varchar')));
    $nullable = ($column['nullable'] ?? false) ? ' | null' : '';

    $base = match (true) {
      str_contains($type, 'bool') => 'boolean',
      $type === 'tinyint' && str_contains(strtolower((string) $column['type']), '(1)') => 'boolean',
      (bool) preg_match('/(int|serial)/', $type) => 'number',
      (bool) preg_match('/(decimal|numeric|float|double|real|money)/', $type) => 'number',
      str_contains($type, 'json') => 'Record<string, unknown>',
      (bool) preg_match('/(date|time)/', $type) => 'string',
      default => 'string',
    };

    return $base . $nullable;
  }

  // -----------------------------------------------------------------------
  // Per-resource modules
  // -----------------------------------------------------------------------

  private function resourceFile(ModelMetadata $meta, ApiVersionProfile $profile): string
  {
    $model = $meta->model;
    $slug = Str::plural(Str::kebab($model));
    $module = $this->moduleName($meta);
    $fn = Str::camel($module);
    $single = Str::camel(Str::singular($module));
    $key = $meta->primaryKey ?? 'id';
    $keyType = $this->keyType($meta);

    $softDeletes = $meta->softDeletes ? <<<TS


/** Restore a soft-deleted {$model}. */
export const restore{$model} = (id: {$keyType}) =>
  http.patch<Envelope<{$model}>>(\`/{$slug}/\${id}/restore\`).then((r) => r.data);

/** Permanently delete a {$model}. Not reversible. */
export const forceDelete{$model} = (id: {$keyType}) =>
  http.delete<void>(\`/{$slug}/\${id}/force\`);
TS : '';

    return <<<TS
/**
 * Generated by zuqongtech/laravel-anvil — do not edit by hand.
 * {$model} — /{$slug}
 */

import { http, type Envelope, type ListParams, type Paginated } from './client';
import type { {$model}, {$model}Input } from './types';

/** Paginated list. Pass sort/direction/q as supported by the API. */
export const list{$model}s = (params?: ListParams, signal?: AbortSignal) =>
  http.get<Paginated<{$model}>>('/{$slug}', params, signal);

export const get{$model} = (id: {$keyType}, signal?: AbortSignal) =>
  http.get<Envelope<{$model}>>(\`/{$slug}/\${id}\`, undefined, signal).then((r) => r.data);

export const create{$model} = (input: {$model}Input) =>
  http.post<Envelope<{$model}>>('/{$slug}', input).then((r) => r.data);

export const update{$model} = (id: {$keyType}, input: Partial<{$model}Input>) =>
  http.put<Envelope<{$model}>>(\`/{$slug}/\${id}\`, input).then((r) => r.data);

export const delete{$model} = (id: {$keyType}) =>
  http.delete<void>(\`/{$slug}/\${id}\`);{$softDeletes}

TS;
  }

  /**
   * @param  array<string, ModelMetadata>  $tables
   */
  private function indexFile(array $tables): string
  {
    $exports = ["export * from './client';", "export * from './types';"];

    foreach ($tables as $meta) {
      $exports[] = "export * from './" . $this->moduleName($meta) . "';";
    }

    if ($this->option('hooks')) {
      $exports[] = "export * from './hooks';";
    }

    $body = implode("\n", $exports);

    return "/** Generated by zuqongtech/laravel-anvil — do not edit by hand. */\n\n{$body}\n";
  }

  /**
   * @param  array<string, ModelMetadata>  $tables
   */
  private function hooksFile(array $tables, ApiVersionProfile $profile): string
  {
    $blocks = [];

    foreach ($tables as $meta) {
      $model = $meta->model;
      $module = $this->moduleName($meta);
      $fnPlural = 'list' . $model . 's';
      $keyType = $this->keyType($meta);
      $queryKey = Str::kebab($module);

      $blocks[] = <<<TS
export const {$model}Keys = {
  all: ['{$queryKey}'] as const,
  list: (params?: ListParams) => [...{$model}Keys.all, 'list', params ?? {}] as const,
  detail: (id: {$keyType}) => [...{$model}Keys.all, 'detail', id] as const,
};

export const use{$model}s = (params?: ListParams) =>
  useQuery({
    queryKey: {$model}Keys.list(params),
    queryFn: ({ signal }) => api.list{$model}s(params, signal),
  });

export const use{$model} = (id: {$keyType}) =>
  useQuery({
    queryKey: {$model}Keys.detail(id),
    queryFn: ({ signal }) => api.get{$model}(id, signal),
    enabled: id !== undefined && id !== null,
  });

export const useCreate{$model} = () => {
  const client = useQueryClient();

  return useMutation({
    mutationFn: api.create{$model},
    onSuccess: () => client.invalidateQueries({ queryKey: {$model}Keys.all }),
  });
};

export const useUpdate{$model} = () => {
  const client = useQueryClient();

  return useMutation({
    mutationFn: ({ id, input }: { id: {$keyType}; input: Partial<api.{$model}Input> }) =>
      api.update{$model}(id, input),
    onSuccess: (_data, variables) => {
      client.invalidateQueries({ queryKey: {$model}Keys.detail(variables.id) });
      client.invalidateQueries({ queryKey: {$model}Keys.all });
    },
  });
};

export const useDelete{$model} = () => {
  const client = useQueryClient();

  return useMutation({
    mutationFn: api.delete{$model},
    onSuccess: () => client.invalidateQueries({ queryKey: {$model}Keys.all }),
  });
};
TS;
    }

    $body = implode("\n\n", $blocks);

    return <<<TS
/**
 * Generated by zuqongtech/laravel-anvil — do not edit by hand.
 *
 * Requires @tanstack/react-query v5.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import * as api from './index';
import type { ListParams } from './client';

{$body}

TS;
  }

  // -----------------------------------------------------------------------
  // Helpers
  // -----------------------------------------------------------------------

  private function moduleName(ModelMetadata $meta): string
  {
    return Str::plural(Str::kebab($meta->model));
  }

  private function keyType(ModelMetadata $meta): string
  {
    foreach ($meta->columns as $column) {
      if ((string) $column['name'] !== ($meta->primaryKey ?? 'id')) {
        continue;
      }

      $type = strtolower((string) ($column['type'] ?? ''));

      return preg_match('/(int|serial)/', $type) === 1 ? 'number' : 'string';
    }

    return 'number | string';
  }

  /**
   * @param  array<string, string>  $files
   */
  private function write(string $dir, array $files, ApiVersionProfile $profile, int $tableCount): int
  {
    $this->newLine();
    $this->line('  <fg=cyan;options=bold>⚒  Anvil — TypeScript client</>');
    $this->table(['', ''], [
      ['Version', $profile->version],
      ['Response case', $profile->responseCase()],
      ['Page parameter', '?' . $profile->perPageParam() . '='],
      ['Resources', (string) $tableCount],
      ['Output', str_replace(base_path() . '/', '', $dir)],
    ]);
    $this->newLine();

    $written = $skipped = 0;

    foreach ($files as $name => $contents) {
      $path = $dir . '/' . $name;
      $exists = is_file($path);

      if ($exists && ! $this->option('force')) {
        $this->line("    <fg=gray>–</> {$name} <fg=gray>(exists)</>");
        $skipped++;

        continue;
      }

      if ($this->option('dry-run')) {
        $this->line("    <fg=cyan>◌</> {$name}");

        continue;
      }

      if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
        $this->error("Could not create {$dir}");

        return self::FAILURE;
      }

      if (file_put_contents($path, $contents) === false) {
        $this->line("    <fg=red>✘</> {$name}");

        return self::FAILURE;
      }

      $this->line("    <fg=green>✔</> {$name}");
      $written++;
    }

    $this->newLine();
    $this->line("  {$written} written, {$skipped} skipped");
    $this->newLine();
    $this->line('  <options=bold>Usage</>');
    $this->line('    <fg=gray>import { configure, list' . 'Users, ApiError } from \'@/api/' . $profile->version . '\';</>');
    $this->line('    <fg=gray>configure({ baseUrl: import.meta.env.VITE_API_URL, getToken: () => localStorage.getItem(\'token\') });</>');
    $this->newLine();

    return self::SUCCESS;
  }
}
