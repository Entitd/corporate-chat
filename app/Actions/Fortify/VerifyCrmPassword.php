<?php

namespace App\Actions\Fortify;

class VerifyCrmPassword
{
    public function __invoke(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hash): bool
    {
        return preg_match('/\A[0-9a-f]{32}\z/i', $hash) === 1
            && hash_equals(strtolower($hash), md5($password));
    }
}
