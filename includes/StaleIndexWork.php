<?php
/**
 * A superseded response must be retried, never recorded as a successful write.
 *
 * @package RiTriever
 */

declare(strict_types=1);

namespace RiTriever;

final class StaleIndexWork extends \RuntimeException {}
