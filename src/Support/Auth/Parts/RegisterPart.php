<?php

declare(strict_types=1);

namespace Zuqongtech\LaravelAnvil\Support\Auth\Parts;

use Zuqongtech\LaravelAnvil\Support\Auth\AuthContext;
use Zuqongtech\LaravelAnvil\Support\Auth\Contracts\ScaffoldPart;
use Zuqongtech\LaravelAnvil\Support\Auth\ScaffoldWriter;
use Zuqongtech\LaravelAnvil\Support\Auth\Ui\FormKit;

/**
 * Registration.
 *
 * Every optional field is gated on the column actually existing, so the same
 * template serves a stock users table and a heavily customised one.
 */
final readonly class RegisterPart implements ScaffoldPart
{
    public function __construct(private FormKit $ui = new FormKit) {}

    public function supports(AuthContext $context): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'Register';
    }

    public function emit(AuthContext $context, ScaffoldWriter $writer): void
    {
        $writer->component('Register', $this->component($context, $writer), $this->view($context, $writer));
    }

    /**
     * @return list<string>
     */
    public function notes(AuthContext $context): array
    {
        $notes = [
            'Set the password policy centrally in a service provider:  Password::defaults(fn () => '
                . 'Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised());',
        ];

        if ($context->rbac && ! $context->assignsDefaultRole()) {
            $notes[] = 'No default role is assigned on registration. Pass --default-role=<name> to assign one, or '
                . 'handle it in a Registered event listener.';
        }

        return $notes;
    }

    private function component(AuthContext $context, ScaffoldWriter $writer): string
    {
        $hasName = $context->has('name');

        // The role lookup is emitted only when a default role was actually
        // supplied. Previously it was gated on the schema alone, so the common
        // case generated Role::where('name', '') — a query that always returns
        // null, assigns nothing, and reports nothing.
        $roleAssign = $context->assignsDefaultRole()
            ? <<<'PHP'

        $roleId = \App\Models\Role::query()->where('name', '%DEFAULT_ROLE%')->value('id');

        if ($roleId) {
            $data['role_id'] = $roleId;
        }
PHP
            : '';

        $statusField = $context->has('status')
            ? "\n        \$data['status'] = 'active';"
            : '';

        return $writer->tokens()->render($this->template(), [
            '%NAME_PROP%' => $hasName ? "    public string \$name = '';\n\n" : '',
            '%NAME_RULE%' => $hasName ? "\n            'name' => ['required', 'string', 'max:255']," : '',
            '%NAME_DATA%' => $hasName ? "\n            'name' => \$validated['name']," : '',
            '%ROLE_ASSIGN%' => $roleAssign,
            '%STATUS_FIELD%' => $statusField,
            '%POST_REGISTER%' => $context->verification
                ? "        return \$this->redirect(route('verification.notice'), navigate: true);"
                : "        return \$this->redirectIntended(default: '/', navigate: true);",
        ]);
    }

    private function template(): string
    {
        return <<<'PHP'
<?php

namespace %AUTH_NS%;

use %USER_FQN%;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('%LAYOUT%')]
class Register extends Component
{
%NAME_PROP%    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register()
    {
        $this->ensureIsNotRateLimited();

        $validated = $this->validate([%NAME_RULE%
            'email' => ['required', 'string', 'email', 'max:255', 'unique:%USERS_TABLE%,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $data = [%NAME_DATA%
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ];%ROLE_ASSIGN%%STATUS_FIELD%

        // Wrapped so a failing listener cannot leave a half-created account
        // behind. Registered is dispatched inside the transaction; move it after
        // the commit if a listener needs to see the committed row.
        $user = DB::transaction(fn () => User::create($data));

        RateLimiter::clear($this->throttleKey());

        event(new Registered($user));

        Auth::guard('%GUARD%')->login($user);
        session()->regenerate();

%POST_REGISTER%
    }

    /**
     * Registration is a write endpoint reachable without credentials, so it needs
     * its own limiter — unique:email rejects duplicates but does nothing about a
     * script creating thousands of distinct accounts.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            RateLimiter::hit($this->throttleKey(), 600);

            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('Too many attempts. Please try again in :minutes minutes.', [
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate('register|'.request()->ip());
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
}
PHP;
    }

    private function view(AuthContext $context, ScaffoldWriter $writer): string
    {
        $hasName = $context->has('name');

        $name = $hasName
            ? $this->ui->field('name', 'Full name', 'text', 'name', 'user', autofocus: true, placeholder: 'Ada Lovelace') . "\n\n"
            : '';

        $email = $this->ui->field(
            'email',
            'Email',
            'email',
            'email',
            'mail',
            autofocus: ! $hasName,
            placeholder: 'you@example.com',
        );

        $password = $this->ui->password('password', 'Password', 'new-password', strength: true);
        $confirm = $this->ui->password('password_confirmation', 'Confirm password', 'new-password');
        $submit = $this->ui->submit('register', 'Create account', 'Creating…');

        $body = <<<BLADE
    <form wire:submit="register" class="space-y-4">
{$name}{$email}

{$password}

{$confirm}

        <p class="pt-1 text-xs leading-relaxed text-gray-500 dark:text-gray-500">
            By creating an account you agree to our terms of service and privacy policy.
        </p>

{$submit}
    </form>
BLADE;

        return $writer->tokens()->render($this->ui->card(
            'user-plus',
            'Create account',
            'A few details and you are ready to go.',
            $body,
            'Already registered? <a href="{{ route(\'login\') }}" class="link" wire:navigate>Sign in</a>',
        ));
    }
}
