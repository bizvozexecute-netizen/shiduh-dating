<?php

$NETCAT_FOLDER = join(strstr(__FILE__, "/") ? "/" : "\\", array_slice(preg_split("/[\/\\\]+/", __FILE__), 0, -3)).( strstr(__FILE__, "/") ? "/" : "\\" );
@include_once ($NETCAT_FOLDER."vars.inc.php");
global $ADMIN_FOLDER;
@include_once ($ADMIN_FOLDER."admin.inc.php");

/**
 * Получение языков доступных в системе
 * @return array Element[English.php] = English
 */
function Language_Show() {
    global $ADMIN_FOLDER;
    if (($handle = opendir($ADMIN_FOLDER."lang"))) {
        $lang = array();
        while (false !== ($file = readdir($handle))) {
            if (strlen($file) > 4 && !strpos($file, '_')) {
                $name = explode(".", $file);
                $lang[$file] = $name[0];
            }
        }
        closedir($handle);
        return($lang);
    } else {
        return(false);
    }
}

function Unauthorize() {
    global $PHP_AUTH_SID;
    $nc_core = nc_Core::get_object();

    if ($sname = session_name()) {
        global $$sname;
    }

    switch ($nc_core->AUTHORIZATION_TYPE) {
        // cookie
        case 'cookie':
            $nc_core->db->query("DELETE FROM `Session` WHERE `Session_ID` = '" . $nc_core->db->escape($PHP_AUTH_SID) . "' OR `SessionTime` < '" . time() . "'");
            // unset back-end and front-end cookies
            $nc_core->cookie->remove("PHP_AUTH_SID");
            $nc_core->cookie->remove("PHP_AUTH_LANG");
            break;
        // http
        case 'http':
            unset($_SERVER['PHP_AUTH_USER']);
            unset($_SERVER['PHP_AUTH_PW']);
            unset($_SERVER['HTTP_AUTHORIZATION']);
            break;
        // session
        case 'session':
            if ($$sname != "") {
                $nc_core->db->query("DELETE FROM Session WHERE Session_ID = '" . $nc_core->db->escape($$sname) . "' OR SessionTime < " . time());
                $$sname = $_POST['$sname'];
                $$sname = $_GET['$sname'];
            }
            unset($_SESSION['User']);
            session_destroy();
            break;
    }

    $nc_core->page->clear_browser_partials_on_next_page_load();
}

function LoginFormHeader() {
    global $ADMIN_FOLDER, $ADMIN_TEMPLATE;
    $nc_core = nc_Core::get_object();
    $lang = $nc_core->lang->detect_lang();
    require_once($ADMIN_FOLDER."lang/".$lang.".php");

    ?><!DOCTYPE html>
<!--[if lt IE 7]><html class="nc-ie6 nc-oldie"><![endif]-->
<!--[if IE 7]><html class="nc-ie7 nc-oldie"><![endif]-->
<!--[if IE 8]><html class="nc-ie8 nc-oldie"><![endif]-->
<!--[if gt IE 8]><!--><html><!--<![endif]-->
  <head>
  <meta http-equiv='content-type' content='text/html; charset=<?= $nc_core->NC_CHARSET ?>'/>
  <link type='text/css' rel='Stylesheet' href='<?= nc_add_revision_to_url($nc_core->ADMIN_TEMPLATE . 'css/login.css') ?>'>
  <link type='text/css' rel='Stylesheet' href='<?= nc_add_revision_to_url($nc_core->ADMIN_TEMPLATE . 'css/style.css') ?>'>
  <?= nc_js(); ?>
  <title><?= SECTION_INDEX_TITLE ?></title>
  </head>
  <body class="nc-admin">
  	<div class='login_wrap'>
    <?php
    $navbar = $nc_core->ui->navbar();

    $navbar->menu->title(SECTION_INDEX_MENU_TITLE)->add_btn('#')->icon_large('logo-white')
        ->title(CMS_SYSTEM_NAME, true)
        ->click('return true');
    $navbar->menu->add_text(SECTION_INDEX_TITLE);
    echo $navbar;
    ?>
    <div class='content' align='center'>
	<?
}

