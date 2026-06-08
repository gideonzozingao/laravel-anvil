<?php

namespace Zuqongtech\LaravelAnvil\Generators;

use Illuminate\Support\Str;
use Zuqongtech\LaravelAnvil\Contracts\Generator;
use Zuqongtech\LaravelAnvil\Support\GenerationOptions;
use Zuqongtech\LaravelAnvil\Support\Helpers;
use Zuqongtech\LaravelAnvil\Support\ModelMetadata;

/**
 * Generates boilerplate Blade views for the web scaffold (--web).
 *
 * Per model, under resources/views/{slug}/  (slug = kebab-plural, e.g. "vehicle-categories"):
 *   index.blade.php   — paginated table with View / Edit / Delete actions + "New" button
 *   create.blade.php  — create form (includes _form)
 *   edit.blade.php    — edit form (includes _form)
 *   show.blade.php     — read-only detail view
 *   _form.blade.php    — shared form fields, inferred from column types
 *
 * Once per run (idempotent, skipped if present):
 *   resources/views/layouts/anvil.blade.php  — Tailwind-CDN base layout with
 *   flash messages and validation-error display.
 *
 * Blade content is produced from nowdoc templates with %TOKEN% placeholders so
 * that PHP never interpolates Blade's own $variables / {{ }} / @directives.
 */
final class ViewGenerator implements Generator
{
    #[\Override]
    public function supports(GenerationOptions $options): bool
    {
        return $options->web ?? false;
    }

    #[\Override]
    public function getName(): string
    {
        return 'View';
    }

    #[\Override]
    public function generate(ModelMetadata $meta, GenerationOptions $options): array
    {
        $results = [];

        $results[] = $this->ensureLayout($options);

        $slug = Helpers::modelToRouteName($meta->model);          // vehicle-categories
        $var = lcfirst($meta->model);                            // vehicleCategory
        $pluralVar = lcfirst(Str::pluralStudly($meta->model));         // vehicleCategories
        $title = Str::headline($meta->model);                      // Vehicle Category
        $titlePl = Str::headline(Str::pluralStudly($meta->model));   // Vehicle Categories
        $layout = config('anvil.web.layout', 'layouts.anvil');
        $pk = $meta->primaryKey ?? 'id';

        $dir = resource_path('views/'.$slug);

        $formCols = $this->formColumns($meta);
        $tableCols = $this->tableColumns($meta);
        $showCols = $this->showColumns($meta);

        $base = [
            '%LAYOUT%' => $layout,
            '%MODEL%' => $meta->model,
            '%TITLE%' => $title,
            '%TITLE_PLURAL%' => $titlePl,
            '%SLUG%' => $slug,
            '%VAR%' => $var,
            '%PLURAL_VAR%' => $pluralVar,
            '%PK%' => $pk,
        ];

        $files = [
            'index.blade.php' => $this->renderIndex($base, $tableCols),
            'create.blade.php' => $this->renderCreate($base),
            'edit.blade.php' => $this->renderEdit($base),
            'show.blade.php' => $this->renderShow($base, $showCols, $pk),
            '_form.blade.php' => $this->renderForm($base, $formCols, $var),
        ];

        foreach ($files as $name => $content) {
            $path = "{$dir}/{$name}";

            if (file_exists($path) && ! $options->force) {
                $results[] = $this->result($name, $path, 'skipped', 'already exists');

                continue;
            }

            if (! $options->dryRun) {
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($path, $content);
            }

            $results[] = $this->result($name, $path, 'success');
        }

        return $results;
    }

    // -----------------------------------------------------------------------
    // Layout (once)
    // -----------------------------------------------------------------------

