<?php

namespace Drupal\islandora_drush_utils\Drush\Commands;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Queue\QueueFactory;
use Drupal\islandora_drush_utils\Drush\Commands\Traits\WrappedCommandVerbosityTrait;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drush\Commands\core\QueueCommands;
use Drush\Drush;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Extended queue-running command.
 */
class QueueDrushCommands extends DrushCommands {

  use AutowireTrait;
  use WrappedCommandVerbosityTrait;

  /**
   * Construct.
   */
  public function __construct(
    #[Autowire(service: 'queue')]
    protected QueueFactory $queueFactory,
  ) {
    parent::__construct();
  }

  /**
   * Command callback; wrap queue running to run to completion.
   *
   * NOTE: `time-per-iteration` and `items-per-iteration` should be selected
   * with awareness of the environments memory constraints.
   */
  #[CLI\Command(name: 'islandora_drush_utils:queue:run')]
  #[CLI\Argument(name: 'name', description: 'The name of the queue to run.')]
  #[CLI\Option(name: 'time-per-iteration', description: 'The time limit we will provide to the wrapped `queue:run` invocation.')]
  #[CLI\Option(name: 'items-per-invocation', description: 'The item limit we will provide to the wrapped `queue:run` invocation.')]
  #[CLI\ValidateQueueName(argumentName: 'name')]
  public function runQueue(
    string $name,
    array $options = [
      'time-per-iteration' => 300,
      'items-per-iteration' => 100,
    ],
  ) : void {
    $queue = $this->queueFactory->get($name, TRUE);

    while ($queue->numberOfItems() > 0) {
      $process = Drush::drush(
        Drush::aliasManager()->getSelf(),
        QueueCommands::RUN,
        [$name],
        [
          'time-limit' => $options['time-per-iteration'],
          'items-limit' => $options['items-per-iteration'],
        ] + $this->getVerbosityOptions(),
      );
      // We expect sane exit from time * items.
      $process->setTimeout(NULL);
      $process->run(static::directOutputCallback(...));
      if (!$process->isSuccessful()) {
        throw new \Exception('Subprocess failed.');
      }
    }
  }

}
