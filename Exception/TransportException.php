<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Exception;

/**
 * Thrown when a node could not be reached at all — no HTTP response.
 *
 * Distinct from {@see TypesenseException} because a 4xx does not trigger failover:
 * a server that answers 4xx is up and disagrees, and trying the next node changes nothing.
 * A 5xx does fail over — that node is lagging or overloaded, its peers may not be.
 */
class TransportException extends TypesenseException
{
}
