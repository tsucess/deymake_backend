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
        'live_session_retrieved' => 'La sesión en vivo se obtuvo correctamente.',
        'live_not_active' => 'Esta transmisión en vivo ya no está activa.',
        'live_signal_sent' => 'La señal en vivo se envió correctamente.',
        'live_signals_retrieved' => 'Las señales en vivo se obtuvieron correctamente.',
        'live_signal_recipient_required' => 'Se requiere un destinatario para esta señal en vivo.',
        'live_signal_recipient_invalid' => 'No puedes enviarte una señal en vivo a ti mismo.',
        'live_signal_type_not_allowed' => 'Este tipo de señal en vivo no está permitido para el participante actual.',
        'live_signal_payload_required' => 'A esta señal en vivo le faltan los datos requeridos.',
        'live_invitee_not_eligible' => 'Este miembro de la audiencia ya no puede unirse a esta transmisión en vivo.',
        'live_guest_already_joined' => 'Este miembro de la audiencia ya ha sido aprobado para unirse a la transmisión.',
        'live_guest_request_pending' => 'Este miembro de la audiencia tiene una solicitud pendiente. Responde a esa solicitud.',
        'live_guest_request_not_pending' => 'Esta solicitud de invitado ya no está pendiente.',
        'live_invite_not_pending' => 'Esta invitación de invitado ya no está pendiente.',
        'only_video_can_go_live' => 'Solo los videos pueden transmitirse en vivo.',
        'upload_must_finish_processing_for_live' => 'Espera a que termine el procesamiento del video antes de iniciar la transmisión en vivo.',
    ],
    'validation' => ['language_supported' => 'Elige un idioma de la aplicación compatible.'],
]);