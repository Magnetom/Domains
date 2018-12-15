<?php

//Defining Constants
define('HOST','localhost');
define('DB','sav');
define('USER','user');
define('PASS','mysqluser');

// имя текуще БД.
$DATABASE_NAME = DB;

// Табилцы текущей БД.
$MARKS_TABLE     = 'marks';
$VEHICLES_TABLE  = 'vehicles';
$VARIABLES_TABLE = 'variables';

//Connecting to Database
$con=mysqli_connect(HOST,USER,PASS,DB);// or die('Unable to Connect to the database');
