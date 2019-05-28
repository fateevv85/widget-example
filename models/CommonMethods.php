<?php

namespace yandexTaxi\models;

use Yii;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;
use Introvert\ApiClient;
use Introvert\Configuration;

class CommonMethods
{
    protected $logName;
    public $api;

    public function __construct($logName, $type = null)
    {
        if ($type) {
            $this->api = CommonMethods::getIntrovertApi($type);
        }

        $this->logName = $logName;
    }

    public function log($data, $type = 'info')
    {
        $name = $this->logName;

        if ($type == 'info') {
            Yii::info($data, $name);
        } elseif ($type == 'warning') {
            Yii::warning($data, $name);
        }
    }

    public static function writeToFile($data, $fileName)
    {
        $dataFile = Yii::getAlias('@webroot') . "/data/$fileName.json";

        static::deleteProp($data);

        json_encode($data);

        if (json_last_error() == JSON_ERROR_NONE) {
            $stringData = Json::encode($data);

            $fh = fopen($dataFile, 'w') or die('can\'t open file');
            fwrite($fh, $stringData);
            fclose($fh);

            $message = $stringData;
        } else {
            $message = 'error in json';
        }

        return $message;
    }

    public static function readFromFile($fileName, $parse = true)
    {
        $dataFile = Yii::getAlias('@webroot') . "/data/$fileName.json";

        if (!file_exists($dataFile)) {
            return false;
        }

        $loadData = file_get_contents($dataFile);

        if ($parse) {
            json_decode($loadData);

            if (json_last_error() == JSON_ERROR_NONE) {
                $loadData = Json::decode($loadData);
            }
        }

        return $loadData;
    }

    public static function createFile($fileName)
    {
        $path = Yii::getAlias('@webroot') . "/data/$fileName.json";
        $fp = fopen($path, 'w');
        fclose($fp);
    }

    public static function deleteProp(array &$arr)
    {
        if (!is_array($arr)) {
            return 'not an array';
        }

        foreach ($arr as $key => &$value) {
            //перебираем массив, если значение - массив, то обрабатываем его нашей функцией
            if (is_array($value)) {
                static::deleteProp($value);
            } else {
                if ($value === 'DELETE') {
                    unset($arr[$key]);
                }
            }
        }
    }

    public static function getIntrovertApi(string $type = 'dev')
    {
        Configuration::getDefaultConfiguration()->setApiKey('key', ArrayHelper::getValue(Yii::$app->params, "$type.apiKey"));

        return new ApiClient();
    }

    public static function setCache($data, $key, $duration)
    {
        $cache = Yii::$app->cache;
        $cache->set($key, $data, $duration);

        return $data;
    }

    public static function getCache($key)
    {
        $cache = Yii::$app->cache;
        $data = $cache->get($key);

        if (!$data) {
            return false;
        }

        return $data;
    }

    public function sendTelegramMessage($message, $proxy, $chatID = '-1001463790616', $token = null)
    {
        $isOn = ArrayHelper::getValue(Yii::$app->params, 'telegram.isOn');
        $chatID = $chatID ?? ArrayHelper::getValue(Yii::$app->params, 'telegram.chatId');
        $token = $token ?? ArrayHelper::getValue(Yii::$app->params, 'telegram.token');

        if (!$isOn) {
            return false;
        }

        $url = "https://api.telegram.org/bot" . $token . "/sendMessage?chat_id=" . $chatID;
        $url = $url . "&text=" . urlencode($message);
        $ch = curl_init();
        $optArray = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => $proxy,
            CURLOPT_CONNECTTIMEOUT_MS => 1000
        ];

        curl_setopt_array($ch, $optArray);
        $result = curl_exec($ch);
        curl_close($ch);

        return json_decode($result);
    }

    public function sendTelegramByProxyList($message)
    {
        $isOn = ArrayHelper::getValue(Yii::$app->params, 'telegram.isOn');

        if (!$isOn) {
            return false;
        }

        $startTime = microtime(true);

        $proxyList = Yii::$app->cache->getOrSet('proxyList', function () {
            return $this->getProxyList();
        });

        if (count($proxyList) < 15) {
            $moreProxy = $this->getProxyList();
            $proxyList += $moreProxy;
        }

        $proxyList = array_filter($proxyList, function ($value) {
            return $value !== '';
        });

        $inactiveProxy = [];
        $sendMessage = false;

        $this->log('Пробуем отправить сообщение, прокси-серверов в массиве: ' . count($proxyList));

        // todo если прокси закончились, а сообщение не отправлено
        if (is_array($proxyList)) {
            for ($i = 1; $i <= 3; $i++) {
                $timeToWait = $i * 1000;

                foreach ($proxyList as $key => $proxy) {
                    $result = $this->sendTelegramMessage($message, $proxy, $timeToWait);

                    if ($result && $result->ok) {
                        $this->log('Сообщение успешно отправлено, время ожидания ответа: ' . $timeToWait);

                        $sendMessage = true;

                        break 2;
                    } else {
                        $inactiveProxy[] = $proxy;
                    }
                }
            }
        }

        $newList = array_values(array_diff($proxyList, $inactiveProxy));

        Yii::$app->cache->set('proxyList', $newList);

        if ($sendMessage) {
            $totalTime = microtime(true) - $startTime;

            $this->log("Отправка сообщения заняла $totalTime c");
        } else {
            $this->log("Сообщение отправлено не было");
        }


        return $result ?? null;
    }

    private function getProxyList()
    {
        $url = 'http://www.freeproxy-list.ru/api/proxy?anonymity=false&token=demo';
        $ch = curl_init();
        $optArray = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
        ];

        curl_setopt_array($ch, $optArray);
        $result = curl_exec($ch);
        curl_close($ch);

        return explode("\n", $result);
    }

    public function sendPrivateMessage($message, $subject)
    {
        $result = $this->sendTelegramByProxyList($message);

        if ($result) {
            $this->log('Результат отправки сообщения в телеграм:');
            $this->log($result);

            $result = json_decode($result);
        }

        if (ArrayHelper::getValue($result, 'ok')) {
            $this->log('Сообщение отправлено');
        } else {
            $this->log('Проблемы с отправкой на телеграм:');
            $this->log($result);

            self::sendMail('v.fateev@introvert.bz', $message, $subject);
        }
    }

    public function sendMail($email, $msg, $subject, $file = null)
    {
        $this->log('Отправляем письмо');
        $api = $this->api;

        if (!$api) {
            $this->log('Нет конфига для апи');
            return;
        }

        $this->log('Формируем сообщение');

        $mail = [
            'msg' => $msg,
            'subject' => $subject,
            'from' => [
                'email' => 'no-reply@taxi.yandex.ru'
            ],
            'to' => [
                ['email' => $email]
            ],
            'additional_data' => [
                'auth_data' => [
                    'apiKey' => 'BGsd2TizpCsYEA3nt7NGvw'
                ],
                'service' => 'mandrill'
            ]
        ];

        if ($file) {
            $mail['attachments'] = $file;
        }

        $this->log('Отправляем');

        try {
            $this->log($mail);

            $mail = $api->mail->send($mail);

            $this->log('Результат отправки сообщения');
            $this->log($mail);

        } catch (Exception $e) {
            $this->log('Exception when calling mail->send: ' . $e->getMessage());
        }
    }

    protected function amoRequest($request)
    {
        try {
            $result = ArrayHelper::getValue($request, 'result');
        } catch (\Exception $exception) {
            $this->log('Ошибка при запросе в АМО: ');
            $this->log($exception);

//            Yii::debug($exception, __METHOD__);
        }

        return $result ?? null;
    }

}
