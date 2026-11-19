<?php

return [
    'user_data_path' => env('USER_DATA_PATH', base_path('../infra/user_data')),
    'n8n_internal_url' => rtrim((string) env('N8N_INTERNAL_URL', 'http://jarvis-n8n:5678'), '/'),
    'owner_email' => env('JARVIS_OWNER_EMAIL'),
    'owner_password' => env('JARVIS_OWNER_PASSWORD'),
    'frontend_url' => env('FRONTEND_URL', 'https://jarvis-dashboard.victor-giroud.fr'),
];
