<?php

/*
 * Brief:   Скрипт по обработке запросов транспортных средств на отметку в системе учета количества пройденных кругов.
 * Author:  Белов Сергей {ODYSSEY} OdysseyGroove@gmail.com
 * Version: 1.01
 * Date:    12.2018
 */

// Отключаем кэширование данных.
header("Cache-Control: no-cache, must-revalidate", true);


// Инфо: Все данные в запросе от клиента приходят в json-формате. Сервер также отвечает в формате json.
$response  = json_decode(file_get_contents("php://input"), true);

// Подключаемся к файлу с настройками безопасности.
require_once('secure.php');
// Подключаемся к файлу с общими настройками.
require_once('settings.php');
// Подключаемся к вспомогательному файлу для генерации случайных чисел и строк.
require_once('randomUtils.php');

// Получаем уникальный идентификатор запроса от клиента (генерируется клиентом).
// Его цель - исключить дублирование записей в БД MySQL при повторных http-запросах, обусловленных
// особенностью работы некоторых библиотек формирования запросов. Например, библиотека Volley в Android в некоторых
// обстоятельствах дуюлирует единажды сформированный запрос, в результате php-скрипт выполняется для одних и ех же данных и
// в БД появляются дублирющиеся записи. Имея колонку request_id в БД и индексом UNIQUE, возможно исключить дублирующие записи.
$request_id = $response['request_id'];

// Проверяем идентификатор
if (!isset($response['request_id']) || $request_id == '') echo_empty_and_die();

// Получаем отпечаток клиента для проверки безопасности.
$token = $response['token'];

// Проверяем отпечаток
if (!isset($response['token']) || $token != $CLIENT_REQUEST_TOKEN) echo_empty_and_die();

// Получаем из http запроса гос. номер траспортного средства.
$vehicle_id = $response['vehicle_id'];

// Если в запросе не указан идентификационный номер, завершаем скрипт.
if (!isset($vehicle_id)) echo_empty_and_die();

// Так как по техническому заданию клиенты и сервер находятся в одной подсети, то становится возможным определение
// MAC-адреса устройства клиента запроса.
$client_mac = get_client_mac();

// Разрешение на запись в БД новой отметки. По-умолчанию - запрещено.
$enable = false;

// Имеет ли текущее ТС временную блокировку (запрет отметок и пр.), установленную администратором ресурса.
$blocked = false;

// Флаг глобального запрещения на возможность клиентам профодить отметки. По-умолчанию - флаг установлен т.е.
// все отметки запрещены. Далее путем чтения системной переменной global_blocked из MySQL таблицы variables алгоритм
// программы уточняет значение этой переменной.
$global_blocked = true;

// Статус выполнения скрипта, который будет направлен клиенту после его выполнения. По-умолчанию - unknown;
$status = "unknown";

// Значение задержки в минутах, которое будет отправлено клиенту, чтобы последующая его попытка сделать отметку
// на состоялась ранее текущего времени плюс это значения. Реальное значение присваивается в течении работы скрипат.
$delay = 0;

// Значение задержки в минутах между двумя последоваельными отметками. Если это значение отсутствует в таблице
// переменных, то выставляется значение по-умолчанию MARK_ENABLE_DEFAULT_TIMEOUT.
$delay_mark = MARK_ENABLE_DEFAULT_TIMEOUT;

// Значение задержки в минутах перед следующей попыткой отметится в случае, если текущее ТС заблокировано
// администратором ресурса. Если это значение отсутствует в таблице переменных, то выставляется значение
// по-умолчанию MARK_BLOCKED_VEHICLE_DEFAULT_TIMEOUT.
$delay_block = MARK_BLOCKED_VEHICLE_DEFAULT_TIMEOUT;

// Утилиты для работы со временем.
require_once 'dateTimeUtils.php';

// Подключается база данных.
require_once 'dbConnect.php';

