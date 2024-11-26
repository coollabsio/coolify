<?php

$version = include 'config/constants.php';
echo $version['coolify']['version'] ?: 'unknown';
