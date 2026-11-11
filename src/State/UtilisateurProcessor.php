<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenant\Entity\Utilisateur;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Hache le mot de passe fourni (champ plainPassword, jamais relu) puis délègue à l'enregistrement Doctrine. */
final class UtilisateurProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private readonly ProcessorInterface $persistProcessor,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Utilisateur && null !== $data->getPlainPassword() && '' !== $data->getPlainPassword()) {
            $data->setPassword($this->hasher->hashPassword($data, $data->getPlainPassword()));
            $data->clearPlainPassword();
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
