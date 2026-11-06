<?php

return [
    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
        'teams' => false,
    ],

    'cache' => [
        'store' => 'default',
        'expiration_time' => 60 * 60,
        'key' => 'spatie.permission.cache',
        'model_key' => 'name',
    ],
];

