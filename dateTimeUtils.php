<?php 


function getTimeDiff ($startDateTime, $stopDateTime){
    
    //$startDateTime = "2017-10-02 14:56:50";
    //$stopDateTime  = "2017-10-02 18:58:44";

    $datetime1 = date_create($startDateTime);
    $datetime2 = date_create($stopDateTime);
    $interval  = date_diff($datetime1, $datetime2);
    //echo $interval->format("%H:%I:%S")."<br>";
    $diffDays = (int) $interval->format("%D");
    $diffHour = (int) $interval->format("%H");
    $diffMin  = (int) $interval->format("%I");
    $diff = $diffDays*24*60 + $diffHour*60 + $diffMin;
    return (int)$diff;
}

function getLocalizedNow(){
    date_default_timezone_set('UTC');
    $_tempDate = new DateTime(date('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    $_tempDate->setTimezone(new DateTimeZone('ASIA/Novosibirsk'));
    return $_tempDate->format('Y-m-d H:i:s');
}

function getLocalizedDate(){
    date_default_timezone_set('UTC');
    $_tempDate = new DateTime(date('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    $_tempDate->setTimezone(new DateTimeZone('ASIA/Novosibirsk'));
    return $_tempDate->format('Y-m-d');
}

function getLocalizedTime(){
    date_default_timezone_set('UTC');
    $_tempDate = new DateTime(date('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    $_tempDate->setTimezone(new DateTimeZone('ASIA/Novosibirsk'));
    return $_tempDate->format('H:i:s');
}

function getFormattedDate($multy_format_date_string){
    if ( !isset($multy_format_date_string) || (strlen($multy_format_date_string)==0) ) return getLocalizedDate();
    
    $tmpDate = new DateTime($multy_format_date_string, new DateTimeZone('UTC'));
    return $tmpDate->format('Y-m-d');
}

function getFormattedTime($multy_format_date_string){
    if ( !isset($multy_format_date_string) || (strlen($multy_format_date_string)==0) ) return getLocalizedTime();
    
    $tmpDate = new DateTime($multy_format_date_string, new DateTimeZone('UTC'));
    return $tmpDate->format('H:i:s');
}
?>