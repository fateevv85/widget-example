<?php

namespace testTaxi\models;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Yii;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;

class CommunicationsAnalytics extends CommonMethods
{
    public $filePath = [];
    private $taskTypes;
    private $date = null;
    private $filterBy;
    private $totalCount;
    private $callText;
    private $type;
    private $commonParams;
    private $widgetParams;
    private $httpClient;
    private $subDomain;
    private $accountId;
    private $cache;
    private $groupTitle = null;
    private $users;
    private $mailToken;
    private $notes = [];
    private $mailboxes = [];
    private $accountMailboxId = 51608;

    public function __construct($logName, $type = null)
    {
        parent::__construct($logName, $type);

        $this->type = $type ?? 'dev';
        $this->commonParams = ArrayHelper::getValue(Yii::$app->params, $this->type);
        $this->widgetParams = ArrayHelper::getValue(Yii::$app->params, 'communications-analytics');
        $this->httpClient = new Client(['http_errors' => false]);

        $cache = Yii::$app->cache;
        $this->cache = $cache;

        $accountInfo = $cache->getOrSet('accountInfo', function () {
            return $this->testRequest($this->api->account->info());
        }, 3600);
        $timezone = ArrayHelper::getValue($accountInfo, 'timezone');
        date_default_timezone_set($timezone);

        $this->subDomain = ArrayHelper::getValue($accountInfo, 'subdomain');
        $this->accountId = ArrayHelper::getValue($accountInfo, 'id');

        $taskTypes = ArrayHelper::getValue($accountInfo, 'task_types');
        $this->taskTypes = ArrayHelper::index($taskTypes, 'id');

        $this->mailToken = $this->getMailToken();
    }

    /**
     * Создает CSV файл с обработанным списком событий
     * @param null $date период выгрузки
     * @param null $groupTitle название группы пользователей
     * @return string ссылка на CSV файл
     * @throws \Introvert\ApiException
     */
    public function getCSVReport($date = null, $groupTitle = null): string
    {
        $this->log('test1');
        ini_set('memory_limit', '1024M');
        ini_set('max_execution_time', 180000);
        $this->groupTitle = $this->filterBy = $groupTitle;
        $this->log('Current group: ' . $groupTitle);
        $this->users = ArrayHelper::index($this->getUsers($this->groupTitle), 'id');

        $this->log('users:');
        $this->log($this->users);

        $mailboxes = $this->getMailboxes();

        $usersMailboxes = array_reduce($mailboxes, function ($carry, $mailbox) {
            $userId = $this->getUserByMailbox($mailbox['id']);
            $carry[$mailbox['email']] = $userId;
            return $carry;
        }, []);

        $this->log('mailboxes: ');
        $this->log($usersMailboxes);
        $this->mailboxes = $mailboxes;
        $this->usersMailboxes = $usersMailboxes;

        $fileData = $this->getCSVFile();

        $dateFrom = strtotime("-$date midnight");
        $dateTo = strtotime('now');
        $this->log('date from: ' . $dateFrom . ' date to: ' . $dateTo);
        $this->date = $dateFrom ? date('d.m.Y', $dateFrom) : $date;

        // получаем все события за указанный период из аналитики
        $eventsArray = $this->getEvents($dateFrom, $dateTo, $this->groupTitle);

        // получаем события создания задач за период
        $createdTaskEntities = $this->getTasksByPeriod($dateFrom, $dateTo);

        // формируем массив, аналогичный массиву из аналитики
        $createTaskEvents = $this->getCreateTaskEventsArray($createdTaskEntities);

        // получаем поля связанных компаний для событий и описание типов задач
        $communicationArray = $this->processingCommunicationAnalytics($eventsArray, $createdTaskEntities);

        // обьединяем все задачи в один массив
        $allEvents = array_merge($eventsArray, $createTaskEvents);

        // фильтруем ненужные события
        $allEvents = array_filter($allEvents, function ($event) {
            $filterItems = [
                'Сообщение кассиру',
                'Входящее СМС',
                'Добавлен новый файл',
                'Добавлена покупка',
                'Добавление в ретаргетинг',
                'Заход на сайт',
                'Изменение поля',
                'Изменения этапа покупателя',
                'Изменение этапа продажи',
                'Исходящее СМС',
                'Компания восстановлена',
                'Компания удалена',
                'Контакт восстановлен',
                'Контакт удален',
                'Новая компания',
                'Новая сделка',
                'Новое примечание',
                'Новый контакт',
                'Новый покупатель',
                'Ответ робота',
                'Открепление',
                'Покупатель удален',
                'Прикрепление',
                'Результат по задаче',
                'Сделка восстановлена',
                'Сделка удалена',
                'Смена ответственного',
                'Теги добавлены',
                'Теги убраны',
                'Тема вопроса определена',
                'Удаление из ретаргетинга',
            ];
            forEach ($filterItems as $filterItem) {
                $nameEvent = ArrayHelper::getValue($event, 'event');
                if (stripos($nameEvent, $filterItem) === 0) {
                    return false;
                }
            }
            return true;
        });

        // обработка массива событий для экспорта в Csv
        $resultEventsArray = $this->handleEventsForCsv($allEvents, $communicationArray);


        // записываем результат в таблицу
        $this->writeCSVReport($fileData, $resultEventsArray);

        // записываем события почты
        $this->saveMailsEvents($fileData, $dateFrom, $dateTo);

        $csvLink = $this->getLinkFile($fileData);
        return $csvLink;
    }

    private function sendToTest($data)
    {
        $result = [];
        try {
            $result = $this->testRequest($this->api->yadro->sendToTest($data));
        } catch (Exception $e) {
            $this->log('Ошибка: ' . $e->getMessage());
            $this->log($data);
        }
        return $result;
    }

    private function makeHttpRequest(array $requestBody)
    {
        try {
            $response = $this->httpClient->request(...$requestBody);
            $result = json_decode($response->getBody(), true) ?? false;
        } catch (RequestException $e) {
            $this->log('Ошибка при при получении почты');
            $this->log($e->getMessage() . PHP_EOL . $e->getTraceAsString());
            return [];
        }

        return $result;
    }

    public function getResponsibleByEntity(int $entityId, string $entityType)
    {
        $entity = [];
        if ($entityType === 'lead') {
            $entity = $this->api->lead->getById($entityId);
        } elseif ($entityType === 'contact') {
            $entity = $this->api->contact->getById($entityId);
        } elseif ($entityType === 'company') {
            $entity = $this->api->company->getById($entityId);
        }
        if (ArrayHelper::getValue($entity, 'code')) {
            $dataEntity = ArrayHelper::getValue($entity, 'result');
            $responsibleId = ArrayHelper::getValue($dataEntity, 'responsible_user_id');
            $result = ArrayHelper::getValue($this->users, $responsibleId);
            return $result;
        }

        return [];
    }

