<?=$widgetName?> = function () {
  const widget = this,
    baseUrl = '<?=$baseUrl?>',
    widgetName = '<?=$smallWidgetName?>',
    twigBaseUrl = `${baseUrl}/tpls/${widgetName}`,
    cssFile = `${baseUrl}/css/${widgetName}.css`,
    twig = require('twigjs');

  // логгирование
  this.yadroLog = (data, comment = false) => {
    const commentSub = (comment) ? `[${comment}]` : '';

    yadroFunctions.log(`<?=$widgetCode?>: ${data} ${commentSub}`);

    if (typeof data === 'object') {
      yadroFunctions.log(data);
    }
  };

  // рендер шаблонов
  this.renderTemplate = (templateName, renderParams, htmlCallback) => {
    yadroFunctions.render(`/intr/${widget.code}/${templateName}.twig`, renderParams, htmlCallback);
  };

  // загрузка шаблонов
  this.initTemplates = (templateNames) => {
    const twigTemplates = {};

    for (let i in templateNames) {
      const twigCode = templateNames[i].replace('.twig', '');

      twigTemplates[twigCode] = twigBaseUrl + '/' + templateNames[i];
    }

    yadroFunctions.addTwig(widget.code, twigTemplates);
  };

  // проверка обьекта на пустоту
  this.isEmpty = (obj) => {
    for (let key in obj) {
      if (obj.hasOwnProperty(key))
        return false;
    }
    return true;
  };

  // открытие виджета
  this.renderConfig = (settingsBlock) => {
    widget.renderExportMenu(settingsBlock, 1);

    if (!yadroFunctions.getSettings(widget.code).oldSettings) {
      const oldSettings = JSON.stringify(yadroFunctions.getSettings(widget.code));
      yadroFunctions.setSettings(widget.code, {oldSettings: oldSettings});
    }
  };

  // сравнение обьектов
  this.isEqualObj = (obj1, obj2) => {
    return JSON.stringify(obj1) === JSON.stringify(obj2);
  };

  // сохранение виджета
  this.saveConfig = () => {
    let period = $('.test-taxi-communications-analytics__range-select')
      .find('button.control--select--button')
      .attr('data-value');

    if (period === 'custom') {
      period = $('.test-taxi-communications-analytics__input-range_picker').val();
    }

    if (!period || period <= 0) {
      yadroFunctions.alert('Укажите количество дней', 2);

      return true;
    }

    const checkedItems = {};

    // находим отмеченные группы
    $('.test-taxi-communications-analytics__group-select')
      .find('.control-checkbox.checkboxes_dropdown__label.is-checked')
      .each((index, el) => {
        if (index !== 0) {
          checkedItems[$(el).find('input[name="select_group"]').attr('data-value')] =
            ($(el).find('.checkboxes_dropdown__label_title').attr('title'));
        }
      });

    const currentData = yadroFunctions.getSettings(widget.code),
      emptyInputs = [];

    if (widget.isEmpty(checkedItems)) {
      yadroFunctions.alert('Выберите отделы', 2);

      return true;
    }

    if (currentData.emails) {
      for (let key in checkedItems) {
        if (!currentData.emails[key]) {
          emptyInputs.push(`"${checkedItems[key]}"`);
        }
      }
    }

    if (emptyInputs.length || !currentData.emails) {
      const message = `Укажите email для отправки отчетов ${widget.declOfNum(emptyInputs.length, ['группе', 'группам', 'группам'])} <span class="test-taxi-communications-analytics__alert_group-list">${emptyInputs.join(', ')}</span>`;

      yadroFunctions.alert(message, 2);

      return true;
    }

    // var oldSettings = JSON.parse(currentData.oldSettings);
    // delete oldSettings.settingsTimeStamp;
    const frontendStatus = $('.switcher__checkbox.intr_widget_frontend_status_switcher').is(':checked');

    /*const newSettings = {
      period: period,
      checked: checkedItems,
      emails: currentData.emails,
      settingsTimeStamp: currentData.settingsTimeStamp,
      frontend_status: frontendStatus
    };*/

    const newSettings = {
      emails: currentData.emails,
      period: period,
      checked: checkedItems,
      frontend_status: frontendStatus,
      settingsTimeStamp: currentData.settingsTimeStamp,
    };

    // if (!widget.isEqualObj(oldSettings, newSettings)) {
    if (currentData.oldSettings !== JSON.stringify(newSettings)) {
      newSettings.settingsTimeStamp = new Date().getTime();
      newSettings.oldSettings = undefined;
      /*yadroFunctions.setSettings(widget.code, {
        settingsTimeStamp: new Date().getTime(),
      });*/

      yadroFunctions.setSettings(widget.code, newSettings);
    }

    /*$(this)
      .addClass('test-taxi-communications-analytics__button_inactive')
      .removeClass('button-input_blue');*/

    // console.log(yadroFunctions.getSettings(widget.code));

    if (frontendStatus) {
      $.get(baseUrl + '/communications-analytics')
        .done((response) => {
          let message,
            type;
          const text = response.split(' ')[0];

          switch (text) {
            case 'no-events':
              message = 'События по группам не найдены';
              type = 2;
              break;
            case 'process':
              message = 'Идет экспорт событий. Отчеты будут отправлены на почту';
              type = 1;
              break;
            case 'wait':
              const date = response.match(/\d\d.\d\d.\d{4}/gm)[0];
              message = 'Отчет с текущими настройками уже был выгружен. Следующая отправка отчета: ' + date;
              type = 2;
              break;
          }

          yadroFunctions.alert(message, type);
        });
    }
    /*if (frontendStatus) {
      fetch(baseUrl + '/getEvents.php').then(
        (response) => {
          response.text().then((text) => {
          CODE HERE
            }
            yadroFunctions.alert(message, type);
          });
        },
        (error) => {
          console.log(error);
          yadroFunctions.alert(error, 2);
        });
    }*/
  };

  // рендер списка групп пользователей
  this.groupRender = (checkedItems) => {
    const groups = AMOCRM.constant('groups'),
      groupRender = [];

    for (let key in groups) {
      const group = groups[key];
      let option = false;

      if (checkedItems && checkedItems[key]) {
        option = true;
      }

      groupRender.push({
        id: key,
        option: group,
        is_checked: option
      });
    }

    return groupRender;
  };

  // рендер модального окна
  this.msg_modal = (groupArr) => {
    widget.renderTemplate('msg_modal', {groups: groupArr}, (html) => {
      const winObj = yadroFunctions.renderModal('test-taxi-communications-analytics__email-settings_modal', html, () => {
        let buttonSave = $('#test-taxi-communications-analytics_approval-button');

        widget.setInputEmails();
        buttonSave.addClass('test-taxi-communications-analytics__button_inactive');

        $('.test-taxi-communications-analytics__modal_input').on('change', () => {
          buttonSave
            .removeClass('test-taxi-communications-analytics__button_inactive')
            .addClass('button-input_blue');
        });

        buttonSave.click(() => {
          yadroFunctions.setSettings(widget.code, widget.getInputEmails());
          winObj.destroy();
        });
      });
    });
  };

  // чтение значений emails из инпутов
  this.getInputEmails = () => {
    const inputEmails = {};

    $('.test-taxi-communications-analytics__modal_input').each((index, el) => {
      const emailData = $(el).val();

      inputEmails[$(el).attr('id')] = emailData.match(/(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))/gmi);
    });

    return {'emails': inputEmails};
  };

  // установка значений emails в инпуты
  this.setInputEmails = () => {
    const emailData = yadroFunctions.getSettings(widget.code);

    if (emailData.emails) {
      $('.test-taxi-communications-analytics__modal_input').each((index, el) => {
        const emailStr = ($.isArray(emailData.emails[$(el).attr('id')])) ? emailData.emails[$(el).attr('id')].join(', ') : emailData.emails[$(el).attr('id')];

        $(el).val(emailStr);
      });
    }
  };

  // склонение существительных
  this.declOfNum = (n, titles) => {
    return titles[(n % 10 === 1 && n % 100 !== 11) ? 0 : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2];
  };

  this.loadPeriodNumber = (periodNumber) => {
    $('.test-taxi-communications-analytics__range-select')
      .children('.test-taxi-communications-analytics__range-select_input-range')
      .removeClass('test-taxi-communications-analytics_hidden')
      .find('.test-taxi-communications-analytics__input-range_picker')
      .val(parseInt(periodNumber));

    $('.test-taxi-communications-analytics__input-range_after-title').text(widget.declOfNum(parseInt(periodNumber), ['день', 'дня', 'дней']))
  };

  this.renderExportMenu = (selector, settings = 0) => {
    const storedData = yadroFunctions.getSettings(widget.code);
    let defaultSelect,
      periodNumber;

    if (storedData.period) {
      if ($.isNumeric(storedData.period)) {
        defaultSelect = 'custom';
        periodNumber = storedData.period;
      } else {
        defaultSelect = storedData.period;
      }
    }

    const renderParams = {
      selectPeriod: {
        name: 'select_period',
        class_name: 'test-taxi-communications-analytics__range-select_input-field',
        items: [
          /*{
            id: 'item1',
            option: 'Select'
          },*/
          {
            id: 'day',
            option: 'Раз в день'
          },
          {
            id: 'week',
            option: 'Раз в неделю'
          },
          {
            id: 'month',
            option: 'Раз в месяц'
          },
          {
            id: 'custom',
            option: 'Указать'
          },
        ],
        selected: defaultSelect
      },

      selectGroup: {
        name: 'select_group',
        class_name: 'test-taxi-communications-analytics__group-select_dropdown',
        title_before: 'Группы:',
        title_numeral: "группа, группы, группы, группы, групп",
        title_empty: 'Не выбраны',
        items: widget.groupRender(storedData.checked)
      },

      /*mainSaveButton: {
        id: 'export_save_button',
        text: 'Сохранить',
        class_name: 'test-taxi-communications-analytics__button_inactive'
      },*/

      // groups: widget.groupRender()
    };

    widget.renderTemplate('export_menu', renderParams, (html) => {
      if (settings) {
        selector.append(html);
      } else {
        $(selector).append(html);
      }

      if (periodNumber) {
        widget.loadPeriodNumber(periodNumber);
      }
    })
  };

  // RENDER
  this.render = () => {
    widget.yadroLog('render');

    return true;
  };

  // BIND ACTIONS
  this.bind_actions = () => {
    widget.yadroLog('bind-actions');

    // при клике на пункт "Указать" списка "Период выгрузки"
    $(document).on('click', '.test-taxi-communications-analytics__range-select .control--select--list--item', (el) => {
      const range = $('.test-taxi-communications-analytics__range-select_input-range');

      if ($(el.currentTarget).text() === 'Указать') {
        range.removeClass('test-taxi-communications-analytics_hidden');
      } else {
        range.addClass('test-taxi-communications-analytics_hidden');
      }
    });

    // клик на 'Настройки почты для групп', открываем модалку
    $(document).on('click', '#test-taxi-communications-analytics_email-settings', () => {
      widget.msg_modal(widget.groupRender());
    });

    // при изменении значения кастомного периода меняем склонение
    $(document).on('change', '.test-taxi-communications-analytics__input-range_picker', (el) => {
      const dayTitle = widget.declOfNum($(el.currentTarget).val(), ['день', 'дня', 'дней']);

      $('.test-taxi-communications-analytics__input-range_after-title').text(dayTitle);

      $('#export_save_button')
        .removeClass('test-taxi-communications-analytics__button_inactive')
        .addClass('button-input_blue');
    });

    return true;
  };

  this.init = () => {
    yadroFunctions.logOn(<?=$test?>);
    widget.yadroLog('init');

    // поключение css
    $('<link>', {
      href: `${cssFile}?v=1.0.1`,
      rel: 'stylesheet',
      type: 'text/css',
    }).appendTo('head');

    // поключение шаблонов
    widget.initTemplates([
      'export_menu.twig',
      'msg_modal.twig'
    ]);
  };

  this.bootstrap = (code) => {
    widget.code = code;
    // если frontend_status не задан, то считаем что виджет выключен
    // const status = yadroFunctions.getSettings(code).frontend_status;
    const status = true;

    if (status) {
      widget.yadroLog('after status');
      widget.init();      // поднято для загрузки шаблонов до отрисовки
      widget.render();
      widget.bind_actions();
      $(document).on('widgets:load', () => {
        widget.render();
      });
    }
  };

};

/**
 * @namespace yadroWidget.widgets
 */
yadroWidget.widgets['<?=$widgetCode?>'] = new <?=$widgetName?>();
yadroWidget.widgets['<?=$widgetCode?>'].bootstrap('<?=$widgetCode?>');
