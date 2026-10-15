<?php

$frontend = (string)env('FRONTEND_URL', 'http://localhost:3000');

return [
    /*
    | The relying party of passkeys: the site members sign in on. A passkey is bound to its id (the registrable domain, so it also works on the
    | subdomains), and the browser only offers it on pages of that domain. Changing it makes every saved passkey unusable
    */
    'rp_id' => env('WEBAUTHN_RP_ID', parse_url($frontend, PHP_URL_HOST) ?: 'localhost'),
    'rp_name' => env('WEBAUTHN_RP_NAME', 'MLP Vector Club'),
    // The origins the browser may report in a response, the front end by default
    'origins' => array_values(array_filter(array_map('trim', explode(',', (string)env('WEBAUTHN_ORIGINS', $frontend))))),
    // How long the options of a sign-in stay good: the browser keeps the autofill request open while a visitor types their address
    'challenge_ttl' => 600,
];