    /**
     * Обработка писем, незавершено
     */
    public function saveMailsEvents(array $fileData, int $dateFrom, int $dateTo)
    {
        $this->log('filter date: ' . $dateFrom . ' - ' . $dateTo);

        $threads = $this->getAllThreadsByDate($dateFrom);
        $threads = array_merge(ArrayHelper::getValue($threads, 'inbox', []), ArrayHelper::getValue($threads, 'sent', []));
        $this->log('count threads: ' . count($threads));

        $this->log('----------------check--------------');
        $this->log(ArrayHelper::getValue($threads, 0));
        $this->log(end($threads));
        $this->log('----------------check_end--------------');

        // обрабатываем треды
        $allMails = [];
        $allEntitiesToRequest = [];
        foreach ($threads as $thread) {
            $this->log($thread);
            $from = ArrayHelper::getValue($thread, 'last_message.from.0.email');
            $to = ArrayHelper::getValue($thread, 'last_message.to.0.email');
            $this->log($thread['id'] . '-' . $thread['mailbox_id'] . '-' . $thread['date'] . '-' . $from . '-' . $to);
            $mailboxId = ArrayHelper::getValue($thread, 'mailbox_id');
            $userId = $this->getUserByMailbox($mailboxId);

            $this->log('mailbox: ' . $mailboxId);
            $this->log('userId: ' . $userId);

            $isCorrectUser = ArrayHelper::keyExists($userId, $this->users);
            $isAccountMailbox = $mailboxId === $this->accountMailboxId;
            $isCurrentDate = $thread['date'] <= $dateTo;

            $this->log('filtering thread: ' . $isCorrectUser . ' - ' . $isAccountMailbox . ' - ' . $isCurrentDate);
            if (!$isCorrectUser && !$isAccountMailbox) {
                $this->log('thread filtered');
                continue;
            }

            if ($isAccountMailbox) {
                $userFrom = ArrayHelper::getValue($this->usersMailboxes, $from);
                $userTo = ArrayHelper::getValue($this->usersMailboxes, $to);
                if (!ArrayHelper::getValue($this->users, $userFrom) && !ArrayHelper::getValue($this->users, $userTo)) {
                    $this->log('thread filtered by emails');
                    continue;
                }
            }

            $threadId = $thread['id'];
            $mailsByDate = $this->getMailByDate($threadId, $dateFrom, $dateTo);
            $requiredMails = ArrayHelper::getValue($mailsByDate, 'requiredMails');
            $this->log('required mails:');
            $this->log($requiredMails);

            // массив связааных сущностей
            $entitiesToRequest = ArrayHelper::getValue($mailsByDate, 'entitiesToRequest');

            $allEntitiesToRequest = array_merge_recursive($allEntitiesToRequest, $entitiesToRequest);
            $allMails = array_merge($allMails, $requiredMails);
        }

        // получаем ИД связанных компаний и массив соответствия ИД сущности =Ю ИД компании
        $companiesIdsToRequest = [];
        $entitiesAccordance = [];
        foreach ($allEntitiesToRequest as $entityType => $ids) {
            if (($entityType == 'companies')) {
                $companiesIds = $ids;
            } else {
                $companiesIds = $this->getLinkedCompanies($entityType, $ids);
                $entitiesAccordance += $companiesIds;
            }

            $companiesIdsToRequest = array_merge($companiesIdsToRequest, $companiesIds);
        }

        // получаем сущности компаний
        $companyEntities = $this->getCompaniesEntities($companiesIdsToRequest);
        // получаем необходимые для отчета поля
        $requireFieldsArray = $this->getRequiredFieldsArray($companyEntities);
        $companyEntities = ArrayHelper::index($companyEntities, 'id');

        $companiesNotReceived = array_diff_key(array_flip($companiesIdsToRequest), $companyEntities);
        $countUniqCompaniesId = count(array_unique($companiesIdsToRequest));
        $countCompaniesEntities = count($companyEntities);

        // получаем результирующий массив, ИД сущности =Ю необходимые поля связанной компании
        $resultCompanyFieldsArray = [];
        foreach ($entitiesAccordance as $entityId => $companyId) {
            if (isset($requireFieldsArray[$companyId])) {
                $resultCompanyFieldsArray[$entityId] = ArrayHelper::getValue($requireFieldsArray, $companyId);
            }
        }

        $resultCompanyFieldsArray += $requireFieldsArray;

        // фильтруем по дате
        $allMails = array_filter($allMails, function ($mail) use ($dateFrom, $dateTo) {
            $date = ArrayHelper::getValue($mail, 'date');
            return $date >= $dateFrom && $date < $dateTo;
        });

        // получаем события
        $eventsMail = [];
        forEach($allMails as $mail) {
            $this->log('Mail:');
            $this->log($mail);

            $ccMail = ArrayHelper::getValue(ArrayHelper::getValue($mail, 'cc'), 'mail');
            $sent = ArrayHelper::getValue($mail, 'sent');
            $mail['type'] = ($ccMail && preg_match('/@mail.test.ru/', $ccMail)) || $sent ? 'sent' : 'inbox';

            $entities = $mail['type'] === 'inbox' ? ArrayHelper::getValue($mail, 'from') : ArrayHelper::getValue($mail, 'to');
            forEach($entities as $entity) {
                $this->log('entity:');
                $this->log($entity);
                $dateCreate= date('d.m.Y H:i:s', ArrayHelper::getValue($mail, 'date'));
                $clientEmail = ArrayHelper::getValue($entity, 'email');
                $event = $mail['type'] === 'inbox' ? 'Входящее письмо' : 'Исходящее письмо';

                // фильтруем повторяющиеся
                $alreadyExist = current(array_filter($eventsMail, function($element) use ($dateCreate, $event, $clientEmail) {
                    return $element['date_create'] === $dateCreate &&
                        $element['client_email'] === $clientEmail &&
                        $element['event'] === $event;
                }));
                if ($alreadyExist) {
                    continue;
                }

                $nameEntities = [
                    'contact' => 'Контакт',
                    'company' => 'Компания',
                    'lead' => 'Сделка',
                ];

                $urlData = explode('/', ArrayHelper::getValue($entity, 'url'));
                end($urlData);
                $entityId = current($urlData);

                $companyFields = ArrayHelper::getValue($resultCompanyFieldsArray, $mail['id']);

                // формируем данные для отчета
                $objectName = ArrayHelper::getValue($nameEntities, $entity['type']);

                $testEmail = $mail['type'] === 'inbox' ?
                    ArrayHelper::getValue($mail, 'to.0.email') :
                    ArrayHelper::getValue($mail, 'from.0.email');

                $userId = ArrayHelper::getValue($this->usersMailboxes, $testEmail);

                $testUser = ArrayHelper::getValue($this->users, $userId);
                $this->log('test USER:');
                $this->log('test: ' . $testEmail . ' - ' . $userId);
                $this->log($testUser);
                $author = ArrayHelper::getValue($testUser, 'title');

                $subDomain = $this->subDomain;
                $link = "https://$subDomain.test.ru" . ArrayHelper::getValue($entity, 'url', '');
                $name = ArrayHelper::getValue($entity, 'name');
                $companyName = ArrayHelper::getValue($companyFields, 'name');
                $companyInn = ArrayHelper::getValue($companyFields, 'inn');
                $companyLink = ArrayHelper::getValue($companyFields, 'link');
                $companyBrandName = ArrayHelper::getValue($companyFields, 'brand_name');

                $handledEvent = [
                    'date_create' => $dateCreate,
                    'author' => $author,
                    'object' => $objectName,
                    'link' => $link,
                    'entity_name' => ($name) ? $this->removeEscapeCharacter($name) : '',
                    'event' => $event,
                    'value_before' => '',
                    'value_after' => '',
                    'task_type' => '',
                    'task_responsible' => '',
                    'phone_number' => '',
                    'call_status' => '',
                    'call_duration' => '',
                    'linked_company_name' => $companyName,
                    'linked_company_inn' => $companyInn,
                    'linked_company_link' => $companyLink,
                    'linked_company_Brand-name' => $companyBrandName,
                    'client_email' => $clientEmail,
                    'test_email' => $testEmail,
                ];

                $this->log('event:');
                $this->log($handledEvent);

                $eventsMail[] = $handledEvent;
            }
        }

        $this->log('count events mail: ' . count($eventsMail));
        $this->writeCSVReport($fileData, $eventsMail);
    }

    /**
     * Получение и кэширование токена почты
     * @param int $duration
     * @return mixed
     */
    private function getMailToken($duration = 3600)
    {
        return $this->cache->getOrSet('token', function () {
            $data = [
                'url' => '/ajax/settings/profile/',
                'method' => 'GET',
                'headers' => [
                    'X-Requested-With: XMLHttpRequest',
                ],
            ];

            return ArrayHelper::getValue($this->sendToTest($data), 'response.params.arUser.test_api_key');
        }, $duration);
    }