// Проверяется факт удачного подключения к БД.
//if (!isset($con)){
//    echo_error_and_die($con,'Unable connect do database!');
//}

// Устанавливается требуемая кодировка символов для работы с БД.
$sql = "SET NAMES utf8;";
mysqli_query($con,$sql);


/*********************************************************************************************/
/* Получаем значение некоторых настроек из БД: значение времени таймаута между отметками и
   таймаут задержки в случае, если текущее ТС заблокировано администратором ресурса.
   если искомые настройки в БД не найдены, то используются значения по-умолчанию.
/*********************************************************************************************/
// Создаем запрос: все нужные настройки.
$sql = "SELECT * FROM $DATABASE_NAME.$VARIABLES_TABLE WHERE name='mark_delay' OR name='disable_delay' OR name='global_blocked';";

// Выполняем скрипт.
$query_result = mysqli_query($con,$sql);

// Взятие выборки было с ошибкой! Останавливаем скрипт.
if ($query_result == false) echo_error_and_die($con,'Unable get sql query result: {get variables}!');

// Если в запрошенной выборке есть результат...
if(mysqli_num_rows($query_result)){

    /* Перебираем все данные в выборке. */
    while($rows = mysqli_fetch_array($query_result)){

        if ($rows['name'] == 'mark_delay')     $tmp1 = $rows['value'];
        if ($rows['name'] == 'disable_delay')  $tmp2 = $rows['value'];

        if ($rows['name'] == 'global_blocked') $tmp3 = $rows['value'];

        // Изменяем значения переменных по-умолчанию на значения из таблицы БД [variables].
        // Но перед этим проверяем даные на валидность.
        if (isset($tmp1) && $tmp1>0) $delay_mark     = $tmp1;
        if (isset($tmp2) && $tmp2>0) $delay_block    = $tmp2;
        if (isset($tmp3))            $global_blocked = ($tmp3=='0')?false:true;
    }
    // Освобождаем ресурсы, занятые предыдущем запросом.
    mysqli_free_result($query_result);

} else {
    /* В списках настроек нет ни одной из искомых переменных: используем значения по-умолчанию! */
}

/*********************************************************************************************/
/* Ищем в БД общую информацию о транспортном средстве. Возможно, оно временно заблокировано
   администратором ресурса и не имеет право отмечаться.
/*********************************************************************************************/
// Создаем запрос: ТС с госномером vehicle_id и статусом disabled.
$sql = "SELECT * FROM $DATABASE_NAME.$VEHICLES_TABLE WHERE vehicle='$vehicle_id' AND blocked='1';";

// Выполняем скрипт.
$query_result = mysqli_query($con,$sql);

// Взятие выборки было с ошибкой! Останавливаем скрипт.
if ($query_result == false) echo_error_and_die($con,'Unable get sql query result: {is vehicle blocked?}!');

// Если в запрошенной выборке есть результат...
if(mysqli_num_rows($query_result)){

    /* Получаем курсор на данные о запрошенном ТС. Значит это ТС заблокировано. Должна быть одна строка с несколькими.
     * колнками. Если количество строк нулевое, значит что-то не так с БД - надо разбираться!
     */
    $rows = mysqli_fetch_array($query_result);
    if (count($rows) == 0) echo_error_and_die($con,'Unexpected problem #1 with database metadata! Please, contact with developer.');
    // Проверка валидности функционирования БД. Значение априори должно быть 1, так как в проичном случае эта выборка не
    // должна была состояться.
    $blocked = (boolean)$rows['blocked'];
    if (!$blocked) echo_error_and_die($con,'Unexpected problem #2 with database metadata! Please, contact with developer.');

    // Освобождаем ресурсы, занятые предыдущем запросом.
    mysqli_free_result($query_result);

    /* Все проверки выполнены. Данный госномер временно ЗАБЛОКИРОВАН администратором ресурса. */

    // Усанавливаем время таймаута.
    $delay = $delay_block;

} else { // Указанный госномер НЕ отмечен как заблокированный.
    $blocked = false;
}


