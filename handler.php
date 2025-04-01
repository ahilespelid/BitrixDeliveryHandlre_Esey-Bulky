<?
namespace Sale\Handlers\Delivery;
use \Bitrix\Sale\Delivery\CalculationResult;
use \Bitrix\Sale\Shipment;
use \Bitrix\Sale\Delivery\Services\Base;
use \Bitrix\Main\Web\HttpClient;

class EasybulkyHandler extends Base
{
    public static function getClassTitle()
        {
            return 'Ease Bulky Служба доставки';
        }
        
    public static function getClassDescription()
        {
            return 'Служба доставки, интегрированная с Ease Bulky API';
        }
        
    protected function calculateConcrete(\Bitrix\Sale\Shipment $shipment)
        {
            $CalculationResult = new \Bitrix\Sale\Delivery\CalculationResult();
//            file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/logs/easebulki.log", print_r([$CalculationResult], 1), FILE_APPEND);                
            if(!empty($apiKey = $this->config['MAIN']['API_KEY'] ?? '')){
                // Получаем данные заказа
                $order          = $shipment->getOrder();
                $weight         = $shipment->getWeight() / 1000; // Вес в кг
                $currency       = $order->getCurrency();
//                file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/logs/easebulki.log", print_r(['$weight' => $weight, '$currency' => $currency], 1), FILE_APPEND);
                // Предполагаем, что нужно получить пункт назначения из свойств заказа
                $locationProp   = $order->getPropertyCollection()->getDeliveryLocation();
                $destination    = $locationProp ? $locationProp->getValue() : 'DEFAULT_CITY';
//                file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/logs/easebulki.log", print_r(['$destination' => $destination], 1), FILE_APPEND);
                if(!empty($location = \Bitrix\Sale\Location\Admin\LocationHelper::getLocationPathDisplay($destination))){
//                    file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/logs/easebulki.log", print_r(['$location' => $location], 1), FILE_APPEND);
                    // Формируем запрос к API
                    $httpClient = new HttpClient();
                    $httpClient->setHeader('Content-Type', 'application/json'); // $httpClient->setHeader('Authorization', 'Bearer '.$apiKey);
                    
                    $response       = json_decode($httpClient->post(
                        'https://api.e-bulky.ru/api/v1/public/calculate',
                        json_encode($requestData = ['apikey' => $apiKey, 'weight' => $weight, 'address' => $location])), true);
//                    file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/logs/easebulki.log", print_r(['$response' => $response], 1), FILE_APPEND);
                    
                    if('200' == $response['status'] && !empty($total = (float)$response['response']['total'])){
                        $CalculationResult->setDeliveryPrice(roundEx($total, 2));
                        $CalculationResult->setPeriodDescription('1 день');
                    }else{
                        $CalculationResult->addError(new \Bitrix\Main\Error('Empty delivery price'));
                    }
                }else{$CalculationResult->addError(new \Bitrix\Main\Error('Empty delivery location adress'));};
            }else{$CalculationResult->addError(new \Bitrix\Main\Error('Empty API token Esae Bulky'));}
//            file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/logs/easebulki.log", print_r([$CalculationResult], 1), FILE_APPEND);
            return $CalculationResult;
        }
    public function isCompatible(Shipment $shipment)
        {
            $totalPrice     = $shipment->getOrder()->getPrice();
            $minSum         = (float)($this->getConfig()['MAIN']['MIN_ORDER_SUM'] ?? 0);
            
            return $totalPrice >= $minSum;
        }

        
    protected function getConfigStructure()
        {
        return [
            'MAIN' => [
                'TITLE' => 'Настройки',
                'DESCRIPTION' => 'Ease Bulky API delivery настройки',
                'ITEMS' => [
                    'API_KEY' => [
                        'TYPE' => 'STRING',
                        'NAME' => 'API Key',
                        'DEFAULT' => ''
                    ],
                    'MIN_ORDER_SUM' => [
                        'TYPE' => 'STRING',
                        'NAME' => 'Минимальная сумма заказа',
                        'DEFAULT' => '1000']]]];
        }
        
    public function isCalculatePriceImmediately()
        {
            return true;
        }
        
    public static function whetherAdminExtraServicesShow()
        {
            return true;
        }
}
