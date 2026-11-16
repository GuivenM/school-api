# Sauvegardes et restauration
**Prérequis** : `composer require symfony/process league/flysystem league/flysystem-aws-s3-v3` et les outils `mysqldump` / `mysql`
installés sur le serveur (variables `BACKUP_MYSQLDUMP_BINARY` / `BACKUP_MYSQL_BINARY` si hors du PATH, ex. `C:\xampp\mysql\bin\mysqldump.exe`).

**Destinations** (`BACKUP_DESTINATIONS`, séparées par des virgules) : `local` (dossier `BACKUP_LOCAL_DIR`, défaut `var/backups`) et `remote`
(stockage objet compatible S3 : AWS S3, Backblaze B2, Wasabi, Cloudflare R2, MinIO — variables `BACKUP_S3_*`).
Avec `local,remote`, chaque sauvegarde est copiée aux deux endroits ; si une copie échoue, l'autre est conservée et l'échec est signalé
(`PARTIEL`). Un dossier local sur le MÊME disque que la base ne protège pas d'une panne du serveur : en production, activer `remote`.

**Chiffrement** : chaque sauvegarde (SQL compressé) est chiffrée avant de quitter le serveur (`BACKUP_ENCRYPTION_KEY`, 32 octets base64).
Générer la clé de production avec `php -r "echo base64_encode(random_bytes(32));"`, la déclarer via `secrets:set` et **en garder une copie hors
du serveur** : sans elle, aucune sauvegarde n'est relisible.

| Action | Commande |
|---|---|
| Sauvegarder une école / toutes | `tenant:backup ecole_demo` · `tenant:backup --all --type=quotidienne` |
| Lister | `tenant:backup:list [slug]` |
| Vérifier (empreinte + déchiffrement, sur chaque destination) | `tenant:backup:verify <id>` |
| Restaurer | `tenant:restore ecole_demo <id> --force` |
| Purger selon la durée de conservation | `tenant:backup:purge` |
| Via l'API (super-admin) | `GET /admin/sauvegardes?tenant=` · `POST /admin/tenants/{slug}/sauvegardes` |

**Tâches planifiées** (cron, ou Planificateur de tâches Windows) : chaque nuit `tenant:backup --all --type=quotidienne` puis
`tenant:backup:purge`. Conservation : quotidiennes 14 jours, manuelles 90 jours, « avant clôture » 1 an, « avant restauration » 30 jours,
**archives de fin d'année : conservées sans limite**.

**À la clôture d'une année** (`BACKUP_ON_CLOSURE=1`) : sauvegarde `avant_cloture` (point de retour ; si elle échoue, la clôture est refusée),
puis archive `archive_annee` de l'état final.

**Restauration** (opération d'exploitation, en console uniquement) : met l'application en maintenance, prend d'abord une sauvegarde
`avant_restauration` de l'état actuel (renvoyée à la fin pour pouvoir revenir en arrière), recrée la base, importe, puis applique les migrations
manquantes. **Tester une restauration sur une école de test au moins une fois, et régulièrement** : une sauvegarde jamais restaurée n'est pas
une garantie.