/*********************************************************************************************/
/* Ищем в БД отмечалось ли это транспортное средство сегодня и когда была последняя отметка. */
/*********************************************************************************************/

// Получаем текущую локализованную дату и время.
$now = getLocalizedNow();

// Создаем запрос: последнее время отметки транспортного средства за сегодня.
$sql = "SELECT MAX(time) FROM $DATABASE_NAME.$MARKS_TABLE WHERE DATE(time)=DATE('$now') AND vehicle_id='$vehicle_id';";

// Выполняем скрипт.
$query_result = mysqli_query($con,$sql);

// Взятие выборки было с ошибкой! Останавливаем скрипт.
if ($query_result == false) echo_error_and_die($con,'Unable get sql query result: {max time vehicle mark}!');

// Если в запрошенной выборке есть результат...
if(mysqli_num_rows($query_result)){

    /* Получаем самую свежую дату и время отметки. */
    $rows = mysqli_fetch_array($query_result);
    $lastMarkTimestamp = $rows[0];

    // Проверяем времянную разницу между предыдущей отметкой и текущей попыткой.
    $timeDiff = getTimeDiff ($lastMarkTimestamp, getLocalizedNow());

    ///////////////////////////////////////////
    //echo_error_and_die($con,'delay_mark: '.$delay_mark.'  Timediff: '.getTimeDiff("2017-10-02 18:58:50", "2017-10-02 18:57:44"));
    //echo_error_and_die($con,'delay_mark: '.$delay_mark.'  Timediff: '.getTimeDiff($lastMarkTimestamp, getLocalizedNow()));
    //echo_error_and_die($con,'Now: '.getLocalizedNow());
    ///////////////////////////////////////////

    // Если она меньше, чем предустановленный таймаут, то не даем разрешение на запись этой отметки в БД.
    if ($timeDiff < $delay_mark) {
        // Обновляем значение задержки, для последующей отправки его клиенту запроса.
        $delay = $delay_mark - $timeDiff;
    } else {
        // В противном случае разрешаем сделать запись отметки в БД.
        $enable = true;
    }

    // Освобождаем ресурсы, занятые предыдущем запросом.
    mysqli_free_result($query_result);

} else { // У указанного гос. номера не было отметок за сегодня. Разрешаем отметку.
    $enable = true;
}

/*********************************************************************************************/
/* По результатам предыдущего запроса мы имеем флаг $enable, значение true которого разрешает
/* сделать запись отметки в БД. Также проверяем флаг на разрешение отметок для этого ТС и флаг
   глобального запрещения отметок.
/*********************************************************************************************/
if ($enable && !$blocked && !$global_blocked){
    /* Делаем отметку в БД. */
    // Создаем запрос
    $sql = "INSERT INTO $DATABASE_NAME.$MARKS_TABLE (vehicle_id,mac, request_id)
        VALUES ('$vehicle_id',
                '$client_mac',
                '$request_id');";

    // Выполняем скрипт.
    if (!mysqli_query($con,$sql)) echo_error_and_die($con,'Unable execute sql query: {do vehicle current mark}!');

    // Отметка выполнена удачно.
    $status  = "success";

    // Следующую попытку можно повторить через максимальный промежуток времени.
    $delay = $delay_mark;

    /* Добавляем текущий госномер в перечень госномеров. Если такой номер уже есть в списке - инкрементируем его
     * "популярность" (popularity).
     */
    // Создаем запрос: записать текущее ТС с таблицу vehicles.
    $sql = "INSERT INTO $DATABASE_NAME.$VEHICLES_TABLE (vehicle) VALUES ('$vehicle_id') ON DUPLICATE KEY UPDATE popularity=popularity+1;";

    // Выполняем скрипт.
    if (!mysqli_query($con,$sql)) echo_error_and_die($con,'Unable do sql query result: {update popularity}!');

    /* Генерируем отпечаток текущего набора данных и обновляем соответствующую переменную dataset_id в таблице
     * переменных variables.
     */
    $value = getRandomStr();
    $sql = "INSERT INTO $DATABASE_NAME.$VARIABLES_TABLE (name,value) VALUES ('dataset','$value') ON DUPLICATE KEY UPDATE value='$value';";

    // Выполняем скрипт.
    if (!mysqli_query($con,$sql)) echo_error_and_die($con,'Unable do sql query result: {update dataset id}!');

} else {

    // Возможность отметки отклонена и отложена на некоторое время.
    $status  =  ($blocked || $global_blocked)?"blocked":"postpone";

    // Следующую попытку следует повторить через ранее вычесленный промежуток времени. На всякий случай проверяем на
    // отрицательное и нулевое значение.
    if ($delay <= 0) $delay = 1;
}

