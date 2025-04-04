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
            return 'Служба доставки Ease Bulky';
        }
        
    public static function getClassDescription()
        {
            return 'Служба доставки крупногабаритных заказов для интернет-магазинов по Москве и Московской области.';
        }
        
    protected function calculateConcrete(\Bitrix\Sale\Shipment $shipment)
        {
            $CalculationResult = new \Bitrix\Sale\Delivery\CalculationResult(); 
//            file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/logs/easebulki.log", print_r([$CalculationResult], 1), FILE_APPEND);                
            if(!empty($API_KEY = $this->config['MAIN']['API_KEY'] ?? '')){
                // Получаем данные заказа
                $order              = $shipment->getOrder();
                $price              = $order->getBasket()->getPrice();
                $weight             = $shipment->getWeight() / 1000; // Вес в кг
                $currency           = $order->getCurrency();
                $shipmentCollection = $order->getShipmentCollection();
                $gabarits           = [];
                $MIN_ORDER_WEIGHT   = is_numeric($this->config['MAIN']['MIN_ORDER_WEIGHT']) ? $this->config['MAIN']['MIN_ORDER_WEIGHT'] : 1;
                
                foreach($shipmentCollection as $shpmnt){
                    $getShipmentItemCollection = $shpmnt->getShipmentItemCollection();
                    foreach($getShipmentItemCollection as $shipmentItem){
                        $basketItem             = $shipmentItem->getBasketItem();
                        $dimensions             = (is_array($dimensions = $basketItem->getField('DIMENSIONS'))) ? $dimensions : unserialize($dimensions);
                        $dimensions['WEIGHT']   = ($basketItem->getField('WEIGHT') ?? 0) / 1000;
//                        $dimensions['VALUME']   = array_product($dimensions);
                        $dimensions['QUANTITY'] = round($basketItem->getField('QUANTITY') ?? 0);
                        $gabarits[]             = $dimensions;
                    }
                }
                
                $dimens = self::getSumDimensions($gabarits);
                
//                file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/logs/easebulki.log", print_r(['$price' => $price, '$gabarits' => $gabarits, 'getSumDimensions' => self::getSumDimensions($gabarits)], 1), FILE_APPEND);
//                file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/logs/easebulki.log", print_r(['$weight' => $weight, '$currency' => $currency], 1), FILE_APPEND);
                // Предполагаем, что нужно получить пункт назначения из свойств заказа
                $locationProp   = $order->getPropertyCollection()->getDeliveryLocation();
                $destination    = $locationProp ? $locationProp->getValue() : 'DEFAULT_CITY';
//                file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/logs/easebulki.log", print_r(['$destination' => $destination], 1), FILE_APPEND);
                if($weight >= $MIN_ORDER_WEIGHT && $weight <= 500 && max(array_column($gabarits, 'WEIGHT')) < 100 ){
                    if(!empty($location = \Bitrix\Sale\Location\Admin\LocationHelper::getLocationPathDisplay($destination))){
//                      file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/logs/easebulki.log", print_r(['$location' => $location], 1), FILE_APPEND);
                        // Формируем запрос к API
                        $httpClient = new HttpClient();
                        $httpClient->setHeader('Content-Type', 'application/json'); // $httpClient->setHeader('Authorization', 'Bearer '.$apiKey);
                        $requestData = ['apikey' => $API_KEY, 'weight' => $weight, 'address' => $location, 'os' => $price];
                        
                        if(!empty($dimens['L']) && !empty($dimens['W']) && !empty($dimens['H'])){
                            $requestData['dimension_side1'] = $dimens['W'] / 10;
                            $requestData['dimension_side2'] = $dimens['H'] / 10;
                            $requestData['dimension_side3'] = $dimens['L'] / 10;
                        }
                        
                        $response       = json_decode($httpClient->post(
                            'https://api.e-bulky.ru/api/v1/public/calculate',
                            json_encode($requestData)), true);
                            
                        file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/logs/easebulki.log", print_r(['$requestData' => $requestData, '$response' => $response], 1), FILE_APPEND);
                    
                        if('200' == $response['status'] && !empty($total = (float)$response['response']['total'])){
                            $CalculationResult->setDeliveryPrice(roundEx($total, 2));
                            $CalculationResult->setPeriodDescription('1 день');
                        }else{$CalculationResult->addError(new \Bitrix\Main\Error('Empty delivery price'));}
                    }else{$CalculationResult->addError(new \Bitrix\Main\Error('Empty delivery location adress'));}
                }else{$CalculationResult->addError(new \Bitrix\Main\Error('Delivery weight is less than 0 or more than 500 kg or weight one position > 100 kg'));}
            }else{$CalculationResult->addError(new \Bitrix\Main\Error('Empty API token Esae Bulky'));}
//            file_put_contents($_SERVER["DOCUMENT_ROOT"] . "/logs/easebulki.log", print_r([$CalculationResult], 1), FILE_APPEND);
            return $CalculationResult;
        }
        
    protected function getConfigStructure()
        {
        return [
            'MAIN' => [
                'TITLE' => 'Настройки',
                'DESCRIPTION' => 'Настройки обработчика службы доставки',
                'ITEMS' => [
                    'API_KEY' => [
                        'TYPE' => 'STRING',
                        'NAME' => 'Ключ API',
                        'DEFAULT' => ''
                    ],
                    /*
                    'MIN_ORDER_SUM' => [
                        'TYPE' => 'STRING',
                        'NAME' => 'Минимальная сумма заказа',
                        'DEFAULT' => '1000'],
                    */
                    'MIN_ORDER_WEIGHT' => [
                        'TYPE' => 'NUMBER',
                        'NAME' => 'Минимальный вес заказа (кг)',
                        'DEFAULT' => '10'],
                        ]]];
        }
        
    public function isCalculatePriceImmediately()
        {
            return true;
        }
        
    public static function whetherAdminExtraServicesShow()
        {
            return true;
        }
        
    /**
     * @param $arGoods array(array(size1, size2, size3, quantity))
     * @return MergerResult
     */
    public static function getSumDimensions($arGoods)
        {
        if (!is_array($arGoods) || !count($arGoods))
            return [];

        $arWork = array();
        foreach ($arGoods as $good)
//            $arWork[] = self::sumSizeOneGoods($good[0], $good[1], $good[2], $good[3]);
            $arWork[] = self::sumSizeOneGoods($good['LENGTH'], $good['WIDTH'], $good['HEIGHT'], $good['QUANTITY']);

        $dimensions = self::sumSize($arWork);
        return $dimensions;
        }

    /**
     * Equal size goods dimensions merger
     * @param int $xi
     * @param int $yi
     * @param int $zi
     * @param int $qty
     * @return array
     */
    public static function sumSizeOneGoods($xi, $yi, $zi, $qty)
        {
        $ar = array($xi, $yi, $zi);
        sort($ar);
        if ($qty <= 1)
            return (array('X' => $ar[0], 'Y' => $ar[1], 'Z' => $ar[2]));

        $x1 = 0;
        $y1 = 0;
        $z1 = 0;
        $l  = 0;

        $max1 = floor(Sqrt($qty));
        for ($y = 1; $y <= $max1; $y++) {
            $i = ceil($qty/$y);
            $max2 = floor(Sqrt($i));
            for ($z = 1; $z <= $max2; $z++) {
                $x = ceil($i/$z);
                $l2 = $x*$ar[0] + $y*$ar[1] + $z*$ar[2];
                if (($l == 0) || ($l2 < $l)) {
                    $l = $l2;
                    $x1 = $x;
                    $y1 = $y;
                    $z1 = $z;
                }
            }
        }
        return (array('X' => $x1*$ar[0], 'Y' => $y1*$ar[1], 'Z' => $z1*$ar[2]));
        }

    /**
     * Goods dimensions merger
     * @param array $a
     * @return array
     */
    public static function sumSize($a)
        {
        $n = count($a);
        if (!($n > 0))
            return (array('L' => 0, 'W' => 0, 'H' => 0));

        for ($i3 = 1; $i3 < $n; $i3++) {
            // sort sizes big to small
            for ($i2 = $i3 - 1; $i2 < $n; $i2++) {
                for ($i = 0; $i <= 1; $i++) {
                    if ($a[$i2]['X'] < $a[$i2]['Y']) {
                        $a1 = $a[$i2]['X'];
                        $a[$i2]['X'] = $a[$i2]['Y'];
                        $a[$i2]['Y'] = $a1;
                    }

                    if (($i == 0) && ($a[$i2]['Y'] < $a[$i2]['Z'])) {
                        $a1 = $a[$i2]['Y'];
                        $a[$i2]['Y'] = $a[$i2]['Z'];
                        $a[$i2]['Z'] = $a1;
                    }
                }
                $a[$i2]['Sum'] = $a[$i2]['X'] + $a[$i2]['Y'] + $a[$i2]['Z']; // sum of sides
            }
            // sort cargo from small to big
            for ($i2 = $i3; $i2 < $n; $i2++) {
                for ($i = $i3; $i < $n; $i++) {
                    if ($a[$i - 1]['Sum'] > $a[$i]['Sum']) {
                        $a2 = $a[$i];
                        $a[$i] = $a[$i - 1];
                        $a[$i - 1] = $a2;
                    }
                }
            }
            // calculate sum dimensions of two smallest cargoes
            if ($a[$i3-1]['X'] > $a[$i3]['X'])
                $a[$i3]['X'] = $a[$i3-1]['X'];
            if ($a[$i3-1]['Y'] > $a[$i3]['Y'])
                $a[$i3]['Y'] = $a[$i3-1]['Y'];
            $a[$i3]['Z'] = $a[$i3]['Z'] + $a[$i3-1]['Z'];
            $a[$i3]['Sum'] = $a[$i3]['X'] + $a[$i3]['Y'] + $a[$i3]['Z']; // sum of sides
        }

        $a = array(
            Round($a[$n-1]['X'], 2),
            Round($a[$n-1]['Y'], 2),
            Round($a[$n-1]['Z'], 2)
        );
        rsort($a);

        return array(
            'L' => $a[0],
            'W' => $a[1],
            'H' => $a[2]
        );
        }
}
?>