    public function getMailboxes($duration = 3600)
    {
        return $this->cache->getOrSet('mailboxes', function () {
            return $this->testRequest($this->api->mail->getMailboxes());
        }, $duration);
    }

    public function getAccountUsers($duration = 3600)
    {
        return $this->cache->getOrSet('accountUsers', function () {
            return $this->testRequest($this->api->account->users());
        }, $duration);
    }

    /**
     * Получение ИД пользователя по ИД ящика
     * @param int $mailboxId
     * @param string|null $userName
     * @return int|string
     */
    public function getUserByMailbox(int $mailboxId, string $userName = null)
    {
        $mailboxes = $this->getMailboxes();
        $users = $this->getAccountUsers();
        $mailboxes = ArrayHelper::index($mailboxes, 'id');
        $userId = ArrayHelper::getValue($mailboxes, "$mailboxId.user_id");

        if (!$userId && $userName) {
            foreach ($users as $id => $user) {
                if (ArrayHelper::getValue($user, 'name') == $userName) {
                    $userId = $id;
                    break;
                }
            }
        }

        return $userId;
    }

    /**
     * Получение трэдов для входящих и исходящих писем за период
     * @param string $date
     * @return array
     */
    public function getAllThreadsByDate(int $date)
    {
        $inboxMail = $this->getThreadTypeByDate($date, 'inbox', 200);
        $sentMail = $this->getThreadTypeByDate($date, 'sent', 200);

        $inboxMail = ArrayHelper::index($inboxMail, 'id');
        $sentMail = ArrayHelper::index($sentMail, 'id');

        return [
            'inbox' => $inboxMail,
            'sent' => $sentMail
        ];
    }

    /**
     * Получение трэдов для исходящих / входящих за период
     * @param string $date дата в относительном формате ("1 day")
     * @param int $limit Количество получаемых писем за один запрос
     * @param string $type ("inbox" || "sent")
     * @return array
     */
    public function getThreadTypeByDate(int $date, string $type = 'inbox', int $limit = null, int $counter = 0): array
    {
        $this->log(__FUNCTION__);

        if (!in_array($type, ['sent', 'inbox'])) {
            $this->log('Некорректный тип почты');

            exit;
        }

        if ($limit <= 0 || $limit > 200) {
            $this->log('Лимит должен быть больше 0 и меньше 200');

            exit;
        }

        if (!$date) {
            $this->log('Не указан период выгрузки почты');

            exit;
        }

        $dateFrom = $date;
        $dateTo = strtotime('now');
        $textDate = date('d.m.Y H:i:s', $dateFrom);
        $dateFilter = "&filter_date_from=$dateFrom&filter_date_to=$dateTo&useFilter=y";

        $this->log('date filter: ' . $dateFilter);

        if (!$this->mailToken) {
            $this->log('Токен для почты не найден');

            exit;
        }

        $limitString = ($limit) ? "&limit=$limit" : '';
        $limitString . $dateFilter; */
        $url = "https://test/api/v2/{$this->accountId}/threads?page=%d&type={$type}&fields[]=message";
        $mailFilteredByDate = [];

        $lastTimeItem = 0;
        $i = 0;
        do {
            $requestBody = [
                'GET',
                sprintf($url, $i),
                [
                    'headers' => [
                        'X-Auth-Token' => $this->mailToken,
                        'X-Requested-With' => 'XMLHttpRequest'
                    ]
                ]
            ];

            $result = $this->makeHttpRequest($requestBody);

            if (!$result) {
                $this->log('Ошибка запроса писем, page: ' . $i);
                $this->log($result);
                return [];
            }

            $mailArray = ArrayHelper::getValue($result, 'items');

            if (!is_array($mailArray)) {
                $this->log('Не получен массив писем');
            } else {
                $mailFilteredByDate = array_merge($mailFilteredByDate, $mailArray);
            }
            $i += 1;
            $lastItem = end($mailArray);
            if (!$lastItem) {
                $lastTimeItem = 0;
            } else {
                $lastTimeItem = ArrayHelper::getValue($lastItem, 'date');
            }
            $this->log('lastTimeItem: ' . $lastTimeItem);
            $this->log('dateFrom: ' . $dateFrom);
            sleep(1);

        } while ($lastTimeItem >= $dateFrom);

        $this->log('Type: ' . $type);
        $this->log('Total: ', ArrayHelper::getValue($result, 'total'));
        $this->log("Emails by date from $textDate: ", count($mailFilteredByDate));

        return $mailFilteredByDate;
    }

    /**
     * Получение писем трэда за период времени, одновременно получаем массив
     * связанных с письмом сущностей, для последующей обработки
     * @param int $threadId
     * @param string $date
     * @return array
     */
    public function getMailByDate(int $threadId, int $dateFrom, int $dateTo): array
    {
        $mailArray = $this->getMailByThread($threadId);
        $requiredMails = [];
        $entitiesToRequest = [];

        foreach ($mailArray as $mail) {
            $mailId = ArrayHelper::getValue($mail, 'id');
            $mailDate = ArrayHelper::getValue($mail, 'date');
            $sent = ArrayHelper::getValue($mail, 'sent');
            $from = ArrayHelper::getValue($mail, 'from.0');
            $to = ArrayHelper::getValue($mail, 'to.0');

            $linkedEntity = ($sent) ? $to : $from;

            $entityName = ArrayHelper::getValue($linkedEntity, 'name');
            $entityType = ArrayHelper::getValue($linkedEntity, 'type');
            $entityUrl = ArrayHelper::getValue($linkedEntity, 'url');

            if ($mailDate > $dateFrom && $mailDate < $dateTo) {
                $requiredMails[] = $mail;

                if ($entityUrl) {
                    preg_match('#\d{6,}#', $entityUrl, $matches);
                    $entityId = (int)ArrayHelper::getValue($matches, '0');
                }

                if (isset($entityId) && $entityType && in_array($entityType, ['lead', 'contact', 'company'])) {
                    $entitiesToRequest[$entityType][$mailId] = $entityId;
                }
            }
        }

        return [
            'requiredMails' => $requiredMails,
            'entitiesToRequest' => $entitiesToRequest
        ];
    }

    /**
     * Получение писем для трэда
     * @param int $threadId
     * @return array
     */
    public function getMailByThread(int $threadId): array
    {
        $url = "https://test/api/v2/{$this->accountId}/threads/$threadId/messages?page=1&limit=200";

        $requestBody = [
            'GET',
            $url,
            [
                'headers' => [
                    'X-Auth-Token' => $this->mailToken,
                    'X-Requested-With' => 'XMLHttpRequest'
                ]
            ]
        ];

        $resultRequest = $this->makeHttpRequest($requestBody);
        $result = ArrayHelper::getValue($resultRequest, 'items');
        if (!is_array($result)) {
            return [];
        }

        return $result;
    }

    private function getAllCompanies(array $companiesId)
    {
        return $this->testRequest($this->api->company->getAll($companiesId));
    }

    private function getAllLeads(array $leadsId)
    {
        return $this->testRequest($this->api->lead->getAll(null, null, $leadsId));
    }

    private function getAllContacts(array $contactsId)
    {
        return $this->testRequest($this->api->company->getAll($contactsId));
    }

    /**
     * Связано ли событие с задачей
     * @param string $eventType
     * @return bool
     */
    private function isTaskEvent(string $eventType): bool
    {
        return stripos($eventType, 'задач') || stripos($eventType, 'task');
    }

