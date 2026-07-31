nc_auth = function ( settings ) {
    if ( !settings ) settings = {};
    // проверять логин
    this.check_login = settings.check_login;// || true;
    // минимальная длина пароля
    this.pass_min = settings.pass_min || 0;
    // id input'a для логина
    this.login_id = '#' + (settings.login_id || 'f_Login');
    // id input'a для пароля
    this.pass1_id = '#' + (settings.pass1_id || 'Password1');
    // id input'a для подтверждения пароля
    this.pass2_id = '#' + (settings.pass2_id || 'Password2');
    // id элемента "ждите"
    this.wait_id = '#' + (settings.wait_id || 'nc_auth_wait');
    // id элемента "логин свободен"
    this.login_ok_id = '#' + (settings.login_ok_id || 'nc_auth_login_ok');
    // id элемента "логин занят"
    this.login_fail_id = '#' + (settings.login_fail_id || 'nc_auth_login_fail');
    // id элемента "логин содержит запрещенные символы"
    this.login_incorrect_id = '#' + (settings.login_incorrect_id || 'nc_auth_login_incorrect');
    // id элемента "надежность пароля"
    this.pass1_security = '#' + (settings.pass1_security || 'nc_auth_pass1_security');
    // id элемента "пароль не может быть пустым"
    this.pass1_empty = '#' + (settings.pass1_empty || 'nc_auth_pass1_empty');
    // id элемента "пароли совпадают"
    this.pass2_ok_id = '#' + (settings.pass2_ok_id || 'nc_auth_pass2_ok');
    // id элемента "пароли не совпадают"
    this.pass2_fail_id = '#' + (settings.pass2_fail_id || 'nc_auth_pass2_fail');

    if ( this.check_login && this.check_login != "0" ) {
        jQuery(this.login_id).change ( function() {
            nc_auth_obj.check_loginf()
            } );
        jQuery(this.login_id).keypress( function() {
            jQuery('.nc_auth_login_check').hide()
            } );
        this.check_loginf();
    }
  
    if ( settings.check_pass && settings.check_pass != "0")
        jQuery(this.pass1_id).bind ( 'keyup change', function() {
            nc_auth_obj.check_pass()
            } );
    if ( settings.check_pass2 && settings.check_pass2 != "0")
        jQuery(this.pass2_id).bind ( 'keyup change', function() {
            nc_auth_obj.check_pass2()
            } );

    this.cache_pass = '';

  
}

nc_auth.prototype = {

    check_loginf : function () {
        if ( !jQuery(this.login_id).val().length ) {
            jQuery('.nc_auth_login_check').hide();
            jQuery('.nc_auth_pass1_check').hide();
            jQuery('.nc_auth_pass2_check').hide();
            return false;
        }

        jQuery.post(NETCAT_PATH + 'modules/auth/ajax.php',
            'act=check_login&login='+jQuery(this.login_id).val(),
            function(res) {
                nc_auth_obj.check_login_res(res);
            },
            "json"  );
        this.process = true;
        jQuery('.nc_auth_login_check').hide();
        jQuery('.nc_auth_pass1_check').hide();
        jQuery('.nc_auth_pass2_check').hide();
        jQuery(this.wait_id).show();
        return false;
    },


    check_login_res : function ( res ) {
        jQuery('.nc_auth_login_check').hide();
        jQuery('.nc_auth_pass1_check').hide();
        jQuery('.nc_auth_pass2_check').hide();
        
        if ( res == 2 ) {
            jQuery(this.login_fail_id).show();
        }
        else if ( res == 1 ) {
            jQuery(this.login_incorrect_id).show();
        }
        else {
            jQuery(this.login_ok_id).show();
        }
    },


    check_pass : function () {
        var p = jQuery(this.pass1_id).val();
        // кэширование во избежание одинаковых проверок
        if ( this.cache_pass == p ) return false;
        this.cache_pass = p;

        jQuery('.nc_auth_pass1_check').hide();
    
        var l = p.length;

        if ( !l ) {
            jQuery(this.pass1_empty).show();
            jQuery(this.pass1_security).hide();
            return false;
        }
        else {
            jQuery(this.pass1_empty).hide();
        }

        if ( l < this.pass_min ) {
            jQuery("#nc_auth_pass_min").show();
            jQuery(this.pass1_security).hide();
            return false;
        }
        jQuery("#nc_auth_pass_min").hide();

        // количетво множеств, из которых составлен пароль ( a-z, A-Z, 0-9, остальные)
        var s = 0;
        var expr1 = new RegExp('[a-z]');
        var expr2 = new RegExp('[A-Z]');
        var expr3 = new RegExp('[0-9]');
        var expr4 = new RegExp('[^a-zA-Z0-9]');
        if ( expr1.test(p) ) s++;
        if ( expr2.test(p) ) s++;
        if ( expr3.test(p) ) s++;
        if ( expr4.test(p) ) s++;

    
        jQuery(this.pass1_security).show();

        if ( s == 4 && l >= 12 ) {
            jQuery('#nc_auth_pass1_s4').show();
        }
        else if ( s >= 3 && l >= 8 ) {
            jQuery('#nc_auth_pass1_s3').show();
        }
        else if ( s >= 2 && l >= 6 ) {
            jQuery('#nc_auth_pass1_s2').show();
        }
        else {
            jQuery('#nc_auth_pass1_s1').show();
        }

        if ( jQuery(this.pass2_id).val() ) this.check_pass2();
        return false;
    },


    check_pass2 : function () {
        jQuery('.nc_auth_pass2_check').hide();
        if ( jQuery(this.pass1_id).val() == jQuery(this.pass2_id).val() ) {
            jQuery(this.pass2_ok_id).show();
        }
        else {
            jQuery(this.pass2_fail_id).show();
        }
    }
  
}


