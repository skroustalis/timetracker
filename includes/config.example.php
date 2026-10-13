<?php
// Reference of every setting the app understands.
// install.php writes includes/config.php for you (app_name, timezone, debug, db).
// Copy any of the optional settings below into that file as needed.
return [
    'app_name' => 'Time Tracker',
    'timezone' => 'Europe/Athens',
    'debug' => false,              // true shows error details on screen; keep false in production

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'cpaneluser_timetracker',
        'user' => 'cpaneluser_tt',
        'pass' => 'change-me',
    ],

    // Optional
    'default_lang' => 'el',        // 'el' or 'en' (each user can pick their own)
    'default_theme' => 'glass',    // 'glass', 'classic', 'neo' or 'minimal'
    'auto_stop_hours' => 8,        // stop forgotten timers after N hours (0 = never); also editable in Settings

    // Email (invitations, password links). Without 'smtp', PHP mail() is used.
    'app_url' => 'https://timetracker.example.com/',
    'mail_from' => 'noreply@example.com',
    'smtp' => [
        'host' => 'mail.example.com',
        'port' => 465,
        'secure' => 'ssl',         // 'ssl' (port 465) or 'tls' (port 587)
        'user' => 'noreply@example.com',
        'pass' => 'change-me',
    ],
];
