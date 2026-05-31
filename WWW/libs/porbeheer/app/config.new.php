<?php
declare(strict_types=1);

/*
 * Centrale configuratie
 * NIET in git
 * NIET in public webroot
 */

return [

    'production' => [

        'db' => [
            'host' => 'sql221.internl.net',
            'name' => 'PorBeheer',
            'user' => 'db8854231',
            'pass' => 'ejCt4MfzwS',
        ],

        'mail' => [
            'smtp_host' => 'mail223.internl.net',
            'smtp_port' => 587,
            'smtp_user' => 'automat@porzbeheer.nl',
            'smtp_pass' => 'TCdsbHsBg3',

            'from_email' => 'no-reply@porzbeheer.nl',
            'from_name'  => 'Administratie Porzbeheer',
            'debug'      => 0,
        ],
    ],

    'demo' => [

        'db' => [
            'host' => 'sql221.internl.net',
            'name' => 'PorDemo',
            'user' => 'db8854232',
            'pass' => 'NZZhaed6Pa',
        ],

        'mail' => [
            'smtp_host' => 'mail223.internl.net',
            'smtp_port' => 587,
            'smtp_user' => 'automat@porzbeheer.nl',
            'smtp_pass' => 'TCdsbHsBg3',

            'from_email' => 'demo_no_reply@porzbeheer.nl',
            'from_name'  => 'Porbeheer DEMO',
            'debug'      => 0,
        ],
    ],

    'development' => [

        'db' => [
            'host' => '127.0.0.1',
            'name' => 'porbeheer',
            'user' => 'porbeheer_user',
            'pass' => 'MoeIlIJk#PasSwOrd23#',
        ],

        'mail' => [
            'smtp_host' => 'mail223.internl.net',
            'smtp_port' => 587,
            'smtp_user' => 'automat@porzbeheer.nl',
            'smtp_pass' => 'TCdsbHsBg3',

            'from_email' => 'dev_no_reply@porbeheer.local',
            'from_name'  => 'Porbeheer DEVELOPMENT',
            'debug'      => 2,
        ],
    ],

    'security' => [
        'session_idle_timeout' => 300
    ],

];