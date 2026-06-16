<?php
use CompanyFinder\Auth;

$config = require __DIR__ . '/../src/bootstrap.php';
(new Auth($config['auth']))->logout();

header('Location: login.php');
exit;
