<?php

declare(strict_types=1);

namespace App\Validator;

use App\Tenant\Entity\AnneeScolaire;
use App\Tenant\Entity\StatutAnnee;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class UneSeuleAnneeActiveValidator extends ConstraintValidator
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UneSeuleAnneeActive || !$value instanceof AnneeScolaire) {
            return;
        }
        if (StatutAnnee::Active !== $value->getStatut()) {
            return;
        }

        $qb = $this->tenantEntityManager->getRepository(AnneeScolaire::class)->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.statut = :statut')
            ->setParameter('statut', StatutAnnee::Active);
        if (null !== $value->getId()) {
            $qb->andWhere('a.id != :id')->setParameter('id', $value->getId());
        }

        if ((int) $qb->getQuery()->getSingleScalarResult() > 0) {
            $this->context->buildViolation($constraint->message)->atPath('statut')->addViolation();
        }
    }
}
