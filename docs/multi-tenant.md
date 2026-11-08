# Multi-tenant (une base MySQL par école)

## Principe
- **Base centrale (landlord)** = connexion/EM `default` : table `tenants` (src/Entity, migrations/).
- **Base d'école** = connexion/EM `tenant` : tables métier (src/Tenant/Entity, tenant_migrations/).
- `TenantDriverMiddleware` remplace dbname/user/password à la connexion selon l'école active.
  Sans école active : connexion refusée (`NoActiveTenantException`).
- L'école de la requête vient (provisoirement, lot 1A) de l'en-tête `X-Tenant`.

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
