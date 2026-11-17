<?php

declare(strict_types=1);

/** Aides de mise en forme partagées par les gabarits (échappement HTML obligatoire pour toute donnée affichée). */
if (!function_exists('bulletin_e')) {
    function bulletin_e(mixed $valeur): string
    {
        return htmlspecialchars((string) $valeur, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    function bulletin_nombre(?float $valeur): string
    {
        return null === $valeur ? '—' : number_format($valeur, 2, ',', ' ');
    }

    function bulletin_coef(float $coefficient): string
    {
        return rtrim(rtrim(number_format($coefficient, 2, ',', ''), '0'), ',');
    }

    function bulletin_rang(?int $rang, ?int $effectif = null): string
    {
        if (null === $rang) {
            return '—';
        }

        return null === $effectif ? (1 === $rang ? '1er' : $rang.'e') : (1 === $rang ? '1er' : $rang.'e').' / '.$effectif;
    }

    function bulletin_date(?string $iso): string
    {
        return null === $iso ? '' : (new DateTimeImmutable($iso))->format('d/m/Y');
    }
}
