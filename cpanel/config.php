<?php
declare(strict_types=1);

/*
 * Set these environment variables in your VPS web server / PHP-FPM config:
 * PTERO_PANEL_URL=https://panel.example.com
 * PTERO_PTLA_KEY=ptla_...
 * PTERO_ADMIN_PASSWORD=use-a-long-random-password
 *
 * Never commit API keys or this file with real credentials to a public repository.
 */
return [
    'panel_url' => getenv('PTERO_PANEL_URL') ?: 'https://panel.xitercsx.biz.id',
    'ptla_key' => getenv('PTERO_PTLA_KEY') ?: 'ptla_UHab3oyEAP9RbqmSfddeef0wqgdbbqIM7hqixGm0srh',
    
     'panels' => [
        'panel1' => [
            'name' => 'Panel1',
            'url' => 'https://panel.xitercsx.biz.id',
            'api_key' => 'ptla_yPvxzPINwTAtPuNr2S6jHssiON0LWp9SFRTJeDCQIVa',
        ],
        'panel2' => [
            'name' => 'Panel2',
            'url' => 'https://panel.xecko.biz.id',
            'api_key' => 'ptla_xEiZhv5LfEySQGBabiL5vnHHzFmC4ydYWq0JfBFY7P4',
        ],
    ],

    'admin_password' => getenv('PTERO_ADMIN_PASSWORD') ?: 'cirenggoreng',
    'session_name' => 'ptero_portal_session',
    'page_size' => 50,
];
