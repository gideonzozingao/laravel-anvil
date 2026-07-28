<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth\Fragments;

use Zuqongtech\LaravelAnvil\Support\Auth\AuthContext;

/**
 * Account lockout: the code fragments injected into Login, plus its migration.
 *
 * A fragment rather than a part because it has no files of its own beyond the
 * migration — it modifies another part's output. Making that relationship
 * explicit is the point: previously these blocks lived in the token map, which
 * meant the map knew about Login's internals and Login had no idea which of its
 * placeholders were feature-gated.
 *
 * Three corrections carried over from the original templates:
 *
 *   1. registerFailedAttempt() took a non-nullable User but was called with the
 *      result of a first() lookup. An unknown email therefore threw a TypeError —
 *      a 500 on the most common failure path, and an enumeration oracle, since a
 *      wrong password returned 422.
 *   2. locked_until->isFuture() assumed the column was cast to a date. Eloquent
 *      only auto-casts created_at/updated_at, so on a stock model this was
 *      "Call to a member function isFuture() on string".
 *   3. The attempt counter was zeroed on lockout, so an attacker got a fresh
 *      allowance every window with no escalation. It now persists and the window
 *      grows with it.
 */
final readonly class LockoutFragment
{
    public function __construct(private AuthContext $context) {}

    public function applies(): bool
    {
        return $this->context->lockout;
    }

    /** Guard placed before the credential check. */
    public function check(): string
    {
        if (! $this->applies()) {
            return '';
        }

        return <<<'PHP'

        // Carbon::parse rather than ->isFuture(): Eloquent only auto-casts
        // created_at/updated_at, so locked_until is a plain string unless the
        // User model declares a cast for it.
        if ($user && filled($user->locked_until)) {
            $lockedUntil = Carbon::parse($user->locked_until);

            if ($lockedUntil->isFuture()) {
                throw ValidationException::withMessages([
                    'email' => __('This account is temporarily locked. Try again :time.', [
                        'time' => $lockedUntil->diffForHumans(),
                    ]),
                ]);
            }
        }
PHP;
    }

    /** Called inside the failed-credentials branch. */
    public function onFailure(): string
    {
        return $this->applies()
            ? '            $this->registerFailedAttempt($user);'
            : '';
    }

    /** Fields merged into the successful-login forceFill. */
    public function resetFields(): string
    {
        return $this->applies()
            ? "'failed_login_attempts' => 0, 'locked_until' => null, "
            : '';
    }

    /** The method itself. */
    public function method(): string
    {
        if (! $this->applies()) {
            return '';
        }

        return <<<'PHP'

    /**
     * Record a failed attempt and lock the account once the threshold is hit.
     *
     * Nullable by design: $user is the result of a lookup that misses whenever
     * the submitted email does not exist. There is nothing to record in that
     * case, and the throttle above already covers it.
     */
    protected function registerFailedAttempt(?User $user): void
    {
        if (! $user) {
            return;
        }

        $attempts = (int) ($user->failed_login_attempts ?? 0) + 1;
        $payload = ['failed_login_attempts' => $attempts];

        if ($attempts >= %LOCK_THRESHOLD%) {
            // The counter is NOT reset here. Keeping it means each subsequent
            // lockout lasts longer, so a sustained attack faces a growing wall
            // instead of the same %LOCK_MINUTES% minutes forever.
            $multiplier = max(1, intdiv($attempts, %LOCK_THRESHOLD%));
            $payload['locked_until'] = now()->addMinutes(min(%LOCK_MINUTES% * $multiplier, 1440));
        }

        $user->forceFill($payload)->save();
    }
PHP;
    }

    /** Extra `use` statements Login needs when this fragment is active. */
    public function imports(): string
    {
        return $this->applies() ? "use Illuminate\Support\Carbon;\n" : '';
    }

    public function migration(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the generated Login component writes on every attempt. Without them a
 * successful sign-in throws, so this migration must run before using the
 * scaffold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('%USERS_TABLE%', function (Blueprint $table): void {
            if (! Schema::hasColumn('%USERS_TABLE%', 'failed_login_attempts')) {
                $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            }

            if (! Schema::hasColumn('%USERS_TABLE%', 'locked_until')) {
                $table->timestamp('locked_until')->nullable()->index();
            }

            if (! Schema::hasColumn('%USERS_TABLE%', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable();
            }

            if (! Schema::hasColumn('%USERS_TABLE%', 'last_login_ip')) {
                // 45 characters: the longest IPv6 form, including an IPv4 tail.
                $table->string('last_login_ip', 45)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('%USERS_TABLE%', function (Blueprint $table): void {
            $table->dropColumn(['failed_login_attempts', 'locked_until', 'last_login_at', 'last_login_ip']);
        });
    }
};
PHP;
    }

    /**
     * Casts the User model needs for the generated code to behave.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        if (! $this->applies()) {
            return [];
        }

        $notes = [];

        if ($this->context->needsLoginSecurityMigration()) {
            $notes[] = 'Run  php artisan migrate  before signing in: login writes failed_login_attempts, '
                .'locked_until, last_login_at and last_login_ip.';
        }

        $notes[] = "Add to the User model's \$casts:  'locked_until' => 'datetime', 'last_login_at' => 'datetime'  "
            .'— the generated code parses the column defensively, but casting keeps it a Carbon everywhere else.';

        return $notes;
    }
}
