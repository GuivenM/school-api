# School API

API de gestion scolaire **multi-école** : chaque école dispose de sa propre base de données, créée automatiquement.
Elle couvre tout le cycle d'une école : inscriptions, notes, moyennes et rangs, trimestres, bulletins PDF, décisions de fin
d'année, passage à l'année suivante, comptes des enseignants, des parents et des élèves, sauvegardes.

- **Stack** : PHP 8.2+, Symfony 7.4 LTS, API Platform, Doctrine ORM, MySQL 8 / MariaDB, JWT.
- **Public visé** : une application web (ou mobile) qui consomme cette API. Il n'y a pas d'interface dans ce dépôt.

---

## 1. Principe : une base par école

| Base | Contenu | Connexion Doctrine |
|---|---|---|
| **Centrale** (« landlord ») | liste des écoles (`tenants`), super-admins, journal des sauvegardes | `default` (`src/Entity`, `migrations/`) |
| **Une par école** | tout le métier scolaire : élèves, notes, bulletins, comptes… | `tenant` (`src/Tenant/Entity`, `tenant_migrations/`) |

- À chaque requête, un middleware Doctrine remplace nom de base, utilisateur et mot de passe selon l'**école active**.
  Sans école active, la connexion à une base d'école est **refusée** (jamais de repli silencieux).
- L'école d'une requête `/api/*` vient **uniquement du JWT**. L'en-tête `X-Tenant: <slug>` n'est lu que pour la connexion et les
  pages publiques de mot de passe (l'utilisateur n'a pas encore de jeton).
- Chaque école a son propre utilisateur MySQL, limité à sa base. Son mot de passe est stocké **chiffré** (libsodium) dans la base centrale.
- Les jetons des super-admins (`/admin/*`) et ceux des écoles (`/api/*`) ne sont pas interchangeables.

## 2. Installation

### Prérequis
PHP 8.2+ (extensions `sodium`, `mbstring`, `intl`, `pdo_mysql`, `zip`/`dom`), Composer, MySQL 8 ou MariaDB 10.6+, OpenSSL, et pour les
sauvegardes les outils `mysqldump` et `mysql`.

### Dépendances
```bash
composer install
# Paquets utilisés (déjà déclarés si vous partez de ce dépôt ; sinon) :
composer require api symfony/messenger symfony/uid lexik/jwt-authentication-bundle doctrine/doctrine-migrations-bundle \
  dompdf/dompdf symfony/process league/flysystem league/flysystem-aws-s3-v3 symfony/mailer symfony/rate-limiter
composer require --dev symfony/maker-bundle symfony/test-pack
```

### Configuration locale
Copier les variables utiles dans `.env.local` (jamais commité) :
```dotenv
APP_SECRET=<chaîne aléatoire>
DATABASE_URL="mysql://user:motdepasse@127.0.0.1:3306/school_central?serverVersion=8.0.32&charset=utf8mb4"
TENANT_DATABASE_URL="mysql://user:motdepasse@127.0.0.1:3306/school_template?serverVersion=8.0.32&charset=utf8mb4"
TENANT_ALLOW_TEMPLATE_FALLBACK=1   # développement seulement (voir « Ajouter une table d'école »)
```
Le `serverVersion` est **obligatoire** dans les deux URL.

