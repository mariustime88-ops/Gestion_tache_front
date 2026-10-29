<?php

return [
    /*
    | Envoie aussi les notifications de tâches par e-mail (en plus du centre
    | de notifications). Activer uniquement quand le serveur SMTP est configuré.
    */
    'mail_notifications' => env('TASKS_MAIL_NOTIFICATIONS', false),
];
