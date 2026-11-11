<?php

declare(strict_types=1);

namespace App\Validator;

use App\Tenant\Entity\Inscription;
use App\Tenant\Entity\TypeInscription;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class TypeInscriptionCoherentValidator extends ConstraintValidator
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TypeInscriptionCoherent || !$value instanceof Inscription) {
            return;
        }
        $type = $value->getType();
        if (null === $type || null === $value->getEleve() || TypeInscription::TransfertEntrant === $type) {
            return;
        }

        $qb = $this->tenantEntityManager->getRepository(Inscription::class)->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.eleve = :eleve')
            ->setParameter('eleve', $value->getEleve());
        if (null !== $value->getId()) {
            $qb->andWhere('i.id != :id')->setParameter('id', $value->getId());
        }
        $autres = (int) $qb->getQuery()->getSingleScalarResult();

        if (TypeInscription::Nouvelle === $type && $autres > 0) {
            $this->context->buildViolation($constraint->messageNouvelle)->atPath('type')->addViolation();
        } elseif (TypeInscription::Nouvelle !== $type && 0 === $autres) {
            $this->context->buildViolation($constraint->messageSuite)->atPath('type')->addViolation();
        }
    }
}
