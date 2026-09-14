<?php
// config.php - Keep this file secure (do not upload to public GitHub)
return [
    'smtp' => [
        'host'       => 'smtp.gmail.com',
        'username'   => 'your-email@gmail.com',          // ← Change this
        'password'   => 'your-16-digit-app-password',    // ← Use Gmail App Password
        'port'       => 587,
        'from_name'  => 'GRC Student Portal',
    ],
    'db' => [
        'host' => 'localhost',
        'name' => 'student_portal_db',
        'user' => 'root',
        'pass' => '',
    ]
];