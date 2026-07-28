<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth\Parts;

use Zuqongtech\LaravelAnvil\Support\Auth\AuthContext;
use Zuqongtech\LaravelAnvil\Support\Auth\Contracts\ScaffoldPart;
use Zuqongtech\LaravelAnvil\Support\Auth\Fragments\TwoFactorGateFragment;
use Zuqongtech\LaravelAnvil\Support\Auth\ScaffoldWriter;
use Zuqongtech\LaravelAnvil\Support\Auth\Ui\FormKit;

/**
 * Two-factor authentication: the challenge, the enrolment screen, the TOTP
 * service, and the columns they need.
 *
 * FOUR CORRECTIONS, ALL SECURITY-RELEVANT
 *
 * 1. THE QR CODE NO LONGER LEAVES THE SERVER. The previous view rendered
 *    <img src="https://api.qrserver.com/...?data={{ urlencode($provisioningUri) }}">.
 *    That URI *contains the TOTP shared secret*, so every enrolment published the
 *    user's second factor to a third party — in their access logs, in the user's
 *    browser history, and to anything on the path. The SVG is now rendered
 *    in-process by bacon/bacon-qr-code; without that package the screen falls back
 *    to manual entry rather than reaching for an external service.
 *
 * 2. THE CHALLENGE IS THROTTLED AND EXPIRES. A six-digit code with unlimited
 *    attempts is not a second factor. Five attempts per pending sign-in, and the
 *    pending state expires — previously the session key had no lifetime, so an
 *    abandoned challenge stayed redeemable forever.
 *
 * 3. ENABLING REQUIRES THE PASSWORD, AND CANNOT OVERWRITE. disable() asked for a
 *    password while enable() did not, so a hijacked session could enrol the
 *    attacker's device — or re-enrol over a confirmed secret, since enable()
 *    unconditionally nulled two_factor_confirmed_at.
 *
 * 4. RECOVERY CODES ARE HASHED. They are credentials. Encrypted-at-rest matches
 *    what Fortify does, but it means database access plus APP_KEY yields usable
 *    codes; hashes do not. The plaintext is shown exactly once, from the session.
 *
 * Also: the secret is no longer a public Livewire property. Public properties are
 * serialised into the page payload on every request and are client-writable absent
 * #[Locked]; a computed property reading the session is neither.
 */
