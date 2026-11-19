<?php

// XBOARD_ROOT lets the plugin tests run against a separate XBoard checkout.
$root = getenv('XBOARD_ROOT') ?: dirname(__DIR__, 3);
$loader = require rtrim($root, '/') . '/vendor/autoload.php';
$loader->addPsr4('Plugin\\ChatwootSync\\', dirname(__DIR__), true);
