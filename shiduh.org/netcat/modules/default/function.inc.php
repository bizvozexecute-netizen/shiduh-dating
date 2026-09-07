<?php

/*
Пример перехвата события "авторизация пользователя"
class ListenUser {
	public function __construct () {
		$nc_core = nc_Core::get_object();
		$nc_core->event->bind($this, array(nc_Event::AFTER_USER_AUTHORIZED => 'authorize_user') );
	}

	public function  authorize_user ( $user_id ) {
		return 0;
	}
}

$listenObj = new  ListenUser();
*/

/*
function my_func () {
}
*/
function translitToEng($s) {
  $s = (string) $s; // преобразуем в строковое значение
  $s = trim($s); // убираем пробелы в начале и конце строки
  $s = function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s); // переводим строку в нижний регистр (иногда надо задать локаль)
  $s = strtr($s, array('а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'j','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'shch','ы'=>'y','э'=>'e','ю'=>'yu','я'=>'ya','ъ'=>'','ь'=>'', ' '=> '_'));
  return $s; // возвращаем результат
}

// год, года, лет 
function num2word($num, $words)
{
    $num = $num % 100;
    if ($num > 19) {
        $num = $num % 10;
    }
    switch ($num) {
        case 1: {
            return($words[0]);
        }
        case 2: case 3: case 4: {
            return($words[1]);
        }
        default: {
            return($words[2]);
        }
    }
}

//получить возраст лет
function get_age( $birthday ){

	$diff = date( 'Ymd' ) - date( 'Ymd', strtotime($birthday) );

	return substr( $diff, 0, -4 );
}

