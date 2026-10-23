<?php

use App\Mcp\Servers\VectorClubServer;
use Laravel\Mcp\Facades\Mcp;

// Only user-created API keys are accepted here, a browser session or a sign-in token is not
Mcp::web('/mcp', VectorClubServer::class)->middleware(['auth:sanctum', 'mcp.key', 'throttle:api']);
