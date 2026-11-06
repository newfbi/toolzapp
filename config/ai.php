<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Modelos de conversação permitidos
    |--------------------------------------------------------------------------
    |
    | A lista é centralizada para que a interface, a validação e os serviços
    | de IA nunca ofereçam modelos divergentes entre si.
    |
    */
    'models' => [
        'gpt-6.1-sol' => 'GPT-6.1 Sol',
        'gpt-6-luna' => 'GPT-6 Luna',
    ],

    'default_model' => 'gpt-6.1-sol',
];