    /**
     * Обрабатывает массив событий: находит связанные компании для сущностей из аналитики
     * @param array $eventsArray массив событий
     * @param array $createdTaskEntities массив созданных задач (их нет в аналитике)
     * @throws \Introvert\ApiException
     * @return array массив требуемых полей компаний (название компании, ссылка, значение доп полей ИНН и Brand name ), массив типов задач
     */
    private function processingCommunicationAnalytics(array $eventsArray, array $createdTaskEntities): array
    {
        $entitiesToRequest = [];
        $companiesIdsToRequest = [];
        $entitiesAccordance = [];
        $resultCompanyFieldsArray = [];
        $taskIdsToRequest = [];
        $taskIdsToRequestFromEntities = [];

        foreach ($eventsArray as $event) {
            $entityInfo = ArrayHelper::getValue($event, 'object.lead');
            $entityId = ArrayHelper::getValue($entityInfo, 'id');
            $entityType = ArrayHelper::getValue($entityInfo, 'entity');
            $entityEvent = ArrayHelper::getValue($event, 'event');
            $dateCreate = ArrayHelper::getValue($event, 'date_create');

            if ($entityId && $entityType) {
                // получаем ИД задач
                if ($entityType == 4) {
                    $taskIdsToRequest[$entityId] = $entityId;
                } else {
                    // ИД сущностей для запроса компаний
                    $entitiesToRequest[$entityType][$entityId] = $entityId;

                    // Получение ИД сущностей из событий задач, где не указан ИД задачи
                    if ($this->isTaskEvent($entityEvent)) {
                        $timeStamp = strtotime($this->getCreateDate($dateCreate));
                        $taskIdsToRequestFromEntities[$timeStamp] = $entityId;
                    }
                }
            }
        }


        $this->log('count($eventsArray): ' . count($eventsArray));
        $this->log('Сущности в массиве для запроса сущностей: ');
        $this->log(array_keys($entitiesToRequest));

        if (isset($entitiesToRequest['leads'])) {
            $this->log('count($entitiesToRequest[leads]): ' . count($entitiesToRequest['leads']));
        }
        if (isset($entitiesToRequest['contacts'])) {
            $this->log('count($entitiesToRequest[contacts]): ' . count($entitiesToRequest['contacts']));
        }
        if (isset($entitiesToRequest['companies'])) {
            $this->log('count($entitiesToRequest[companies]): ' . count($entitiesToRequest['companies']));
        }

        $this->log('count($taskIdsToRequest): ' . count($taskIdsToRequest));

        // получаем все сущности задач
        $taskEntities = $this->getAllTasksById($taskIdsToRequest);

        // Получение задач из событий, где указаны только ИД родительских сущностей
        $chunkedTaskIds = array_chunk($taskIdsToRequestFromEntities, 500);
        $tasksFromEntities = [];
        $requestedTasks = [];

        foreach ($chunkedTaskIds as $taskIdsArray) {
            $tasksFromEntities = array_merge($tasksFromEntities, $this->getAllTasks(['element_id' => $taskIdsArray]));
        }

        foreach ($tasksFromEntities as $task) {
            $lastModified = ArrayHelper::getValue($task, 'last_modified');
            $elementId = ArrayHelper::getValue($task, 'element_id');
            $entityId = ArrayHelper::getValue($taskIdsToRequestFromEntities, $lastModified);

            if ($entityId == $elementId) {
                $task += ['requested_from_another_entity' => true];
                $requestedTasks[] = $task;
            }
        }

        $taskTypesArray = [];

        // получаем массив соответствия ИД сущности из события задачи и типа задачи
        foreach (array_merge($taskEntities, $requestedTasks) as $taskEntity) {
            $id = ArrayHelper::getValue($taskEntity, 'id');
            $type = ArrayHelper::getValue($taskEntity, 'task_type');
            $text = ArrayHelper::getValue($taskEntity, 'text', '');
            $fromAnotherEntity = ArrayHelper::getValue($taskEntity, 'requested_from_another_entity');
            $elementId = ArrayHelper::getValue($taskEntity, 'element_id');


            $taskTypesArray[($fromAnotherEntity) ? $elementId : $id] = [
                'type' => $this->getTaskType($type),
                'text' => $text,
            ];
        }

        $this->log('count($taskEntities): ' . count($taskEntities));
        $this->log('count($taskCreate): ' . count($createdTaskEntities));

        // обьединяем массив задач из событий аналитики с событиями создания задач
        $allTasks = array_merge($taskEntities, $createdTaskEntities);

        $this->log('count($allTasks): ' . count($allTasks));

        // получаем сущности, связанные с задачами
        $entitiesToRequestFromTasks = $this->getTasksLinkedEntitiesId($allTasks);

        // обьединяем их сущностями их событий аналитики
        $entitiesToRequest = ArrayHelper::merge($entitiesToRequest, $entitiesToRequestFromTasks);

        $this->log('После обьединения с сущностями из задач');

        // получаем ИД связанных компаний и массив соответствия ИД сущности =Ю ИД компании
        foreach ($entitiesToRequest as $entityType => $ids) {
            $this->log("$entityType: " . count($ids));

            if (($entityType == 'companies')) {
                $companiesIds = $ids;
            } else {
                $companiesIds = $this->getLinkedCompanies($entityType, $ids);
                $entitiesAccordance += $companiesIds;
            }

            $companiesIdsToRequest = array_merge($companiesIdsToRequest, $companiesIds);
        }

        $this->log('before: ' . count($entitiesAccordance));
        $this->log('Количество ИД компаний: ' . count($companiesIdsToRequest));
        $this->log('Количество элементов массива соответствия сущностей: ' . count($entitiesAccordance));

        // получаем сущности компаний
        $companyEntities = $this->getCompaniesEntities($companiesIdsToRequest);
        // получаем необходимые для отчета поля
        $requireFieldsArray = $this->getRequiredFieldsArray($companyEntities);
        $companyEntities = ArrayHelper::index($companyEntities, 'id');

        $companiesNotReceived = array_diff_key(array_flip($companiesIdsToRequest), $companyEntities);
        $countUniqCompaniesId = count(array_unique($companiesIdsToRequest));
        $countCompaniesEntities = count($companyEntities);

        $this->log('Не получено сущностей компаний:');
        $this->log($companiesNotReceived);
        $this->log('Количество уникальных ИД компаний: ' . $countUniqCompaniesId);
        $this->log('Количество сущностей компаний: ' . $countCompaniesEntities);
        $this->log('Не найдено компаний по Id: ' . ($countUniqCompaniesId - $countCompaniesEntities));
        $this->log('Количество элементов массива требуемых полей компаний: ' . count($requireFieldsArray));

        // получаем результирующий массив, ИД сущности =Ю необходимые поля связанной компании
        foreach ($entitiesAccordance as $entityId => $companyId) {
            if (isset($requireFieldsArray[$companyId])) {
                $resultCompanyFieldsArray[$entityId] = ArrayHelper::getValue($requireFieldsArray, $companyId);
            }
        }

        $this->log('Количество связанных компаний с требуемыми полями для событий : ' . count($requireFieldsArray));
        $this->log('Количество других связанных сущностей с требуемыми полями для событий : ' . count($resultCompanyFieldsArray));

        $resultCompanyFieldsArray += $requireFieldsArray;

        $this->log('После обьединения: ' . count($resultCompanyFieldsArray));

        return [
            'resultCompanyFields' => $resultCompanyFieldsArray,
            'taskTypes' => $taskTypesArray,
        ];
    }

