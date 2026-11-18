<?php

namespace App\Coroner\Service;

/**
 * Raised while the (simulated) third-party inventory API is down.
 * Retrying after the outage is over is exactly what fixes these.
 */
final class InventoryUnavailableException extends \RuntimeException
{
}
