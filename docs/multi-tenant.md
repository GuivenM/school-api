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

- Un élève est unique par (nom, prénoms, date de naissance) ; le matricule, s'il est fourni, est unique (généré automatiquement à la première inscription sinon).
- Un élève a au plus un tuteur `principal`. Supprimer un élève ou un tuteur supprime ses liens.
- `Classe.titulaire` et `ClasseMatiere.enseignant` référencent un enseignant (IRI) ; supprimer l'enseignant les vide.
- Les comptes tuteur/élève ne verront que leurs propres données via des routes dédiées (lots suivants).

## Comptes utilisateurs (API, directeur uniquement)
| Action | Requête |
|---|---|
| Lister / filtrer | `GET /api/utilisateurs` (`?email=`, `?role=`, `?actif=`) |
| Créer | `POST /api/utilisateurs` `{"email","role","plainPassword","nom","prenoms","enseignant"/"tuteur"/"eleve": "<IRI>"}` |
| Modifier / désactiver / réinitialiser le mot de passe | `PATCH /api/utilisateurs/{id}` (`actif`, `role`, `plainPassword`...) |
| Changer son propre mot de passe (tout rôle) | `POST /api/me/password` `{"currentPassword","newPassword"}` |

- Pas de suppression : on désactive (`actif: false`). Le jeton déjà émis cesse de fonctionner à la requête suivante.
- Un compte `enseignant`, `tuteur` ou `eleve` doit être lié à la personne correspondante (une seule fois) ; un directeur, un
  secrétaire ou un comptable peut en plus être lié à un enseignant (directeur qui enseigne).
- L'école garde toujours au moins un directeur actif.
- Le mot de passe (10 caractères minimum) n'est jamais renvoyé par l'API.
- Invitation par email et « mot de passe oublié » : lot dédié à venir.

## Inscriptions (API)
`/api/inscriptions` (filtres `?eleve=`, `?annee=`, `?classe=`, `?statut=`, `?type=`). Lecture : personnel ; écriture : secrétariat et directeur.

- Une inscription = un élève dans une classe pour **une** année (une seule par élève et par année). Elle porte le parcours : notes, bulletins et décision de fin d'année viendront s'y rattacher.
- `type` : `nouvelle` (première inscription de l'élève), `reinscription` / `redoublement` (il a déjà un parcours), `transfert_entrant` (libre, `provenance` = école d'origine).
- La classe doit appartenir à l'année ; l'année ne doit pas être clôturée ; l'élève doit être `actif`.
- Le **matricule** `AAAA-NNNN` (année de début + numéro d'ordre dans l'école) est attribué à l'élève lors de sa première inscription.
- **Effectif maximum** d'une classe : jamais bloquant. Si dépassé, la réponse contient `"avertissements": ["Effectif maximum dépassé : ..."]`.
- Transfert / abandon : `PATCH` avec `statut` = `transferee` ou `abandonnee` + `dateSortie` (+ `motifSortie`). Le statut de l'élève suit (`transfere` / `sorti`) et redevient `actif` si l'inscription repasse à `en_cours`. `terminee` est réservé à la clôture d'année.
- Un élève, une classe ou une année qui a des inscriptions ne peut plus être supprimé (`409`).

## Évaluations, notes, moyennes (API)
Toutes les notes sont **sur 20** (pas de barème variable). Lecture : personnel de l'école ; écriture : secrétariat/directeur partout,
**un enseignant uniquement sur les matières de classe dont il est l'enseignant** (`ClasseMatiere.enseignant` = sa fiche).

| Ressource | URL |
|---|---|
| Évaluations (interrogation, devoir, composition) | `/api/evaluations` (filtres `?classeMatiere=`, `?periode=`, `?type=`) |
| Notes | `/api/notes` (filtres `?evaluation=`, `?inscription=`, `?statut=`) |
| Saisie d'une classe entière, tout ou rien | `POST /api/evaluations/{id}/notes` `{"notes": [{"inscription": "<IRI>", "valeur": 14.5}, {"inscription": "<IRI>", "statut": "absent"}]}` |
| Résultats d'une classe pour un trimestre | `GET /api/periodes/{id}/resultats?classe=<id>` |
| Bulletin d'un élève (trimestres + annuel) | `GET /api/inscriptions/{id}/bulletin` |
| Fermer un trimestre (secrétariat) / le rouvrir (directeur) | `POST /api/periodes/{id}/fermer` · `POST /api/periodes/{id}/rouvrir` |

