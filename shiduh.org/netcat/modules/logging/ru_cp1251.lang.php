<?php

/* $Id $ */

define("NETCAT_MODULE_LOGGING_DESCRIPTION", "Логирование системных событий");

define("NETCAT_MODULE_LOGGING_CATALOGUE", "сайт &laquo;<a href='%s'>%s</a>&raquo;");
define("NETCAT_MODULE_LOGGING_SUBDIVISION", "сайт &laquo;<a href='%s'>%s</a>&raquo;, раздел &laquo;<a href='%s'>%s</a>&raquo;");
define("NETCAT_MODULE_LOGGING_SUBCLASS", "сайт &laquo;<a href='%s'>%s</a>&raquo;, раздел &laquo;<a href='%s'>%s</a>&raquo;, инфоблок &laquo;<a href='%s'>%s</a>&raquo;");
define("NETCAT_MODULE_LOGGING_AREA_INFOBLOCK", "сайт &laquo;<a href='%s'>%s</a>&raquo;, сквозной инфоблок &laquo;<a href='%s'>%s</a>&raquo;");
define("NETCAT_MODULE_LOGGING_CLASS", "компонент &laquo;<a href='%s'>%s</a>&raquo;");
define("NETCAT_MODULE_LOGGING_CLASSTEMPLATE", "компонент &laquo;<a href='%s'>%s</a>&raquo;, шаблон компонента &laquo;<a href='%s'>%s</a>&raquo;");
define("NETCAT_MODULE_LOGGING_MESSAGE", "сайт &laquo;<a href='%s'>%s</a>&raquo;, раздел &laquo;<a href='%s'>%s</a>&raquo;, инфоблок &laquo;<a href='%s'>%s</a>&raquo;, компонент &laquo;<a href='%s'>%s</a>&raquo;, сообщение &laquo;<a href='%s'>%s</a>&raquo;");
define("NETCAT_MODULE_LOGGING_SYSTEMTABLE", "системная таблица &laquo;<a href='%s'>%s</a>&raquo;");
define("NETCAT_MODULE_LOGGING_TEMPLATE", "макет &laquo;<a href='%s'>%s</a>&raquo;");
define("NETCAT_MODULE_LOGGING_USER", "пользователь &laquo;<a href='%s'>%s</a>&raquo;");
define("NETCAT_MODULE_LOGGING_MODULE", "модуль &laquo;<a href='%s'>%s</a>&raquo;, сайт &laquo;<a href='%s'>%s</a>&raquo;");
define("NETCAT_MODULE_LOGGING_COMMENT", "сайт &laquo;<a href='%s'>%s</a>&raquo;, раздел &laquo;<a href='%s'>%s</a>&raquo;, инфоблок &laquo;<a href='%s'>%s</a>&raquo;, компонент &laquo;<a href='%s'>%s</a>&raquo;, сообщение &laquo;<a href='%s'>%s</a>&raquo;, комментарий &laquo;<a href='%s' target='_blank'>%s</a>&raquo;");

define("NETCAT_MODULE_LOGGING_DATA_ID", "#");
define("NETCAT_MODULE_LOGGING_DATA_EVENT", "Событие");
define("NETCAT_MODULE_LOGGING_DATA_INFO", "Информация");
define("NETCAT_MODULE_LOGGING_DATA_DATE", "Дата события");
define("NETCAT_MODULE_LOGGING_DATA_USER", "Пользователь");
define("NETCAT_MODULE_LOGGING_DATA_SYSTEM", "система");

define("NETCAT_MODULE_LOGGING_BUTTON_CLEAR", "Очистить");
define("NETCAT_MODULE_LOGGING_BUTTON_CANCEL", "Отменить");
define("NETCAT_MODULE_LOGGING_BUTTON_SORT", "Отобразить");

define("NETCAT_MODULE_LOGGING_NOTICE_CLEAR", "Очистить все данные логирования?");

define("NETCAT_MODULE_LOGGING_SHOW_PARAMETERS", "Параметры отображения");
define("NETCAT_MODULE_LOGGING_SHOW_SORT_BY", "сортировать по");
define("NETCAT_MODULE_LOGGING_SHOW_SORT_BY_DATE", "дате события");
define("NETCAT_MODULE_LOGGING_SHOW_SORT_BY_EVENT", "названию события");
define("NETCAT_MODULE_LOGGING_SHOW_SORT_BY_USER", "пользователю");
define("NETCAT_MODULE_LOGGING_SHOW_SORT_DIR", "порядок сортировки");
define("NETCAT_MODULE_LOGGING_SHOW_SORT_DIR_ASC", "по возрастанию");
define("NETCAT_MODULE_LOGGING_SHOW_SORT_DIR_DESC", "по убыванию");
define("NETCAT_MODULE_LOGGING_SHOW_ONLY_ACTION", "отобразить действие");
define("NETCAT_MODULE_LOGGING_SHOW_ONLY_ACTION_NONE", "-- действие не задано --");
define("NETCAT_MODULE_LOGGING_SHOW_ONLY_ESSENCE", "отобразить сущность");
define("NETCAT_MODULE_LOGGING_SHOW_ONLY_ESSENCE_NONE", "-- сущность не задана --");

define("NETCAT_MODULE_LOGGING_EVENT_TYPE_ADD", "добавление");
define("NETCAT_MODULE_LOGGING_EVENT_TYPE_UPDATE", "изменение");
define("NETCAT_MODULE_LOGGING_EVENT_TYPE_DROP", "удаление");
define("NETCAT_MODULE_LOGGING_EVENT_TYPE_CHECK", "включение");
define("NETCAT_MODULE_LOGGING_EVENT_TYPE_UNCHECK", "выключение");
define("NETCAT_MODULE_LOGGING_EVENT_TYPE_AUTHORIZE", "авторизация");

define("NETCAT_MODULE_LOGGING_EVENT_ESSENCE_CATALOGUE", "сайт");
define("NETCAT_MODULE_LOGGING_EVENT_ESSENCE_SUBDIVISION", "раздел");
define("NETCAT_MODULE_LOGGING_EVENT_ESSENCE_SUBCLASS", "инфоблок");
define("NETCAT_MODULE_LOGGING_EVENT_ESSENCE_CLASS", "компонент");
define("NETCAT_MODULE_LOGGING_EVENT_ESSENCE_CLASSTEMPLATE", "шаблон компонента");
define("NETCAT_MODULE_LOGGING_EVENT_ESSENCE_MESSAGE", "объект");
define("NETCAT_MODULE_LOGGING_EVENT_ESSENCE_SYSTEMTABLE", "системная таблица");
define("NETCAT_MODULE_LOGGING_EVENT_ESSENCE_TEMPLATE", "макет");
define("NETCAT_MODULE_LOGGING_EVENT_ESSENCE_USER", "пользователь");
define("NETCAT_MODULE_LOGGING_EVENT_ESSENCE_MODULE", "модуль");
define("NETCAT_MODULE_LOGGING_EVENT_ESSENCE_COMMENT", "комментарий");

define("NETCAT_MODULE_LOGGING_WIDGETCLASS", "виджет-компонент &laquo;<a href='%s'>%s</a>&raquo;");
define("NETCAT_MODULE_LOGGING_WIDGET", "виджет &laquo;<a href='%s'>%s</a>&raquo;, на основе виджет-компонента &laquo;<a href='%s'>%s</a>&raquo;");
define("NETCAT_MODULE_LOGGING_WIDGET_DROP", "виджет &laquo;%s&raquo;, на основе виджет-компонента &laquo;%s&raquo;");
?>