    /**
     * Формирует массив, аналогичный массиву аналитики, для удобства дальнейшей обработки
     * @param array $taskEntities сущности задач
     * @return array
     */
    private function getCreateTaskEventsArray(array $taskEntities): array
    {
        $entitiesToRequest = $this->getTasksLinkedEntitiesId($taskEntities);

        $entities = [];
        $this->log(array_keys($entitiesToRequest));

        foreach ($entitiesToRequest as $entity => $ids) {
            $functionName = 'get' . ucfirst($entity) . 'Entities';

            $entities = ArrayHelper::merge($entities, ArrayHelper::index($this->$functionName($ids), 'id'));
        }

        $resultArray = [];

        foreach ($taskEntities as $task) {
            $linkedEntityId = ArrayHelper::getValue($task, 'element_id');
            $linkedEntity = ArrayHelper::getValue($entities, $linkedEntityId);
            $author = ArrayHelper::getValue($task, 'text_author_name');

            $resultArray[] = [
                'event' => 'Создание задачи',
                'date_create' => ArrayHelper::getValue($task, 'text_date_create'),
                'author' => (is_array($author)) ? ArrayHelper::getValue($author, 'title', '') : $author,
                'object' => [
                    'lead' => [
                        'id' => $linkedEntityId,
                        'name' => ArrayHelper::getValue($task, 'text_entity_name'),
//                        'entity' => '',
                        'url' => ArrayHelper::getValue($task, 'text_url'),
                        'entity_name' => ArrayHelper::getValue($linkedEntity, 'name'),
                    ]
                ],
                'value_before' => [
                    'text' => ArrayHelper::getValue($task, 'text'),
                ],
                'value_after' => [],
                'responsible' => ArrayHelper::getValue($task, 'text_responsible_user.title'),
                'task_type' => ArrayHelper::getValue($task, 'text_task_type'),
            ];
        }

        return $resultArray;
    }

    /**
     * Получение задач по их ИД
     * @param array $taskIdArray
     * @return array массив задач
     */
    private function getAllTasksById(array $taskIdArray): array
    {
        // максимальное количесвто сущностей для запроса - 500
        $chunkedArray = array_chunk(array_values($taskIdArray), 500);
        $tasksEntities = [];

        foreach ($chunkedArray as $tasksId) {
            $tasksEntities = array_merge($tasksEntities, $this->getAllTasks(['id' => $tasksId]));
            sleep(1);
        }

        return $tasksEntities;
    }

    /**
     * Возвращает текстовый тип задачи
     * @param mixed $taskType может быть либо текстом, либо числом
     * @return string
     */
    private function getTaskType($taskType): string
    {
        if (!is_numeric($taskType)) {
            return strtr(strtolower($taskType), [
                'call' => 'Звонок',
                'meeting' => 'Встреча',
                'letter' => 'Написать письмо',
            ]);
        }

        return ArrayHelper::getValue($this->taskTypes, "$taskType.name", '');
    }

    /**
     * Получение массива задач за период, предварительная обработка массива задач
     * @param $dateFrom
     * @param string $dateTo
     * @return array
     */
    private function getTasksByPeriod($dateFrom, string $dateTo = 'midnight'): array
    {
        $allTasks = $this->getAllTasks(['ifmodif' => $dateFrom]);
        $periodTasks = [];

        foreach ($allTasks as $task) {
            $dateCreate = ArrayHelper::getValue($task, 'date_create');

            if ($dateCreate < strtotime($dateTo) && $dateCreate > strtotime($dateFrom)) {
                $taskType = ArrayHelper::getValue($task, 'task_type');
                $task['text_task_type'] = $this->getTaskType($taskType);
                $elementType = ArrayHelper::getValue($task, 'element_type');

                if ($elementId = ArrayHelper::getValue($task, 'element_id')) {
                    $enType = '';

                    if ($elementType == 1) {
                        $ruType = 'Контакт';
                        $enType = 'contacts';
                    } elseif ($elementType == 2) {
                        $ruType = 'Сделка';
                        $enType = 'leads';
                    } elseif ($elementType == 3) {
                        $ruType = 'Компания';
                        $enType = 'companies';
                    }

                    $task['text_entity_name'] = $ruType ?? '';
                    $task['text_url'] = ($enType) ? "/$enType/detail/$elementId" : '';
                }

                $creator = ArrayHelper::getValue($task, 'created_user_id');
                $responsible = ArrayHelper::getValue($task, 'responsible_user_id');

                $task['text_author_name'] = ($creator == 0) ? $creator : ArrayHelper::getValue($this->users, $creator);
                $task['text_responsible_user'] = ArrayHelper::getValue($this->users, $responsible);
                $task['text_date_create'] = date('d.m.Y H:i:s', $dateCreate);

                $periodTasks[] = $task;
            }
        }

        return $periodTasks;
    }

    /**
     * Получение сущностей задач
     * @param array $params фильтр
     * @param null $groupName группа пользователей
     * @return mixed|null
     * @throws \Introvert\ApiException
     */
    private function getAllTasks(array $params = [], $groupName = null)
    {
        $id = ArrayHelper::getValue($params, 'id');
        $elementId = ArrayHelper::getValue($params, 'element_id');

        if (is_array($elementId)) {
            $elementId = array_values($elementId);
        }

        $usersId = ArrayHelper::getValue($params, 'crm_user_id');
        $type = ArrayHelper::getValue($params, 'type');
        $modified = ArrayHelper::getValue($params, 'ifmodif');

        if ($this->groupTitle && !$groupName) {
            $groupName = $this->groupTitle;
        }

        if ($groupName) {
            $usersId = $this->cache->getOrSet('usersId', function () use ($groupName) {
                return ArrayHelper::getColumn($this->getUsers($groupName), 'id');
            }, 3600);
        }

        return $this->testRequest($this->api->task->getAll($id, $elementId, $usersId, $type, $modified));
    }

    /**
     * Получение ИД сущностей, связанных с задачами
     * @param array $taskEntities
     * @return array
     */
    public function getTasksLinkedEntitiesId(array $taskEntities)
    {
        $taskEntities = ArrayHelper::index($taskEntities, 'id');

        $this->log('count($tasksEntities): ' . count($taskEntities));

        $entityType = [
            'Типы сущностей',
            'contacts',
            'leads',
            'companies'
        ];
        $entitiesToRequest = [];

        foreach ($taskEntities as $taskId => $task) {
            $linkedElementType = ArrayHelper::getValue($task, 'element_type');
            $linkedElementId = (int)ArrayHelper::getValue($task, 'element_id');
            $linkedElementType = ArrayHelper::getValue($entityType, $linkedElementType);

            if ($linkedElementType && $linkedElementId) {
                $entitiesToRequest[$linkedElementType][$taskId] = $linkedElementId;
            }

        }

        $this->log('Связанные сущности для задач, которые были найдены: ' . implode(', ', array_keys($entitiesToRequest)));
        $this->log('Количество элементов для всех сущностей, прикрепленных к задачам: ' . (count($entitiesToRequest, 1) - count(array_keys($entitiesToRequest))));

        return $entitiesToRequest;
    }

    /**
     * Получает массив ИД сущностей, возвращает массив ИД связанных компаний для них
     * @param string $entityType тип сущности
     * @param array $entitiesToRequest массив ИД сущности => ИД компании
     * @param string $to связанная сущность, которую необходимо получить
     * @throws \Introvert\ApiException
     * @return array
     */
    private function getLinkedCompanies(string $entityType, array $entitiesToRequest, string $to = 'companies'): array
    {
        if (in_array($entityType, ['lead', 'contact'])) {
            $entityType = "{$entityType}s";
        } elseif ($entityType == 'company') {
            $entityType = 'companies';
        }

        if (!in_array($entityType, ['leads', 'contacts', 'companies'])) {
            $this->log('Передан неверный тип сущности: ' . $entityType);
            exit;
        }

        $uniqueIds = array_unique(array_values($entitiesToRequest));
        $chunkedArray = array_chunk($uniqueIds, 500);
        $linksArray = [];

        foreach ($chunkedArray as $array) {
            $linksArray = array_merge($linksArray, $this->testRequest($this->api->links->getLinks($entityType, $array, $to)));

            sleep(1);
        }

        if ($linksArray) {
            $result = ArrayHelper::map($linksArray, 'from_id', 'to_id');
            $companiesId = [];

            foreach ($entitiesToRequest as $entityId => $linkedEntityId) {
                $companyId = ArrayHelper::getValue($result, $linkedEntityId);

                if ($companyId) {
                    $companiesId[$entityId] = $companyId;
                }
            }

            $companiesId += $result;

            return $companiesId;
        }

        return $linksArray;
    }