    protected function ensureLayout(GenerationOptions $options): array
    {
        $layout = config('anvil.web.layout', 'layouts.anvil');
        $path = resource_path('views/'.str_replace('.', '/', $layout).'.blade.php');

        if (! config('anvil.web.generate_layout', true) || file_exists($path)) {
            return $this->result(basename($path), $path, 'skipped', 'layout exists or disabled');
        }

        if (! $options->dryRun) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $this->layoutTemplate());
        }

        return $this->result(basename($path), $path, 'success', 'layout created');
    }

    protected function layoutTemplate(): string
    {
        return <<<'BLADE'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name', 'Laravel'))</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style type="text/tailwindcss">
        @layer components {
            .form-input { @apply w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 border px-3 py-2; }
            .btn-primary { @apply inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500; }
            .btn-secondary { @apply inline-flex items-center rounded-md bg-gray-200 px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-300; }
            .link { @apply text-indigo-600 hover:text-indigo-800 text-sm font-medium mr-3; }
            .link-danger { @apply text-red-600 hover:text-red-800 text-sm font-medium bg-transparent border-0 cursor-pointer; }
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen">
    <nav class="bg-[#1a1a2e] text-white">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center gap-3">
            <span class="text-lg font-bold tracking-wide">&#9874; {{ config('app.name', 'Laravel') }}</span>
            <span class="text-xs opacity-60">Admin</span>
        </div>
    </nav>

    <main class="max-w-6xl mx-auto px-4 py-8">
        @if (session('success'))
            <div class="mb-6 rounded-md bg-green-50 border border-green-200 px-4 py-3 text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-md bg-red-50 border border-red-200 px-4 py-3 text-red-800">
                <p class="font-semibold mb-1">Please fix the following:</p>
                <ul class="list-disc list-inside text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
BLADE;
    }

    // -----------------------------------------------------------------------
    // View renderers
    // -----------------------------------------------------------------------

    protected function renderIndex(array $base, array $tableCols): string
    {
        $head = '';
        $row = '';
        foreach ($tableCols as $col) {
            $label = $this->label($col);
            $head .= "                <th class=\"px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase\">{$label}</th>\n";
            $row .= "                    <td class=\"px-4 py-3 text-sm text-gray-700\">{{ \$%VAR%->{$col} }}</td>\n";
        }

        $tpl = <<<'BLADE'
@extends('%LAYOUT%')

@section('title', '%TITLE_PLURAL%')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">%TITLE_PLURAL%</h1>
        <a href="{{ route('%SLUG%.create') }}" class="btn-primary">+ New %TITLE%</a>
    </div>

    <div class="overflow-x-auto bg-white rounded-lg shadow">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
%TABLE_HEAD%                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($%PLURAL_VAR% as $%VAR%)
                    <tr class="hover:bg-gray-50">
%TABLE_ROW%                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('%SLUG%.show', $%VAR%) }}" class="link">View</a>
                            <a href="{{ route('%SLUG%.edit', $%VAR%) }}" class="link">Edit</a>
                            <form action="{{ route('%SLUG%.destroy', $%VAR%) }}" method="POST" class="inline" onsubmit="return confirm('Delete this %TITLE%?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="link-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="99" class="px-4 py-6 text-center text-gray-400">No %TITLE_PLURAL% found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $%PLURAL_VAR%->links() }}
    </div>
