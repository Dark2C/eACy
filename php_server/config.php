<?php
// Configurazione principale della demo.
// Le credenziali MySQL vengono salvate automaticamente da install.php.

define('APP_NAME', 'EACY Control Panel');
define('APP_TIMEZONE', 'Europe/Rome');
define('SESSION_NAME', 'eacy_access_demo');

// Database MySQL
define('DB_HOST', 'localhost');
define('DB_NAME', 'my_ciampagliasandbox');
define('DB_USER', 'user');
define('DB_PASS', 'pass');
define('DB_CHARSET', 'utf8mb4');

// Prefisso delle tabelle. Usa soltanto lettere, numeri e underscore.
define('TABLE_PREFIX', 'eacy_');

// Limite massimo del body Base64 ricevuto dal microcontrollore.
define('MAX_API_BODY_BYTES', 524288);
