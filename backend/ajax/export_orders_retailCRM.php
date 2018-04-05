<?php
$path_parts = pathinfo($_SERVER['SCRIPT_FILENAME']); // Определяем директорию скрипта (полезно для запуска из cron'а)
chdir($path_parts['dirname']); // Задаём директорию выполнения скрипта
// Подключаем API Simpla
require_once '../../api/Okay.php';
// Подключим зависимые библиотеки (API RetailCRM)
require_once '../../vendor/autoload.php';
// Подключаем класс с методами интеграции RetailCRM
require_once '../../api/Retail.php';
$config = Retail::config('../../integration/config.php');

// Требуется пройтись по всем заказам, собрать из них необходимые данные.
// После формирования исчерпывающего набора данных подготовить к отправке список задействованных покупателей (пакетно по 50 штук)
// API RetailCRM /api/customers/upload
// Затем выгрузить данные по самим заказам (пакетно по 50 штук)
// API /api/orders/upload

$retail          = new Retail(\RetailCrm\ApiClient::V5);
$clientRetailCRM = new \RetailCrm\ApiClient(
    $config['urlRetail'],
    $config['keyRetail'],
    \RetailCrm\ApiClient::V5,
    $config['siteCode']
);
// Если есть непустой файл history.log, то значит полная выгрузка уже производилась. Повторять полную выгрузку нельзя.
$checkFile = $config['logDirectory'] . 'history.log';
if (file_exists($checkFile)) {
    // Выгрузим все заказы, появившиеся после указанного в логе времени
    $lastDate = Retail::getDate($checkFile);
    Retail::logger('Готовимся выгружать заказы, созданные после ' . $lastDate, 'orders-info');
} else {
    // Файла с датой последней выгрузки нет, поэтому считаем, что надо выгружать всё
    $lastDate = null;
    Retail::logger('Готовимся к первоначальной выгрузке всех клиентов', 'orders-info');
}
$data = $retail->fetch($lastDate);
Retail::logger('Все данные для выгрузки: ' . print_r($data, true), 'orders-info');
// Массив данных разбит на пакеты - не более 50 записей в каждом пакете
// Пройдём по всему массиву клиентов и отправим каждый пакет
if (!is_null($data) && is_array($data)) {
    foreach ($data as $pack) {
        try {
            $responseCustomers = $clientRetailCRM->request->customersUpload($pack['customers'], $config['siteCode']);
            Retail::logger('RetailCRM_Api::customersUpload: Выгрузили следующих клиентов: ' . print_r($pack['customers'], true), 'orders-info');
        } catch (\RetailCrm\Exception\CurlException $e) {
            Retail::logger('RetailCRM_Api::customersUpload ' . $e->getMessage(), 'connect');
            echo 'Сетевые проблемы. Ошибка подключения к retailCRM: ' . $e->getMessage();
        }
        // Получаем подробности обработки клиентов
        if (isset($responseCustomers)) {
            if ($responseCustomers->isSuccessful()) {
                // Сохраняем идентификаторы клиентов RetailCRM
                $customersIds = $responseCustomers->__get('uploadedCustomers');
                $retail->saveRetailIds($customersIds, 'user');
                $status = 'Все клиенты успешно выгружены в RetailCRM.' . '<br>';
            } elseif (460 === $responseCustomers->getStatusCode()) {
                Retail::logger('Ошибка при выгрузке некоторых клиентов: ' . print_r($responseCustomers, true), 'customers');
                echo 'Не все клиенты успешно выгружены в RetailCRM.' . '<br>';
                echo sprintf(
                    "Ошибка при выгрузке некоторых клиентов: [Статус HTTP-ответа %s] %s <br>",
                    $responseCustomers->getStatusCode(),
                    $responseCustomers->getErrorMsg()
                );
                $arErrorText = $responseCustomers->getErrors();
                foreach ($arErrorText as $key => $errorText) {
                    echo 'Customer ID:' . $pack['customers'][$key]['externalId'] . ' - ' . $errorText . '<br>';
                }
            } else {
                Retail::logger('Ошибка при выгрузке клиентов: ' . print_r($responseCustomers, true), 'customers');
                echo sprintf(
                        "Ошибка при выгрузке клиентов: [Статус HTTP-ответа %s] %s",
                        $responseCustomers->getStatusCode(),
                        $responseCustomers->getErrorMsg()
                    ) . '<br>';
                $arErrorText = $responseCustomers->getErrors();
                foreach ($arErrorText as $key => $errorText) {
                    var_dump($responseCustomers->getStatusCode());
                    echo 'Customer ID:' . $pack['customers'][$key]['externalId'] . ' - ' . $errorText . '<br>';
                }
            }
        }
        // Переходим к выгрузке заказов
        try {
            $responseOrders = $clientRetailCRM->request->ordersUpload($pack['orders'], $config['siteCode']);
            Retail::logger(date('Y-m-d H:i:s'), 'history-log'); // Помечаем время последней выгрузки заказов
            Retail::logger('RetailCRM_Api::ordersUpload: Выгрузили следующие заказы', 'orders-info');
        } catch (\RetailCrm\Exception\CurlException $e) {
            Retail::logger('RetailCRM_Api::ordersUpload ' . $e->getMessage(), 'connect');
            echo 'Сетевые проблемы. Ошибка подключения к retailCRM: ' . $e->getMessage();
        }
        // Получаем подробности обработки заказов
        if (isset($responseOrders)) {
            if ($responseOrders->isSuccessful() && 201 === $responseOrders->getStatusCode()) {
                echo $status . 'Все заказы успешно выгружены в RetaiCRM.' . '<br>';
                // Сохраняем идентификаторы заказов RetailCRM
                $ordersIds = $responseOrders->__get('uploadedOrders');
                $retail->saveRetailIds($ordersIds, 'order');
            } elseif ($responseOrders->isSuccessful() && 460 === $responseOrders->getStatusCode()) {
                Retail::logger('Ошибка при выгрузке некоторых заказов: ' . print_r($responseOrders, true), 'customers');
                echo 'Не все заказы успешно выгружены в RetaiCRM.' . '<br>';
                echo sprintf(
                        "Ошибка при выгрузке заказов: [Статус HTTP-ответа %s] %s",
                        $responseCustomers->getStatusCode(),
                        $responseCustomers->getErrorMsg()
                    ) . '<br>';
                $arErrorText = $responseOrders->getErrors();
                foreach ($arErrorText as $errorText) {
                    echo $errorText . '<br>';
                }
            } else {
                Retail::logger('Ошибка при выгрузке заказов: ' . print_r($responseOrders, true), 'orders-error');
                echo sprintf(
                        "Ошибка при выгрузке заказов: [Статус HTTP-ответа %s] %s",
                        $responseOrders->getStatusCode(),
                        $responseOrders->getErrorMsg()
                    ) . '<br>';
                $arErrorText = $responseOrders->getErrors();
                foreach ($arErrorText as $errorText) {
                    echo $errorText . '<br>';
                }
            }
        }
    } // Конец цикла по пакетам
} else {
    // Для выгрузки данных нет
    Retail::logger('Выгрузка прерывается - нечего выгружать.', 'orders-info');
    Retail::logger(date('Y-m-d H:i:s'), 'history-log'); // Помечаем время последней попытки выгрузки заказов
    echo 'Выгружать нечего';
}