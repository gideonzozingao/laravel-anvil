<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Livewire\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Coercion layer between a Livewire form's raw (always-string, often empty)
 * property values and the model.
 *
 * Generated components declare a cast map and get both directions for free:
 *
 *     use NormalizesFormState;
 *
 *     public $tenant_id = null;
 *     public $amount = null;
 *     public $is_active = false;
 *
 *     protected array $anvilCasts = [
 *         'tenant_id' => 'int',
 *         'amount' => 'decimal?',
 *         'is_active' => 'bool',
 *     ];
 *
 *     public function mount(?BillingPaymentSchedule $record = null): void
 *     {
 *         $this->record = $record;
 *
 *         if ($record?->exists) {
 *             $this->fillFormState($record);
 *         }
 *     }
 *
 *     public function save(): void
 *     {
 *         $this->validate();
 *
 *         $payload = $this->normalizedFormState();
 *
 *         $this->record?->exists
 *             ? $this->record->update($payload)
 *             : BillingPaymentSchedule::create($payload);
 *     }
 *
 * A trailing "?" on a cast marks a nullable column: an empty input becomes null.
 * Without it, an empty input on a NOT NULL string column stays "" — which is a
 * legal value and lets validation, not a 23502, be the thing that complains.
 */
trait NormalizesFormState
{
    /**
     * Column => cast, as rendered by LivewirePropertyMap::renderCastMap().
     *
     * @return array<string, string>
     */
    protected function anvilCastMap(): array
    {
        return property_exists($this, 'anvilCasts') && is_array($this->anvilCasts)
            ? $this->anvilCasts
            : [];
    }

    /**
     * Form state coerced to model-ready values.
     *
     * @param  list<string>|null  $only  Restrict to these columns (partial saves).
     * @return array<string, mixed>
     */
    protected function normalizedFormState(?array $only = null): array
    {
        $payload = [];

        foreach ($this->anvilCastMap() as $name => $cast) {
            if ($only !== null && ! in_array($name, $only, true)) {
                continue;
            }

            if (! property_exists($this, $name)) {
                continue;
            }

            $payload[$name] = $this->normalizeFormValue($this->{$name}, $cast);
        }

        return $payload;
    }

    /**
     * Coerce a single raw value.
     */
    protected function normalizeFormValue(mixed $value, string $cast): mixed
    {
        $nullable = str_ends_with($cast, '?');
        $cast = rtrim($cast, '?');

        if (is_string($value)) {
            $value = trim($value);
        }

        // Arrays and booleans have a meaningful empty value; everything else
        // treats "blank" as absent.
        if ($cast === 'array') {
            return $this->normalizeArrayValue($value, $nullable);
        }

        if ($cast === 'bool') {
            if ($value === null || $value === '') {
                return $nullable ? null : false;
            }

            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        if (in_array($value, [null, '', []], true)) {
            // A NOT NULL string column keeps "" — that is a value, not a gap.
            return ($cast === 'string' && ! $nullable && $value === '') ? '' : null;
        }

        return match ($cast) {
            // is_numeric guards the case validation did not: a stray "n/a" becomes
            // null instead of (int) 0, which would silently write a wrong FK.
            'int' => is_numeric($value) ? (int) $value : null,
            'float' => is_numeric($this->stripNumericNoise($value)) ? (float) $this->stripNumericNoise($value) : null,
            // Kept as a string: (float) "12345678.91" loses cents at scale.
            'decimal' => is_numeric($this->stripNumericNoise($value)) ? (string) $this->stripNumericNoise($value) : null,
            'date' => $this->normalizeDate($value, 'Y-m-d'),
            'datetime' => $this->normalizeDate($value, 'Y-m-d H:i:s'),
            'time' => $this->normalizeTime($value),
            default => is_scalar($value) ? (string) $value : $value,
        };
    }

    /**
     * Push a model's attributes back into form state, converted to something an
     * <input> can render. Without this, a Carbon instance bound to a date input
     * renders as a full ISO string and fails to re-parse, and a json column
     * arrives as a string that breaks foreach.
     */
    protected function fillFormState(Model $record): void
    {
        foreach ($this->anvilCastMap() as $name => $cast) {
            if (! property_exists($this, $name)) {
                continue;
            }

            $this->{$name} = $this->presentFormValue($record->getAttribute($name), $cast);
        }
    }

    /**
     * Reset every bound property to its empty-form value.
     */
    protected function resetFormState(): void
    {
        foreach ($this->anvilCastMap() as $name => $cast) {
            if (! property_exists($this, $name)) {
                continue;
            }

            $base = rtrim((string) $cast, '?');

            $this->{$name} = match ($base) {
                'array' => [],
                'bool' => str_ends_with((string) $cast, '?') ? null : false,
                default => null,
            };
        }

        $this->resetValidation();
    }

    // -----------------------------------------------------------------------

    protected function presentFormValue(mixed $value, string $cast): mixed
    {
        $base = rtrim($cast, '?');

        if ($value === null) {
            return match ($base) {
                'array' => [],
                'bool' => str_ends_with($cast, '?') ? null : false,
                default => null,
            };
        }

        return match ($base) {
            'array' => $this->normalizeArrayValue($value, false),
            'bool' => (bool) $value,
            'date' => $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string) $value,
            // datetime-local wants exactly "Y-m-d\TH:i".
            'datetime' => $value instanceof DateTimeInterface ? $value->format('Y-m-d\TH:i') : (string) $value,
            'time' => $value instanceof DateTimeInterface ? $value->format('H:i') : substr((string) $value, 0, 5),
            default => is_scalar($value) ? (string) $value : $value,
        };
    }

    protected function normalizeArrayValue(mixed $value, bool $nullable): mixed
    {
        if ($value === null || $value === '') {
            return $nullable ? null : [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }

            // Fall back to a comma-separated list from a plain text input.
            return array_values(array_filter(array_map(trim(...), explode(',', $value)), fn ($v): bool => $v !== ''));
        }

        return [$value];
    }

    /**
     * Strip the separators a locale-aware user types into a number field.
     * "K 1,250.00" → "1250.00"
     */
    protected function stripNumericNoise(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $stripped = preg_replace('/[^0-9eE+\-.]/', '', $value);

        return $stripped === '' ? $value : $stripped;
    }

    protected function normalizeDate(mixed $value, string $format): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format($format);
        }

        if (! is_string($value)) {
            return null;
        }

        // <input type="datetime-local"> posts "2026-07-29T14:30".
        $value = str_replace('T', ' ', trim($value));

        try {
            return (new \DateTimeImmutable($value))->format($format);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function normalizeTime(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s');
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        return preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value) === 1
            ? (strlen($value) === 5 ? $value.':00' : $value)
            : null;
    }
}
