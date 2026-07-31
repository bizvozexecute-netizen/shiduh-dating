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

(function(e){function A(a){var b=e(a),c=h(b),d=c.data("maxFiles"),f=c.data("fieldName"),g=function(a){return"multifile_"+a+"["+f+"][]"},t=r(a);b.hide().clone().val("").appendTo(c).show();b.removeClass("nc-upload-input").appendTo(c.find(".nc-upload-new-files"));e.each(t,function(k){var f=c.data("nextUploadIndex")||0;c.data("nextUploadIndex",f+1);1==t.length&&b.addClass("nc-upload-file-index--"+f);k=u(a,k).hide().data("uploadIndex",f).append(l(g("id")),l(g("upload_index"),f),l(g("delete"),0).addClass("nc-upload-file-remove-hidden"));
c.data("customName")&&(f=c.data("customNameCaption"),f=v("text",g("name"),"",f),e('<div class="nc-upload-file-custom-name"/>').append(f).appendTo(k));d&&"0"!=d&&w(c)>=d?x(k):k.slideDown(100)});m(c)}function u(a,b){var c=r(a)[b],d=e('<div class="nc-upload-file"><div class="nc-upload-file-info"><span class="nc-upload-file-name">'+c.name+'</span> <a href="#" class="nc-upload-file-remove" tabindex="-1">\u00d7</a></div></div>');a=h(a).find(".nc-upload-files");n&&5242880>c.size&&y(c.type)?(b=new FileReader,
b.onload=function(a){p(d,c.type,c.name,a.target.result,!0)},b.readAsDataURL(c)):p(d,c.type,c.name,null,!0);d.appendTo(a);z(d);return d}function p(a,b,c,d,f){var g=e("<div/>").addClass("nc-upload-file-preview nc-upload-file-drag-handle");(b=d&&y(b))&&n?(g.addClass("nc-upload-file-preview-image").append('<img src="'+d+'" />'),h(a).addClass("nc-upload-with-preview")):(d=c.lastIndexOf("."),g.addClass("nc-upload-file-preview-other"),0<d&&g.append('<span class="nc-upload-file-extension">'+c.substr(d+1)+
"</span>"));a.prepend('<div class="nc-upload-file-drag-icon nc-upload-file-drag-handle"><i class="nc-icon nc--file-text"></i></div>',g);(b||a.closest(".nc-upload-with-preview").length)&&n&&(f?g.slideDown(100):g.show());return g}function y(a){return/^image\/(jpe?g|png|gif|bmp|webp|svg([+-]xml)?)$/i.test(a)}function r(a){return n?a.files:[{name:a.value.substr(a.value.lastIndexOf("\\")+1),type:"",size:0}]}function q(a){return a.hasClass("nc-upload-multifile")}function h(a){return e(a).closest(".nc-upload")}
function w(a){return a.find(".nc-upload-file").filter(function(){return"none"!==e(this).css("display")}).length}function m(a){var b=q(a)?a.data("maxFiles"):1;b&&a.find(".nc-upload-input").toggle(w(a)<b)}function l(a,b){return v("hidden",a,b)}function v(a,b,c,d){return e("<input />",{type:a,name:b,value:void 0===c?"":c,placeholder:d||""})}function B(a){a.preventDefault();a.stopPropagation();a=e(a.target).closest(".nc-upload-file");var b=h(a),c=b.find(".nc-upload-input"),d=c;x(a);q(b)?b.find(".nc-upload-file-index--"+
a.data("uploadIndex")).val(""):(d=c.clone().val("").show(),c.replaceWith(d));a.slideUp(100,function(){b.hasClass("nc-upload-with-preview")&&0==b.find(".nc-upload-file:visible .nc-upload-file-preview-image").length&&b.removeClass("nc-upload-with-preview");var a=e.Event("change");a.target=d[0];e(document).trigger(a);m(b)});return!1}function x(a){a.find(".nc-upload-file-remove-hidden").val(1).trigger("change")}function z(a){a.find(".nc-upload-file-remove").off("click.nc-upload").on("click.nc-upload",
B)}if(e){var n="FileReader"in window;e.fn.upload=function(){return this.each(function(){var a=e(this);if(!a.hasClass("nc-upload-applied")){a.addClass("nc-upload-applied");var b=q(a),c=a.find(".nc-upload-files"),d=c.find(".nc-upload-file");0<d.length&&!b&&a.find(".nc-upload-input").hide();d.each(function(){var a=e(this),c=a.data("type"),b=a.find(".nc-upload-file-name"),d=b.html();b=b.data("preview-url")?b.data("preview-url"):b.prop("href");p(a,c,d,b,!1)});setTimeout(function(){m(a)},10);a.on("change",
".nc-upload-input",function(){b?A(this):this.value&&(u(this,0).hide().slideDown(100).find(".nc-upload-file-custom-name input:text").focus(),m(h(this)))});c.find(".nc-upload-file-remove").attr("onclick","");z(c);b&&a.append(l("multifile_js["+a.data("fieldName")+"]",1));if(b){var f;c.on("mousedown",".nc-upload-file-drag-handle",function(a){a.preventDefault();c.addClass("nc--dragging");f=e(this).closest(".nc-upload-file").addClass("nc--dragged");e(window).on("mouseup.nc-upload",function(){c.removeClass("nc--dragging");
f.removeClass("nc--dragged");e(window).off("mouseup.nc-upload");f=null})});c.on("mousemove",".nc-upload-file",function(a){var b=e(this);if(f&&!b.hasClass("nc--dragged")){if("none"==b.css("float")){a=a.pageY-b.offset().top;var c=b.height();a=a<c/2}else a=a.pageX-b.offset().left,c=b.width(),a=a<c/2;a?f.insertBefore(b):f.insertAfter(b)}})}}})};e(document).on("apply-upload",function(a){e(".nc-upload").upload()})}})(window.$nc||window.jQuery);

