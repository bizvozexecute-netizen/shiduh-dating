/**
 * Редактор условий (скидки, правила выбора цен, правила подбора
 * адресов менеджеров и т.п.)
 */

/**
 * @constructor
 * @param {Object} options
 *      container: selector for the condition editor container
 *      input_name: name of the hidden input field (will be created)
 *      conditions: conditions to set
 *      site_id: ID of the current site
 *      groups_to_show: array with the names of the condition groups that will be shown
 *      groups_to_exclude: array with the names of the condition groups that won't be shown
 *      conditions_to_exclude: array with the names of the conditions to exclude
 */
function nc_condition_editor(options) {
    this.init(options);
}

(function ($) {

    var Class = nc_condition_editor,
        Instance = Class.prototype,
        lang = nc_condition_messages,
        adminFilesPath = Class.adminFilesPath = NETCAT_PATH + 'admin/',
        filesPath = Class.filesPath = Class.adminFilesPath + 'condition/',
        dataPath = Class.dataPath = filesPath + 'data/';

    // ****** "Static" properties ******
    // Cache results from server (key = url)
    Class.dataCache = {};

    // URLs to fetch miscellaneous data
    Class.urls = {
        SelectSubdivisionList: dataPath + "json/subdivision_list.php?site_id=%siteId%&sub_class_id=%subClassId%", // SelectFromJson
        SelectItemList: dataPath + "json/object_list.php?sub_class_id=%subClassId%", // SelectFromJson
        SelectItemPropertyList: dataPath + "json/component_field_list.php?sub_class_id=%subClassId%",
        SelectFromClassifierList: dataPath + "json/classifier_values.php"
    };
    // Store URLs for which requests are in progress (prevent duplicate requests when editor.load() is called)
    Class.loadingInProgress = {};

    // ****** PROPERTIES ******
    Instance.siteId = null;
    Instance.subClassId = null;
    Instance.root = null;
    Instance.inputField = null;
    Instance.isInitializing = false;
    Instance.defaultChosenParams = {disable_search_threshold: 10, no_results_text: lang.SEARCH_NO_RESULTS};
    Instance.conditionGroupsToShow = [];
    Instance.conditionGroupsToExclude = [];
    Instance.conditionsToExclude = [];

    // Item properties (i.e. fields), used in 'itemproperty' conditions
    Instance.itemProperties = {};

    var opEqNe = Class.opEqNe = {
            name: 'op', type: 'SimpleSelect', values: {
                eq: lang.EQUALS,
                ne: lang.NOT_EQUALS
            }
        }, // равно-не равно
        opContains = Class.opContains = {
            name: 'op', type: 'SimpleSelect', values: {
                contains: lang.CONTAINS,
                notcontains: lang.NOT_CONTAINS
            }
        },
        opString = Class.opString = {
            name: 'op', type: 'SimpleSelect', values: {
                eq: lang.EQUALS,
                ne: lang.NOT_EQUALS,
                contains: lang.CONTAINS,
                notcontains: lang.NOT_CONTAINS,
                begins: lang.BEGINS_WITH
            }
        },
        opNumber = Class.opNumber = {
            name: 'op', type: 'SimpleSelect', values: {
                ge: lang.GREATER_OR_EQUALS,
                gt: lang.GREATER_THAN,
                le: lang.LESS_OR_EQUALS,
                lt: lang.LESS_THAN,
                eq: lang.EQUALS,
                ne: lang.NOT_EQUALS
            }
        },
        opDateTime = Class.opDateTime = opNumber,
        opAll = Class.opAll = $.extend({}, opString);

    $.extend(opAll.values, opNumber.values);

    var makeSubdivisionField = Class.makeSubdivisionField = function (fieldName) {
        return {
            name: fieldName || 'value',
            type: 'SelectFromJson',
            source: Class.urls.SelectSubdivisionList,
            placeholder: lang.SELECT_SUBDIVISION
        };
    };

    var makeItemField = Class.makeItemField = function (fieldName) {
        return {
            name: fieldName || 'value',
            type: 'SelectFromJson',
            source: Class.urls.SelectItemList,
            placeholder: lang.SELECT_OBJECT
        };
    };

    /**
     * @param {Object} condition
     * @returns {boolean} условие является группой (и, или)
     */
    function isGroup(condition) {
        return condition.type === 'and' || condition.type === 'or';
    }

    /**
     * nb: записи должны быть отсортированы по группе
     */
    Instance.conditionTypes = {
        // GROUP_OBJECTS //
        object_sub: {
            group: 'GROUP_OBJECTS',
            label: 'TYPE_SUBDIVISION',
            params: [
                lang.TYPE_SUBDIVISION,
                opEqNe,
                makeSubdivisionField('value')
            ]
        },
        object_parentsub: {
            group: 'GROUP_OBJECTS',
            label: 'TYPE_SUBDIVISION_DESCENDANTS',
            params: [
                lang.TYPE_SUBDIVISION_DESCENDANTS,
                opEqNe,
                makeSubdivisionField('value')
            ]
        },
        object: {
            group: 'GROUP_OBJECTS',
            label: 'TYPE_OBJECT',
            params: [
                lang.TYPE_OBJECT,
                opEqNe,
                makeItemField('value')
            ]
        },
        object_property: {
            group: 'GROUP_OBJECTS',
            label: 'TYPE_OBJECT_FIELD',
            params: [
                lang.TYPE_OBJECT_FIELD,
                {name: 'field', type: 'SelectItemProperty'},
                {type: 'ItemPropertyOptions'}
            ]
        }
    };


    // ****** Helper functions ******
    /**
     * Returns the element's top coordinate relative to the body
     * @param {HTMLElement} el
     * @returns Number
     */
    var getOffsetTop = function (el) {
        if (!el.getBoundingClientRect) {
            return $(el).offset();
        }
        var body = document.body,
            docEl = document.documentElement,
            scrollTop = window.pageYOffset || docEl.scrollTop || body.scrollTop,
            clientTop = docEl.clientTop || body.clientTop || 0;
        return Math.round(el.getBoundingClientRect().top + scrollTop - clientTop);
    };

    /**
     * Get default parameter value (stored in the parent $(.condition) data)
     * Used in Instance.reinitializeChosenSelects
     */
    var getInitialParamValue = function (input) {
        var values = input.closest(".condition").data('initialValues') || {};
        return values[input.attr('name')];
    };

    /**
     * Scrolls the "Chosen" element into view (handler for the "chosen:showing_dropdown" event)
     */
    var scrollChosenIntoView = function (event, eventData) {
        // var chosen = $(this).data("chosen")
        var chosen = eventData.chosen;
        setTimeout(function () {
            // .scrollIntoView() results in unwanted behaviour
            var dropdown = chosen.dropdown,
                dropdownBottom = getOffsetTop(dropdown[0]) + dropdown.outerHeight(), // jQuery offset() produces wrong results
                body = $('body, html'),
                bodyBottom = body.scrollTop() + $(window).height(),
                scrollDelta = dropdownBottom - bodyBottom;

            if (scrollDelta > 0) {
                var newScrollTop = Math.min(
                    body.scrollTop() + scrollDelta,
                    getOffsetTop(chosen.container[0]) // do not scroll further than the "chosen" select itself
                );

                body.animate({scrollTop: newScrollTop}, 400);
                body.css('height', dropdownBottom + 'px');
            }

        }, 100);
    };

    var calculateHash = function (str) {
        for (var hash = 0, i = 0, len = str.length; i < len; i++) {
            hash = (hash << 5) - hash + str.charCodeAt(i);
            hash &= hash;
        }
        hash = hash.toString(36).replace("-", "N");
        return hash
    };

    // ****** METHODS ******
    /**
     * Initialization:
     */
    Instance.init = function (options) {
        this.loadStylesIn(window);

        this.siteId = options.site_id;
        this.subClassId = options.sub_class_id;
        this.root = $nc(options.container).addClass('nc-condition-editor');
        this.inputField = $nc("<input type='hidden' name='" + options.input_name + "'/>")
            .appendTo(this.root);

        var data = options.conditions;
        if (data && !$nc.isEmptyObject(data)) {
            this.inputField.val(JSON.stringify(data));
        }
        this.conditionGroupsToShow = options.groups_to_show || [];
        this.conditionGroupsToExclude = options.groups_to_exclude || [];
        this.conditionsToExclude = options.conditions_to_exclude || [];

        this.load(data);

        $nc(window).resize($nc.proxy(this, 'updateAllConditionLabelPositions'));
    };

    /**
     *
     */
    Instance.load = function (input) {
        this.isInitializing = true;

        var conditionTree = input || {};
        if (typeof input === "string") {
            conditionTree = (JSON && JSON.parse) ? JSON.parse(input) : eval("(" + input + ")");
        }
        // clear the editor
        this.root.find(".condition-group").first().remove();
        this.addGroup(this.root, conditionTree);

        this.isInitializing = false;

        var editor = this;

        $(window).load(function () {
            editor.updateAllConditionLabelPositions();
            editor.root.addClass("enable-transitions");
        });
    };

    /**
     *
     */
    Instance.save = function () {
        var rootGroupContainer = this.root.children(".condition-group"),
            result = this.getGroupData(rootGroupContainer),
            jsonResult = result ? JSON.stringify(result) : '';

        this.inputField.val(jsonResult);

        return result;
    };

    /**
     *
     */
    Instance.onFormSubmit = function () {
        if (!this.checkConditions()) {
            return false;
        }
        else {
            this.save();
            return true;
        }
    };

    /**
     * @return {Object|null}
     */
    Instance.getGroupData = function (groupContainer) {
        var groupType = groupContainer.find(".operator-cell select").val(),
            result = {type: groupType, conditions: []},
            allConditions = groupContainer.find(".condition-cell").first(),
            conditionsAndGroups = allConditions.children(".condition, .condition-group"),
            editor = this;

        if (conditionsAndGroups.length == 0) {
            return null;
        } // empty group!

        conditionsAndGroups.each(function () {
            var el = $(this),
                output;
            if (el.hasClass('condition-group')) {
                output = editor.getGroupData(el);
            }
            else {
                output = editor.getConditionData(el);
            }

            if (output) {
                result.conditions.push(output);
            }
        });

        return result;
    };

    /**
     *
     * @return {Object|null}
     */
    Instance.getConditionData = function (conditionContainer) {
        var values = conditionContainer.data("initialValues"), // default values, in case not all conditions selects are "initialized" (loaded)
            invalidParams = false;

        conditionContainer.find(".condition-param-value").not(".condition-param-value-initializing").each(function () {
            var $this = $(this),
                value = $this.val();
            if (value == '' || value == null) {
                if (console) {
                    console.warn('Condition parameter %s has no value, the condition is ignored (type=%s)', this.name, values.type);
                }
                invalidParams = true;
                return false;
            }
            else {
                if ($this.hasClass("condition-param-value-date") && $this.datepicker) {
                    value = $this.datepicker('getISODate') || value;
                }
                values[this.name] = value;
            }
            return true;
        });

        values.type = conditionContainer.data("conditionType");

        if (invalidParams) {
            return null;
        }
        return values;
    };

    /**
     *
     * @return bool
     */
    Instance.checkConditions = function () {
        var isOk = true;
        this.root.find(".condition-param-value").not(".condition-param-value-initializing").each(function () {
            var $this = $(this),
                value = $this.val();
            if (value == '' || value == null) {
                isOk = false;

                // show an ugly alert
                var firstStringField = $this.closest(".condition").find(".condition-string").first().text();
                alert(lang.VALUE_REQUIRED.replace("%s", firstStringField));

                // and focus the associated input element
                if ($this.is("select")) {
                    $this.data("chosen").selected_item.focus();
                }
                else if ($this.is("input:hidden")) {
                    $this.siblings("a.condition-param-popup").focus();
                }
                else {
                    $this.focus();
                }

                return false; // exit .each()
            }
        });
        return isOk;
    };

    /**
     * @param {window} targetWindow    (window  or  window.top)
     */
    Instance.loadStylesIn = function (targetWindow) {
        var id = "nc_condition_editor_stylesheet";
        if (targetWindow.$nc("#" + id).length == 0) {
            targetWindow.$nc("head").append(
                '<link rel="stylesheet" id="' + id + '" type="text/css" href="' + adminFilesPath + 'js/datepicker/datepicker.css">'
            );
        }
    };

    /**
     *
     */
    Instance.addGroup = function (parentContainer, values) {
        var editor = this,
            group = $("<div class='condition-group'>" +
                "<div class='condition-group-container'>" +
                "<div class='operator-cell'>" +
                "<div class='operator-label nc-label nc--blue'>" + lang.AND + "</div>" +
                "<select class='operator-type'>" +
                "<option value='and'>" + lang.AND_DESCRIPTION + "</option>" +
                "<option value='or'>" + lang.OR_DESCRIPTION + "</option>" +
                "</select>" +
                "<a class='nc-icon-s nc--remove remove-group-button'></a>" +
                "</div>" +
                "<div class='condition-cell'></div>" +
                "<div class='buttons-cell'>" +
                "<a class='add-condition-button'>" + lang.ADD + "</a>" +
                "</div>" +
                "</div>" +
                "</div>"),
            insertionPoint = group.find('.condition-cell'),
            typeSelect = group.find("select");

        group.find('.add-condition-button')
            .click(function () {
                editor.showAddConditionDialog(insertionPoint);
            });

        group.find('.remove-group-button')
            .click(function () {
                editor.removeGroup(group);
            })
            .attr('title', lang.REMOVE_GROUP);

        group.find('.operator-type')
            .change(function () {
                editor.updateConditionLabelText($(this))
            });

        group.find('.operator-label')
            .click(function () {
                editor.changeOperator($(this));
            });

        var parentGroups = parentContainer.parents('.condition-group'),
            isRoot = !parentGroups.length;

        if (isRoot) {
            group.addClass('root');
        }

        if (parentGroups.length % 2) {
            group.find('.condition-group-container').addClass('even-level');
        }

        parentContainer.append(group);

        typeSelect.chosen(this.defaultChosenParams);

        if (!$.isEmptyObject(values)) {
            typeSelect.val(values.type).change().trigger("chosen:updated");
            for (var i = 0, last = values.conditions.length; i < last; i++) {
                var condition = values.conditions[i];
                if (isGroup(condition)) {
                    this.addGroup(insertionPoint, condition);
                }
                else {
                    this.addCondition(condition.type, insertionPoint, condition);
                }
            }
            if (isRoot && last === 1 && !isGroup(values.conditions[0])) {
                group.find('.operator-cell').hide();
            }
        }
        else if (isRoot) {
            group.find('.operator-cell').hide();
        }

        this.updateOperatorLabelPosition(parentGroups.first());
    };

    /**
     *
     */
    Instance.showAddConditionDialog = function (target) {
        var options = [
                "<option></option>",
                "<option value='addGroup' class='create-group-option'>" + lang.ADD_GROUP + "</option>"
            ],
            prevGroup = null;
        for (var typeName in this.conditionTypes) {
            var conditionData = this.conditionTypes[typeName],
                groupsToShow = this.conditionGroupsToShow;

            // skip if the group of this condition is NOT in the this.conditionGroupsToShow
            if (groupsToShow.length && $.inArray(conditionData.group, groupsToShow) == -1) {
                continue;
            }

            // skip if the group of this condition is in the this.conditionGroupsToExclude
            if ($.inArray(conditionData.group, this.conditionGroupsToExclude) != -1) {
                continue;
            }

            // skip the condition if it's 'name' is in the this.conditionsToExclude
            if ($.inArray(typeName, this.conditionsToExclude) != -1) {
                continue;
            }

            if (prevGroup != conditionData.group) {
                if (prevGroup != null) {
                    options.push("</optgroup>");
                }
                options.push('<optgroup label="' + lang[conditionData.group] + '">');
                prevGroup = conditionData.group;
            }
            options.push('<option value="' + typeName + '">' + lang[conditionData.label] + '</option>');
        }
        options.push("</optgroup>");

        var select = $('<select data-placeholder="' + lang.SELECT_CONDITION_TYPE + '">' + options.join('') + '</select>'),
            tmpDiv = $('<div class="add-condition-type-select"></div>').append(select).appendTo(target),
            editor = this;

        select.chosen(this.defaultChosenParams)
            .on("chosen:hiding_dropdown", function () {
                var conditionTypeName = select.val(),
                    parentContainer = select.closest(".condition-cell");
                // remove the <select> when the dropdown is closed (in any case)
                select.parents('.add-condition-type-select').remove();
                // add the condition line if something was selected
                if (conditionTypeName == 'addGroup') {
                    editor.addGroup(parentContainer, null);
                }
                else if (conditionTypeName) {
                    editor.addCondition(conditionTypeName, parentContainer, {});
                }
            })
            .on("chosen:showing_dropdown", scrollChosenIntoView);

        setTimeout(function () {
            tmpDiv.find('.chosen-container').trigger('mousedown');
        }, 1); // open the <select>
    };

    /**
     *
     */
    Instance.updateAllConditionLabelPositions = function () {
        var editor = this;
        this.root.find(".condition-group").each(function () {
            editor.updateOperatorLabelPosition($(this));
        });
    };

    /**
     *
     */
    Instance.updateOperatorLabelPosition = function (groupContainer) {
        if (this.isInitializing) {
            return;
        }

        var conditionCell = groupContainer.find('.condition-cell').first(),
            conditions = conditionCell.children('.condition, .condition-group'),
            operatorCell = groupContainer.find('.operator-cell').first(),
            operatorLabel = operatorCell.find('.operator-label');

        if (conditions.length > 1) {
            if (groupContainer.is('.root') && !operatorCell.is(':visible')) {
                operatorCell.slideDown(400, $.proxy(this, 'updateAllConditionLabelPositions')).show();
                return;
            }

            var wasVisible = operatorLabel.hasClass('visible'),
                first = conditions.first(),
                firstTop = first.position().top,
                last = conditions.last(),
                lastTop = last.position().top,

                top = firstTop + 27 + 0.5 * (lastTop - firstTop - operatorLabel.height());

            if (first.is('.condition-group')) {
                top += 12;
            }
            if (last.is('.condition-group')) {
                top += 12;
            }

            top = Math.min(firstTop + $(window).height() - 100, Math.round(top)) + 'px';

            operatorLabel.addClass('visible');
            if (wasVisible) {
                setTimeout(function () {
                    operatorLabel.css('top', top);
                }, 1); // trigger transition animation
            }
            else {
                operatorLabel.css('top', top);
            }
        }
        else {
            operatorLabel.removeClass('visible');
            if (groupContainer.is('.root') && operatorCell.is(':visible')) {
                operatorCell.slideUp(400, $.proxy(this, 'updateAllConditionLabelPositions'));
            }
        }
    };

    /**
     *
     */
    Instance.updateConditionLabelText = function (select) {
        select.closest(".operator-cell").find(".operator-label").html(lang[select.val().toUpperCase()]);
    };

    /**
     *
     */
    Instance.changeOperator = function (operatorLabel) {
        var operatorSelect = operatorLabel.closest(".operator-cell").find(".operator-type");

        operatorSelect
            .val(operatorSelect.val() == 'and' ? 'or' : 'and')
            .change()
            .trigger("chosen:updated");
    };

    /**
     *
     */
    Instance.addCondition = function (conditionType, container, values) {
        /**
         * Markup for the condition:
         * <div class="condition condition-[type]" data-conditionType=[type] data-initialValues=[values]>
         *      <span class="condition-string">String</span>
         *      <span class="condition-param">
         *          ...
         *          <input class="condition-param-value" name="paramName">   ← class is required!
         *              ↑ may have 'data-fieldParams' = { field params }
         *          ...
         *      </span>
         * </div>
         *
         */

        var conditionData = this.conditionTypes[conditionType],
            conditionDiv = $("<div />", {
                'class': 'condition condition-' + conditionType,
                data: {
                    conditionType: conditionType,
                    initialValues: values // store values for the fields which require initialization
                }
            }).appendTo(container),
            editor = this;

        this.addFields(conditionDiv, conditionData.params, values);

        $("<a class='nc-icon-s nc--remove remove-condition-button'></a>")
            .click(function () {
                editor.removeCondition($(this).closest('.condition'));
            })
            .attr('title', lang.REMOVE_CONDITION)
            .appendTo(conditionDiv);

        this.updateOperatorLabelPosition(container.closest('.condition-group'));

        if (!this.isInitializing) {
            conditionDiv.find("input, select").not(":hidden").first().focus();
        }
    };

    /**
     *
     * @param target
     * @param {Array}  params
     * @param {Object} values
     */
    Instance.addFields = function (target, params, values) {
        var editor = this,
            hasValues = (values && !$.isEmptyObject(values));

        for (var i = 0, len = params.length; i < len; i++) {
            var param = params[i];
            if (typeof param === 'string') {
                target.append("<span class='condition-string'>" + param + "</span>");
            }
            else {
                // call appendXyzField()
                var methodName = "append" + param.type + "Field";
                if (this[methodName] === undefined) {
                    console && console.warn("Incorrect field type '%s': no %s method", param.type, methodName);
                    continue;
                }
                var field = this[methodName](target, param, hasValues ? values[param.name] : param.value);
                field.wrap($("<span/>", {'class': 'condition-param'}));
            }
        }

        // init chosen
        target.find("select")
            .chosen(this.defaultChosenParams)
            .on("chosen:showing_dropdown", scrollChosenIntoView)
            .each(function () {
                // load select values where needed
                var select = $(this);
                if (select.data('loadData')) {
                    var fieldParams = select.data('fieldParams') || {},
                        callback = $.proxy(editor, "on" + fieldParams.type + "ListReady"),
                        script = fieldParams.source || Class.urls[fieldParams.type + 'List'],
                        url = editor.makeFullUrl(script, fieldParams.requestParams);

                    editor.loadData(url, callback, select.data('callbackParams'));
                }
            });

        // init datepicker
        target.find("input.condition-param-value-date").datepicker();
    };

    /**
     *
     */
    Instance.removeGroup = function (groupContainer) {
        var hasConditions = groupContainer.find(".condition").length > 0,
            confirmationText = (groupContainer.hasClass('root')
                ? lang.REMOVE_ALL_CONFIRMATION
                : lang.REMOVE_GROUP_CONFIRMATION);

        if (!hasConditions || confirm(confirmationText)) {
            groupContainer.remove();
            if (!this.root.find('.condition-group').length) {
                this.addGroup(this.root, null);
            }
            else {
                this.updateAllConditionLabelPositions();
            }
        }
    };

    /**
     *
     */
    Instance.removeCondition = function (conditionContainer) {
        // if not all values have been set, do not bother to confirm
        var skipConfirmation = false;
        conditionContainer.find(".condition-param-value").not(".condition-param-value-initializing").each(function () {
            var value = $(this).val();
            if (value == '' || value == null) {
                skipConfirmation = true;
                return false; // exit .each()
            }
        });

        // prepare condition description for the confirmation dialog
        var conditionText = [];
        conditionContainer.find(".condition-string, .condition-param-value:not([type=hidden]), .essence-caption").each(function () {
            var $this = $(this);
            if ($this.is(".condition-string, .essence-caption")) {
                conditionText.push($this.text());
            }
            else if ($this.is("select")) {
                conditionText.push('[' + $.trim($this.find("option:selected").text()) + ']');
            }
            else {
                conditionText.push($this.val());
            }
        });

        if (skipConfirmation || confirm(lang.REMOVE_CONDITION_CONFIRMATION.replace('%s', conditionText.join(' ')))) {
            conditionContainer.remove();
            this.updateAllConditionLabelPositions();
        }
    };

    /********* Condition field generators *********/
    /**
     *
     */
    Instance.appendSimpleSelectField = function (target, params, value) {
        var html = ['<select name="' + params.name + '">'];
        for (var optionValue in params.values) {
            html.push('<option value="' + optionValue + '">' + params.values[optionValue] + "</option>");
        }
        html.push("</select>");

        var select = $(html.join('')).addClass("condition-param-value");
        if (value || value === 0) {
            select.val(value);
        } // sic (otherwise wrong value might be set)

        return select.appendTo(target);
    };

    /**
     *
     */
    Instance.createListSelectField = function (fieldParams, selectValue, placeholder) {
        return $('<select />', {
            name: fieldParams.name,
            'class': 'condition-param-value condition-param-value-initializing condition-param-' + fieldParams.type
        }).data({
            loadData: true,
            fieldParams: fieldParams
//                    placeholder: placeholder
        }).attr('data-placeholder', placeholder);
    };

    // Convention for <select> fields which load data from the server:
    // below: [TYPE] = param.type
    //      <select class='condition-param-value condition-param-[TYPE]>
    //      server script:      Class.urls.[TYPE]List    (or fieldParams.source if it is set)
    //      ajax data handler:  this.on[TYPE]ListReady

    /**
     *
     */
    Instance.appendSelectItemPropertyField = function (target, params, value) {
        return this.createListSelectField(params, value, lang.SELECT_OBJECT_FIELD)
            .appendTo(target);
    };

    /**
     *
     */
    Instance.appendSelectFromClassifierField = function (target, params, value) {
        var classifier = params.requestParams.classifier;
        return this.createListSelectField(params, value, lang.SELECT_VALUE)
            .data('callbackParams', {classifier: classifier})
            .addClass("condition-param-SelectFromClassifier-" + classifier)
            .appendTo(target);
    };

    /**
     *
     */
    Instance.appendItemPropertyOptionsField = function (target, params, value) {
        return $("<span class='condition-itemproperty-variable-part' />").appendTo(target);
    };

    /**
     *
     */
    Instance.appendInputField = function (target, params, value) {
        return $("<input />", {
            type: params.inputType || 'text',
            name: params.name,
            value: value,
            autocomplete: 'off',
            'class': 'condition-param-value' + (params['class'] ? ' ' + params['class'] : '')
        }).appendTo(target);
    };

    /**
     *
     */
    Instance.appendDateTimeInputField = function (target, params, value) {
        return $("<input />", {
            type: 'text',
            name: params.name,
            value: value,
            'class': 'condition-param-value condition-param-value-date'
        }).appendTo(target);
    };

    Instance.getSelectFromJsonClass = function (url) {
        return "condition-param-SelectFromJson-" + calculateHash(url);
    };

    /**
     *
     */
    Instance.appendSelectFromJsonField = function (target, params, value) {
        var url = this.makeFullUrl(params.source, params.requestParams);
        return this.createListSelectField(params, value, params.placeholder)
            .addClass(this.getSelectFromJsonClass(url))
            .appendTo(target);
    };

//    /**
//     *
//     */
//    Instance.append_Field = function(target, params, value) {
//    };

    /********* Data loaders *********/
    /**
     *
     * @param {String} url
     * @param {Function} [callback]
     * @param {Object} [callbackParams]    will be passed to the callback function
     */
    Instance.loadData = function (url, callback, callbackParams) {
        if (!callback) {
            callback = $.noop;
        }

        if (Class.loadingInProgress[url]) {
            return;
        }

        if (typeof Class.dataCache[url] === 'undefined') {
            Class.loadingInProgress[url] = true;
            $.getJSON(url, function (data) {
                Class.dataCache[url] = data;
                callback(data, url, callbackParams);
                Class.loadingInProgress[url] = false;
            });
        }
        else {
            callback(Class.dataCache[url], url, callbackParams);
        }
    };

    /**
     *
     * @param {string} script
     * @param {Object} params
     * @returns {string}
     */
    Instance.makeFullUrl = function (script, params) {
        var url = script;
        if (params) {
            url += (url.indexOf("?") >= 0 ? "&" : "?") + $.param(params);
        }
        return url.replace("%siteId%", this.siteId).replace("%subClassId%", this.subClassId);
    };

    /**
     *
     */
    Instance.fillSelectsWithOptions = function (selector, data) {
        var selects = this.root.find(selector);

        if (selects.length > 0) {
            var options = ["<option></option>"];
            if ($.isArray(data)) {
                for (var i = 0, len = data.length; i < len; i++) {
                    options.push('<option value="' + data[i].key + '">' + data[i].value + '</option>');
                }
            }

            selects.append(options.join(''));
            this.reinitializeChosenSelects(selects);
        }
    };

    /**
     *
     */
    Instance.reinitializeChosenSelects = function (selects) {
        selects.each(function () {
            var select = $(this),
                chosen = select.data('chosen');
            // check if the field was focused
            select.data('hadFocus',
                chosen ? chosen.container.hasClass("chosen-container-active")
                    : select.is(":focus")
            );
            select.val(getInitialParamValue(select))
                .removeClass("condition-param-value-initializing");
        })
        // chosen doesn’t update width when 'chosen:updated' is triggered
            .chosen('destroy').chosen(this.defaultChosenParams)
        // restore focus
            .each(function () {
                var select = $(this);
                if (select.data('hadFocus')) {
                    select.data('chosen').selected_item.focus();
                }
                select.removeData('hadFocus');
            });
    };

    /**
     *
     */
    Instance.onSelectFromClassifierListReady = function (data, url, callbackParams) {
        var selector = 'select.condition-param-SelectFromClassifier-' + callbackParams.classifier + ':empty';
        this.fillSelectsWithOptions(selector, data);
    };

    /**
     *
     */
    Instance.onSelectItemPropertyListReady = function (data, url, callbackParams) {
        this.itemProperties = data; // store info about fields in this.itemProperties to reuse it later

        var options = ["<option></option>"];
        for (var groupName in data) {
            options.push('<optgroup label="' + groupName + '">');
            for (var i = 0, last = data[groupName].length; i < last; i++) {
                var field = data[groupName][i];
                options.push("<option value='" + field.id + "'>" + field.description + "</option>");
            }
            options.push('</optgroup>');
        }

        var selects = this.root.find('select.condition-param-SelectItemProperty:empty');
        selects.append(options.join(''))
            .change($.proxy(this, 'onItemPropertySelect'));
        this.reinitializeChosenSelects(selects);

        // generate variable part if the field is known  [must be after reinitializeChosenSelects]
        selects.trigger('change');
    };

    /**
     *
     */
    Instance.generatePropertyFieldConfiguration = function (property) {
        var fields = [];

        switch (property.type) {
            case 'string':
            case 'text':
                fields.push(opString, {name: 'value', type: 'Input'});
                break;
            case 'integer':
                fields.push(opNumber, {name: 'value', type: 'Input', inputType: 'number'});
                break;
            case 'float':
                fields.push(opNumber, {name: 'value', type: 'Input'});
                break;
            case 'select':
            case 'multiselect':
                fields.push(
                    (property.type === 'multiselect' ? opContains : opEqNe),
                    {
                        name: 'value',
                        type: 'SelectFromClassifier',
                        requestParams: {classifier: property.classifier}
                    }
                );
                break;
            case 'boolean':
                fields.push(
                    '<input type="hidden" name="op" value="eq" class="condition-param-value">' + lang.EQUALS,
                    {name: 'value', type: 'SimpleSelect', values: {1: lang.TRUE, 0: lang.FALSE}}
                );
                break;
            case 'datetime':
                fields.push(opDateTime, {name: 'value', type: 'DateTimeInput'});
                break;
        }

        return fields;
    };

    /**
     * Handler for item property select value change
     */
    Instance.onItemPropertySelect = function (event) {
        var select = $(event.target),
            property = this.getItemPropertyData(select.val());

        if (!property) {
            return;
        }

        var conditionContainer = select.closest(".condition"),
            variableContainer = conditionContainer.find(".condition-itemproperty-variable-part"),
            valuesToSet = conditionContainer.data('initialValues'),
            fields = this.generatePropertyFieldConfiguration(property);

        variableContainer.html('');
        this.addFields(variableContainer, fields, valuesToSet);
    };

    /**
     *
     * @param {String} propertyId
     * @returns {Object|null}
     */
    Instance.getItemPropertyData = function (propertyId) {
        var properties = this.itemProperties;
        for (var groupName in properties) {
            for (var i = 0, last = properties[groupName].length; i < last; i++) {
                var field = properties[groupName][i];
                if (field.id == propertyId) {
                    return field;
                }
            }
        }
        return null;
    };

    /**
     *
     */
    Instance.onSelectFromJsonListReady = function (data, url, callbackParams) {
        var selectClass = this.getSelectFromJsonClass(url);
        this.fillSelectsWithOptions('select.' + selectClass + ':empty', data);
    };

})($nc);