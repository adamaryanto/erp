<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Diagnosis (9router, OpenAI-compatible)
    |--------------------------------------------------------------------------
    | Kunci HANYA di .env server, jangan pernah commit.
    */
    'nine_base_url' => env('AI_9ROUTER_BASE_URL', ''),
    'nine_key' => env('AI_9ROUTER_KEY', ''),
    'nine_model' => env('AI_9ROUTER_MODEL', 'kr/claude-haiku-4.5'),

];