**Statuts d'une note** : `saisie` (a une valeur de 0 à 20, 2 décimales max), `absent`, `dispense`, `non_evalue` (sans valeur, ignorés des
moyennes : jamais comptés comme zéro). Le type d'évaluation n'influe pas sur le calcul.

**Règles de calcul**
- moyenne d'une matière (trimestre) = moyenne simple de toutes ses notes `saisie` ;
- moyenne du trimestre = Σ(moyenne matière × coefficient) / Σ coefficients des matières ayant au moins une note ;
- moyenne annuelle = moyenne des moyennes de trimestre existantes (un élève arrivé au 2e trimestre est calculé sur 2 trimestres) ;
- arrondi à 2 décimales à chaque étape (matière, trimestre, année) ; rang = 1 + nombre d'élèves strictement meilleurs (ex æquo = même rang).

**Fermeture d'un trimestre** : fige moyennes et rangs de toutes les classes (le bulletin ne bouge plus si un coefficient ou une note
change ensuite) et verrouille la saisie (notes, évaluations, trimestre). `rouvrir` supprime les résultats figés ; ils sont recalculés
à la prochaine fermeture. Trimestre ouvert : résultats calculés en direct (`"source": "calcul"`) ; fermé : lus dans les résultats figés
(`"source": "snapshot"`).

Limites actuelles : tout le personnel (y compris un enseignant) peut LIRE les notes de toutes les classes ; la restriction de lecture
aux classes de l'enseignant viendra avec les routes dédiées. Les bulletins PDF et les appréciations arrivent au lot 5B.

## Clôture d'année et passage à la suivante
Même **seuil d'admission pour tous les niveaux** : paramètre `seuil_admission` de l'école (10 par défaut).

| Étape | Requête | Droit |
|---|---|---|
| Décision du conseil de classe | `POST /api/inscriptions/{id}/decision` `{"decision": "admis\|redouble\|exclu", "motif": "..."}` ; `DELETE` pour revenir au calcul | secrétariat, directeur |
| Simulation (ne modifie rien) | `POST /api/annees_scolaires/{id}/cloture/apercu` `{"anneeSuivante": "<IRI>", "affectations": [...]}` | secrétariat, directeur |
| Clôture (irréversible, tout ou rien) | `POST /api/annees_scolaires/{id}/cloturer` (même corps) | directeur |

**Décision** : calculée d'après la moyenne annuelle (≥ seuil → `admis`, sinon `redouble`). Le conseil de classe peut décider autrement ;
le **motif est alors obligatoire**. Un élève sans aucune moyenne exige une décision du conseil (avec motif).

**Devenir à la clôture**
- `admis` → réinscription (`reinscription`) dans le **niveau suivant** de l'année suivante ;
- `redouble` → `redoublement` dans le même niveau de l'année suivante ;
- `exclu` → l'élève sort de l'école (statut `sorti`) ;
- `admis` dans un niveau **sans niveau suivant** (dernier niveau de l'école) → fin de cycle : l'élève sort de l'école (`sorti`).
  Les examens d'État (BEPC, BAC) ne sont pas gérés par l'application.

**Classes de destination** : créer d'abord, dans l'année suivante (statut `brouillon`), les classes d'accueil. S'il n'y a qu'une classe du bon
niveau, elle est choisie automatiquement ; sinon préciser `affectations` :
`[{"classeSource": "/api/classes/1", "admis": "/api/classes/10", "redoublants": "/api/classes/11"}]`.

**Blocages** (la simulation les liste, la clôture les refuse tant qu'il en reste) : année non active, trimestre non fermé, aucune année suivante
valide, élève sans décision, classe de destination manquante ou ambiguë, élève déjà inscrit dans l'année suivante.

**À la clôture** : chaque inscription passe à `terminee` avec son bilan figé (moyenne annuelle, rang, effectif, décision calculée et finale) ;
l'année passe à `cloturee` et devient **en lecture seule** (classes, matières de classe, trimestres, notes, inscriptions). L'année suivante peut
ensuite être activée (`PATCH` `statut: active`).
