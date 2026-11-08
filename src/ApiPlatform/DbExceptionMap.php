<?php

declare(strict_types=1);

namespace App\ApiPlatform;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Une contrainte de base (élément encore utilisé, doublon concurrent) devient un 409 au lieu d'un 500. */
final class DbExceptionMap
{
    public const TO_STATUS = [
        ForeignKeyConstraintViolationException::class => 409,
        UniqueConstraintViolationException::class => 409,
    ];

    private function __construct()
    {
    }
}
