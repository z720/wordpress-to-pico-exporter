<?php
/*
 * Run the exporter from the command line and spit the zipfile to STDOUT.
 *
 * Usage:
 *
 *     $ php pico-export-cli.php > my-pico-files.zip
 *
 * Must be run in the wordpress-to-pico-exporter/ directory.
 *
 */

include "../../../wp-load.php";
include "../../../wp-admin/includes/file.php";
require_once "pico-export.php"; //ensure plugin is "activated"

$pe = new Pico_Export();
$pe->export();
