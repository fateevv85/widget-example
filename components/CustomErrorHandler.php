<?php

namespace testTaxi\components;

use testTaxi\models\CommonMethods;
use testTaxi\models\testTest;
use Yii;
use yii\web\ErrorHandler;

class CustomErrorHandler extends ErrorHandler
{
    protected function renderException($exception)
    {
        parent::renderException($exception);

        $model = new CommonMethods('custom-error-handler');
        $message = "Произошла ошибка:\n{$exception->getMessage()}";

        if ($exception->getPrevious()) {
            $message .= "\nMessage: {$exception->getPrevious()->getMessage()}
            \n File: {$exception->getPrevious()->getFile()}";
        }

        $model->sendTelegramByProxyList($message);
    }
}