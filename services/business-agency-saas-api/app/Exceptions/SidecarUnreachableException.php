<?php
# ─────────────────────────────────────────────────────
# Module   : Exception
# ─────────────────────────────────────────────────────

namespace App\Exceptions;

use Exception;

class SidecarUnreachableException extends Exception
{
    // Custom exception to denote sidecar connection/timeout failures
}