if (typeof(lsDisplayLibLoaded) == 'undefined') {
    var lsDisplayLibLoaded = true;
    var E_CLICK  = 0,
        E_SUBMIT = 1;

    jQuery(function(){
        var bindEvents = function($container){
            jQuery('[data-nc-ls-display-link]', $container).click(function(){
                eventHandler(this, true, E_CLICK);
                return false;
            });
            jQuery('form[data-nc-ls-display-form]', $container).submit(function(){
                eventHandler(this, true, E_SUBMIT);
                return false;
            });
        }

        var eventHandler = function(element, callBindEvents, event_type){

            switch (event_type) {

                case E_SUBMIT:
                    var url_attr  = 'action';
                    var data_attr = 'data-nc-ls-display-form';
                    break;

                case E_CLICK:
                default:
                    var url_attr  = 'href';
                    var data_attr = 'data-nc-ls-display-link';
                    break;
            }

            var $this    = jQuery(element);
            var url      = $this.attr(url_attr);
            var obj_data = $this.attr(data_attr);

            if (obj_data) {
                obj_data = jQuery.parseJSON(obj_data);
            }
            else {
                return false;
            }

            var replace_content = obj_data.subdivisionId !== false;

            if (url) {
                if (obj_data.displayType == 'shortpage' || (obj_data.displayType == 'longpage_vertical' && typeof(obj_data.subdivisionId) == 'undefined')) {

                    var send_as_post = event_type === E_SUBMIT && $this.attr('method').toLowerCase() === 'post';
                    var send_data    = jQuery.extend({}, obj_data.query); // clone

                    send_data.isNaked       = parseInt(typeof send_data.isNaked !== 'undefined' ? send_data.isNaked : 1);
                    send_data.lsDisplayType = obj_data.displayType;
                    send_data.skipTemplate  = parseInt(send_data.skipTemplate ? send_data.skipTemplate : obj_data.displayType == 'shortpage' && typeof(obj_data.subdivisionId) != 'undefined' ? 1 : 0);

                    if (send_as_post) {
                        url += (url.indexOf('?') >= 0 ? '&' : '?') + jQuery.param(send_data);
                        send_data = $this.serialize();
                    }

                    jQuery.ajax({
                        type:    send_as_post ? 'POST' : 'GET',
                        url:     url,
                        data:    send_data,
                        success: function(data){
                            var $container = [];

                            if (typeof(obj_data.onSubmit) !== 'undefined') {
                                if (data[0] == '{' || data[0] == '[') {
                                    data = jQuery.parseJSON(data);
                                }

                                if ((eval(obj_data.onSubmit)).call($this.get(0), data) === false) {
                                    replace_content = false;
                                }
                            }

                            if ( ! replace_content) {
                                return false;
                            }

                            if (typeof(obj_data.subdivisionId) == 'undefined') {
                                $container = $this.closest('[data-nc-ls-display-container]');
                            } else {
                                jQuery('[data-nc-ls-display-container]').each(function(){
                                    var $element = jQuery(this);
                                    var containerData = $element.attr('data-nc-ls-display-container');
                                    if (containerData) {
                                        containerData = jQuery.parseJSON(containerData);
                                        if (containerData.subdivisionId == obj_data.subdivisionId) {
                                            $container = $element;
                                            return false;
                                        }
                                    }

                                    return true;
                                });
                            }

                            if (!$container.length) {
                                $container = jQuery('[data-nc-ls-display-container]');
                            }

                            $container.html(data);

                            if (callBindEvents) {
                                bindEvents($container);
                            }

                            if (typeof(parent.nc_ls_quickbar) != 'undefined') {
                                var quickbar = parent.nc_ls_quickbar;
                                if (quickbar) {
                                    var $quickbar = jQuery('.nc-navbar').first();
                                    $quickbar.find('.nc-quick-menu LI:eq(0) A').attr('href', quickbar.view_link);
                                    $quickbar.find('.nc-quick-menu LI:eq(1) A').attr('href', quickbar.edit_link);
                                    $quickbar.find('.nc-menu UL LI:eq(0) A').attr('href', quickbar.sub_admin_link);
                                    $quickbar.find('.nc-menu UL LI:eq(1) A').attr('href', quickbar.template_admin_link);
                                    $quickbar.find('.nc-menu UL LI:eq(2) A').attr('href', quickbar.admin_link);
                                }
                            }
                        }
                    });

                } else if (obj_data.displayType == 'longpage_vertical') {
                    var scrolled = false;

                    var scrollToContainer = function(containerData, $element){
                        if (containerData) {
                            containerData = jQuery.parseJSON(containerData);
                            if (containerData.subdivisionId == obj_data.subdivisionId) {
                                jQuery('HTML,BODY').animate({
                                    scrollTop: $element.offset().top - jQuery('BODY').offset().top
                                }, containerData.animationSpeed);
                                return true;
                            }
                        }

                        return false;
                    };

                    jQuery('[data-nc-ls-display-pointer]').each(function(){
                        var $element = jQuery(this);
                        if (scrollToContainer($element.attr('data-nc-ls-display-pointer'), $element)) {
                            scrolled = true;
                            return false;
                        }

                        return true;
                    });

                    if (!scrolled) {
                        jQuery('[data-nc-ls-display-container]').each(function(){
                            var $element = jQuery(this);

                            if (scrollToContainer($element.attr('data-nc-ls-display-container'), $element)) {
                                return false;
                            }

                            return true;
                        });
                    }
                }

                if (replace_content) {
                    if (!!(window.history && history.pushState)) {
                        window.history.pushState({}, '', url);
                    }
                }

                if (event_type === E_CLICK) {
                    if (typeof(obj_data.onClick) == 'undefined') {
                        $this.addClass('active').siblings().removeClass('active');
                    } else {
                        eval('var callback = ' + obj_data.onClick);
                        callback.call($this.get(0));
                    }
                }

                return false;
            }
        }

        jQuery('[data-nc-ls-display-link]').click(function(){
            eventHandler(this, true, E_CLICK);
            return false;
        });

        jQuery('form[data-nc-ls-display-form]').submit(function(){
            eventHandler(this, true, E_SUBMIT);
            return false;
        });

        jQuery('[data-nc-ls-display-pointer]').each(function(){
            var $this = jQuery(this);
            var data = jQuery.parseJSON($this.attr('data-nc-ls-display-pointer'));
            if (data.onReadyScroll) {
                setTimeout(function(){
                    jQuery('HTML,BODY').scrollTop($this.offset().top);
                }, 1000);
                return false;
            }

            return true;
        });
    });
}
