<?php

namespace Drupal\islandora_drush_utils\Drush\Commands\Traits;

use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Process\Process;

/**
 * Facilitate forwarding of verbosity options to wrapped commands.
 */
trait WrappedCommandVerbosityTrait {

  /**
   * Helper; build map of verbosity options from the current instance.
   *
   * @return bool[]
   *   An associative array mapping options as strings to booleans representing
   *   the state of the given options.
   *
   * @see static::mapVerbosityOptions()
   */
  protected function getVerbosityOptions() : array {
    $input = match(TRUE) {
      $this instanceof DrushCommands => $this->input(),
    };

    return static::mapVerbosityOptions($input);
  }

  /**
   * Helper; build map of verbosity options from the given input.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The input from which to map the options.
   *
   * @return bool[]
   *   An associative array mapping options as strings to booleans representing
   *   the state of the given options.
   */
  protected static function mapVerbosityOptions(InputInterface $input) : array {
    return [
      // Adapted from https://github.com/drush-ops/drush/blob/7fe0a492d5126c457c5fb184c4668a132b0aaac6/src/Application.php#L291-L302
      'verbose' => $input->getParameterOption(['--verbose', '-v'], FALSE, TRUE) !== FALSE,
      'vv' => $input->getParameterOption(['-vv'], FALSE, TRUE) !== FALSE,
      'vvv' => $input->getParameterOption(['--debug', '-d', '-vvv'], FALSE, TRUE) !== FALSE,
    ];
  }

  /**
   * Helper to directly output from wrapped commands.
   *
   * @param string $type
   *   The type of output, one of Process::OUT and Process::ERR.
   * @param string $output
   *   The output to output.
   *
   * @return false|int
   *   The number of bytes written; otherwise, FALSE.
   */
  protected static function directOutputCallback(string $type, string $output) : false|int {
    $fp = match($type) {
      Process::OUT => STDOUT,
      Process::ERR => STDERR,
    };
    return fwrite($fp, $output);
  }

}
