<?php
return [
    'db_host' => getenv('DB_HOST') ?: 'localhost',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_name' => getenv('DB_NAME') ?: 'mk_tracker',
    'db_user' => getenv('DB_USER') ?: 'root',
    'db_pass' => getenv('DB_PASS') ?: '',
    'upload_dir' => getenv('UPLOAD_DIR') ?: __DIR__ . '/uploads',
    'max_upload_mb' => (int)(getenv('MAX_UPLOAD_MB') ?: 15),
    'allowed_extensions' => ['pdf', 'doc', 'docx', 'jpg', 'png', 'zip', 'rar', '7z'],
];