nc_auth_token = function ( settings ) {
    // случайное числов
    this.randnum = settings.randnum || 0;
    // id формы
    this.form_id = settings.form_id || 'nc_auth_form';
    // id селекта с логинами
    this.select_id = settings.select_id || 'nc_token_login';
    // id input'a для ввода нового логина
    this.login_id = settings.login_id || 'nc_token_login';
    // id скрытого поля с цифровой подписью/публичный ключом
    this.token_id = settings.token_id || 'nc_token_signature';
    // id объекта-плагина
    this.plugin_id = settings.plugin_id || 'nc_token_plugin';
    this.plugin = document.getElementById(this.plugin_id);
}


nc_auth_token.prototype = {

    load : function () {
        if ( !this.plugin.rtwIsTokenPresentAndOK()  ) return false;
        i=0;
        this.plugin.rtwGetNumberOfContainers();
        while ( (cont_name = this.plugin.rtwGetContainerName(i++)) ) {
            this.add_option(cont_name, cont_name, 0, 0);
        }
    
        return true;
    },

    add_option : function (text, value, isDefaultSelected, isSelected) {
        oListbox = document.getElementById(this.select_id);
        var oOption = document.createElement("option");
        oOption.appendChild(document.createTextNode(text));
        oOption.setAttribute("value", value);
        if (isDefaultSelected) oOption.defaultSelected = true;
        else if (isSelected) oOption.selected = true;
        oListbox.appendChild(oOption);
    },

  
    sign : function () {
        // Проверки:
        // плагин не установлен
        if ( !this.plugin.valid ) return 1;
        // токен отсутсвует
        if ( !this.plugin.rtwIsTokenPresentAndOK() ) return 2;
        // диалоговое окно ввода пин-кода
        if ( !this.plugin.rtwIsUserLoggedIn()) this.plugin.rtwUserLoginDlg();
        // ошибочный пин-код
        if ( !this.plugin.rtwIsUserLoggedIn()) return 3;
    
        tsign = document.getElementById(this.token_id);
        ltlog = document.getElementById(this.select_id);
        //  заполнение эцп
        tsign.value = this.plugin.rtwSign(ltlog.value, this.randnum);
        this.plugin.rtwLogout();
		
        if (tsign.value){
            document.getElementById('nc_token_form').submit();
        }
        else {
            return 4;
        }

        return 0;

    },

    reg : function () {
        // Проверки:
        // плагин не установлен
        if ( !this.plugin.valid ) return 1;
        // токен отсутсвует
        if ( !this.plugin.rtwIsTokenPresentAndOK() ) return 2;
        // диалоговое окно ввода пин-кода
        if ( !this.plugin.rtwIsUserLoggedIn()) this.plugin.rtwUserLoginDlg();
        // ошибочный пин-код
        if ( !this.plugin.rtwIsUserLoggedIn()) return 3;
        // логин отсутствует
        if ( !jQuery('#' + this.login_id).val() ) return 4;

        // регистрация
        var key = this.plugin.rtwGenKeyPair(jQuery('#' + this.login_id).val());
        this.plugin.rtwLogout();

        // ошибка создания ключа
        if ( !key ) return 5;

        jQuery('#' + this.token_id).val(key);

        return 0;
    },

    attempt_delete : function  ( name ) {
        if ( !this.plugin.valid || !this.plugin.rtwIsTokenPresentAndOK() ) return false;
        //запрос пин-кода
        if ( !this.plugin.rtwIsUserLoggedIn()) this.plugin.rtwUserLoginDlg();
        if ( !this.plugin.rtwIsUserLoggedIn()) return false;
        // удаление
        var r = this.plugin.rtwDestroyContainer(name);
        this.plugin.rtwLogout();

        return r;
    }
}


