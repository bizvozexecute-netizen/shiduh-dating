<?
$NETCAT_FOLDER = join(strstr(__FILE__, "/") ? "/" : "\\", array_slice(preg_split("/[\/\\\]+/", __FILE__), 0, -2)).( strstr(__FILE__, "/") ? "/" : "\\" );
include_once $NETCAT_FOLDER . 'vars.inc.php';
require_once $ROOT_FOLDER . 'connect_io.php';
$nc_core = nc_core::get_object();
$nc_core->modules->load_env();

//проверяем др и отправляем сообщение шадханам
$nc_core->db->query("SELECT * FROM Message204");
$arr = $nc_core->db->last_result;
$candidateDate = date("Y-m-d H:i:s");
foreach ($arr as &$value) {
   $pieces = explode(" ", $value->Date_of_birth);
   $pieces2 = explode("-", $pieces[0]);
   if(date("m") === $pieces2[1] && date("d") === $pieces2[2]) {
        $candidateName = $value->Name;
        $candidateUserID = $value->User_ID;
        $candidateMessageID = $value->Message_ID;

        $candidateTheme = 'С днём рождения';
        $candidateMessage = 'Мазаль Тов, '.$candidateName .'! ❤️ </br>

Команда сервиса One Soul поздравляет Вас и желает всех благословений с Небес! </br>

Пусть Творец радует Вас изо дня в день и делает каждый момент жизни приятнее! </br>

Желаем создать счастливый еврейский дом, в котором царит шлом байт, крепкого здоровья и парнасы. Пусть Б-г оберегает вас от всего дурного и ведет самым лучшим путем!</br>

С уважением,</br>
Команда One Soul';
        
        $nc_core->db->query("INSERT INTO `Message6` 
                        (`Checked`, `ToUser`, `Subject`, `Message`, `User_ID`, `Subdivision_ID`, `Sub_Class_ID`, `Created`) 
                        VALUES 
                        ('1', '$candidateUserID', '$candidateTheme', '$candidateMessage', '1', '28', '29', '$candidateDate') "); 
                        
        if ($value->Shadhan_1) {
           $shadhanID = $value->Shadhan_1;
           $nc_core->db->query("INSERT INTO `Message6` 
                        (`Checked`, `ToUser`, `Subject`, `Message`, `User_ID`, `Subdivision_ID`, `Sub_Class_ID`, `Created`) 
                        VALUES 
                        ('1', '$shadhanID', '$candidateTheme', '$candidateMessage', '$candidateUserID', '28', '29', '$candidateDate') "); 
        }
        if ($value->Shadhan_2) {
           $shadhanID = $value->Shadhan_2;
           $nc_core->db->query("INSERT INTO `Message6` 
                        (`Checked`, `ToUser`, `Subject`, `Message`, `User_ID`, `Subdivision_ID`, `Sub_Class_ID`, `Created`) 
                        VALUES 
                        ('1', '$shadhanID', '$candidateTheme', '$candidateMessage', '$candidateUserID', '28', '29', '$candidateDate') "); 
        }
        if ($value->Shadhan_3) {
           $shadhanID = $value->Shadhan_3;
           $nc_core->db->query("INSERT INTO `Message6` 
                        (`Checked`, `ToUser`, `Subject`, `Message`, `User_ID`, `Subdivision_ID`, `Sub_Class_ID`, `Created`) 
                        VALUES 
                        ('1', '$shadhanID', '$candidateTheme', '$candidateMessage', '$candidateUserID', '28', '29', '$candidateDate') "); 
        }
   }
} 