<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth;

use InvalidArgumentException;

/**
 * The look of every generated auth screen, in one place.
 *
 * Each part used to inline its own markup, which is why login and register drifted
 * into two different input styles. Parts now ask for a field and get the same
 * ring, the same focus state, the same error treatment and the same dark-mode
 * variant every time.
 *
 * On Tailwind and dynamic classes: the accent is interpolated at GENERATION time,
 * so the file written to disk contains literal `focus:ring-indigo-600`. Tailwind's
 * scanner sees it. Never move this interpolation into the Blade runtime — a class
 * assembled from a variable at request time is invisible to the compiler and
 * silently produces an unstyled form.
 *
 * Markup targets Tailwind 3.4+ and the Alpine that ships inside Livewire 3. No
 * plugins required: @tailwindcss/forms is not assumed, which is why every control
 * carries its full ring/border set explicitly.
 */
final readonly class Ui
{
    /**
     * Accents with a full 50–950 scale in default Tailwind, so the generated
     * classes resolve without a config change.
     *
     * @var array<string, array{600: string, 500: string, 700: string}>
     */
    private const ACCENTS = [
        'indigo' => ['600' => 'indigo-600', '500' => 'indigo-500', '700' => 'indigo-700'],
        'blue' => ['600' => 'blue-600', '500' => 'blue-500', '700' => 'blue-700'],
        'emerald' => ['600' => 'emerald-600', '500' => 'emerald-500', '700' => 'emerald-700'],
        'violet' => ['600' => 'violet-600', '500' => 'violet-500', '700' => 'violet-700'],
        'rose' => ['600' => 'rose-600', '500' => 'rose-500', '700' => 'rose-700'],
        'slate' => ['600' => 'slate-700', '500' => 'slate-600', '700' => 'slate-800'],
    ];

    public function __construct(private string $accent = 'indigo')
    {
        if (! self::supportsAccent($accent)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown accent "%s". Expected one of: %s.',
                $accent,
                implode(', ', self::accents()),
            ));
        }
    }

    /**
     * @return list<string>
     */
    public static function accents(): array
    {
        return array_keys(self::ACCENTS);
    }

    public static function supportsAccent(?string $accent): bool
    {
        return $accent !== null && array_key_exists(strtolower(trim($accent)), self::ACCENTS);
    }

    // -----------------------------------------------------------------------
    // Tokens
    // -----------------------------------------------------------------------

    public function accentClass(string $prefix, string $shade = '600'): string
    {
        return $prefix.'-'.self::ACCENTS[$this->accent][$shade];
    }

    /**
     * The base control classes. Error state is applied by Blade at runtime via
     *
     * @error, so validation styling needs no second class list.
     */
    public function controlClasses(): string
    {
        return implode(' ', [
            'block w-full rounded-lg border-0 px-3.5 py-2.5',
            'text-sm text-gray-900 dark:text-gray-100',
            'bg-white dark:bg-white/5',
            'shadow-sm ring-1 ring-inset ring-gray-300 dark:ring-white/10',
            'placeholder:text-gray-400 dark:placeholder:text-gray-500',
            'focus:ring-2 focus:ring-inset '.$this->accentClass('focus:ring'),
            'disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500',
            'transition',
        ]);
    }

    public function labelClasses(): string
    {
        return 'block text-sm font-medium leading-6 text-gray-900 dark:text-gray-200';
    }

    // -----------------------------------------------------------------------
    // Controls
    // -----------------------------------------------------------------------

    /**
     * A labelled text-ish input.
     *
     * @param  array{
     *     name: string,
     *     label: string,
     *     type?: string,
     *     autocomplete?: string,
     *     placeholder?: string,
     *     hint?: string,
     *     required?: bool,
     *     autofocus?: bool,
     *     inputmode?: string,
     *     wire?: string
     * }  $field
     */
    public function input(array $field): string
    {
        $name = $field['name'];
        $type = $field['type'] ?? 'text';
        $wire = $field['wire'] ?? 'wire:model.blur';
        $required = $field['required'] ?? true;

        return $this->render(<<<'HTML'
<div>
    <div class="flex items-center justify-between">
        <label for="{{NAME}}" class="{{LABEL_CLASSES}}">{{ __('{{LABEL}}') }}{{REQUIRED_MARK}}</label>
        {{TRAILING}}
    </div>
    <div class="mt-2">
        <input
            {{WIRE}}="{{NAME}}"
            id="{{NAME}}"
            name="{{NAME}}"
            type="{{TYPE}}"
            {{ATTRIBUTES}}
            class="{{CONTROL_CLASSES}} @error('{{NAME}}') ring-red-500 dark:ring-red-500 focus:ring-red-500 @enderror"
            @error('{{NAME}}') aria-invalid="true" aria-describedby="{{NAME}}-error" @enderror
        >
    </div>
    {{HINT}}
    @error('{{NAME}}')
        <p id="{{NAME}}-error" role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
</div>
HTML, [
            '{{NAME}}' => $name,
            '{{LABEL}}' => $this->escapeTranslation($field['label']),
            '{{TYPE}}' => $type,
            '{{WIRE}}' => $wire,
            '{{LABEL_CLASSES}}' => $this->labelClasses(),
            '{{CONTROL_CLASSES}}' => $this->controlClasses(),
            '{{REQUIRED_MARK}}' => $required ? '<span class="text-red-500" aria-hidden="true"> *</span>' : '',
            '{{TRAILING}}' => $field['trailing'] ?? '',
            '{{ATTRIBUTES}}' => $this->attributes($field),
            '{{HINT}}' => isset($field['hint'])
                ? '<p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __(\''.$this->escapeTranslation($field['hint']).'\') }}</p>'
                : '',
        ]);
    }

    /**
     * Password field with a reveal toggle and an optional strength meter.
     *
     * The meter runs entirely in Alpine off the input's own value — it never
     * round-trips the password to the server for scoring. That matters because a
     * Livewire property is serialised into the page snapshot on every request; the
     * less time a plaintext password spends in component state, the better. Which
     * is also why the binding is .blur and never .live.
     *
     * @param  array{name?: string, label?: string, autocomplete?: string, meter?: bool, trailing?: string, required?: bool}  $field
     */
    public function password(array $field = []): string
    {
        $name = $field['name'] ?? 'password';
        $meter = $field['meter'] ?? false;

        return $this->render(<<<'HTML'
<div x-data="{ show: false{{METER_STATE}} }">
    <div class="flex items-center justify-between">
        <label for="{{NAME}}" class="{{LABEL_CLASSES}}">{{ __('{{LABEL}}') }}{{REQUIRED_MARK}}</label>
        {{TRAILING}}
    </div>
    <div class="relative mt-2">
        <input
            wire:model.blur="{{NAME}}"
            id="{{NAME}}"
            name="{{NAME}}"
            :type="show ? 'text' : 'password'"
            autocomplete="{{AUTOCOMPLETE}}"
            required
            {{METER_BINDING}}
            class="{{CONTROL_CLASSES}} pr-11 @error('{{NAME}}') ring-red-500 dark:ring-red-500 focus:ring-red-500 @enderror"
            @error('{{NAME}}') aria-invalid="true" aria-describedby="{{NAME}}-error" @enderror
        >
        <button
            type="button"
            x-on:click="show = ! show"
            :aria-label="show ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
            :aria-pressed="show"
            class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset {{FOCUS_RING}} dark:hover:text-gray-200"
            tabindex="-1"
        >
            <svg x-show="! show" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <svg x-show="show" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243" />
            </svg>
        </button>
    </div>
{{METER}}
    @error('{{NAME}}')
        <p id="{{NAME}}-error" role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
</div>
HTML, [
            '{{NAME}}' => $name,
            '{{LABEL}}' => $this->escapeTranslation($field['label'] ?? 'Password'),
            '{{AUTOCOMPLETE}}' => $field['autocomplete'] ?? 'current-password',
            '{{LABEL_CLASSES}}' => $this->labelClasses(),
            '{{CONTROL_CLASSES}}' => $this->controlClasses(),
            '{{FOCUS_RING}}' => $this->accentClass('focus-visible:ring'),
            '{{REQUIRED_MARK}}' => ($field['required'] ?? true)
                ? '<span class="text-red-500" aria-hidden="true"> *</span>'
                : '',
            '{{TRAILING}}' => $field['trailing'] ?? '',
            '{{METER_STATE}}' => $meter ? ", pw: ''" : '',
            '{{METER_BINDING}}' => $meter ? 'x-on:input="pw = $event.target.value"' : '',
            '{{METER}}' => $meter ? $this->strengthMeter() : '',
        ]);
    }

    private function strengthMeter(): string
    {
        return <<<'HTML'
    <div class="mt-2" x-show="pw.length > 0" x-cloak>
        <div
            x-data="{
                get score() {
                    let s = 0;
                    if (this.pw.length >= 8) s++;
                    if (this.pw.length >= 12) s++;
                    if (/[a-z]/.test(this.pw) && /[A-Z]/.test(this.pw)) s++;
                    if (/[0-9]/.test(this.pw)) s++;
                    if (/[^A-Za-z0-9]/.test(this.pw)) s++;
                    return Math.min(s, 4);
                },
            }"
            class="flex items-center gap-2"
        >
            <div class="flex h-1.5 flex-1 gap-1" role="presentation">
                <template x-for="i in 4" :key="i">
                    <div
                        class="h-full flex-1 rounded-full transition-colors"
                        :class="i <= score
                            ? (score <= 1 ? 'bg-red-500' : score === 2 ? 'bg-amber-500' : score === 3 ? 'bg-lime-500' : 'bg-emerald-500')
                            : 'bg-gray-200 dark:bg-white/10'"
                    ></div>
                </template>
            </div>
            <span
                class="w-16 text-right text-xs font-medium text-gray-500 dark:text-gray-400"
                x-text="score <= 1 ? '{{ __('Weak') }}' : score === 2 ? '{{ __('Fair') }}' : score === 3 ? '{{ __('Good') }}' : '{{ __('Strong') }}'"
            ></span>
        </div>
        <p class="sr-only" aria-live="polite" x-text="'{{ __('Password strength') }}: ' + score + '/4'"></p>
    </div>
