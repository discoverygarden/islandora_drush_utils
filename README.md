# Islandora Utilities

![](https://github.com/discoverygarden/islandora_drush_utils/actions/workflows/auto-lint.yml/badge.svg)
![](https://github.com/discoverygarden/islandora_drush_utils/actions/workflows/auto-semver.yml/badge.svg)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

## Introduction

Contains a set of utility Drush commands for Islandora.

## Table of Contents

* [Features](#features)
* [Requirements](#requirements)
* [Installation](#installation)
* [Usage](#usage)
* [Troubleshooting/Issues](#troubleshootingissues)
* [Maintainers and Sponsors](#maintainers-and-sponsors)
* [Development/Contribution](#developmentcontribution)
* [License](#license)

## Features

- Deleter command to recursively delete all related nodes, media, and files.
- Generate thumbnails command to re-derive thumbnails for nodes.
- Null child weight command to identify and update nodes with a mix of null and integer values in field_weight.
- Re-derive command to re-derive all derivatives for a set of nodes.
- A user wrapper to replace the legacy [--user](https://github.com/drush-ops/drush/issues/3396) drush wrapper to support user in drush commands.

## Requirements

This module requires the following modules/libraries:

* [Islandora](https://github.com/Islandora/islandora)

## Installation

Install as usual, see
[this]( https://www.drupal.org/docs/extending-drupal/installing-modules) for
further information.

## Usage

### Deleter

```bash
drush islandora_drush_utils:delete-recursively -vvv --dry-run --empty 7,11 --user=islandora
```

Given a comma-separated list of nodes to target, this command performs a breadth-first search to find all descendent nodes and deletes them, including their related media, and marks files related to media as "temporary" such that they become eligible for garbage collection by Drupal.

### Generate thumbnails

```bash
drush islandora_drush_utils:rederive_thumbnails --model=Image -vvv --nids=7,11 --user=islandora
```

Given a comma-separated list of nodes to target, this command will re-generate thumbnails for the target model. A file containing comma-separated nids can also be provided as input.

### Re-derive

```bash
drush islandora_drush_utils:rederive -vvv --user=islandora
```

This command re-generates all derivatives on the website. It is possible to define which "media use" term should be used as the source for derivative generation. This defaults to [Original file](http://pcdm.org/use#OriginalFile)

### Null child weight

```bash
drush islandora_drush_utils:null-child-weight-updater --verbose
--dry-run 10 --user=islandora
```

This command identifies and updates nodes that have a mix of null and integer values in field_weight.

### Queue runner

We have taken to use `queue:run` more; however, it does not natively have protections like running batches do, to occasionally re-fork to reduce memory usage due to Drupal's use of static caching.

This command allows the specification of some options with which to run a queue to completion:
- `--time-per-iteration`: The amount of time we will request `queue:run` to run, in seconds. Defaults to 300.
- `--items-per-iteration`: The number of items to request `queue:run` to process. Defaults to 100.

Otherwise, this is intended to be just be a slightly safer (in terms of memory usage) alternative to `queue:run`.

This command is just `islandora_drush_utils:queue:run`, and might be invoked with something like:

```bash
drush islandora_drush_utils:queue:run {queue_id}
```

where `{queue_id}` is the ID/name of one of the queues as might be returned from `queue:list` (or otherwise defined as the ID of a `@QueueWorker` plugin).

### SEC-873 Remedy

SEC-873 is an internal issue dealing with repercussions of https://www.drupal.org/project/views_bulk_edit/issues/3084329 .

With this issue, replacing or adding paragraphs values to paragraph fields using Views Bulk Operations "modify fields" operation could lead to the same paragraph entity/ID being unexpectedly used across multiple parent entities. This can then lead to issues attempting to individually edit any of these entities, such as values from different paragraph revisions leaking to other entities referencing the same paragraph ID.

At the moment, this d.o issue is still open; however, there are patches on it which appear to work (though there may be other issues if combining with allowing the creation of new taxonomy terms inside of the paragraph; however, such should be able to be worked-around by creating such terms first, separately).

The process here creates new paragraph entities where it is detected that a paragraph is being shared across multiple parent entities, such that future edits the particular items should work as expected.

There are a few related commands:

- `islandora_drush_utils:sec-873:get-current`: Dumps CSV to stdout which can be consumed by the `:repair` and/or `:repair:enqueue` commands, identifying what paragraphs are referenced across which entities in current/default revisions.
- `islandora_drush_utils:sec-873:get-revisions`: Similar to `:get-current`, dumps similar CSV to stdout across ALL revisions, including the revision ID alongside entity IDs. Intended more for informative purposes.
- `islandora_drush_utils:sec-873:repair`: Wraps the `[...]:enqueue` and `[...]:batch` commands together, for convenience.
- `islandora_drush_utils:sec-873:repair:enqueue`: Consume CSV to populate queue to be processed.
- `islandora_drush_utils:sec-873:repair:batch`: Process the populated queue.

### User wrapper

```bash
--user=1
```

Before Drush 9, there was a "--user" option that could be used to run commands as other users. Here, a new  "@islandora_drush_utils-user-wrap" annotation is provided, which can be used to allow the --user option in commands.

## Troubleshooting/Issues

Having problems or solved a problem? Contact [discoverygarden](http://support.discoverygarden.ca).

## Maintainers and Sponsors

Current maintainers:

* [discoverygarden](http://www.discoverygarden.ca)

Sponsors:

* [FLVC](https://www.flvc.org/)

## Development/Contribution

If you would like to contribute to this module, please check out github's helpful
[Contributing to projects](https://docs.github.com/en/get-started/quickstart/contributing-to-projects) documentation and Islandora community's [Documention for developers](https://islandora.github.io/documentation/contributing/CONTRIBUTING/#github-issues) to create an issue or pull request and/or
contact [discoverygarden](http://support.discoverygarden.ca).

## License

[GPLv3](http://www.gnu.org/licenses/gpl-3.0.txt)