function LoginFormFooter() {
?>
    </div>
    </div> <!-- / .login_wrap -->
    <?= ENDHTML_NETCAT; ?>
    </body>
    </html>
<?php
}

/**
 * Вывод формы авторизации пользователя
 */
function LoginForm() {
    global $REQUEST_URI, $AUTH_USER, $ADMIN_LANGUAGE, $ADMIN_TEMPLATE, $AUTH_PW;
    global $posting, $USER_LANG, $ADMIN_AUTHTYPE, $AUTHORIZATION_TYPE;
    global $SUB_FOLDER, $HTTP_ROOT_PATH;
    global $nc_core;

    if (isset($_REQUEST['AUTH_USER']) || isset($_REQUEST['AUTH_PW'])) {
        $textinfo = CONTROL_AUTH_LOGIN_OR_PASSWORD_INCORRECT;
    }

    $m_auth = $nc_core->modules->get_by_keyword('auth'); // есть модуль ЛК
    $login_en = 1; // доступна авторизация по логину
    $token_en = 0; // доступна авторизация по токену
    if ($m_auth) {
        $nc_auth = nc_auth::get_object();
        $login_en = $nc_core->get_settings('authtype_admin', 'auth') & NC_AUTHTYPE_LOGIN;
        $token_en = $nc_auth->token_enabled();
        $nc_auth_token = new nc_auth_token();
        $nc_token_rand = $nc_auth_token->get_random_256();
        $_SESSION['nc_token_rand'] = $nc_token_rand;
    }

    $need_captcha = $nc_core->user->captcha_is_required();
    if ($nc_core->user->captcha_is_invalid()) {
        $textinfo = $nc_core->user->captcha_is_missing() ? NETCAT_MODERATION_CAPTCHA : NETCAT_MODULE_CAPTCHA_WRONG_CODE_SMALL;
    }

    $lang = Language_Show();
    $sellang = isset($_COOKIE['PHP_AUTH_LANG']) ? $_COOKIE['PHP_AUTH_LANG'] : $ADMIN_LANGUAGE;

    // селект с языком
    $lang_select = "<div class='nc-select nc--blocked'><select name='NEW_AUTH_LANG'>";
    foreach ($lang AS $val) {
        $lang_select .= "<option value='".$val."'".($val == $sellang ? " selected" : "").">".$val."</option>\n";
    }
    $lang_select .="  </select><i class='nc-caret'></i></div>";

    // сохранить логин пароль
    $loginsave = '';
    if ($ADMIN_AUTHTYPE == 'manual' && $AUTHORIZATION_TYPE == 'cookie') {
        $loginsave = nc_admin_checkbox_simple('loginsave', '', CONTROL_AUTH_HTML_SAVELOGIN);
    }
?>
    <noscript><div style="font-weight: bold;"><?=CONTROL_AUTH_JS_REQUIRED ?></div></noscript>

<?php if ($m_auth) : ?>
    <script type='text/javascript' src='<?= nc_add_revision_to_url(nc_module_path('auth') . 'auth.js') ?>'></script>
<?php endif; ?>

<script type='text/javascript'>
    function authCheckFields () {
        var authForm = document.getElementById('AUTH_FORM');
        var login = document.getElementsByName('AUTH_USER');
        var pass = document.getElementsByName('AUTH_PW');

        switch (true) {
            case (login.value == '' && pass.value == ''):
                alert('<?=CONTROL_AUTH_FIELDS_NOT_EMPTY ?>');
                return false;
                break;
            case (login.value == ''):
                alert('<?=CONTROL_AUTH_LOGIN_NOT_EMPTY ?>');
                return false;
                break;
            default:
            	return true;
                //authForm.submit();
            }
        }
        $nc(function() {
			$nc('#AUTH_FORM').submit( function() {
				var login = $nc("input[name = 'AUTH_USER']").val();
				var pass = $nc("input[name = 'AUTH_PW']").val();
				if (!login  && !pass) {
					alert('<?=CONTROL_AUTH_FIELDS_NOT_EMPTY ?>');
					return false;
				}
				if (!login) {
					alert('<?=CONTROL_AUTH_LOGIN_NOT_EMPTY ?>');
					return false;
				}
				return true;
			});

			function place_footer() {
				var footer = $nc('.bottom_line');

				var form  = $nc('.content');
				var body_height = $nc(document.body).height();

				var form_bottom = form.offset().top + form.height();

				footer.css({top:null, bottom:null});

				if (form_bottom + footer.height() > body_height) {
					footer.css({top:form_bottom+'px'});
				} else {
					footer.css({bottom:'0px'});
				}
			}

			$nc(window).resize(place_footer);

			place_footer();

            $nc('INPUT[name=AUTH_USER]').focus();
        });
</script>
<form action='<?=$REQUEST_URI ?>' method='post' name='AUTH_FORM' id='AUTH_FORM'>
    <input type='hidden' name='AuthPhase' value='1'>

    <table border='0' cellpadding='4' cellspacing='0' id="classical" style="display:none; margin:0 auto">
        <tr>
            <td></td>
            <td class="nc-text-red"><?= (isset($textinfo) ? $textinfo : '') ?></td>
        </tr>
        <tr>
            <td><label><?=CONTROL_AUTH_HTML_LOGIN ?></label></td>
            <td><?=nc_admin_input_simple('AUTH_USER', stripcslashes($AUTH_USER), 32, "", "id='AUTH_USER' class='nc--blocked' maxlength='255'") ?></td>
        </tr>
        <tr>
            <td><label><?=CONTROL_AUTH_HTML_PASSWORD ?></label></td>
            <td><?=nc_admin_input_password('AUTH_PW', stripcslashes($AUTH_PW), 32, "", "class='nc--blocked' maxlength='255'") ?></td>
        </tr>
        <tr>
            <td><label><?=CONTROL_AUTH_HTML_LANG ?></label></td>
            <td><?=$lang_select ?></td>
        </tr>
<?php if ($need_captcha) : ?>
            <tr>
                <td></td>
                <td class="captcha"><?=nc_captcha_formfield() ?></td>
            </tr>
            <? if (nc_captcha::get_instance()->get_provider()->requires_separate_input_field()): ?>
            <tr>
                <td><?= NETCAT_MODERATION_CAPTCHA_SMALL ?></td>
                <td><?= nc_admin_input_simple('nc_captcha_code', '', 32, "maxlength='255'") ?></td>
            </tr>
    <? endif; ?>
<?php endif; ?>
                    <tr>
                        <td rowspan='2'><?= (isset($icon) ? $icon : '') ?></td>
                        <td><?=$loginsave ?></td>
                    </tr>
                    <tr>
                        <td>
                            <button type='submit' class="nc-btn nc--blue"><?=CONTROL_AUTH_HTML_AUTH ?></button>
                            <span id='menu'></span>
<?php
                    if ($posting && $REQUEST_URI != $REQUESTED_FROM) {
                        echo "<br/><a href='".$REQUESTED_FROM."' class='relogin'>".CONTROL_AUTH_HTML_BACK."</a>";
                    }
?>
                </td>
            </tr>
        </table>

        <!-- форма авторизация по токену -->
<? /*$token_en=1;*/ ?>
<? if ($token_en) { ?>
            <table border='0' cellpadding='4' cellspacing='0' id="token" style="display:none; margin:0 auto">
                <tr>
                    <td colspan="2">
                        <div id='tokeninfo' class="nc-alert nc--red"></div>
                    </td>
                </tr>
                <tr><td colspan="2">
                            <div id='nc_token_plugin_wrapper'></div>
                            <script>
                                $nc("#nc_token_plugin_wrapper").append("<object id='nc_token_plugin' type='application/x-rutoken' width='0' height='0'></object>");
                            </script>
                        <input type='hidden' value='' id='nc_token_signature'  name='nc_token_signature'/>
                    </td></tr>
                <tr>
                    <td><label><?=CONTROL_AUTH_HTML_LOGIN ?></label></td>
                                <td><div class='nc-select nc--blocked'><select  name='nc_token_login' id='nc_token_login'></select><i class='nc-caret'></i></div></td>
                            </tr>
                            <tr>
                                <td><label><?=CONTROL_AUTH_HTML_LANG ?></label></td>
                                <td><?=$lang_select ?></td>
                            </tr>
                            <tr>
                                <td>
                                </td>
                                <td colspan="2">
                                    <button onclick='nc_token_sign(); return false;' type='submit' class="nc-btn nc--blue"><?=CONTROL_AUTH_HTML_AUTH ?></button>
                                </td>
                            </tr>
                        </table>
<? } ?>
                    </form>

                    <script type='text/javascript'>
                            var authForm = document.getElementById('AUTH_FORM');
                            // перенаправлять туда, куда пользователь хотел зайти
                            authForm.action += window.location.hash;

                            function show_token () {
<?php if ($login_en && $token_en): ?>
                                  $nc("#menu").html("<a href='#' class='nc-btn nc--small' onclick='show_classical(); return false;'><?=NETCAT_AUTH_TYPE_LOGINPASSWORD ?></a>");
<?php endif; ?>

                                  $nc("#classical").hide();
                                  $nc("#token").show();
                                  $nc("#classical :input").attr('disabled', true);
                                  $nc("#token :input").removeAttr('disabled');
                                  $nc('#tokeninfo').hide();
                                  if ( !nc_token_obj.load() ) {
                                      $nc('#tokeninfo').html("<?=CONTROL_AUTH_USB_TOKEN_NOT_INSERTED ?>");
                                      $nc('#tokeninfo').show();
                                  }
                              }

                              function show_classical () {
<?php if ($login_en && $token_en): ?>
                                      $nc("#menu").html("<button type='button' class='nc-btn nc--right nc--small' onclick='show_token()' ><?=NETCAT_AUTH_TYPE_TOKEN ?></button>");
<?php endif; ?>

                                      $nc("#classical").show();
                                      $nc("#token").hide();
                                      $nc("#classical :input").removeAttr('disabled');
                                      $nc("#token :input").attr('disabled', true);
                                  }

<?php if ($m_auth)
                                        echo "nc_token_obj = new nc_auth_token ( {'randnum' : '".$nc_token_rand."'}); "; ?>

                                function nc_token_sign ( ) {
                                    $nc('#tokeninfot').hide();
                                    err_text = { 1: "<?=CONTROL_AUTH_TOKEN_PLUGIN_DONT_INSTALL ?>", 2: "<?=CONTROL_AUTH_USB_TOKEN_NOT_INSERTED ?>",
                                        3: "<?=CONTROL_AUTH_PIN_INCORRECT ?>", 4: "<?=CONTROL_AUTH_KEYPAIR_INCORRECT ?>"};

                                    if ( (err_num = nc_token_obj.sign()) ) {
                                        $nc('#tokeninfo').html(err_text[err_num]);
                                        $nc('#tokeninfo').show();
                                    }
                                }
<?= $login_en ? "show_classical();" : "show_token();"; ?>

                            </script>
<?php
                            }

