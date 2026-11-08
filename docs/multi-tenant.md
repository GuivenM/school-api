# Multi-tenant (une base MySQL par école)

## Principe
- **Base centrale (landlord)** = connexion/EM `default` : table `tenants` (src/Entity, migrations/).
- **Base d'école** = connexion/EM `tenant` : tables métier (src/Tenant/Entity, tenant_migrations/).
- `TenantDriverMiddleware` remplace dbname/user/password à la connexion selon l'école active.
  Sans école active : connexion refusée (`NoActiveTenantException`).
- L'école d'une requête `/api/*` vient **uniquement du JWT** (claims `scope=tenant` + `tenant=<slug>`) ;
  l'en-tête `X-Tenant` n'est lu que pour `/api/login` (et pour `/_tenant/*` en dev/test).
- Les tokens des super-admins (`scope=landlord`) ne valent que sur `/admin/*`, et inversement.

## Droits MySQL requis pour l'utilisateur de DATABASE_URL
```sql
GRANT CREATE USER ON *.* TO 'app'@'%';
GRANT ALL PRIVILEGES ON `school\_%`.* TO 'app'@'%' WITH GRANT OPTION;
```
(`school_` = paramètre `app.tenant.db_prefix` ; `school_test_` en environnement test.)

## Commandes
```bash
php bin/console doctrine:migrations:migrate          # base centrale
php bin/console tenant:create ecole_demo "École Démo"
php bin/console tenant:list
php bin/console tenant:migrate --all                 # ou : tenant:migrate ecole_demo
php bin/console tenant:delete ecole_demo --force     # irréversible
```

## Ajouter une table tenant (lots suivants)
1. Créer l'entité dans `src/Tenant/Entity`.
2. En local, dans `.env.local` : `TENANT_ALLOW_TEMPLATE_FALLBACK=1`, puis :
```bash
php bin/console doctrine:database:create --connection=tenant --if-not-exists
php bin/console doctrine:migrations:migrate --configuration=migrations-tenant.yaml --em=tenant
php bin/console doctrine:migrations:diff    --configuration=migrations-tenant.yaml --em=tenant
```
3. Vérifier la migration générée dans `tenant_migrations/` et **y ajouter `isTransactional(): false`**
   (comme dans les migrations existantes : MySQL valide chaque DDL, sinon erreur « There is no active transaction »),
   puis `php bin/console tenant:migrate --all`.

## Tests d'isolation
```bash
php bin/console --env=test doctrine:database:create --if-not-exists
php bin/console --env=test doctrine:migrations:migrate -n
php bin/phpunit tests/Tenancy
```

## Authentification (JWT)
```bash
php bin/console lexik:jwt:generate-keypair           # une fois (clés dans config/jwt/, ignorées par git)
php bin/console admin:create admin@exemple.com       # super-admin (mot de passe demandé)
php bin/console tenant:user:create ecole_demo directeur@ecole.test directeur
```
- Super-admin : `POST /admin/login` `{"email","password"}` → `{"token"}` ; puis `GET /admin/tenants`.
- École : `POST /api/login` avec l'en-tête `X-Tenant: ecole_demo` → `{"token"}` ; puis `Authorization: Bearer <token>` (`GET /api/me`).
- Rôles d'école : directeur, secretariat, comptable, enseignant, tuteur, eleve (le directeur hérite de secrétariat, comptable et enseignant).

## Référentiel (API)
Toutes les routes sont sous `/api`, protégées par le JWT d'école. **Lecture** : tout utilisateur connecté ;
**écriture** : secrétariat et directeur.

| Ressource | URL |
|---|---|
| Années scolaires | `/api/annees_scolaires` (filtre `?statut=active`) |
| Trimestres | `/api/periodes` (filtre `?annee=/api/annees_scolaires/1`) |
| Niveaux | `/api/niveaux` |
| Matières | `/api/matieres` |
| Classes | `/api/classes` (filtres `?annee=`, `?niveau=`) |
| Matières d'une classe (coefficients) | `/api/classe_matieres` (filtres `?classe=`, `?matiere=`) |

- Les relations s'écrivent avec l'IRI : `{"annee": "/api/annees_scolaires/1"}`.
- Création/lecture : `Content-Type: application/ld+json` ; modification (PATCH) : `application/merge-patch+json`.
- Une seule année peut être `active`. `cloturee` / `archivee` sont réservés à la clôture d'année (lot 6).
- Supprimer un élément encore utilisé répond `409`.
- Pour exposer d'autres dossiers d'entités à API Platform : `config/packages/api_platform.yaml` > `mapping.paths`.

## Personnes (API)
Données personnelles (élèves mineurs, tuteurs, enseignants) : **lecture réservée au personnel** (secrétariat, comptable,
enseignant, directeur) ; les comptes `tuteur` et `eleve` reçoivent `403`. **Écriture** : secrétariat et directeur.

| Ressource | URL |
|---|---|
| Élèves | `/api/eleves` (filtres `?nom=`, `?prenoms=` partiels, `?matricule=`, `?statut=`) |
| Tuteurs | `/api/tuteurs` (filtres `?nom=`, `?prenoms=`, `?telephone=`) |
| Liens élève/tuteur | `/api/eleve_tuteurs` (filtres `?eleve=`, `?tuteur=`) |
| Enseignants | `/api/enseignants` (filtres `?nom=`, `?actif=`) |

- Un élève est unique par (nom, prénoms, date de naissance) ; le matricule, s'il est fourni, est unique (génération automatique au lot 4).
- Un élève a au plus un tuteur `principal`. Supprimer un élève ou un tuteur supprime ses liens.
- `Classe.titulaire` et `ClasseMatiere.enseignant` référencent un enseignant (IRI) ; supprimer l'enseignant les vide.
- Les comptes tuteur/élève ne verront que leurs propres données via des routes dédiées (lots suivants).
