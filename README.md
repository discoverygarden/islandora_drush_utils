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

* [FLVC](@todo Add link)

## Development/Contribution

If you would like to contribute to this module, please check out github's helpful
[Documentation for Developers](https://docs.github.com/en/get-started/quickstart/contributing-to-projects) to create an issue or pull request and/or
contact [discoverygarden](http://support.discoverygarden.ca).

## License

[GPLv3](http://www.gnu.org/licenses/gpl-3.0.txt)