function Refuse() {
    global $nc_core, $AUTH_TYPE, $admin_mode, $nc_auth;
    // AJAX call
    if (defined('NC_ADMIN_DISABLE_LOGIN_PAGE') || $nc_core->input->fetch_post('NC_HTTP_REQUEST')) {
        // issue strange header (actually not RFC2616-compliant) and die
        nc_set_http_response_code(401);
        exit;
    }

    switch ($nc_core->AUTHORIZATION_TYPE) {
        case 'cookie':
        case 'session':
            if (!$admin_mode) {
                if (is_object($nc_auth)) {
                    $nc_auth->login_form();
                }
            } else {
                LoginFormHeader();
                LoginForm();
                LoginFormFooter();
            }
            break;

        default :
            # по дефолту авторизация 'http'
            Header("WWW-authenticate:  basic  realm=Enter your login and password");
            nc_set_http_response_code(401);
            LoginFormHeader();
            print CONTROL_AUTH_MSG_MUSTAUTH;
            LoginFormFooter();
    }

    exit;
}

/**
 * Функция для авторизации
 *
 * @param int $required_id = 0, если не равен 0, то выполнится авторизация пользователя с id  = required_id
 * @param string $auth_phase фаза авторизации: attempt - попытка продлить авторизацию, authorize - авторизация
 * @param int $auth_variant вариант авторизации: NC_AUTHTYPE_LOGIN, NC_AUTHTYPE_HASH, ...
 * @param bool $isInsideAdmin авторизация в админку
 * @param bool $create_session создавать сессию
 *
 * @return int  идентификатор авторизированного пользователя
 */
