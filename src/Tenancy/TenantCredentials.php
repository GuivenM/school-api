<?php

declare(strict_types=1);

namespace App\Tenancy;

final readonly class TenantCredentials
{
    public function __construct(
        public string $slug,
        public string $dbName,
        public string $dbUser,
        #[\SensitiveParameter] public string $dbPassword,
    ) {
    }
}
