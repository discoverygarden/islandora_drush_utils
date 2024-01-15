<?php

namespace Drupal\islandora_drush_utils\Drush\Traits;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Logging helper.
 */
trait LoggingTrait {

  /**
   * Helper; get the name of the logging service to obtain.
   *
   * In PHP 8.2, this could become a constant (as traits will allow for
   * constants).
   *
   * @return string
   *   The name of the logging service that ::getLogger() should obtain.
   */
  protected static function getLoggerName() : string {
    return 'logger.islandora_drush_utils';
  }

  /**
   * Helper; get logging service from service container.
   *
   * @return \Psr\Log\LoggerInterface
   *   The logging service against which to log.
   */
  protected static function getLogger() : LoggerInterface {
    return \Drupal::service(static::getLoggerName());
  }

  /**
   * Logging helper.
   *
   * @param string $message
   *   The message to log.
   * @param array $context
   *   Replacements/context for the message.
   * @param string $level
   *   The log level.
   */
  protected function log(string $message, array $context = [], string $level = LogLevel::DEBUG) : void {
    static::getLogger()->log($level, $message, $context);
  }

}