/*********************************************************************************************/
/* Вне зависимости от того, разрешено ли этому транспортному средству делать текущую отметку в БД,
   нам необходимо в ответе клиенту отослать список уже сделанных отметок за текущий день.
/*********************************************************************************************/
$today_marks = array();
// Создаем запрос: все отметки транспортного средства за сегодня.
$sql = "SELECT time FROM $DATABASE_NAME.$MARKS_TABLE WHERE DATE(time)=DATE('$now') AND vehicle_id='$vehicle_id';";

// Выполняем скрипт.
$query_result = mysqli_query($con,$sql);

// Взятие выборки было с ошибкой! Останавливаем скрипт.
if ($query_result == false) echo_error_and_die($con,'Unable get sql query result: {all vehicle marks}!');

// Если в запрошенной выборке есть результат...
if(mysqli_num_rows($query_result)) {
    $ii = 0;
    /* Перебираем все данные в выборке. */
    while($rows = mysqli_fetch_array($query_result)){
        // Складываем их в массив для последующей передачи клиенту запроса.
        $today_marks[$ii++] = $rows[0];
    }
    // Освобождаем ресурсы, занятые предыдущем запросом.
    mysqli_free_result($query_result);
}

// Закрываем соединение с БД.
mysqli_close($con);

/*********************************************************************************************/
/* Отсылаем клиенту статус и задержку, которую он должен выдержать перед следующей попыткой
   отметиться, а также все текущие отметки за сегодняшний день.
/*********************************************************************************************/
echo json_encode(array('status'=>$status,'delay'=>$delay, 'today_marks'=>$today_marks));


/*********************************************************************************************/
/* Вспомогательные функции для текущего скрипта.
/*********************************************************************************************/

// Функция возвращает клиенту пустой ответ в формате json.
// Используется при неверных запросах или отсутствии запрашиваемой информации.
function echo_empty_and_die(){
    // Возвращается пустой ответ.
    echo json_encode(array('status'=>'empty'));
    die();
}

function echo_error_and_die($con,$info){
    // Если указан параметр соединения с БД - отключаемся от БД.
    if ($con != null) mysqli_close($con);
    // Возвращается ошибку.
    echo json_encode(array('status'=>'error', 'details'=>$info));
    //echo json_encode(array('status'=>'error'));
    die();
}

// Определение MAC-адреса клиента запроса.
// Примечание: Использование этой функции возможно только если клиент и сервер находятся в одной подсети!
//             В любых других случаях определение MAC-адреса удаленного устройства невозможно!
function get_client_mac(){
    $ipAddress=$_SERVER['REMOTE_ADDR'];

    #run the external command, break output into lines
    $arp=`arp -a $ipAddress`;
    $lines=explode("\n", $arp);

    #look for the output line describing our IP address
    foreach($lines as $line) {
        $cols=preg_split('/\s+/', trim($line));
        if ($cols[0]==$ipAddress) return $cols[1];
    }
}