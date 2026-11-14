<?php

declare(strict_types=1);

namespace App\Tenant\Calcul;

final class ClotureImpossibleException extends \DomainException
{
    /** @param list<array<string, mixed>> $blocages */
    public function __construct(public readonly array $blocages)
    {
        parent::__construct('La clôture de l\'année est impossible : '.\count($blocages).' point(s) bloquant(s).');
    }
}
