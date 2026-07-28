<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth\Ui;

/**
 * The Blade primitives every auth screen is assembled from.
 *
 * One place to restyle auth: change card() or field() here and all seven screens
 * follow. That was already true of the original helpers — extracting them just
 * means editing them no longer means scrolling past 900 lines of unrelated
 * component templates.
 */
final readonly class FormKit
{
    public function __construct(private IconSet $icons = new IconSet) {}

    public function icon(string $name, string $class = 'h-5 w-5'): string
    {
        return $this->icons->render($name, $class);
    }

    /**
     * The shell every screen shares: icon tile, heading, sub-heading, status
     * region, body, optional footer.
     */
    public function card(string $icon, string $heading, string $subheading, string $body, string $footer = ''): string
    {
        $footerBlock = $footer === '' ? '' : <<<BLADE


    <p class="mt-7 border-t border-gray-100 pt-5 text-center text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
        {$footer}
    </p>
BLADE;

        $iconSvg = $this->icon($icon);
        $statusIcon = $this->icon('check-circle', 'mt-0.5 h-5 w-5 shrink-0');

        return <<<BLADE
<div class="card p-6 sm:p-8">
    <div class="icon-tile">{$iconSvg}</div>

    <h1 class="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">{$heading}</h1>
    <p class="mt-1.5 mb-6 text-sm text-gray-500 dark:text-gray-400">{$subheading}</p>

    @if (session('status'))
        <div class="alert-ok mb-5" role="status">
            {$statusIcon}
            <span>{{ session('status') }}</span>
        </div>
    @endif

{$body}{$footerBlock}
</div>
BLADE;
    }

    /** A text input with an optional leading icon, error state and hint. */
    public function field(
        string $model,
        string $label,
        string $type = 'text',
        string $autocomplete = 'off',
        ?string $icon = null,
        bool $autofocus = false,
        string $hint = '',
        string $placeholder = '',
    ): string {
        $iconMarkup = $icon === null ? '' : '<span class="input-affix">'.$this->icon($icon, 'h-4 w-4').'</span>';
        $iconClass = $icon === null ? '' : ' form-input-icon';
        $focus = $autofocus ? ' autofocus' : '';
        $placeholderAttr = $placeholder === '' ? '' : ' placeholder="'.$placeholder.'"';
        $hintMarkup = $hint === '' ? '' : "\n            <p class=\"form-hint\">{$hint}</p>";
        $errorIcon = $this->icon('exclamation', 'mt-0.5 h-3.5 w-3.5 shrink-0');

        return <<<BLADE
        <div>
            <label class="form-label" for="{$model}">{$label}</label>
            <div class="relative">
                {$iconMarkup}
                <input wire:model="{$model}" id="{$model}" name="{$model}" type="{$type}"
                       autocomplete="{$autocomplete}" required{$focus}{$placeholderAttr}
                       @error('{$model}') aria-invalid="true" aria-describedby="{$model}-error" @enderror
                       class="form-input{$iconClass} @error('{$model}') form-input-error @enderror">
            </div>{$hintMarkup}
            @error('{$model}')
                <p class="form-error" id="{$model}-error">
                    {$errorIcon}
                    <span>{{ \$message }}</span>
                </p>
            @enderror
        </div>
BLADE;
    }

    /** A password input with a reveal toggle and an optional strength meter. */
    public function password(
        string $model,
        string $label,
        string $autocomplete = 'current-password',
        bool $strength = false,
        bool $autofocus = false,
    ): string {
        $strengthAttr = $strength ? " data-strength=\"{$model}-meter\"" : '';
        $focus = $autofocus ? ' autofocus' : '';
        $meter = $strength ? $this->strengthMeter($model) : '';
        $lockIcon = $this->icon('lock', 'h-4 w-4');
        $eye = $this->icon('eye', 'h-4 w-4');
        $eyeOff = $this->icon('eye-off', 'h-4 w-4');
        $errorIcon = $this->icon('exclamation', 'mt-0.5 h-3.5 w-3.5 shrink-0');

        return <<<BLADE
        <div>
            <label class="form-label" for="{$model}">{$label}</label>
            <div class="relative">
                <span class="input-affix">{$lockIcon}</span>
                <input wire:model="{$model}" id="{$model}" name="{$model}" type="password"
                       autocomplete="{$autocomplete}" required{$focus}{$strengthAttr}
                       @error('{$model}') aria-invalid="true" aria-describedby="{$model}-error" @enderror
                       class="form-input form-input-icon form-input-action @error('{$model}') form-input-error @enderror">
                <button type="button" data-reveal="{$model}" class="input-action" aria-label="Show password" tabindex="-1">
                    <span data-reveal-icon>{$eye}</span>
                    <span data-reveal-icon class="hidden">{$eyeOff}</span>
                </button>
            </div>{$meter}
            @error('{$model}')
                <p class="form-error" id="{$model}-error">
                    {$errorIcon}
                    <span>{{ \$message }}</span>
                </p>
            @enderror
        </div>
BLADE;
    }

    /** A one-time-code input: monospace, wide tracking, numeric keypad. */
    public function otpField(string $model = 'code', string $label = 'Authentication code', int $maxlength = 6): string
    {
        $errorIcon = $this->icon('exclamation', 'mt-0.5 h-3.5 w-3.5 shrink-0');

        return <<<BLADE
        <div>
            <label class="form-label" for="{$model}">{$label}</label>
            <input wire:model="{$model}" id="{$model}" name="{$model}" type="text" inputmode="numeric"
                   autocomplete="one-time-code" maxlength="{$maxlength}" autofocus placeholder="000000"
                   @error('{$model}') aria-invalid="true" aria-describedby="{$model}-error" @enderror
                   class="form-input text-center font-mono text-lg tracking-[0.4em] @error('{$model}') form-input-error @enderror">
            @error('{$model}')
                <p class="form-error" id="{$model}-error">
                    {$errorIcon}
                    <span>{{ \$message }}</span>
                </p>
            @enderror
        </div>
BLADE;
    }

    public function strengthMeter(string $model): string
    {
        $items = [
            'length' => '12+ characters',
            'case' => 'Upper & lowercase',
            'number' => 'A number',
            'symbol' => 'A symbol',
        ];

        $checks = '';

        foreach ($items as $key => $text) {
            $checkIcon = $this->icon('check', 'h-3 w-3 shrink-0');
            $checks .= <<<BLADE

                    <li class="check-item" data-check="{$key}" data-met="0">
                        {$checkIcon}{$text}
                    </li>
BLADE;
        }

        return <<<BLADE

            <div id="{$model}-meter" class="mt-2.5">
                <div class="flex items-center gap-2">
                    <div class="flex flex-1 gap-1">
                        <span data-bar class="strength-bar"></span>
                        <span data-bar class="strength-bar"></span>
                        <span data-bar class="strength-bar"></span>
                        <span data-bar class="strength-bar"></span>
                    </div>
                    <span data-strength-label class="w-12 text-right text-xs font-medium text-gray-500 dark:text-gray-400"></span>
                </div>
                <ul class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">{$checks}
                </ul>
            </div>
BLADE;
    }

    /** A submit button with a spinner bound to the given Livewire action. */
    public function submit(string $action, string $label, string $busyLabel, string $class = 'btn-primary'): string
    {
        $spinner = $this->icon('spinner', 'h-4 w-4 animate-spin');

        return <<<BLADE
        <button type="submit" class="{$class}" wire:loading.attr="disabled" wire:target="{$action}">
            <span wire:loading.remove wire:target="{$action}">{$label}</span>
            <span wire:loading.flex wire:target="{$action}" class="items-center gap-2">
                {$spinner}
                {$busyLabel}
            </span>
        </button>
BLADE;
    }

    /** An inline alert block. $variant is one of error|ok|info|warn. */
    public function alert(string $variant, string $body, string $icon = 'info', string $extraClass = 'mb-5'): string
    {
        $iconSvg = $this->icon($icon, 'mt-0.5 h-5 w-5 shrink-0');
        $role = $variant === 'error' ? 'alert' : 'status';

        return <<<BLADE
    <div class="alert-{$variant} {$extraClass}" role="{$role}">
        {$iconSvg}
        <span>{$body}</span>
    </div>
BLADE;
    }
}