@endsection
BLADE;

        return $this->apply($tpl, ['%TABLE_HEAD%' => $head, '%TABLE_ROW%' => $row] + $base);
    }

    protected function renderCreate(array $base): string
    {
        $tpl = <<<'BLADE'
@extends('%LAYOUT%')

@section('title', 'New %TITLE%')

@section('content')
    <div class="max-w-2xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">New %TITLE%</h1>

        <form action="{{ route('%SLUG%.store') }}" method="POST" class="bg-white rounded-lg shadow p-6">
            @csrf
            @include('%SLUG%._form')

            <div class="flex items-center gap-3 mt-6">
                <button type="submit" class="btn-primary">Create</button>
                <a href="{{ route('%SLUG%.index') }}" class="btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
@endsection
BLADE;

        return $this->apply($tpl, $base);
    }

    protected function renderEdit(array $base): string
    {
        $tpl = <<<'BLADE'
@extends('%LAYOUT%')

@section('title', 'Edit %TITLE%')

@section('content')
    <div class="max-w-2xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">Edit %TITLE%</h1>

        <form action="{{ route('%SLUG%.update', $%VAR%) }}" method="POST" class="bg-white rounded-lg shadow p-6">
            @csrf
            @method('PUT')
            @include('%SLUG%._form', ['%VAR%' => $%VAR%])

            <div class="flex items-center gap-3 mt-6">
                <button type="submit" class="btn-primary">Save changes</button>
                <a href="{{ route('%SLUG%.index') }}" class="btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
@endsection
BLADE;

        return $this->apply($tpl, $base);
    }

    protected function renderShow(array $base, array $showCols, string $pk): string
    {
        $rows = '';
        foreach ($showCols as $col) {
            $label = $this->label($col);
            $rows .= "            <div class=\"py-3 border-b border-gray-100\">\n"
                ."                <dt class=\"text-sm font-medium text-gray-500\">{$label}</dt>\n"
                ."                <dd class=\"text-gray-900\">{{ \$%VAR%->{$col} }}</dd>\n"
                ."            </div>\n";
        }

        $tpl = <<<'BLADE'
@extends('%LAYOUT%')

@section('title', '%TITLE% #{{ $%VAR%->%PK% }}')

@section('content')
    <div class="max-w-2xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">%TITLE% #{{ $%VAR%->%PK% }}</h1>
            <div>
                <a href="{{ route('%SLUG%.edit', $%VAR%) }}" class="btn-primary">Edit</a>
                <a href="{{ route('%SLUG%.index') }}" class="btn-secondary">Back</a>
            </div>
        </div>

        <dl class="bg-white rounded-lg shadow p-6">
%SHOW_ROWS%        </dl>
    </div>
@endsection
BLADE;

        return $this->apply($tpl, ['%SHOW_ROWS%' => $rows] + $base);
    }

    protected function renderForm(array $base, array $formCols, string $var): string
    {
        $fields = '';
        foreach ($formCols as $col) {
            $fields .= $this->formField($col);
        }

        // The partial only contains the field markup; %VAR% is resolved last.
        return $this->apply($fields, $base);
    }

    // -----------------------------------------------------------------------
    // Field builders (return Blade with %VAR% token, resolved by apply())
    // -----------------------------------------------------------------------

    protected function formField(array $col): string
    {
        $name = $col['name'];
        $label = $this->label($name);
        $type = $this->inputType($col);
        $req = ($col['nullable'] ?? false) ? '' : ' required';

        $valueExpr = "old('{$name}', optional(\$%VAR% ?? null)->{$name})";

        if ($type === 'textarea') {
            $control = "<textarea name=\"{$name}\" rows=\"4\" class=\"form-input\"{$req}>{{ {$valueExpr} }}</textarea>";
        } elseif ($type === 'checkbox') {
            $control = "<input type=\"hidden\" name=\"{$name}\" value=\"0\">\n"
                ."        <input type=\"checkbox\" name=\"{$name}\" value=\"1\" {{ {$valueExpr} ? 'checked' : '' }} class=\"rounded border-gray-300\">";
        } elseif ($type === 'select') {
            $options = '';
            foreach ($this->enumValues($col) as $opt) {
                $options .= "\n            <option value=\"{$opt}\" {{ {$valueExpr} === '{$opt}' ? 'selected' : '' }}>{$opt}</option>";
            }
            $control = "<select name=\"{$name}\" class=\"form-input\"{$req}>\n"
                ."            <option value=\"\">&mdash; Select &mdash;</option>{$options}\n        </select>";
        } else {
            $step = $type === 'number' && $this->isDecimal($col) ? ' step="any"' : '';
            $control = "<input type=\"{$type}\" name=\"{$name}\" value=\"{{ {$valueExpr} }}\"{$step} class=\"form-input\"{$req}>";
        }

        return "    <div class=\"mb-4\">\n"
            ."        <label class=\"block text-sm font-medium text-gray-700 mb-1\">{$label}</label>\n"
            ."        {$control}\n"
            ."        @error('{$name}') <p class=\"text-sm text-red-600 mt-1\">{{ \$message }}</p> @enderror\n"
            ."    </div>\n";
    }

    // -----------------------------------------------------------------------
    // Column selection
    // -----------------------------------------------------------------------

    /** Columns shown on create/edit forms. */
    protected function formColumns(ModelMetadata $meta): array
    {
        $skip = array_merge(
            [$meta->primaryKey, 'created_at', 'updated_at', 'deleted_at', 'remember_token', 'password', 'email_verified_at'],
            $meta->compositePrimaryKey,
        );

        return array_values(array_filter(
            array_map(fn ($c) => $c, $meta->columns),
            fn ($c) => ! in_array($c['name'], $skip, true),
        ));
    }

    /** Columns shown as table columns on index (pk + a few scalars). */
    protected function tableColumns(ModelMetadata $meta): array
    {
        $sensitive = ['password', 'remember_token'];
        $skip = ['deleted_at', 'updated_at'];
        $names = [];

        $pk = $meta->primaryKey ?? 'id';
        $names[] = $pk;

        foreach ($meta->columns as $c) {
            if (count($names) >= 5) {
                break;
            }
            $n = $c['name'];
            if ($n === $pk || in_array($n, $sensitive, true) || in_array($n, $skip, true)) {
                continue;
            }
            if ($this->inputType($c) === 'textarea') {
                continue; // skip long text in tables
            }
            $names[] = $n;
        }

        return $names;
    }

    /** Columns shown on the show page (all non-sensitive). */
    protected function showColumns(ModelMetadata $meta): array
    {
        $sensitive = ['password', 'remember_token'];

        return array_values(array_filter(
            array_column($meta->columns, 'name'),
            fn ($n) => ! in_array($n, $sensitive, true),
        ));
    }

    // -----------------------------------------------------------------------
    // Type inference
    // -----------------------------------------------------------------------

    protected function inputType(array $col): string
    {
        $name = strtolower($col['name']);
        $raw = strtolower($col['type'] ?? 'varchar');
        $type = preg_replace('/\(.*\)/', '', $raw);

        if (str_starts_with($raw, 'enum')) {
            return 'select';
        }
        if ($type === 'tinyint' && str_contains($raw, '(1)')) {
            return 'checkbox';
        }
        if (in_array($type, ['boolean', 'bool'], true)) {
            return 'checkbox';
        }
        if (in_array($type, ['text', 'mediumtext', 'longtext', 'tinytext', 'json', 'jsonb'], true)) {
            return 'textarea';
        }
        if (in_array($type, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'decimal', 'numeric', 'float', 'double', 'real'], true)) {
            return 'number';
        }
        if ($type === 'date') {
            return 'date';
        }
        if (in_array($type, ['datetime', 'timestamp'], true)) {
            return 'datetime-local';
        }
        if (str_contains($name, 'email')) {
            return 'email';
        }
        if (str_contains($name, 'password')) {
            return 'password';
        }
        if (str_contains($name, 'url') || str_contains($name, 'website')) {
            return 'url';
        }

        return 'text';
    }

    protected function isDecimal(array $col): bool
    {
        $type = strtolower(preg_replace('/\(.*\)/', '', $col['type'] ?? ''));

        return in_array($type, ['decimal', 'numeric', 'float', 'double', 'real'], true);
    }

    /** @return list<string> */
    protected function enumValues(array $col): array
    {
        if (preg_match("/enum\('(.+?)'\)/i", $col['type'] ?? '', $m)) {
            return array_map('trim', explode("','", $m[1]));
        }

        return [];
    }

    protected function label(string $name): string
    {
        return Str::headline(preg_replace('/_id$/', '', $name));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Sequential token replacement. Block tokens (%TABLE_ROW% etc.) must be in
     * $map before %VAR%/%SLUG% so that %VAR% inside an injected block is also
     * resolved (str_replace reprocesses prior replacements).
     */
    protected function apply(string $template, array $map): string
    {
        return str_replace(array_keys($map), array_values($map), $template);
    }

    protected function result(string $name, string $path, string $status, ?string $reason = null): array
    {
        $out = [
            'type' => $this->getName(),
            'name' => $name,
            'path' => $path,
            'status' => $status,
        ];

        if ($reason !== null) {
            $out['reason'] = $reason;
        }

        return $out;
    }
}
