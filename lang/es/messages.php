<?php

return array_replace_recursive(require __DIR__.'/../en/messages.php', [
    'home' => ['retrieved' => 'Los datos de inicio se obtuvieron correctamente.'],
    'categories' => ['retrieved' => 'Las categorías se obtuvieron correctamente.'],
    'profile' => [
        'retrieved' => 'El perfil se obtuvo correctamente.',
        'updated' => 'El perfil se actualizó correctamente.',
    ],
    'feeds' => [
        'posts_retrieved' => 'Las publicaciones se obtuvieron correctamente.',
        'liked_retrieved' => 'Los videos que te gustan se obtuvieron correctamente.',
        'saved_retrieved' => 'Los videos guardados se obtuvieron correctamente.',
        'drafts_retrieved' => 'Los borradores de video se obtuvieron correctamente.',
    ],
    'preferences' => [
        'retrieved' => 'Las preferencias se obtuvieron correctamente.',
        'updated' => 'Las preferencias se actualizaron correctamente.',
    ],
    'notifications' => [
        'live_now_title' => ':name está en vivo ahora',
        'live_now_body' => 'Únete ahora a la transmisión en vivo de :name.',
    ],
    'videos' => [
        'live_started' => 'La transmisión en vivo se inició correctamente.',
        'live_stopped' => 'La transmisión en vivo se detuvo correctamente.',
        'only_video_can_go_live' => 'Solo los videos pueden transmitirse en vivo.',
    ],
    'validation' => ['language_supported' => 'Elige un idioma de la aplicación compatible.'],
]);