nc_auth_ajax = function ( settings ) {
    if ( !settings ) settings = {};
    this.auth_link = '#' + (settings.auth_link || 'nc_auth_link');
    this.params = settings.params || '';
    this.params_hash = settings.params_hash;
    this.postlink = settings.postlink || NETCAT_PATH + 'modules/auth/ajax.php';
    this.template = settings.template || '';
    this.template_hash = settings.template_hash;
    jQuery(this.auth_link).click( function(){
        nc_auth_ajax_obj.show_layer();
    } );
    jQuery('#nc_auth_form_ajax').submit( function(){
        nc_auth_ajax_obj.sign();
        return false;
    } );
}

nc_auth_ajax.prototype = {
    show_layer : function () {
        jQuery('#nc_auth_layer').modal();
    },

    sign : function () {
        // collect form values into array
        oForm = document.getElementById('nc_auth_form_ajax');
        var values = 'act=auth&params=' + nc_auth_ajax_obj.params + '&template=' + nc_auth_ajax_obj.template +
            '&params_hash=' + nc_auth_ajax_obj.params_hash +
            '&template_hash=' + nc_auth_ajax_obj.template_hash;
        for (var i=0; i < oForm.length; i++) {
            var el = oForm.elements[i];
            if (el.tagName=="SELECT") {
                values +=  '&' + el.name + '=' + el.options[el.options.selectedIndex].value;
            }
            else if (el.tagName=="INPUT" && (el.type=="checkbox" || el.type=="radio")) {
                if (el.checked) values +=  '&' + el.name + '=' + el.value;
            }
            else if (el.name && el.value != undefined) {
                values +=  '&' + el.name + '=' + el.value;
            }
        }

        jQuery.post(this.postlink, values, function(res) {
            nc_auth_ajax_obj.sign_res(res);
        }, 'json');
    },


    sign_res : function ( res ) {
        if ( res.captcha_wrong ) {
            jQuery('#nc_auth_captcha_error').show();
            var s = jQuery("#nc_auth_form_ajax img[name='nc_captcha_img']").attr('src');
            s = s.replace(/code=[a-z0-9]+/, "code="+res.captcha_hash);
            jQuery("#nc_auth_form_ajax img[name='nc_captcha_img']").attr('src', s);
            return false;
        }
    
        if ( !res.user_id ) {
            jQuery('#nc_auth_error').show();
            return false;
        }

        jQuery.modal.close();
        jQuery('.auth_block').replaceWith(res.auth_block);

        return false;
    }
}

function nc_auth_openid_select ( url ) {
    oTxt = document.getElementById('openid_url');
    oTxt.value = url;

    if ( (start = url.indexOf("USERNAME") ) > 0 ) {
        length = 8;
        if (oTxt.createTextRange) {
            var oRange = oTxt.createTextRange();
            oRange.moveStart("character", start);
            oRange.moveEnd("character", length - oTxt.value.length);
            oRange.select();
        }
        else if (oTxt.setSelectionRange) {
            oTxt.setSelectionRange(start, start+length);
        }
        oTxt.focus();
    }




}
