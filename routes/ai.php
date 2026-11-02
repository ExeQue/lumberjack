<?php

declare(strict_types=1);

use App\Domains\Mcp\Http\Middleware\AuthenticateMcpToken;
use App\Domains\Mcp\Servers\LumberjackServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', LumberjackServer::class)->middleware([AuthenticateMcpToken::class, 'throttle:mcp']);