    /**
     * Возвращает сущности компаний по их ИД
     * @param array $companiesId
     * @return array
     */
    private function getCompaniesEntities(array $companiesId): array
    {
        $companiesId = array_unique(array_values($companiesId));
        $chunkedArray = array_chunk($companiesId, 500);
        $resultArray = [];

        foreach ($chunkedArray as $array) {
            $resultArray = array_merge($resultArray, $this->getAllCompanies($array));

            sleep(1);
        }

        return $resultArray;
    }

    /**
     * Возвращает сущности сделок по их ИД
     * @param array $leadsId
     * @return array
     */
    private function getLeadsEntities(array $leadsId): array
    {
        $leadsId = array_unique(array_values($leadsId));
        $chunkedArray = array_chunk($leadsId, 500);
        $resultArray = [];

        foreach ($chunkedArray as $array) {
            $resultArray = array_merge($resultArray, $this->getAllLeads($array));

            sleep(1);
        }

        return $resultArray;
    }

    /**
     * Возвращает сущности контактов по их ИД
     * @param array $contactsId
     * @return array
     */
    private function getContactsEntities(array $contactsId): array
    {
        $contactsId = array_unique(array_values($contactsId));
        $chunkedArray = array_chunk($contactsId, 500);
        $resultArray = [];

        foreach ($chunkedArray as $array) {
            $resultArray = array_merge($resultArray, $this->getAllContacts($array));

            sleep(1);
        }

        return $resultArray;
    }

    /**
     * Возвращает массив требуемых полей компании для массива компаний (ИД сущности => массив полей)
     * @param array $companyEntities
     * @return array
     */
    private function getRequiredFieldsArray(array $companyEntities): array
    {
        $requiredCompanyFieldsArray = [];

        foreach ($companyEntities as $companyEntity) {
            $requiredFields = $this->getRequiredCompanyFields($companyEntity);
            $requiredCompanyFieldsArray = array_merge($requiredCompanyFieldsArray, $requiredFields);
        }

        return ArrayHelper::index($requiredCompanyFieldsArray, 'id');
    }

    /**
     * Возвращает массив требуемых полей компании для отдельной компании
     * @param array $company
     * @return array
     */
    private function getRequiredCompanyFields(array $company): array
    {
        $requiredFields = [];
        $companyName = ArrayHelper::getValue($company, 'name');
        $customFields = ArrayHelper::getValue($company, 'custom_fields');
        $companyId = ArrayHelper::getValue($company, 'id');

        foreach ($customFields as $customField) {
            $customFieldId = ArrayHelper::getValue($customField, 'id');
            $customFieldValue = ArrayHelper::getValue($customField, 'values.0.value');

            if ($customFieldId == '421907') {
                $brandName = $customFieldValue;
            }

            if ($customFieldId == '433979') {
                $inn = $customFieldValue;
            }
        }

        $requiredFields[] = [
            'id' => $companyId,
            'name' => $companyName,
            'brand_name' => $brandName ?? '',
            'inn' => $inn ?? '',
            'link' => "https://{$this->subDomain}.test.ru/companies/detail/$companyId"
        ];

        return $requiredFields;
    }

    /**
     * Получение пользователей
     * @param null $groupName Фильтрация по названию группы
     * @return array|bool|mixed
     */
    public function getUsers($groupName = null)
    {
        $this->log('функция getUsers запущена');

        $url = '/ajax/get_managers_with_group/';
        $data = [
            'url' => $url,
            'method' => 'POST',
            'headers' => [
                'X-Requested-With: XMLHttpRequest'
            ],
            'params' => [
            ]
        ];
        $result = $this->sendToTest($data);
        $managers = ArrayHelper::getValue($result, 'managers');

        if ($groupName) {
            $groups = ArrayHelper::getValue($result, 'groups');
            $groupId = null;

            foreach ($groups as $key => $group) {
                if ($group == $groupName) {
                    $groupId = $key;
                }
            }

            if (!$groupId) {
                $this->log('Группа не найдена: ' . $groupName);
                $this->log($groups);
                $this->log('return false');

                return false;
            }

//            $this->filterBy = $groupName;
            $users = [];

            foreach ($managers as $manager) {
                if ($manager['group'] == $groupId) {
                    $users[] = $manager;
                }
            }

            return $users;
        }

        $this->log('Все пользователи: ');
        $this->log($managers);

        return $managers;
    }

    /**
     * Получение массива событий
     * @param null $date начало периода
     * @param null $groupTitle название группы
     * @param null $userTitle имя пользователя
     * @param null $onlyCount если указано, от возвращает только количество событий
     * @return array|mixed|string
     */
    public function getEvents($dateFrom, $dateTo, $groupTitle = null, $userTitle = null, $onlyCount = null)
    {
        $this->log('функция getEvents запущена');

        $url = '/ajax/events/list/';
        $count = '/ajax/events/count/';
        $params = [];

        if ($groupTitle || $userTitle) {
            $users = $this->users;

            if (!$users) {
                return false;
            }

            if (count($users) > 0) {
                foreach ($users as $user) {
                    if ($groupTitle || $userTitle == ArrayHelper::getValue($user, 'title')) {
                        $userIds[] = ArrayHelper::getValue($user, 'id');
                    }
                }

                if (empty($userIds)) {
                    $this->log('Пользователь не найден: ' . $userTitle);
                    $this->log('Выходим');
                    exit;
                }

                if (isset($userTitle)) {
                    $this->filterBy = $userTitle;
                }

                $params['filter']['main_user'] = $userIds;
            }
        }

        $dateFilter = [
            'filter_date_switch' => 'created',
            'filter_date_from' => $dateFrom,
            'filter_date_to' => $dateTo,
        ];

        $params = array_merge($params, $dateFilter);

        $data = [
            'url' => $count,
            'headers' => [
                'X-Requested-With: XMLHttpRequest',
                'Cookie: user_lang=ru;'
            ],
        ];

        if (count($params) > 0) {
            $data['params'] = $params;
        }

        $this->log('Данные для запроса в АМО:');
        $this->log($data);

        $total = $this->sendToTest($data);
        $totalPages = ArrayHelper::getValue($total, 'pagination.total');
        $totalCount = ArrayHelper::getValue($total, 'total');

        $this->log('Всего страниц: ' . $totalPages);
        $this->log('Всего событий: ' . $totalCount);

        if (!$totalCount) {
            $this->log('События для группы не найдены, return false');
            return [];
        }

        if ($onlyCount) {
            return "Событий: $totalCount, Страниц: $totalPages";
        }

        $this->totalCount = $totalCount;
        $data['url'] = $url;

        if ($totalPages > 1) {
            /* if ($totalPages > 50) { */
            /*     $totalPages = 50; */
            /* } */
            $eventArray = [];

            for ($i = 1; $i <= $totalPages; $i++) {
                sleep(1);
                $this->log('обрабатываем страницу № ' . $i);

                $data['params']['PAGEN_1'] = $i;
                $eventArray = array_merge($eventArray, ArrayHelper::getValue($this->sendToTest($data), 'response.items'));
                $this->log('страница № ' . $i . ' обработана');
            }

            return $eventArray;
        }

        $result = ArrayHelper::getValue($this->sendToTest($data), 'response.items');

        return $result;
    }

    /**
     * Является ли тип события звонком
     * @param $eventType
     * @return bool
     */
    private function isCallEvent(string $eventType): bool
    {
        return in_array($eventType, ['Исходящий звонок', 'Входящий звонок']);
    }

