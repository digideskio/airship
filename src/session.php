<?php
declare(strict_types=1);

// Start the session
if (!\session_id()) {
    if (isset($state->session_config)) {
        \session_start($state->session_config);
    } else {
        \session_start([
            // Prevent uninitialized sessions from being accepted
            'use_strict_mode' => true,
            // 32 bytes = 256 bits, which mean a 50% chance of 1 collision after 2^128 sessions
            'entropy_length' => 32,
            // The session ID cookie should be inaccessible to JavaScript
            'cookie_httponly' => true
        ]);
    }
}

if (!isset($_SESSION['created_canary'])) {
    // We haven't seen this session ID before
    $oldSession = $_SESSION;
    // Create the canary
    $oldSession['created_canary'] = (new \DateTime('NOW'))
        ->format('Y-m-d\TH:i:s');
    // Make sure $_SESSION is empty before we regenerate IDs
    $_SESSION = [];
    \session_regenerate_id(true);
    // Now let's restore the superglobal
    $_SESSION = $oldSession;
}