final readonly class TwoFactorPart implements ScaffoldPart
{
    public function __construct(private FormKit $ui = new FormKit) {}

    public function supports(AuthContext $context): bool
    {
        return $context->twoFactor;
    }

    public function name(): string
    {
        return 'TwoFactor';
    }

    public function emit(AuthContext $context, ScaffoldWriter $writer): void
    {
        $writer->component(
            'TwoFactorChallenge',
            $this->challengeComponent($writer),
            $this->challengeView($writer),
        );

        // Without an enrolment screen nothing can ever write two_factor_secret,
        // which makes the challenge unreachable and the feature decorative.
        $writer->component(
            'TwoFactorSettings',
            $this->settingsComponent($writer),
            $this->settingsView($writer),
        );

        $writer->appFile(
            'Services/TwoFactorAuthenticationService.php',
            $writer->tokens()->render($this->service()),
            'Service',
            'TwoFactorAuthenticationService',
        );

        if ($context->needsTwoFactorMigration()) {
            $writer->migration(
                'add_two_factor_columns_to_' . $context->usersTable . '_table',
                $writer->tokens()->render($this->migration()),
                '2fa columns',
            );
        }
    }

    /**
     * @return list<string>
     */
    public function notes(AuthContext $context): array
    {
        return [
            'Install the TOTP library:  composer require pragmarx/google2fa',
            'Install the QR renderer:  composer require bacon/bacon-qr-code  — without it the enrolment screen falls '
                . 'back to manual secret entry. Nothing is ever sent to a third-party QR service.',
            'Link to the setup screen from your account area:  route(\'two-factor.settings\')',
            "Cast the columns:  'two_factor_confirmed_at' => 'datetime'  in the User model's \$casts.",
            'Recovery codes are hashed, so they cannot be re-displayed. Regenerating them is the only way to recover '
                . 'a lost list.',
        ];
    }

    // -----------------------------------------------------------------------
    // Challenge
    // -----------------------------------------------------------------------

    private function challengeComponent(ScaffoldWriter $writer): string
    {
        return $writer->tokens()->render($this->challengeTemplate(), [
            '%PENDING_SECONDS%' => (string) TwoFactorGateFragment::PENDING_SECONDS,
        ]);
    }

    private function challengeTemplate(): string
    {
        return <<<'PHP'
<?php

namespace %AUTH_NS%;

use %USER_FQN%;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The second step of sign-in.
 *
 * The pending user id lives in the session, never in a component property: a
 * public property is client-writable, and being able to nominate which account is
 * being challenged would defeat the whole mechanism.
 */
#[Layout('%LAYOUT%')]
class TwoFactorChallenge extends Component
{
    /** Attempts allowed against one pending sign-in. */
    private const MAX_ATTEMPTS = 5;

    #[Validate('required|string|min:6|max:64')]
    public string $code = '';

    public function mount()
    {
        if (! $this->pendingId()) {
            return $this->redirect(route('login'), navigate: true);
        }

        return null;
    }

    public function verify()
    {
        $this->validate();

        $pendingId = $this->pendingId();

        if (! $pendingId) {
            return $this->redirect(route('login'), navigate: true);
        }

        $this->ensureIsNotRateLimited($pendingId);

        $user = User::find($pendingId);

        if (! $user) {
            $this->forgetPending();

            return $this->redirect(route('login'), navigate: true);
        }

        $service = app(TwoFactorAuthenticationService::class);
        $code = trim(str_replace([' ', '-'], '', $this->code));

        // decrypt() throws if APP_KEY rotated since the secret was stored; a
        // failed attempt is the right outcome, not a 500.
        $passed = false;

        try {
            $passed = $service->verify(decrypt($user->two_factor_secret), $code);
        } catch (\Throwable) {
            $passed = false;
        }

        if (! $passed) {
            $passed = $this->consumeRecoveryCode($service, $user, $this->code);
        }

        if (! $passed) {
            RateLimiter::hit($this->throttleKey($pendingId), %PENDING_SECONDS%);

            throw ValidationException::withMessages(['code' => __('That code is not valid.')]);
        }

        RateLimiter::clear($this->throttleKey($pendingId));

        $remember = (bool) session('login.2fa.remember');
        $this->forgetPending();

        Auth::guard('%GUARD%')->login($user, $remember);
        session()->regenerate();

        return $this->redirectIntended(default: '/', navigate: true);
    }

    /**
     * Recovery codes are stored hashed, so every candidate must be checked
     * individually. A match is consumed — single use is the entire point.
     */
    protected function consumeRecoveryCode(TwoFactorAuthenticationService $service, User $user, string $submitted): bool
    {
        if (! $user->two_factor_recovery_codes) {
            return false;
        }

        try {
            $hashes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?: [];
        } catch (\Throwable) {
            return false;
        }

        $remaining = $service->consumeRecoveryCode(trim($submitted), $hashes);

        if ($remaining === null) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($remaining)),
        ])->save();

        return true;
    }

    /**
     * Five attempts against one pending sign-in. Without this a six-digit code is
     * guessable inside the TOTP window.
     */
    protected function ensureIsNotRateLimited(int|string $pendingId): void
    {
        $key = $this->throttleKey($pendingId);

        if (! RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout(request()));

        // Too many wrong codes abandons the sign-in entirely rather than leaving
        // a pending session someone can come back to.
        $this->forgetPending();

        throw ValidationException::withMessages([
            'code' => __('Too many attempts. Please sign in again.'),
        ]);
    }

    protected function throttleKey(int|string $pendingId): string
    {
        return 'two-factor|'.$pendingId.'|'.request()->ip();
    }

    /**
     * The pending id, or null when it is absent or expired.
     *
     * The expiry is the correction: previously the session key had no lifetime, so
     * a browser left on this screen held a redeemable half-authentication
     * indefinitely.
     */
    protected function pendingId(): int|string|null
    {
        $id = session('login.2fa.id');
        $expiresAt = (int) session('login.2fa.expires_at', 0);

        if (! $id) {
            return null;
        }

        if ($expiresAt > 0 && now()->getTimestamp() > $expiresAt) {
            $this->forgetPending();

            return null;
        }

        return $id;
    }

    protected function forgetPending(): void
    {
        session()->forget(['login.2fa.id', 'login.2fa.remember', 'login.2fa.expires_at']);
    }

    public function render()
    {
        return view('livewire.auth.two-factor-challenge');
    }
}
PHP;
    }

    private function challengeView(ScaffoldWriter $writer): string
    {
        $otp = $this->ui->otpField('code', 'Authentication code', 64);
        $submit = $this->ui->submit('verify', 'Verify', 'Verifying…');

        $body = <<<BLADE
    <form wire:submit="verify" class="space-y-4">
{$otp}

{$submit}
    </form>

    <details class="mt-6 text-sm">
        <summary class="cursor-pointer select-none font-medium text-gray-500 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
            Lost access to your device?
        </summary>
        <p class="mt-2 text-gray-500 dark:text-gray-400">
            Enter one of the recovery codes you saved when you enabled two-factor authentication. Each code works once.
        </p>
    </details>
BLADE;

        return $writer->tokens()->render($this->ui->card(
            'shield',
            'Two-factor authentication',
            'Enter the 6-digit code from your authenticator app.',
            $body,
        ));
    }

    // -----------------------------------------------------------------------
    // Settings / enrolment
    // -----------------------------------------------------------------------

    private function settingsComponent(ScaffoldWriter $writer): string
    {
        return $writer->tokens()->render($this->settingsTemplate(), [
            '%APP_LAYOUT%' => $this->appLayout(),
        ]);
    }

    /**
     * The enrolment screen is an authenticated settings page, so it uses the
     * application layout — not the guest layout, which renders the sign-in chrome
     * around it.
     */
    private function appLayout(): string
    {
        return (string) config('anvil.web.layout', 'layouts.app');
    }

    private function settingsTemplate(): string
    {
        return <<<'PHP'
<?php

namespace %AUTH_NS%;

use App\Services\TwoFactorAuthenticationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Enable, confirm and disable two-factor authentication.
 *
 * The secret is written but NOT trusted until the user proves they can produce a
 * code from it (two_factor_confirmed_at). Confirming before trusting is what stops
 * someone locking themselves out with a mis-scanned QR code.
 *
 * PROPERTY VISIBILITY IS DELIBERATE. The pending secret and the plaintext recovery
 * codes live in the SESSION and are exposed through #[Computed] accessors.
 * Livewire serialises public properties into the page payload on every request and
 * accepts client updates to them unless they are #[Locked] — neither of which is
 * acceptable for a shared secret.
 */
#[Layout('%APP_LAYOUT%')]
class TwoFactorSettings extends Component
{
    private const SESSION_SECRET = 'two-factor.setup.secret';

    private const SESSION_CODES = 'two-factor.setup.codes';

    #[Locked]
    public bool $enabled = false;

    #[Locked]
    public bool $confirming = false;

    public string $code = '';

    public string $password = '';

    public function mount(): void
    {
        $user = Auth::guard('%GUARD%')->user();

        $this->enabled = $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
        $this->confirming = ! $this->enabled && session()->has(self::SESSION_SECRET);
    }

    /**
     * The otpauth:// URI for the pending enrolment. Read from the session, so it
     * never enters the component payload.
     */
    #[Computed]
    public function provisioningUri(): ?string
    {
        $secret = session(self::SESSION_SECRET);

        if (! is_string($secret) || $secret === '') {
            return null;
        }

        return app(TwoFactorAuthenticationService::class)->provisioningUri(
            (string) Auth::guard('%GUARD%')->user()->email,
            $secret,
        );
    }

    /**
     * The QR code as inline SVG, rendered in this process.
     *
     * Returns null when bacon/bacon-qr-code is absent, and the view falls back to
     * manual entry. It does NOT fall back to an external QR service: the URI being
     * encoded contains the shared secret, and handing that to a third party would
     * defeat the point of the second factor.
     */
    #[Computed]
    public function qrCode(): ?string
    {
        $uri = $this->provisioningUri();

        return $uri === null
            ? null
            : app(TwoFactorAuthenticationService::class)->qrSvg($uri);
    }

    /** Shown exactly once, immediately after enable(). */
    #[Computed]
    public function recoveryCodes(): array
    {
        $codes = session(self::SESSION_CODES, []);

        return is_array($codes) ? $codes : [];
    }

    /**
     * Begin enrolment.
     *
     * Requires the account password. disable() always did; enable() did not, which
     * meant a hijacked session could enrol the attacker's own device — and, because
     * the old implementation unconditionally nulled two_factor_confirmed_at, could
     * do so over an already-confirmed secret.
     */
    public function enable(): void
    {
        $this->validate(['password' => ['required', 'string']]);

        $user = Auth::guard('%GUARD%')->user();

        if (! Hash::check($this->password, $user->password)) {
            throw ValidationException::withMessages(['password' => __('That password is incorrect.')]);
        }

        abort_if($this->enabled, 403, 'Two-factor authentication is already enabled. Disable it first.');

        $service = app(TwoFactorAuthenticationService::class);

        $secret = $service->generateSecret();
        $plaintextCodes = $service->recoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($service->hashRecoveryCodes($plaintextCodes))),
            'two_factor_confirmed_at' => null,
        ])->save();

        // The secret is committed to the row only on confirm(): an abandoned
        // enrolment leaves nothing behind that could be challenged against.
        session([
            self::SESSION_SECRET => $secret,
            self::SESSION_CODES => $plaintextCodes,
        ]);

        $this->confirming = true;
        $this->password = '';
        $this->reset('code');
    }

    public function confirm(): void
    {
        $this->validate(['code' => ['required', 'string']]);

        $secret = session(self::SESSION_SECRET);

        if (! is_string($secret) || $secret === '') {
            throw ValidationException::withMessages([
                'code' => __('That enrolment has expired. Start again.'),
            ]);
        }

        $service = app(TwoFactorAuthenticationService::class);

        if (! $service->verify($secret, trim(str_replace([' ', '-'], '', $this->code)))) {
            throw ValidationException::withMessages([
                'code' => __('That code is not valid. Wait for the next one and try again.'),
            ]);
        }

        Auth::guard('%GUARD%')->user()->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ])->save();

        session()->forget([self::SESSION_SECRET, self::SESSION_CODES]);

        $this->enabled = true;
        $this->confirming = false;
        $this->code = '';

        session()->flash('status', __('Two-factor authentication is now enabled.'));
    }

    /** Abandon a half-finished enrolment. */
    public function cancel(): void
    {
        session()->forget([self::SESSION_SECRET, self::SESSION_CODES]);

        $this->confirming = false;
        $this->reset('code', 'password');
    }

    /**
     * Disabling requires the account password: someone with a hijacked session
     * should not be able to strip the second factor.
     */
    public function disable(): void
    {
        $this->validate(['password' => ['required', 'string']]);

        $user = Auth::guard('%GUARD%')->user();

        if (! Hash::check($this->password, $user->password)) {
            throw ValidationException::withMessages(['password' => __('That password is incorrect.')]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        session()->forget([self::SESSION_SECRET, self::SESSION_CODES]);

        $this->enabled = false;
        $this->confirming = false;
        $this->reset('code', 'password');

        session()->flash('status', __('Two-factor authentication has been disabled.'));
    }

    public function render()
    {
        return view('livewire.auth.two-factor-settings');
    }
}
PHP;
    }

    private function settingsView(ScaffoldWriter $writer): string
    {
        $enableSubmit = $this->ui->submit('enable', 'Enable two-factor', 'Preparing…');
        $confirmSubmit = $this->ui->submit('confirm', 'Confirm and enable', 'Confirming…');
        $disableSubmit = $this->ui->submit('disable', 'Disable two-factor', 'Disabling…', 'btn-danger');
        $otp = $this->ui->otpField('code', 'Authentication code');
        $lockIcon = $this->ui->icon('lock', 'h-4 w-4');
        $errorIcon = $this->ui->icon('exclamation', 'mt-0.5 h-3.5 w-3.5 shrink-0');
        $okIcon = $this->ui->icon('check-circle', 'mt-0.5 h-5 w-5 shrink-0');
        $infoIcon = $this->ui->icon('info', 'mt-0.5 h-5 w-5 shrink-0');

        $passwordInput = static fn(string $label): string => <<<BLADE
            <div>
                <label class="form-label" for="password">{$label}</label>
                <div class="relative">
                    <span class="input-affix">%LOCK_ICON%</span>
                    <input wire:model="password" id="password" type="password" autocomplete="current-password" required
                           class="form-input form-input-icon @error('password') form-input-error @enderror">
                </div>
                @error('password')
                    <p class="form-error">%ERROR_ICON%<span>{{ \$message }}</span></p>
                @enderror
            </div>
BLADE;

        $enablePassword = strtr($passwordInput('Confirm your password to continue'), [
            '%LOCK_ICON%' => $lockIcon,
            '%ERROR_ICON%' => $errorIcon,
        ]);

        $disablePassword = strtr($passwordInput('Confirm your password to disable'), [
            '%LOCK_ICON%' => $lockIcon,
            '%ERROR_ICON%' => $errorIcon,
        ]);

        $body = <<<BLADE
    @if (\$enabled)
        <div class="alert-ok mb-6" role="status">
            {$okIcon}
            <span>Two-factor authentication is <strong>enabled</strong> on this account.</span>
        </div>

        <form wire:submit="disable" class="space-y-4">
{$disablePassword}

{$disableSubmit}
        </form>
    @elseif (\$confirming)
        <div class="alert-info mb-5">
            {$infoIcon}
            <span>Scan the code with your authenticator app, then enter the 6-digit code it shows.</span>
        </div>

        @if (\$this->qrCode)
            {{-- Rendered in-process. The provisioning URI contains the shared
                 secret, so it is never handed to an external QR service. --}}
            <div class="mb-5 flex justify-center">
                <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-800">
                    {!! \$this->qrCode !!}
                </div>
            </div>
        @else
            <div class="alert-warn mb-5">
                {$infoIcon}
                <span>
                    QR rendering needs <code class="font-mono text-xs">bacon/bacon-qr-code</code>.
                    Enter the key below manually in the meantime.
                </span>
            </div>
        @endif

        <details class="mb-5" @if (! \$this->qrCode) open @endif>
            <summary class="cursor-pointer select-none text-sm font-medium text-gray-500 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                Enter the key manually
            </summary>
            <p class="mt-2 break-all rounded-xl bg-gray-50 p-3 font-mono text-xs text-gray-600 dark:bg-gray-950 dark:text-gray-400">
                {{ \$this->provisioningUri }}
            </p>
        </details>

        <div class="alert-warn mb-5 flex-col items-start">
            <p class="font-semibold">Save your recovery codes</p>
            <p class="mt-1 text-xs">
                Each code works once if you lose your device. They are stored hashed, so this is the only time they
                can be shown.
            </p>
            <ul class="mt-3 grid w-full grid-cols-2 gap-1 font-mono text-xs">
                @foreach (\$this->recoveryCodes as \$recoveryCode)
                    <li>{{ \$recoveryCode }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-ghost mt-3 text-xs"
                    data-copy="{{ implode(PHP_EOL, \$this->recoveryCodes) }}">Copy all</button>
        </div>

        <form wire:submit="confirm" class="space-y-4">
{$otp}

{$confirmSubmit}
        </form>

        <button type="button" wire:click="cancel" class="btn-ghost mt-3 w-full text-sm">Cancel setup</button>
    @else
        <p class="mb-6 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
            Add a second step to sign-in using an authenticator app such as Google Authenticator, 1Password or Authy.
            You will be asked for a code each time you sign in.
        </p>

        <form wire:submit="enable" class="space-y-4">
{$enablePassword}

{$enableSubmit}
        </form>
    @endif
BLADE;

        return $writer->tokens()->render($this->ui->card(
            'shield',
            'Two-factor authentication',
            'Protect your account with a second step.',
            $body,
        ));
    }

    // -----------------------------------------------------------------------
    // Service
    // -----------------------------------------------------------------------

    private function service(): string
    {
        return <<<'PHP'
<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP secrets, provisioning URIs, QR rendering, verification and recovery codes.
 *
 * Requires: composer require pragmarx/google2fa
 * Optional: composer require bacon/bacon-qr-code   (for qrSvg)
 */
class TwoFactorAuthenticationService
{
    /** Codes generated per enrolment. */
    public const RECOVERY_CODE_COUNT = 8;

    public function __construct(private Google2FA $engine = new Google2FA) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /** otpauth:// provisioning URI. Contains the shared secret — treat as one. */
    public function provisioningUri(string $holder, string $secret): string
    {
        return $this->engine->getQRCodeUrl(config('app.name', 'Application'), $holder, $secret);
    }

    public function verify(string $secret, string $code): bool
    {
        // verifyKey() accepts a window of adjacent time steps, which absorbs
        // clock drift between the server and the authenticator app.
        return (bool) $this->engine->verifyKey($secret, $code);
    }

    /**
     * The QR code as inline SVG, rendered in this process.
     *
     * Returns null when bacon/bacon-qr-code is not installed. The caller must fall
     * back to manual entry — NOT to an external QR service, which would mean
     * transmitting the shared secret to a third party.
     */
    public function qrSvg(string $uri, int $size = 200): ?string
    {
        if (! class_exists(Writer::class)) {
            return null;
        }

        try {
            $writer = new Writer(new ImageRenderer(
                new RendererStyle($size, 0),
                new SvgImageBackEnd,
            ));

            return $writer->writeString($uri);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fresh plaintext recovery codes. Show them once, then discard.
     *
     * @return list<string>
     */
    public function recoveryCodes(int $count = self::RECOVERY_CODE_COUNT): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5));
        }

        return $codes;
    }

    /**
     * Hash codes for storage.
     *
     * Recovery codes are credentials with the same power as a TOTP code, so they
     * are hashed rather than encrypted: database access plus APP_KEY should not
     * yield a usable code.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function hashRecoveryCodes(array $codes): array
    {
        return array_values(array_map(static fn (string $code): string => Hash::make($code), $codes));
    }

    /**
     * Check a submitted code against the stored hashes.
     *
     * @param  list<string>  $hashes
     * @return list<string>|null  The remaining hashes on a match, null on no match
     */
    public function consumeRecoveryCode(string $submitted, array $hashes): ?array
    {
        $submitted = Str::upper(trim($submitted));

        foreach ($hashes as $index => $hash) {
            if (! is_string($hash) || ! Hash::check($submitted, $hash)) {
                continue;
            }

            unset($hashes[$index]);

            return array_values($hashes);
        }

        return null;
    }
}
PHP;
    }

    private function migration(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor columns.
 *
 * text, not string: the secret and the recovery-code list are both stored
 * encrypted, and ciphertext for eight hashed codes comfortably exceeds 255 bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('%USERS_TABLE%', function (Blueprint $table): void {
            if (! Schema::hasColumn('%USERS_TABLE%', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable();
            }

            if (! Schema::hasColumn('%USERS_TABLE%', 'two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')->nullable();
            }

            if (! Schema::hasColumn('%USERS_TABLE%', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('%USERS_TABLE%', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
PHP;
    }
}
