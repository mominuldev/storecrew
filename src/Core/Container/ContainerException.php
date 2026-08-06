<?php
/**
 * Thrown when a service cannot be resolved.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew\Core\Container;

use Psr\Container\ContainerExceptionInterface;

defined( 'ABSPATH' ) || exit;

final class ContainerException extends \RuntimeException implements ContainerExceptionInterface {
}
