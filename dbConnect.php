<?php

//Defining Constants
define('HOST','localhost');
define('USER','root');
define('PASS','');
define('DB','sav');

// имя текуще БД.
$DATABASE_NAME = DB;

// Табилцы текущей БД.
$MARKS_TABLE    = 'marks';
$VEHICLES_TABLE = 'vehicles';

//Connecting to Database
$con=mysqli_connect(HOST,USER,PASS,DB) or die('Unable to Connect to the database');