//функция отправки письма на почту
function sendEmail( $userID, $theme, $message ){

    // Получаем объект nc_core
    $nc_core = nc_Core::get_object();
    $system_env = $nc_core->get_settings();
    $email_field = $nc_core->get_settings('UserEmailField');
    $user_info = $nc_core->db->get_row("SELECT `".$email_field."` AS `email` FROM `User` WHERE `User_ID` = '".$userID."' ", ARRAY_A);

    $mailbody = '<table style="width: 100%; height: 100%; border-collapse: collapse; background-color: #F5F2EC; color: #3A2B2B; font-weight: normal; font-family: Tahoma, Geneva, Verdana, sans-serif;">
    <tbody>
      <tr>
        <td style="padding: 0;" align="center" valign="top">
          <table style="width: 100%; max-width: 600px; border-collapse: collapse;">
            <tbody>
              <tr>
                <td style="text-align: center; padding: 32px 0 27px;">
                  <img style="width: 200px; height: 52px;" src="https://shiduh.org/letter/resources/logo.png" alt="">
                </td>
              </tr>
              <tr>
                <td style="padding: 39px 32px 28px; background-color: #fff; border-radius: 16px;">
                  <table style="width: 100%; border-collapse: collapse; ">
                    <tbody>
                      <tr>
                        <td style="padding: 0 0 31px; border-bottom: 1px solid #E1DFDF; font-size: 14px; line-height: 18px;">';
    $mailbody .= $message;

    $mailbody .= '</td>
                      </tr>
                      <tr>
                        <td style="padding: 30px 0 0;">
                          <table style="width: 100%; border-collapse: collapse;">
                            <tbody>
                              <tr>
                                <td style="width: 61px; padding: 0;">
                                  <img style="width: 61px; height: 52px;" src="https://shiduh.org/letter/resources/short-logo.png" alt="">
                                </td>
                                <td style="padding: 0 0 3px 20px; color: #3E3E59; font-weight: bold; font-size: 14px;">
                                  C наилучшими пожеланиями,<br>
                                  команда ONE SOUL
                                </td>
                              </tr>
                            </tbody>
                          </table>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </td>
              </tr>
              <tr>
                <td style="padding: 28px 0; border-bottom: 1px solid #c7c7c7;">
                  <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                      <td style="width: 160px; padding: 0; color: #3A2B2B; font-size: 10px; font-weight: bold; line-height: 12px;">
                        Еврейские знакомства через<br>
                        шадханов с разных концов<br>
                        земного шара
                      </td>
                      <td style="padding: 0; text-align: right;">
                        <a href="https://instagram.com/one.soul.jew" title="" style="display: inline-block; line-height: 41px; width: 40px; height: 40px; background-color: #262839;  border-radius: 50%; margin-right: 10px; text-align: center;"><img src="https://shiduh.org/letter/resources/inst.png" alt="" style="display: inline-block; width: 16px; height: 16px;"></a>
                        <a href="https://youtube.com/@OneSoulJew" title="" style="display: inline-block; line-height: 41px; width: 40px; height: 40px; background-color: #262839;  border-radius: 50%;  margin-right: 10px; text-align: center;"><img src="https://shiduh.org/letter/resources/youtube.png" alt="" style="display: inline-block; width: 17px; height: 12px;"></a>
                        <a href="https://t.me/shiduh_org" title="" style="display: inline-block; line-height: 41px; width: 40px; height: 40px; background-color: #262839;  border-radius: 50%;  text-align: center;"><img src="https://shiduh.org/letter/resources/tme.png" alt="" style="display: inline-block; width: 16px; height: 16px;"></a>  
                      </td>
                      <td style="width: 160px; padding: 0 0 0 18px; text-align: right;">
                        <a href="https://shiduh.org/" title="Перейти на сайт" style="display: inline-block; width: 100%; height: 40px; background-color: #C8AE88; text-decoration: none; color: white; font-weight: normal; font-size: 14px; line-height: 40px; text-align: center; border-radius: 12px;">Перейти на сайт</a>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
              <tr>
                <td style="padding: 10px 0 7px; font-size: 10px; line-height: 12px;">
                  <p><b>Уведомление об ограничении ответственности</b></p>
                  <p>
                    Настоящее сообщение (и любые вложенные файлы), направленное по электронной почте, может содержать конфиденциальную информацию, доступ к которой ограничен действующим законодательством. Несанкционированное раскрытие или использование содержащейся в нем информации представляет собой правонарушение и влечет за собой установленную законом ответственность. Если Вы не являетесь тем получателем, которому это сообщение было предназначено, просим Вас незамедлительно информировать об этом отправителя и удалить полученное сообщение и любые вложенные файлы с Вашей системы. Мы не несем ответственности за сообщения, которые были модифицированы, изменены или фальсифицированы.
                  </p>
                </td>
              </tr>
              <!-- <tr>
                <td style="padding: 0 0 10px; font-size: 10px; line-height: 12px;">
                  <a href="" title="" style="color: #D2A679; text-decoration: none;">Отписаться от рассылки</a>
                </td>
              </tr> -->
            </tbody>
          </table>
        </td>
      </tr>
    </tbody>
  </table>';                          

    // $mailer = new CMIMEMail();
    // $mailer->mailbody(strip_tags($mailbody), $mailbody);
    // $mailer->send($user_info['email'], $system_env['SpamFromEmail'], $system_env['SpamFromEmail'], $theme, $system_env['SpamFromName']);
    
    $nc_core = nc_Core::get_object();
    $nc_core->mail->mailbody( strip_tags($mailbody), $mailbody);

    $nc_core->mail->send($user_info['email'], $system_env['SpamFromEmail'], $system_env['SpamFromEmail'], $theme, $system_env['SpamFromName'], $theme);
}


// подтверждение пользователя как кандидата и добавление в компонент кандидатов 
class ListenUser {
	public function __construct () {
		$nc_core = nc_Core::get_object();
		$nc_core->event->bind($this, array('addUser' => 'add_candidat') );
	}