HTML;
    }

    public function checkbox(string $name, string $label): string
    {
        return $this->render(<<<'HTML'
<div class="flex items-center gap-2">
    <input
        wire:model.live="{{NAME}}"
        id="{{NAME}}"
        name="{{NAME}}"
        type="checkbox"
        class="h-4 w-4 rounded border-gray-300 {{TEXT}} {{FOCUS_RING}} dark:border-white/20 dark:bg-white/5"
    >
    <label for="{{NAME}}" class="text-sm text-gray-700 dark:text-gray-300">{{ __('{{LABEL}}') }}</label>
</div>
HTML, [
            '{{NAME}}' => $name,
            '{{LABEL}}' => $this->escapeTranslation($label),
            '{{TEXT}}' => $this->accentClass('text'),
            '{{FOCUS_RING}}' => $this->accentClass('focus:ring'),
        ]);
    }

    /**
     * Submit button with a loading state wired to a specific action, so a slow
     * request cannot be double-submitted.
     */
    public function submit(string $label, string $target, ?string $loadingLabel = null): string
    {
        return $this->render(<<<'HTML'
<button
    type="submit"
    wire:loading.attr="disabled"
    wire:target="{{TARGET}}"
    class="flex w-full items-center justify-center gap-2 rounded-lg {{BG}} px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition {{HOVER}} focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 {{OUTLINE}} disabled:cursor-not-allowed disabled:opacity-60"
>
    <svg wire:loading wire:target="{{TARGET}}" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
    </svg>
    <span wire:loading.remove wire:target="{{TARGET}}">{{ __('{{LABEL}}') }}</span>
    <span wire:loading wire:target="{{TARGET}}">{{ __('{{LOADING}}') }}</span>
</button>
HTML, [
            '{{TARGET}}' => $target,
            '{{LABEL}}' => $this->escapeTranslation($label),
            '{{LOADING}}' => $this->escapeTranslation($loadingLabel ?? $label.'…'),
            '{{BG}}' => $this->accentClass('bg'),
            '{{HOVER}}' => $this->accentClass('hover:bg', '700'),
            '{{OUTLINE}}' => $this->accentClass('focus-visible:outline'),
        ]);
    }

    /**
     * The card every auth screen sits in.
     */
    public function card(string $title, string $subtitle, string $body, string $footer = ''): string
    {
        return $this->render(<<<'HTML'
<div class="w-full max-w-md">
    <div class="rounded-2xl bg-white/90 p-8 shadow-xl ring-1 ring-gray-900/5 backdrop-blur dark:bg-gray-900/70 dark:ring-white/10">
        <div class="mb-6">
            <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ __('{{TITLE}}') }}</h1>
            {{SUBTITLE}}
        </div>

        <x-anvil.auth-status />

