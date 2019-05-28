<?php

namespace testTaxi\controllers;

use testTaxi\models\CommunicationsAnalytics;
use Yii;
use yii\helpers\Json;
use yii\helpers\ArrayHelper;
use yii\web\Response;

class CommunicationsAnalyticsController extends HookHandlerController
{

    public function actionIndex()
    {
        Yii::$app->log->targets['communications-analytics-error']->enabled = true;
        Yii::getLogger()->flushInterval = 1;

        $eventHandler = new CommunicationsAnalytics('communications-analytics', 'dev');
        $pid = '#' . getmypid() . '#';

        $eventHandler->log("---START-{$pid}---");

        $params = $eventHandler->getWidgetParams();
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_JSON;

        $eventHandler->log($params);

        if (!$params) {
            $eventHandler->log('Параметры виджета не получены. Выходим.');
            exit;
        }

        //  list($emails, $period, $checked, $frontendStatus, $settingsTimeStamp) = array_values($params);

        $emails = ArrayHelper::getValue($params, 'emails');
        $period = ArrayHelper::getValue($params, 'period');
        $checked = ArrayHelper::getValue($params, 'checked');
        $frontendStatus = ArrayHelper::getValue($params, 'frontend_status');
        $settingsTimeStamp = ArrayHelper::getValue($params, 'settingsTimeStamp');

        $eventHandler->log('Период выгрузки:' . $period);
        $eventHandler->log('Отмеченные группы:');
        $eventHandler->log($checked);
        $eventHandler->log('Адреса почты: ');
        $eventHandler->log($emails);
        $eventHandler->log('Метка времени: ' . $settingsTimeStamp);
        $eventHandler->log('Статус виджета: ' . $frontendStatus);

        if (!$frontendStatus) {
            $eventHandler->log('Виджет выключен, выходим.');
            exit;
        }

        $period = (is_numeric($period)) ? "$period day" : "1 $period";
        $settingsTimeStampOld = $eventHandler->getSettingsTimeStamp();

        $eventHandler->log('Период выгрузки после обработки: ' . $period);
        $eventHandler->log('Существующая метка времени: ' . $settingsTimeStampOld);

        if (!$settingsTimeStampOld || $settingsTimeStampOld && $settingsTimeStamp > $settingsTimeStampOld) {
            $eventHandler->log('Настройки обновлены, обнуляем последнюю дату старта, обновляем метку времени');

            $eventHandler->setLastStartDate('clear');
            $eventHandler->setSettingsTimeStamp($settingsTimeStamp);
        }

        $eventHandler->log('Запускаем скрипт');

        $response->data = 'process';
        $response->send();

        // для каждой отмеченной группы пользователей получаем отчет за период
        foreach ($checked as $key => $groupTitle) {
            if ($csvLink = $eventHandler->getCSVReport($period, $groupTitle)) {
                $eventHandler->filePath[$key] = $csvLink;
            }
        }

        if (!count($eventHandler->filePath)) {
            $message = 'За период с ' . date('d.m.Y', strtotime("-$period")) .
                ' по ' . date('d.m.Y', strtotime('-1 day')) .
                ' для групп ' . implode(', ', $checked) . ' события не найдены';

            $eventHandler->log($message);

            $eventHandler->sendMail(array_values($emails), $message, 'События не найдены');

            exit;
        }

        $eventHandler->log('Путь к файлам:');
        $eventHandler->log($eventHandler->filePath);

        $newLastDate = $eventHandler->setLastStartDate('date');

        // находим соответствие между почтами для групп и выбранными группами
        $groupsByMail = $eventHandler->getGroupsByMail(array_intersect_key($emails, $checked));

        $message = 'Отчет с ' . date('d.m.Y', strtotime("-$period")) . ' по ' . date('d.m.Y', strtotime('-1 day'));

        foreach ($groupsByMail as $mail => $files) {
            $eventHandler->sendMail($mail, $message, 'default', $files);
        }

        $eventHandler->log("---END-{$pid}---");
    }
}