	public function  add_candidat ( $user_id ) {
		$nc_core = nc_Core::get_object();
        $system_env = $nc_core->get_settings();

        //получаем информацию о включенном пользователе
        $user = $nc_core->user->get_by_id($user_id);
        // A2: значения уходят в SQL — экранируем; имя вида «x', 1, ...» иначе
        // привязывало бы анкету к чужому User_ID
        $userName = $nc_core->db->escape($user['Name']);
        $userEmail = $nc_core->db->escape($user['Email']);
        $userGender = (int)$user['Gender_id'];
        $userDate = sprintf('%04d-%02d-%02d', (int)$user['Date_of_birth_year'], (int)$user['Date_of_birth_month'], (int)$user['Date_of_birth_day']);
        $userPhone = $nc_core->db->escape($user['Phone']);
        $userType = $user['Type_id'];
        $userCreated = $user['Created'];
        $userKeyword = urlencode(translitToEng($userName));
        // $userUrl = urlencode(translit($user['Name']));

        //если это кандидат
        if($userType === 1) {
    	    //проверяем на наличие такого же кандидата с таким же email
    	    $nc_core->db->query("SELECT Message_ID FROM Message204 WHERE Email = '$userEmail'");
    			$arr = $nc_core->db->last_result;

    			if (empty($arr)) {
    		  	$nc_core->db->query("INSERT INTO `Message204` 
    		  		(`Name`, `Gender`, `Date_of_birth`, `Phone`, `Email`, `Subdivision_ID`, `Sub_Class_ID`, `User_ID`, `Created`) 
    		  		VALUES 
    		  		('$userName', '$userGender', '$userDate', '$userPhone', '$userEmail', '23', '22', '$user_id', '$userCreated' ) ");  
    			}
    	}
        //если это шадхан
        if($userType === 2) {
            //отправка сообщения кандидатам о добавлении шадхана
            $candidateDate = date("Y-m-d H:i:s");
            $candidateUserID = 1;
            
            $candidateTheme = 'Появление нового шадхана на сайте One Soul';    
            $candidateMessage = 'Уважаемый кандидат! <br>
        На сайте One Soul появился новый <a href="/my/shadhany/shadhan_'.$user_id.'.html">шадхан</a>!  <br>
Если это именно то, что нужно Вам, то выбирайте его своим шадханом!  <br>
С уважением, <br>
Команда One Soul';

            $nc_core->db->query("SELECT * FROM User WHERE Type = 1 AND User_ID != 1 AND User_ID != 119", ARRAY_A );
            $arr = $nc_core->db->last_result;
            
            foreach ($arr as $row) {
                $shadhanID = $row['User_ID'];
                sendEmail($shadhanID, $candidateTheme, $candidateMessage);
                $nc_core->db->query("INSERT INTO `Message6` 
                                (`Checked`, `ToUser`, `Subject`, `Message`, `User_ID`, `Subdivision_ID`, `Sub_Class_ID`, `Created`, `Status`) 
                                VALUES 
                                ('1', '$shadhanID', '$candidateTheme', '$candidateMessage', '$candidateUserID', '28', '29', '$candidateDate', '0') "); 
            }   

            $candidateMessage = 'Уважаемый шадхан! <br>
        На сайте One Soul появился новый <a href="/my/shadhany/shadhan_'.$user_id.'.html">шадхан</a>!  <br>
Теперь Вы можете работать сообща и создавать совместные новые идеи шидуха <br>
Команда One Soul желает Сията деШмая и плодотворных шидухов!  <br>
С уважением, <br>
Команда One Soul';

            $nc_core->db->query("SELECT * FROM User WHERE Type = 2 AND User_ID != 1 AND User_ID != 119", ARRAY_A );
            $arr = $nc_core->db->last_result;
            
            foreach ($arr as $row) {
                $shadhanID = $row['User_ID'];
                sendEmail($shadhanID, $candidateTheme, $candidateMessage);
                $nc_core->db->query("INSERT INTO `Message6` 
                                (`Checked`, `ToUser`, `Subject`, `Message`, `User_ID`, `Subdivision_ID`, `Sub_Class_ID`, `Created`, `Status`) 
                                VALUES 
                                ('1', '$shadhanID', '$candidateTheme', '$candidateMessage', '$candidateUserID', '28', '29', '$candidateDate', '0') "); 
            }   

        }
	} 



}

$listenObj = new ListenUser();

?>