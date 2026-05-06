<?php

declare(strict_types=1);

return [
    'app_name' => 'Drug Disease AI Predictor',
    'base_url' => 'http://localhost:8080',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'drug_disease_ai',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'python_api' => [
        'base_url' => 'http://127.0.0.1:8000',
        'timeout' => 120,
        'compare_timeout' => 180,
    ],
    'session_name' => 'drug_disease_ai_session',
];
