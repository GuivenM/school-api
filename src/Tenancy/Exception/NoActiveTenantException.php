<?php

declare(strict_types=1);

namespace App\Tenancy\Exception;

/** La connexion "tenant" a été utilisée sans qu'aucune école ne soit active. */
final class NoActiveTenantException extends \LogicException
{
}
