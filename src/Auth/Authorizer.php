<?php

declare(strict_types=1);

namespace LonelyLights\Prosetta\Auth;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use LonelyLights\Prosetta\Enums\Ability;

/**
 * The one place a host decides who may do what, per locale:
 * Prosetta::authorizeUsing(fn ($user, Ability $ability, ?string $locale): bool => ...).
 * A user allowed Manage may also translate and review every locale, and
 * one allowed Review for a locale may translate it.
 * With no hook, only the local environment is allowed. A null user is the
 * system (console, queue) and is always allowed.
 */
final class Authorizer {
    private ?Closure $callback = null;

    public function using(Closure $callback): void {
        $this->callback = $callback;
    }

    public function allows(?Authenticatable $user, Ability $ability, ?string $locale = null): bool {
        if ($user === null) {
            return true;
        }

        if ($this->callback === null) {
            return app()->environment('local');
        }

        if (($this->callback)($user, $ability, $locale) === true) {
            return true;
        }

        # Manage Covers Every Language; Review Implies Translate
        if ($ability !== Ability::Manage && ($this->callback)($user, Ability::Manage, null) === true) {
            return true;
        }

        return $ability === Ability::Translate && ($this->callback)($user, Ability::Review, $locale) === true;
    }

    public function authorize(?Authenticatable $user, Ability $ability, ?string $locale = null): void {
        if (! $this->allows($user, $ability, $locale)) {
            throw new AuthorizationException(sprintf('Not allowed to %s%s.', $ability->value, $locale === null ? '' : " $locale"));
        }
    }
}
