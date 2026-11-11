<?php

declare(strict_types=1);

namespace App\Validator;

use App\Tenant\Entity\RoleUtilisateur;
use App\Tenant\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

final class UnDirecteurActifAuMoinsValidator extends ConstraintValidator
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.tenant_entity_manager')]
        private readonly EntityManagerInterface $tenantEntityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UnDirecteurActifAuMoins || !$value instanceof Utilisateur) {
            return;
        }
        if (RoleUtilisateur::Directeur === $value->getRole() && $value->isActif()) {
            return; // cet utilisateur est lui-même un directeur actif
        }

        $qb = $this->tenantEntityManager->getRepository(Utilisateur::class)->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.role = :role')
            ->andWhere('u.actif = true')
            ->setParameter('role', RoleUtilisateur::Directeur);
        if (null !== $value->getId()) {
            $qb->andWhere('u.id != :id')->setParameter('id', $value->getId());
        }

        if (0 === (int) $qb->getQuery()->getSingleScalarResult()) {
            $this->context->buildViolation($constraint->message)->atPath('actif')->addViolation();
        }
    }
}