### Droits MySQL
L'utilisateur de `DATABASE_URL` doit pouvoir créer les bases et utilisateurs des écoles :
```sql
GRANT CREATE USER ON *.* TO 'user'@'%';
GRANT ALL PRIVILEGES ON `school\_%`.* TO 'user'@'%' WITH GRANT OPTION;
```
(`school_` est le préfixe des bases d'école ; `school_test_` en environnement de test.)

### Clés JWT
```bash
php bin/console lexik:jwt:generate-keypair
```
Sous Windows, si la commande échoue (`error:80000003`), générer les clés avec `openssl` en ligne de commande (voir la documentation
de LexikJWT) ; la phrase secrète est `JWT_PASSPHRASE`. Ne jamais commiter `config/jwt/*.pem`.

### Premier démarrage
```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate                 # base centrale
php bin/console admin:create vous@exemple.com               # premier super-admin
php bin/console tenant:create ecole_demo "École Démo"       # une école de test
php bin/console tenant:user:create ecole_demo directeur@ecole.test directeur
symfony server:start        # ou : php -S localhost:8000 -t public
```

## 3. Variables d'environnement

| Variable | Rôle |
|---|---|
| `DATABASE_URL` / `TENANT_DATABASE_URL` | base centrale / modèle de connexion des bases d'école |
| `TENANT_ENCRYPTION_KEY` | clé (32 octets base64) chiffrant les mots de passe des bases d'école. **En production : `secrets:set`** |
| `TENANT_DB_USER_HOST` | hôte des utilisateurs MySQL d'école (`%` ou `localhost`) |
| `TENANT_ALLOW_TEMPLATE_FALLBACK` | `0` en production (fail closed) ; `1` en local pour générer des migrations d'école |
| `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE` | clés de signature des jetons |
| `APP_FRONTEND_URL`, `MAILER_DSN`, `MAILER_FROM` | liens et envoi des emails (invitation, mot de passe oublié) |
| `BACKUP_DESTINATIONS` | `local`, `remote` ou `local,remote` |
| `BACKUP_LOCAL_DIR`, `BACKUP_S3_*` | dossier local ; stockage objet compatible S3 (AWS, Backblaze, Wasabi, R2, MinIO) |
| `BACKUP_ENCRYPTION_KEY` | clé de chiffrement des sauvegardes. **À conserver aussi hors du serveur** |
| `BACKUP_MYSQLDUMP_BINARY`, `BACKUP_MYSQL_BINARY` | outils MySQL (nom ou chemin complet) |
| `BACKUP_ON_CLOSURE` | `1` = sauvegarde avant/après la clôture d'une année |

Les clés présentes dans `.env` sont des valeurs de **développement** : les remplacer en production.

## 4. Rôles et droits

| Rôle | Accès |
|---|---|
| **Super-admin** (`/admin/*`) | crée, suspend et sauvegarde les écoles ; ne voit aucune donnée scolaire |
| **directeur** | tout dans son école : comptes, clôture d'année, réouverture d'un trimestre. Inclut secrétariat, comptable, enseignant |
| **secretariat** | gère référentiel, personnes, inscriptions, notes (toutes classes), ferme un trimestre, décisions du conseil |
| **comptable** | lecture des données du personnel (la gestion financière arrivera avec les frais de scolarité) |
| **enseignant** | écrit les notes et appréciations de **ses** matières de classe ; titulaire : appréciation générale |
| **tuteur** | voit ses enfants : notes, bulletins publiés |
| **eleve** | se voit lui-même |

Lecture des données personnelles (élèves, tuteurs, enseignants, inscriptions, notes) : personnel uniquement. Les comptes tuteur et
élève passent par `/api/me/*` et ne reçoivent que des résultats **publiés** (trimestre fermé, année clôturée).

## 5. Fonctionnement métier en bref

- **Notes toujours sur 20.** Statuts : `saisie`, `absent`, `dispense`, `non_evalue` (les trois derniers sont ignorés, jamais comptés zéro).
- **Moyenne d'une matière** = moyenne simple de ses évaluations. **Moyenne du trimestre** = moyenne pondérée par les coefficients des
  matières notées. **Moyenne annuelle** = moyenne des moyennes de trimestre existantes. Arrondi à 2 décimales à chaque étape ; rang
  = 1 + nombre d'élèves strictement meilleurs.
- **Fermer un trimestre** fige les résultats (un bulletin émis ne change plus) et verrouille la saisie ; le directeur peut le rouvrir.
- **Inscription** : un élève, une classe, une année. Le matricule `AAAA-NNNN` est attribué à la première inscription. Un effectif
  maximum de classe dépassé n'est qu'un **avertissement**.
- **Fin d'année** : décision calculée (moyenne annuelle ≥ seuil de l'école, 10 par défaut, identique pour tous les niveaux) ou prise
  par le conseil de classe (motif obligatoire si elle diffère). La **clôture** est irréversible : bilan figé, année en lecture seule,
  réinscription des admis au niveau suivant, redoublements, sorties. Un niveau sans niveau suivant termine son cycle (les examens
  d'État BEPC/BAC ne sont pas gérés).
- **Sauvegardes** : chiffrées, copiées en local et/ou sur un stockage distant ; point de retour avant chaque clôture.

## 6. Commandes

| Commande | Rôle |
|---|---|
| `admin:create <email>` | crée un super-admin |
| `tenant:create <slug> "<nom>"` | crée une école (base, utilisateur MySQL, migrations, paramètres) |
| `tenant:user:create <slug> <email> <rôle>` | crée un utilisateur d'école |
| `tenant:list` | liste les écoles |
| `tenant:migrate <slug> \| --all` | applique les migrations d'école |
| `tenant:delete <slug> --force` | **supprime** une école (irréversible) |
| `tenant:backup <slug> \| --all [--type=quotidienne]` | sauvegarde |
| `tenant:backup:list`, `tenant:backup:verify <id>`, `tenant:backup:purge` | liste, vérification, purge |
| `tenant:restore <slug> <id> --force` | restaure (une sauvegarde de l'état actuel est prise d'abord) |

En production, la création d'école et des comptes se fait par l'API (voir `docs/creation-ecole.md`) : aucune commande côté école.

## 7. API en un coup d'œil

Authentification par JWT : `POST /api/login` (en-tête `X-Tenant: <slug>`) pour une école, `POST /admin/login` pour un super-admin ;
puis `Authorization: Bearer <jeton>`. Les ressources API Platform utilisent `application/ld+json` (`application/merge-patch+json` pour `PATCH`).

| Domaine | Ressources et routes |
|---|---|
| Plateforme | `POST /admin/tenants`, `…/{slug}/invitation`, `…/suspendre`, `…/reactiver`, `GET /admin/sauvegardes` |
| Référentiel | `/api/annees_scolaires`, `periodes`, `niveaux`, `matieres`, `classes`, `classe_matieres` |
| Personnes | `/api/eleves`, `tuteurs`, `eleve_tuteurs`, `enseignants`, `utilisateurs` |
| Scolarité | `/api/inscriptions`, `evaluations`, `notes`, `POST /api/evaluations/{id}/notes` (saisie d'une classe) |
| Résultats | `GET /api/periodes/{id}/resultats?classe=`, `…/inscriptions/{id}/bulletin`, `bulletin.pdf`, `/api/classes/{id}/bulletins.pdf` |
| Trimestres | `POST /api/periodes/{id}/fermer` · `…/rouvrir` ; appréciations : `PUT /api/inscriptions/{id}/appreciations/{periode}` |
| Fin d'année | `POST /api/inscriptions/{id}/decision`, `POST /api/annees_scolaires/{id}/cloture/apercu` · `…/cloturer` |
| Famille | `GET /api/me/eleves`, `/api/me/inscriptions/{id}/bulletin`, `bulletin.pdf`, `notes` |
| Comptes | `POST /api/me/password`, `POST /api/mot-de-passe/demande`, `POST /api/mot-de-passe/definir` |

La documentation interactive d'API Platform est disponible sur `/api/docs` (authentifié). Le détail des règles, corps de requête et codes
de réponse se trouve dans `docs/`.

## 8. Documentation détaillée

| Fichier | Contenu |
|---|---|
| `docs/multi-tenant.md` | architecture, référentiel, personnes, comptes, inscriptions, notes, clôture d'année, bulletins PDF |
| `docs/creation-ecole.md` | mise en service d'une école, invitation du directeur, mot de passe oublié |
| `docs/espace-famille.md` | espace parents/élèves et règles de visibilité |
| `docs/sauvegardes.md` | sauvegardes, destinations locale et distante, chiffrement, restauration |
| `docs/securite-connexion.md` | limitation des tentatives de connexion, réglages de production |

## 9. Ajouter une table d'école

1. Créer l'entité dans `src/Tenant/Entity`.
2. En local, dans `.env.local` : `TENANT_ALLOW_TEMPLATE_FALLBACK=1`, puis :
   ```bash
   php bin/console doctrine:database:create --connection=tenant --if-not-exists
   php bin/console doctrine:migrations:migrate --configuration=migrations-tenant.yaml --em=tenant
   php bin/console doctrine:migrations:diff    --configuration=migrations-tenant.yaml --em=tenant
   ```
3. Relire la migration générée dans `tenant_migrations/` et y ajouter `isTransactional(): false` (MySQL valide chaque instruction DDL ;
   sans cela : « There is no active transaction »). Déclarer aussi `ENGINE = InnoDB` pour les nouvelles tables.
4. Appliquer à toutes les écoles : `php bin/console tenant:migrate --all`.

## 10. Tests

```bash
php bin/console --env=test doctrine:database:create --if-not-exists
php bin/console --env=test doctrine:migrations:migrate -n
php bin/phpunit                                      # tout (≈ 8 minutes)
php bin/phpunit tests/Tenancy/NotesApiTest.php       # un seul fichier (recommandé en développement)
```
Prérequis : clés JWT générées, utilisateur MySQL ayant les droits ci-dessus. Les tests créent de **vraies** écoles (base + utilisateur
MySQL) puis les suppriment, d'où leur durée. Ils couvrent l'isolation entre écoles, l'authentification, chaque domaine métier, la clôture
d'année, les PDF, l'espace famille et le chiffrement des sauvegardes. La limitation de connexion n'est pas couverte automatiquement
(limite relevée en test).

## 11. Mise en production — liste de contrôle

- [ ] `APP_ENV=prod`, `APP_DEBUG=0`, `APP_SECRET` unique ; `TENANT_ALLOW_TEMPLATE_FALLBACK=0`.
- [ ] Secrets de production (`php bin/console secrets:set`) : `TENANT_ENCRYPTION_KEY`, `BACKUP_ENCRYPTION_KEY` (copie hors serveur), `JWT_PASSPHRASE`, clés S3, `MAILER_DSN`.
- [ ] HTTPS ; CORS configuré pour l'URL de l'application web ; `framework.trusted_proxies` si un proxy est devant l'API.
- [ ] Sauvegardes : `BACKUP_DESTINATIONS=local,remote`, tâche planifiée nocturne (`tenant:backup --all --type=quotidienne` puis `tenant:backup:purge`), **une restauration testée** sur une école de test.
- [ ] Mise à jour : `doctrine:migrations:migrate` (base centrale) puis `tenant:migrate --all` (toutes les écoles).
- [ ] Cache partagé (Redis) si plusieurs serveurs (limitation de connexion).

## 12. Limites connues et suite prévue

- Tout le personnel peut **lire** les notes de toutes les classes (seule l'écriture est limitée par enseignant) : restriction de lecture à prévoir.
- Pas encore de journal d'audit des modifications, de frais de scolarité et paiements, d'absences, d'import CSV/Excel, de notifications SMS.
- La création d'école est synchrone (quelques secondes).
- La restauration d'une sauvegarde est une opération d'exploitation en console.