    /**
     * Возвращает статус звонка
     * @param string $statusTag тэг статуса из массива событий
     * @return string
     */
    private function getCallStatusText(string $statusTag): string
    {
        if ($statusTag == '') {
            return $statusTag;
        }

        preg_match('#\d#', $statusTag, $match);

        $callStatusCode = ArrayHelper::getValue($match, 0);
        $statusDescription = [
            'Описания статусов',
            'Оставил голосовое сообщение',
            'Перезвонить позже',
            'Нет на месте',
            'Разговор состоялся',
            'Неверный номер',
            'Не дозвонился',
            'Номер занят',
        ];

        return ArrayHelper::getValue($statusDescription, $callStatusCode);
    }

    /**
     * Преобразует дату события
     * @param string $date
     * @return string
     */
    private function getCreateDate(string $date): string
    {
        $todayDate = date('d.m.Y');
        $yesterdayDate = date('d.m.Y', strtotime('yesterday'));

        return str_replace(['Сегодня', 'Вчера', 'Today', 'Yesterday'], [$todayDate, $yesterdayDate, $todayDate, $yesterdayDate], $date);
    }

    /**
     * Возращает обработанный массив событий для экспорта в CSV
     * @param array $eventsArray
     * @param array $communicationArray массив доп полей компаний, типов задач
     * @return array
     */
    public function handleEventsForCsv(array $eventsArray, array $communicationArray = []): array
    {
        $subDomain = $this->subDomain;
        $handledArray = [];
        $checkEvents = [];
        $companyFields = ArrayHelper::getValue($communicationArray, 'resultCompanyFields');
        $taskTypes = ArrayHelper::getValue($communicationArray, 'taskTypes');

        $this->log('subdomain: ' . $subDomain);

        foreach ($eventsArray as $key => $event) {
            $dateCreate = $this->getCreateDate(ArrayHelper::getValue($event, 'date_create', ''));

            $object = ArrayHelper::getValue($event, 'object.lead');
            $objectName = ArrayHelper::getValue($object, 'name');
            $link = "https://$subDomain.test.ru" . ArrayHelper::getValue($object, 'url', '');
            $name = ArrayHelper::getValue($object, 'entity_name');
            $entityId = ArrayHelper::getValue($object, 'id');
            $eventType = ArrayHelper::getValue($event, 'event');

            $companyName = '';
            $companyInn = '';
            $companyLink = '';
            $companyBrandName = '';

            if ($linkedEntity = ArrayHelper::getValue($companyFields, $entityId)) {
                $companyName = ArrayHelper::getValue($linkedEntity, 'name');
                $companyInn = ArrayHelper::getValue($linkedEntity, 'inn');
                $companyLink = ArrayHelper::getValue($linkedEntity, 'link');
                $companyBrandName = ArrayHelper::getValue($linkedEntity, 'brand_name');
            }

            if (!$linkedEntity) {
                $checkEvents[$objectName][$entityId] = $entityId;
            }

            $valueBefore = $this->arrayHandler(ArrayHelper::getValue($event, 'value_before'), $eventType);
            $taskType = ArrayHelper::getValue($event, 'task_type');

            if ($this->isTaskEvent($eventType) && !$taskType) {
                $taskType = ArrayHelper::getValue($taskTypes, $entityId . '.type', '');
            }

            $valueAfter = ArrayHelper::getValue($event, 'value_after');
            $duration = ArrayHelper::getValue($valueAfter, 'params.duration');

            // если длительность звонка меньше 5 с
            $callStatusTag = ($duration && $duration <= 5) ?
                'Звонок не состоялся' :
                $this->getCallStatusText(ArrayHelper::getValue($valueAfter, 'params.result', ''));

            $duration = ($duration) ? $this->durationToTime($duration) : '';
            $author = ArrayHelper::getValue($event, 'author');

            $handledEvent = [
                'date_create' => $dateCreate,
                'author' => $author,
                'object' => $objectName,
                'link' => $link,
                'entity_name' => ($name) ? $this->removeEscapeCharacter($name) : '',
                'event' => ArrayHelper::getValue($event, 'event'),
                'value_before' => $valueBefore,
                'value_after' => $this->arrayHandler($valueAfter, $eventType),
                'task_type' => $taskType,
                'task_responsible' => ($this->isTaskEvent($eventType)) ? ArrayHelper::getValue($event, 'responsible') ?? $author : '',
                'phone_number' => ArrayHelper::getValue($valueAfter, 'params.element.name', ''),
                'call_status' => $callStatusTag,
                'call_duration' => $duration,
                'linked_company_name' => $companyName,
                'linked_company_inn' => $companyInn,
                'linked_company_link' => $companyLink,
                'linked_company_Brand-name' => $companyBrandName,
                'client_email' => '',
                'test_email' => '',
            ];

            $handledArray[] = $handledEvent;
        }

        $this->log('Компании не были найдены для событий: ');
        $this->log($checkEvents);

        ArrayHelper::multisort($handledArray, 'date_create', SORT_DESC);

        $this->totalCount = count($handledArray);
        $this->log('Всего событий: ' . $this->totalCount);

        return $handledArray;
    }

    /**
     * Создает CSV файл с названиями столбцов, возвращает массив с данными о файле
     * @param array $handledEventsArray массив событий
     * @param int $filterBy добавлять в название файла фильтр событий
     * @return string
     */
    private function getCSVFile(int $filterBy = 1): array
    {
        $this->log('Создаём csv файл');

        $filterName = '';

        if ($filterBy) {
            $filterName = $this->filterBy;

            if (in_array(PHP_OS, ['WIN32', 'WINNT', 'Windows'])) {
                $filterName = str_replace(' ', '_', $filterName);
                $filterName = $this->translit($filterName);

                $this->log('Меняем названия фильтра для Windows: ' . $filterName);
            }
        }

        $name = 'events_' . $this->date . '-' . date('d.m.Y') . "_$filterName($this->totalCount)";
        $reportsDir = Yii::getAlias('@webroot/reports/communications-analytics');

        if (!file_exists($reportsDir) && !is_dir($reportsDir)) {
            try {
                FileHelper::createDirectory($reportsDir, 0775, true);
            } catch (\Exception $exception) {
                $this->log('Ошибка при создании папки, выходим: ' . $exception);
                exit;
            }
        }

        $file = $reportsDir . '/' . $name . '.csv';

        try {
            $fp = fopen($file, 'w');
        } catch (\Exception $exception) {
            $this->log('Ошибка при создании файла, выходим: ');
            $this->log($exception);

            exit;
        }

        fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $headers = [
            'Дата',
            'Автор',
            'Тип объекта',
            'Ссылка на объект',
            'Название объекта',
            'Событие',
            'Значение до',
            'Значение после',
            'Тип задачи',
            'Ответственный за задачу',
            'Номер телефона клиента',
            'Статус звонка',
            'Продолжительность звонка',
            'Название компании',
            'ИНН компании',
            'Ссылка на связанную компанию',
            'Brand name (доп. поле)',
            'Email клиента',
            'Email пользователя в амо',
        ];
        fputcsv($fp, $headers, ";");
        fclose($fp);

        return [ 'file' => $file, 'name' => $name, 'reportsDir' => $reportsDir ];
    }


    private function writeCSVReport(array $fileData, array $handledEventsArray, int $filterBy = 1)
    {
        $file = $fileData['file'];
        $name = $fileData['name'];
        $fp = fopen($file, 'a');
        foreach ($handledEventsArray as $fields) {
            fputcsv($fp, $fields, ";");
        }

        fclose($fp);
    }

    /**
     * Перебор вложенных массивов
     * @param $arr
     * @param $eventType
     * @return string
     */
    private function arrayHandler(array $arr, string $eventType): string
    {
        if (!is_array($arr)) {
            return 'not an array';
        }

        $renderArr = [];

        foreach ($arr as $key => $value) {
            //перебираем массив, если значение - массив, то обрабатываем его нашей функцией
            if (is_array($value)) {
                $renderArr[] = $this->arrayHandler($value, $eventType);
            } else {
                switch ($eventType) {
                    case 'Исходящий звонок':
                    case 'Входящий звонок':
                        if ($key == 'user_login') {
                            $this->callText = $value;
                        }
                        if ($key == 'name') {
                            $renderArr[] = "$this->callText к $value";
                        }
                        break;
                    default:
                        if ($key == 'name' || $key == 'text')
                            $renderArr[] = $this->removeEscapeCharacter($value);
                }
            }
        }

        $renderArr = array_filter($renderArr, function ($value) {
            return $value;
        });

        return implode(', ', $renderArr);
    }

