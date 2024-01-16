<?php

namespace Drupal\islandora_drush_utils\Drush\Commands;

use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandError;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Command wrapper to perform user switching.
 *
 * Prior to Drush 9, there was a "--user" option which could be used to run
 * commands as other users... this command wrapper introduces the
 * "@islandora_drush_utils-user-wrap" annotation to use it where we want
 * to.
 */
class UserWrapperCommands implements LoggerAwareInterface, ContainerInjectionInterface {

  use LoggerAwareTrait;

  /**
   * The user.
   *
   * @var \Drupal\Core\Session\AccountInterface|false
   */
  protected AccountInterface|false $user = FALSE;

  /**
   * Constructor.
   */
  public function __construct(
    protected AccountSwitcherInterface $switcher,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected $debug = FALSE,
  ) {
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('account_switcher'),
      $container->get('entity_type.manager'),
      FALSE,
    );
  }

  /**
   * Add the option to the command.
   *
   * @hook option @islandora-drush-utils-user-wrap
   */
  public function userOption(Command $command, AnnotationData $annotationData) {
    $command->addOption(
      'user',
      'u',
      InputOption::VALUE_REQUIRED,
      'The Drupal user as whom to run the command.'
    );
  }

  /**
   * Logging helper; only log debug messages when so-instructed.
   *
   * @param string $message
   *   The message to log.
   */
  protected function logDebug($message) {
    if ($this->debug) {
      $this->logger->debug($message);
    }
  }

  /**
   * Ensure the user provided is valid.
   *
   * @hook validate @islandora-drush-utils-user-wrap
   */
  public function userExists(CommandData $commandData) {
    $input = $commandData->input();
    $user = $input->getOption('user');

    if (!isset($user)) {
      $this->logDebug('"user" option does not appear to be set');
      return NULL;
    }

    $user_storage = $this->entityTypeManager->getStorage('user');
    if (is_numeric($user)) {
      $this->logDebug('"user" appears to be numeric; loading as-is');
      $this->user = $user_storage->load($user);
    }
    else {
      $this->logDebug('"user" is non-numeric; assuming it is a name');
      $candidates = $user_storage->loadByProperties(['name' => $user]);
      if (count($candidates) > 1) {
        return new CommandError(
          \dt('Too many candidates for user name: @spec', [
            '@spec' => $user,
          ])
        );
      }
      $this->user = reset($candidates);
    }

    if (!$this->user) {
      return new CommandError(\dt('Failed to load the user: @spec', [
        '@spec' => $user,
      ]));
    }
  }

  /**
   * Perform the swap before running the command.
   *
   * @hook pre-command @islandora-drush-utils-user-wrap
   */
  public function switchUser(CommandData $commandData) {
    $this->logDebug('pre-command');
    if ($this->user) {
      $this->logDebug('switching user');
      $this->switcher->switchTo($this->user);
      $this->logDebug('switched user');
    }
  }

  /**
   * Swap back after running the command.
   *
   * @hook post-command @islandora-drush-utils-user-wrap
   */
  public function unswitch($result, CommandData $commandData) {
    $this->logDebug('post-command');
    if ($this->user) {
      $this->logDebug('to switch back');
      $this->switcher->switchBack();
      $this->logDebug('switched back');
      $this->user = FALSE;
    }

    return $result;
  }

}