function Authorize($required_id = 0, $auth_phase = 'attempt', $auth_variant = NC_AUTHTYPE_LOGIN, $isInsideAdmin = false, $create_session = true) {
    global $nc_core, $perm, $AUTH_USER_ID, $AuthPhase;

    if ($perm instanceof Permission) {
        return $AUTH_USER_ID;
    }

    // для совместимости со старыми версиями
    if ($nc_core->modules->get_by_keyword('auth') && !class_exists('nc_auth')) {
        $nc_core->modules->load_env();
    }

    if ($required_id) {
        return $nc_core->user->authorize_by_id($required_id, $auth_variant, $isInsideAdmin, $create_session);
    }

    if ($AuthPhase || $auth_phase == 'authorize') {
        global $AUTH_USER, $AUTH_PW, $nc_captcha_code;
        return $nc_core->user->authorize_by_pass($AUTH_USER, $AUTH_PW, $nc_captcha_code);
    }

    return $nc_core->user->attempt_to_authorize();
}

/**
 * @param $SubClassID
 * @param $action
 * @param $posting
 * @param null|int $object_user_id проверка ID пользователя для действий с собственными записями
 * @return bool|int
 * @throws Exception
 */
function CheckUserRights($SubClassID, $action, $posting, $object_user_id = null) {
    global $perm;
    # значения action
    #   1 - read
    #   2 - add
    #   4 - change
    #   8 - subscribe
    #  16 - moderate

    if (!$perm) {
        Authorize();
    }
    if (!$perm instanceof Permission) {
        return 0;
    }

    if ($perm->isSupervisor()) {
        return 1;
    }


    switch ($action) {
        case "read":
            $mask = MASK_READ | MASK_MODERATE; //moderator can read all
            break;
        case "comment":
            $mask = MASK_COMMENT;
            break;
        case "add":
            $mask = MASK_ADD;
            break;
        case "change":
            $mask = MASK_MODERATE; // в любом случае модератор может все
            if ($object_user_id === null || $object_user_id == $GLOBALS['AUTH_USER_ID']) {
                // нужно точно узнать, какое изменение происходит
                if (!empty($GLOBALS['delete'])) {
                    $mask |= MASK_DELETE;
                } else if (!empty($GLOBALS['checked'])) {
                    $mask |= MASK_CHECKED;
                } else {
                    $mask |= MASK_EDIT;
                }
            }
            break;
        case "subscribe":
            $mask = MASK_SUBSCRIBE;
            break;
        case "moderate":
            $mask = MASK_MODERATE;
            break;
        case "admin":
            $mask = MASK_ADMIN;
            break;
        default:
            $mask = MASK_READ | MASK_MODERATE;
            break;
    }

    if ($perm->isGuest()) { // право гость
        //дает просматривать без записи в БД
        return ($posting == 0 || $mask == 1);
    }


    // собственно, проверка прав
    // случай, когда в разделе есть компонент
    if ($SubClassID) {
        return $perm->isSubClass($SubClassID, $mask, true);
    }

    // Возможен случай, когда в разделе нет компонента, в этом случае надо проверить
    // на доступ к разделу.
    global $current_sub;
    return $perm->isSubdivision($current_sub['Subdivision_ID'], $mask);
}