    private function translit($str)
    {
        $rus = array('А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ё', 'Ж', 'З', 'И', 'Й', 'К', 'Л', 'М', 'Н', 'О', 'П', 'Р', 'С', 'Т', 'У', 'Ф', 'Х', 'Ц', 'Ч', 'Ш', 'Щ', 'Ъ', 'Ы', 'Ь', 'Э', 'Ю', 'Я', 'а', 'б', 'в', 'г', 'д', 'е', 'ё', 'ж', 'з', 'и', 'й', 'к', 'л', 'м', 'н', 'о', 'п', 'р', 'с', 'т', 'у', 'ф', 'х', 'ц', 'ч', 'ш', 'щ', 'ъ', 'ы', 'ь', 'э', 'ю', 'я', ' ', ':');
        $lat = array('A', 'B', 'V', 'G', 'D', 'E', 'E', 'J', 'Z', 'I', 'Y', 'K', 'L', 'M', 'N', 'O', 'P', 'R', 'S', 'T', 'U', 'F', 'H', 'C', 'Ch', 'Sh', 'Sch', '', 'Y', '\'', 'E', 'Yu', 'Ya', 'a', 'b', 'v', 'g', 'd', 'e', 'e', 'j', 'z', 'i', 'y', 'k', 'l', 'm', 'n', 'o', 'p', 'r', 's', 't', 'u', 'f', 'h', 'c', 'ch', 'sh', 'sch', '', 'y', '\'', 'e', 'yu', 'ya', '_', '');

        return str_replace($rus, $lat, $str);
    }

    /**
     * Форматирует количество секунд во время, если лидирующие цифры - нули (часы), отбрасываем их
     * @param int $duration
     * @return string|string[]|null
     */
    public function durationToTime(int $duration)
    {
        $time = gmdate('H:i:s', $duration);

        return preg_replace('#^0+:#', '', $time);
    }

    /**
     * Декодируем экранированные символы
     * @param string $text
     * @return string
     */
    public function removeEscapeCharacter(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Получение параметров виджета
     * @return array
     */
    public function getWidgetParams(): array
    {
        $yadroApiKey = ArrayHelper::getValue($this->commonParams, 'yadroApiKey');
        $widgetCode = ArrayHelper::getValue($this->widgetParams, 'widgetCode');
        $params = file_get_contents('https://test/settings.php?code=' . $widgetCode . '&key=' . $yadroApiKey);

        if ($params === false) {
            $this->log('Ошибка: Не удалось получить параметры виджета');
            exit;
        }

        return json_decode($params, true);
    }

    /**
     * Устанавливает дату последнего запуска
     * @param string $type
     * @return bool|false|int|string
     */
    public function setLastStartDate($type = 'unix')
    {
        if ($type == 'clear') {
            self::setCache(null, 'last-start-date', 0);

            $this->log("Дата последнего запуска обнулена");

            return true;
        }

        $date = date('d.m.Y', time());

        self::setCache($date, 'last-start-date', 0);

        $this->log("Дата последнего запуска установлена: $date");

        return ($type == 'unix') ? time() : date('d.m.Y');
    }

    /**
     * Возвращает дату последнего запуска
     * @param string $type
     * @return bool|false|int|mixed
     */
    public function getLastStartDate($type = 'unix')
    {
        $date = self::getCache('last-start-date');

        if ($date) {
            $this->log("Дата последнего запуска получена: $date");

            return ($type == 'unix') ? strtotime($date) : $date;
        }

        $this->log('Дата последнего запуска не получена');

        return false;
    }

    public function setSettingsTimeStamp($timeStamp)
    {
        self::setCache($timeStamp, 'settings-time-stamp', 0);

        $this->log("Установлена новая метка времени: $timeStamp");
    }

    public function getSettingsTimeStamp()
    {
        $timeStamp = self::getCache('settings-time-stamp');

        return $timeStamp ?? false;
    }

    public function sendMail($email, $msg, $subject, $file = null)
    {
        $this->log('Формируем сообщение');

        if (is_array($email)) {
            foreach ($email as $current) {
                $emailRecipient[] = ['email' => $current];
            }
        } else {
            $emailRecipient[] = ['email' => $email];
        }

        $subject = ($subject == 'default') ? 'Отчет "Список событий" от ' . date('d.m.Y') : $subject;

        $mail = [
            'msg' => $msg,
            'subject' => $subject,
            'from' => [
                'email' => 'no-reply@taxi.test.ru'
            ],
            'to' => $emailRecipient,
            'additional_data' => [
                'auth_data' => [
                    'apiKey' => 'test'
                ],
                'service' => 'mandrill'
            ]
        ];

        if ($file) {
            $mail['attachments'] = $file;
        }

        $this->log('Отправляем: ');
        $this->log($mail);

        try {
            $result = $this->testRequest($this->api->mail->send($mail));

            $this->log('Результат отправки сообщения: ');
            $this->log($result);
        } catch (\Exception $e) {
            $this->log('Ошибка при вызове mail->send: ' . $e->getMessage());
        }
    }

    /**
     * Формирование массива соответсвия email => путь к csv файлу
     * @param $array
     * @param int $deep
     * @param array $readyArray
     * @param null $keyMain
     * @return array
     */
    public function getGroupsByMail($array, $deep = 0, &$readyArray = [], $keyMain = null)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) { // обход вложенного массива
                self::getGroupsByMail($value, $deep + 1, $readyArray, $key);
            } elseif ($value) {
                $readyArray[$value][] = $this->filePath[$keyMain];
            }
        }

        return $readyArray;
    }

    /**
     * @param $fileNameZip
     * @param $filePath
     * @param null $localName
     * @return bool
     */
    private function zipFile(string $fileNameZip, string $filePath, $localName = null): bool
    {
        $this->log('Создаем ZIP архив');
        $zip = new \ZipArchive();

        $this->log($fileNameZip);

        if ($zip->open($fileNameZip, \ZipArchive::CREATE) !== TRUE) {
            $this->log('Ошибка создания архива. Отмена');
            $this->log('END');
            return false;
        } else {
            $this->log('Все норм');
            $this->log($zip->status);
            $this->log($zip->statusSys);
        }

        $result = $zip->addFile($filePath, $localName);
        $this->log('Результат добавления файла ' . $result);
        $this->log($zip->numFiles . ' ' . $zip->status);

        $zip->close();

        return true;
    }

    private function getLinkFile(array $fileData)
    {
        $file = $fileData['file'];
        $name = $fileData['name'];
        $reportsDir = $fileData['reportsDir'];
        try {
            $fileSize = filesize($file);
        } catch (\Exception $exception) {
            $this->log('Ошибка определения размера файла, выходим: ');
            $this->log($exception);

            exit;
        }

        $this->log('file size: ' . $fileSize);
        if ($fileSize >= 500000) {
            $fileNameZip = $reportsDir . '/' . $name . '.zip';
            $localName = "$name.csv";

            $this->zipFile($fileNameZip, $file, $localName);

            $file = $fileNameZip;
            $name = $name . '.zip';
        } else {
            $name = $name . '.csv';
        }

        $basePath = ArrayHelper::getValue($this->commonParams, 'basePath');

        $this->log($file);
        $this->log('Меняем путь для вложения:');
        $this->log("$basePath/reports/communications-analytics/$name");

        return "$basePath/reports/communications-analytics/$name";
    }
}
