<?php

namespace App\Providers;

use App\Actions\Fortify\VerifyCrmPassword;
use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

class CrmUserProvider extends EloquentUserProvider
{
    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        $user = parent::retrieveById($identifier);

        return $user instanceof User && $user->crm_active ? $user : null;
    }

    public function retrieveByToken(mixed $identifier, #[\SensitiveParameter] mixed $token): ?Authenticatable
    {
        $user = parent::retrieveByToken($identifier, $token);

        return $user instanceof User && $user->crm_active ? $user : null;
    }

    /** @param array<string, mixed> $credentials */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        $login = $credentials['email'] ?? null;

        if (! is_string($login) || $login === '' || ! isset($credentials['password'])) {
            return null;
        }

        $users = $this->newModelQuery()
            ->where('crm_active', true)
            ->where(function (Builder $query) use ($login): void {
                $query->where(function (Builder $local) use ($login): void {
                    $local->whereNull('crm_id')->where('email', $login);
                })->orWhere(function (Builder $crm) use ($login): void {
                    $crm->whereNotNull('crm_id')->where('crm_username', $login);
                });
            })
            ->limit(2)
            ->get();

        $user = $users->count() === 1 ? $users->first() : null;

        return $user instanceof User ? $user : null;
    }

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        if (! $user instanceof User || ! $user->crm_active) {
            return false;
        }

        if ($user->crm_id === null) {
            return parent::validateCredentials($user, $credentials);
        }

        $password = $credentials['password'] ?? null;

        return is_string($password)
            && (new VerifyCrmPassword)($password, $user->crm_password_hash ?? '');
    }

    /** @param array<string, mixed> $credentials */
    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false): void
    {
        if ($user instanceof User && $user->crm_id !== null) {
            return;
        }

        parent::rehashPasswordIfRequired($user, $credentials, $force);
    }
}
