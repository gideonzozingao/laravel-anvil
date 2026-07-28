<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth\Parts;

use Zuqongtech\LaravelAnvil\Support\Auth\AuthContext;
use Zuqongtech\LaravelAnvil\Support\Auth\Contracts\ScaffoldPart;
use Zuqongtech\LaravelAnvil\Support\Auth\ScaffoldWriter;

/**
 * routes/auth.php.
 *
 * Emitted last because it names every component class and has to agree with which
 * features were generated.
 *
 * THE GUARD FIX
 *
 * The previous file used bare `Route::middleware('guest')` and `'auth'` — the
 * DEFAULT guard — while every generated component authenticates
 * Auth::guard($guard). With --guard=admin the two disagreed: the routes were
 * protected by the web guard and the components signed users into the admin guard,
 * so a signed-in admin still looked like a guest to the middleware and the
 * redirect loop that follows is baffling to debug. Both middleware are now
 * guard-qualified.
 */
final readonly class RoutesPart implements ScaffoldPart
{
    public function supports(AuthContext $context): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'Routes';
    }

    public function emit(AuthContext $context, ScaffoldWriter $writer): void
    {
        $writer->baseFile(
            'routes/auth.php',
            $writer->tokens()->render($this->template(), [
                '%IMPORTS%' => $this->imports($context),
                '%GUEST_ROUTES%' => $this->guestRoutes($context),
                '%AUTH_ROUTES%' => $this->authRoutes($context),
            ]),
            'Routes',
            'routes/auth.php',
        );
    }

    /**
     * @return list<string>
     */
    public function notes(AuthContext $context): array
    {
        $notes = [];

        if ($context->guard !== 'web') {
            $notes[] = sprintf(
                'The routes are guarded with auth:%s / guest:%s. Confirm auth.guards.%s exists in config/auth.php — '
                    .'an undefined guard throws on the first request.',
                $context->guard,
                $context->guard,
                $context->guard,
            );
        }

        $notes[] = "Apply the 'password.confirm' middleware to any route that should require recent re-authentication.";

        return $notes;
    }

    // -----------------------------------------------------------------------
    // Composition
    // -----------------------------------------------------------------------

    private function imports(AuthContext $context): string
    {
        $classes = ['ForgotPassword', 'Login', 'Register', 'ResetPassword'];

        if ($context->verification) {
            $classes[] = 'VerifyEmail';
        }

        if ($context->twoFactor) {
            $classes[] = 'TwoFactorChallenge';
            $classes[] = 'TwoFactorSettings';
        }

        sort($classes);

        return implode("\n", array_map(
            static fn (string $class): string => 'use %AUTH_NS%\\'.$class.';',
            $classes,
        ));
    }

    private function guestRoutes(AuthContext $context): string
    {
        $routes = <<<'PHP'
    Route::get('login', Login::class)->name('login');
    Route::get('register', Register::class)->name('register');
    Route::get('forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('reset-password/{token}', ResetPassword::class)->name('password.reset');
PHP;

        if ($context->twoFactor) {
            // Guest, not auth: Login logs the user back out before redirecting
            // here, so the challenge is reached without an authenticated session.
            $routes .= "\n    Route::get('two-factor-challenge', TwoFactorChallenge::class)"
                ."\n        ->middleware('throttle:20,1')"
                ."\n        ->name('two-factor.login');";
        }

        return $routes;
    }

    private function authRoutes(AuthContext $context): string
    {
        $blocks = [];

        if ($context->verification) {
            $blocks[] = <<<'PHP'
    Route::get('verify-email', VerifyEmail::class)->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->intended('/');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
PHP;
        }

        if ($context->twoFactor) {
            $blocks[] = <<<'PHP'
    // password.confirm forces recent re-authentication before the second factor
    // can be changed, so a hijacked-but-idle session cannot silently re-enrol.
    Route::get('settings/two-factor', TwoFactorSettings::class)
        ->middleware('password.confirm')
        ->name('two-factor.settings');
PHP;
        }

        $blocks[] = <<<'PHP'
    Route::post('logout', function () {
        Auth::guard('%GUARD%')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect('/');
    })->name('logout');
PHP;

        return implode("\n\n", $blocks);
    }

    private function template(): string
    {
        return <<<'PHP'
<?php

%IMPORTS%
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication routes
|--------------------------------------------------------------------------
|
| Generated by  php artisan anvil:forge-auth.
| Require this file from routes/web.php:  require __DIR__.'/auth.php';
|
| Both middleware are qualified with the '%GUARD%' guard so they agree with the
| guard the components authenticate against. An unqualified 'auth' here would
| check the default guard instead, and a non-default --guard would produce a
| signed-in user that the middleware still treats as a guest.
|
*/

Route::middleware('guest:%GUARD%')->group(function (): void {
%GUEST_ROUTES%
});

Route::middleware('auth:%GUARD%')->group(function (): void {
%AUTH_ROUTES%
});
PHP;
    }
}
