<?php

declare(strict_types=1);

namespace App\Validator;

use App\Tenant\Entity\EleveTuteur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class UnSeulTuteurPrincipalValidator extends ConstraintValidator
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UnSeulTuteurPrincipal || !$value instanceof EleveTuteur) {
            return;
        }
        if (!$value->isPrincipal() || null === $value->getEleve()) {
            return;
        }

        $qb = $this->tenantEntityManager->getRepository(EleveTuteur::class)->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.eleve = :eleve')
            ->andWhere('l.principal = true')
            ->setParameter('eleve', $value->getEleve());
        if (null !== $value->getId()) {
            $qb->andWhere('l.id != :id')->setParameter('id', $value->getId());
        }

        if ((int) $qb->getQuery()->getSingleScalarResult() > 0) {
            $this->context->buildViolation($constraint->message)->atPath('principal')->addViolation();
        }
    }
}