{{BODY}}
    </div>
{{FOOTER}}
</div>
HTML, [
            '{{TITLE}}' => $this->escapeTranslation($title),
            '{{SUBTITLE}}' => $subtitle === ''
                ? ''
                : '<p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __(\''.$this->escapeTranslation($subtitle).'\') }}</p>',
            '{{BODY}}' => $this->indent($body, 2),
            '{{FOOTER}}' => $footer === ''
                ? ''
                : '    <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">'.$footer.'</p>',
        ]);
    }

    /**
     * Session status banner. Emitted once as a Blade component so seven screens
     * do not each carry their own copy.
     */
    public function statusComponent(): string
    {
        return <<<'HTML'
@if (session('status'))
    <div role="status" class="mb-6 flex items-start gap-3 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-800 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20">
        <svg class="mt-0.5 h-4 w-4 flex-none" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
        </svg>
        <span>{{ session('status') }}</span>
    </div>
@endif
HTML;
    }

    public function link(string $href, string $label): string
    {
        return sprintf(
            '<a href="%s" wire:navigate class="font-semibold %s hover:underline">{{ __(\'%s\') }}</a>',
            $href,
            $this->accentClass('text'),
            $this->escapeTranslation($label),
        );
    }

    // -----------------------------------------------------------------------

    /**
     * @param  array<string, string>  $replacements
     */
    private function render(string $template, array $replacements): string
    {
        return rtrim(strtr($template, $replacements))."\n";
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function attributes(array $field): string
    {
        $attributes = [];

        if ($field['required'] ?? true) {
            $attributes[] = 'required';
        }

        if ($field['autofocus'] ?? false) {
            $attributes[] = 'autofocus';
        }

        foreach (['autocomplete', 'placeholder', 'inputmode'] as $key) {
            if (isset($field[$key]) && $field[$key] !== '') {
                $attributes[] = sprintf('%s="%s"', $key, htmlspecialchars((string) $field[$key], ENT_QUOTES));
            }
        }

        return implode(' ', $attributes);
    }

    private function indent(string $body, int $levels): string
    {
        $pad = str_repeat('    ', $levels);

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : $pad.$line,
            explode("\n", rtrim($body)),
        ));
    }

    /**
     * Labels land inside __('...'), so an apostrophe would close the string and
     * break the generated Blade at compile time.
     */
    private function escapeTranslation(string $text): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $text);
    }
}
