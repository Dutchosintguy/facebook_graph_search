<?php
require_once __DIR__ . '/FbGraphSearch.php';

$fbGraphSearch = new FbGraphSearch();
$fbGraphSearch->retrieveParameters();
$fbGraphSearch->doFbQuery();
