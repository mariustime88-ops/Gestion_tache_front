# Suivi — API de gestion des tâches

API REST Laravel 13 pour la gestion des comptes, des rôles, des notifications,
des échanges et du cycle de vie des tâches. Les comptes sont authentifiés par
des jetons Laravel Sanctum.

## Configuration locale

1. Démarrer MySQL depuis XAMPP et créer la base `gestion_taches` si elle
   n’existe pas.
2. Configurer le `.env` avec les identifiants de la base :

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gestion_taches
DB_USERNAME=root
DB_PASSWORD=
```

3. Depuis ce dossier, installer les dépendances, exécuter les migrations puis
   créer le premier compte administrateur :

```powershell
composer install
php artisan migrate
php artisan app:create-administrator
php artisan serve --host=127.0.0.1 --port=8000
```

La commande de création demande le nom, l’adresse e-mail et un mot de passe
confidentiel (12 caractères minimum) ; aucun compte ni mot de passe par défaut
n’est fourni. Dans un autre terminal, lancer
[l’interface Angular](../mon-projet-angular/README.md) avec `npm start`.

## Parcours des rôles

- L’inscription publique crée seulement une demande d’accès d’agent en attente.
- Le rôle `admin` est distinct du Directeur général (`dg`) ; le compte administrateur
  est créé avec `php artisan app:create-administrator` (mot de passe de 8 caractères
  minimum).
- Le Directeur général consulte toutes les tâches de l’organisation et l’annuaire
  actif des chefs de service, chefs de division et agents. Il peut affecter une
  tâche à l’un de ces rôles, puis accepter le résultat, demander une reprise ou
  refuser une tâche soumise, quel que soit le service.
- Un chef de service suit son service et peut affecter des tâches à ses agents.
- Un chef de division suit sa division et peut affecter des tâches à ses agents.
- L’administration IT crée les divisions et rattache les chefs de division et
  les agents à leur division. Elle peut gérer les profils par rôle, suspendre ou
  réactiver un compte, et consulter l’équipe des chefs sans supprimer l’historique.
- Le Directeur général dispose d’un annuaire en lecture seule, organisé par
  service et division.
- Le destinataire d’une tâche (chef de service, chef de division ou agent) la
  démarre pour prévenir la personne qui l’a affectée, échange dans le fil de
  suivi, puis l’envoie pour validation.
- Le responsable qui a créé la tâche peut accepter le résultat, demander une
  reprise ou le refuser. Le Directeur général peut également examiner toute
  tâche soumise.
- Le rôle `admin` conserve les fonctions d’administration déjà présentes ; il
  n’est pas le rôle du Directeur général.
- L’administration IT peut créer des rôles personnalisés, insérés au-dessus
  d’un rôle choisi dans la hiérarchie. Le rôle personnalisé hérite des capacités
  de ce rôle et peut être attribué depuis la gestion des profils ; un rôle ne
  peut pas être inséré au-dessus de `admin`.

Après déploiement, exécutez `php artisan migrate` pour créer le référentiel de
divisions et rattacher les profils existants à leurs divisions déclarées.

Après les migrations, créez les comptes initiaux de direction avec la commande
`php artisan app:provision-initial-roles`. Elle demande le mot de passe commun
de façon masquée, refuse d’écraser une adresse existante et réserve les trois
adresses e-mail aux comptes de direction. Le compte DG n’a pas de périmètre de
service ; le chef de service démarre avec `Service général` et le chef de division
avec `Service général` et `Division générale`. Les agents choisissent une
division créée par l’administration IT lors de leur inscription.

La migration sépare également le compte initial `directiongeneral@gmail.com`
du rôle `admin`. Si cette personne est déjà connectée pendant la migration,
elle doit se déconnecter puis se reconnecter pour actualiser son rôle dans
l’interface.

## Projets et candidatures

- Un chef de service actif peut publier un projet rattaché à son service.
- Les agents consultent les projets ouverts, puis peuvent envoyer une
  candidature ou une demande de contact distincte.
- Le porteur et l’agent échangent dans un fil privé ; le porteur peut accepter
  ou refuser une candidature et clôturer ou rouvrir les demandes.
- Un agent conserve l’accès à ses échanges après la clôture du projet. Les
  autres utilisateurs ne peuvent pas consulter ces conversations.

Les tables `projects`, `project_applications` et `project_messages` sont créées
par les migrations Laravel lors de `php artisan migrate`.

## Profil utilisateur

Le menu du compte permet d’ouvrir le profil ou de se déconnecter. Depuis le
profil, chaque utilisateur peut modifier son nom et sa photo ; l’adresse e-mail
reste en lecture seule. Le changement de mot de passe demande le mot de passe
actuel et sa confirmation. Les photos sont limitées aux formats JPG, PNG et
WebP (5 Mo maximum) et sont servies depuis le disque public Laravel.

Après les migrations, créer le lien de stockage public une seule fois avec
`php artisan storage:link`.

## Vérification

```powershell
php artisan test
vendor\bin\pint --test
```

Les tests Laravel utilisent une base SQLite en mémoire et ne modifient pas les
données MySQL de développement.

## Suivi du cahier des charges

| Module | Fonctionnalité | Où la trouver |
| --- | --- | --- |
| M2 | Priorité des tâches (`low`, `normal`, `high`, `urgent`) | `tasks.priority`, champ `priority` de `POST/PATCH /api/tasks` |
| Règle 2 | Saut de niveau : notification automatique aux supérieurs contournés lors d’une attribution | `POST /api/tasks` |
| M4 | Motif obligatoire (côté serveur) pour « À refaire » et « Rejetée » | `POST /api/tasks/{id}/review` |
| M3 | Demande de report de date limite par le destinataire, décision du donneur d’ordre (refus motivé) | `POST /api/tasks/{id}/extension-request`, `POST /api/tasks/{id}/extension-decision` |
| M4 | Relance du donneur d’ordre après 3 jours sans validation d’une tâche « Terminée » | commande `tasks:send-reminders` |
| M5 | Statut « En retard » calculé selon la date limite (`is_overdue`, filtre `?overdue=1`) | modèle `Task`, `GET /api/tasks`, `GET /api/dashboard` |
| M6 | Rappels à J-2 et au Jour J, alerte de retard, alertes e-mail (en option) | commande `tasks:send-reminders`, `config/tasks.php` |
| M7 | Export des tâches visibles par l’utilisateur au format CSV (ouvrable dans Excel), avec les filtres | `GET /api/tasks/export` |
| M8 | Journal d’audit horodaté, non modifiable (auteur, date, action, anciennes et nouvelles valeurs) | `GET /api/audit-logs` (administrateur et DG), table `audit_logs` |

### Mise à jour d’une installation existante

```powershell
composer install
php artisan migrate
```

### Rappels automatiques

La commande `tasks:send-reminders` est planifiée chaque jour à 07 h 00. Il faut
déclencher le planificateur Laravel toutes les minutes :

```
* * * * * cd /chemin/du/projet && php artisan schedule:run >> /dev/null 2>&1
```

Sous Windows, créer une tâche planifiée qui exécute `php artisan schedule:run`
chaque minute. Pour un essai manuel : `php artisan tasks:send-reminders`.

### Alertes par e-mail

Les notifications sont toujours visibles dans le centre de notifications. Pour
les envoyer aussi par e-mail, configurer le serveur SMTP (`MAIL_*`) puis
activer `TASKS_MAIL_NOTIFICATIONS=true` dans le `.env`.

### Hors périmètre de cette version

Le transfert latéral entre pairs et l’export PDF ne sont pas encore
implémentés (voir « Perspectives » du cahier des charges).

### Tests

```powershell
php artisan test
```

Les fonctionnalités ci-dessus sont couvertes par `tests/Feature/TaskFollowUpFeaturesTest.php`.
