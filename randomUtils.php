<?php

function getRandomStr(){
    mt_srand ((double) microtime() * 1000000);
    $randomString1 = dechex(mt_rand());
    $randomString2 = dechex(mt_rand());
    return $randomString1.$randomString2;
}

