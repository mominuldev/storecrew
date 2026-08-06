<?php
/**
 * Thrown when a requested service is not registered.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core\Container;

use Psr\Container\NotFoundExceptionInterface;

defined( 'ABSPATH' ) || exit;

final class NotFoundException extends \RuntimeException implements NotFoundExceptionInterface {
}
