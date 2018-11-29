<?php

//Defining Constants
define('HOST','localhost');
define('USER','root');
define('PASS','');
define('DB','sav');

$DATABASE_NAME = DB;
$MARKS_TABLE = 'marks';

//Connecting to Database
$con=mysqli_connect(HOST,USER,PASS,DB) or die('Unable to Connect to the database');
