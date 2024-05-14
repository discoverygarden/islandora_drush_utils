<?php

namespace Drupal\islandora_drush_utils\Drush\CommandInfoAlterers;

use Consolidation\AnnotatedCommand\CommandInfoAltererInterface;
use Consolidation\AnnotatedCommand\Parser\CommandInfo;
use Psr\Log\LoggerInterface;

/**
 * Re-create the global --user option.
 *
 * XXX: Drush 9 dropped it; however, we require this option for various
 * commands.
 */
class UserWrappingAlterer implements CommandInfoAltererInterface {

  /**
   * The annotation we use to handle user swapping.
   */
  const ANNO = 'islandora-drush-utils-user-wrap';

  /**
   * The set of commands we wish to alter.
   */
  const COMMANDS = [
    'content-sync:import',
    'content-sync:export',
    'batch:process',
    'migrate:rollback',
  ];

  /**
   * Constructor.
   */
  public function __construct(
    protected LoggerInterface $logger,
    protected $debug = FALSE,
  ) {
    // No-op.
  }

  /**
   * {@inheritdoc}
   */
  public function alterCommandInfo(CommandInfo $commandInfo, $commandFileInstance) : void {
    if (!$commandInfo->hasAnnotation(static::ANNO) && in_array($commandInfo->getName(), static::COMMANDS)) {
      if ($this->debug) {
        $this->logger->debug(
              'Adding annotation "@annotation" to @command.', [
                '@annotation' => static::ANNO,
                '@command' => $commandInfo->getName(),
              ]
          );
      }
      $commandInfo->addAnnotation(static::ANNO, 'User swapping fun.');
    }
  }

}
