<?php

require_once('View.php');

class CheckoutView extends View {

    public function __construct() {
        parent::__construct();


        // Если нажали оформить заказ
        if(isset($_POST['checkout'])) {

            if (empty($_SESSION['shopping_cart'])){
                header('location: '.$this->config->root_url.'/'.$this->lang_link.'cart/');
            }

            $order = new stdClass;
            $order->payment_method_id = $this->request->post('payment_method_id', 'integer');
            $order->delivery_id = $this->request->post('delivery_id', 'integer');
            $order->name        = $this->request->post('name');
            $order->email       = $this->request->post('email');
            $order->address     = $this->request->post('address');

            $country = $this->request->post('country');
            $city = $this->request->post('city');


            if (!empty($city)){
                $order->address .= ', '.$city;
            }
            if (!empty($country)){
                $order->address .= ', '.$country;
            }

            $order->phone       = $this->request->post('phone');
            $order->comment     = $this->request->post('comment');
            $order->ip      	= $_SERVER['REMOTE_ADDR'];
            $delivery_price = $this->request->post('delivery_price');

            $this->design->assign('delivery_id', $order->delivery_id);
            $this->design->assign('name', $order->name);
            $this->design->assign('email', $order->email);
            $this->design->assign('phone', $order->phone);
            $this->design->assign('address', $order->address);

            $captcha_code =  $this->request->post('captcha_code', 'string');

            // Скидка
            $cart = $this->cart->get_cart();
            $order->discount = $cart->discount;

            if($cart->coupon) {
            	$order->coupon_discount = $cart->coupon_discount;
            	$order->coupon_code = $cart->coupon->code;
            }

            if(!empty($this->user->id)) {
                $order->user_id = $this->user->id;
            }

            if(empty($order->name)) {
                $this->design->assign('error', 'empty_name');
            } elseif(empty($order->email)) {
                $this->design->assign('error', 'empty_email');
            } elseif($this->settings->captcha_cart && (($_SESSION['captcha_code'] != $captcha_code || empty($captcha_code)) || empty($_SESSION['captcha_code']))) {
                $this->design->assign('error', 'captcha');
            } else {
                // Добавляем заказ в базу
                $order->lang_id = $this->languages->lang_id();
                $order_id = $this->orders->add_order($order);
                $_SESSION['order_id'] = $order_id;

                // Если использовали купон, увеличим количество его использований
                if($cart->coupon) {
                    $this->coupons->update_coupon($cart->coupon->id, array('usages'=>$cart->coupon->usages+1));
                }


                // Добавляем товары к заказу
                foreach($_SESSION['shopping_cart'] as $variant_id=>$amount) {
                    $this->orders->add_purchase(array('order_id'=>$order_id, 'variant_id'=>intval($variant_id), 'amount'=>intval($amount)));
                }
                $order = $this->orders->get_order($order_id);

                // Стоимость доставки
                $delivery = $this->delivery->get_delivery($order->delivery_id);


                if(!empty($delivery) && $delivery->free_from > $order->total_price) {
                    //$this->orders->update_order($order->id, array('delivery_price'=>$delivery->price, 'separate_delivery'=>$delivery->separate_payment));
                    $this->orders->update_order($order->id, array('delivery_price'=>$delivery_price, 'separate_delivery'=>$delivery->separate_payment));
                }

                // Отправляем письмо пользователю
                $this->notify->email_order_user($order->id);

                // Отправляем письмо администратору
                $this->notify->email_order_admin($order->id);

                // Очищаем корзину (сессию)
                $this->cart->empty_cart();
                unset($_SESSION['captcha_code']);

                // Передаем данные заказа в RetailCRM
                $this->retail->request('ordersCreate', $this->retail->getOrderRetailData($order_id));

                // Перенаправляем на страницу заказа
                header('location: '.$this->config->root_url.'/'.$this->lang_link.'order/'.$order->url);
            }
        }
    }

    public function fetch()
    {
        //Если в сессии не указан код страны, подставим россию по умолчанию
        if(!$_SESSION['country_code']){
            $_SESSION['country_code']='RU';
        }

        //Если у нас не Россия, то выводим способ доставки только EMS
        if ($_SESSION['country_code'] == 'RU') {
            $deliveries = $this->delivery->get_deliveries(['enabled' => 1]);
            $delivery_price = $this->ems->get_price((string)$_SESSION['country_code'], true, (string)$_SESSION['zipcode']);
        } else {
            $deliveries = $this->delivery->get_deliveries(['enabled' => 1, 'delivery_id' => [3]]);
            $delivery_price = $this->ems->get_price((string)$_SESSION['country_code']);
        }

        foreach($deliveries as $k=>$delivery) {
            $delivery->payment_methods = $this->payment->get_payment_methods(array('delivery_id'=>$delivery->id, 'enabled'=>1));

            if ($delivery->id == 3){
                if (!$delivery_price) {
                    unset($deliveries[$k]);
                    continue;
                }
                $delivery->price = $delivery_price;
            }
        }

        //Передадим в шаблон
        $country_select = $this->ems->get_country((string)$_SESSION['country_code']);
        $this->design->assign('country_select', $country_select);
        $this->design->assign('country_code', (string)$_SESSION['country_code']);
        $this->design->assign('zipcode', (string)$_SESSION['zipcode']);
        $this->design->assign('delivery_price', $delivery_price);
        $this->design->assign('all_currencies', $this->money->get_currencies());
        $this->design->assign('deliveries', $deliveries);

        //Список стран
        $countries = $this->ems->get_countries(array("enabled"=>1, "sotable"=>"name"));
        $this->design->assign('countries', $countries);
        
        // Данные пользователя
        if($this->user) {
            $last_order = $this->orders->get_orders(array('user_id'=>$this->user->id, 'limit'=>1));
            $last_order = reset($last_order);
            if($last_order) {
                $this->design->assign('name', $last_order->name);
                $this->design->assign('email', $last_order->email);
                $this->design->assign('phone', $last_order->phone);
                $this->design->assign('address', $last_order->address);
            } else {
                $this->design->assign('name', $this->user->name);
                $this->design->assign('email', $this->user->email);
                $this->design->assign('phone', $this->user->phone);
                $this->design->assign('address', $this->user->address);
            }
        }

        
        // Если существуют валидные купоны, нужно вывести инпут для купона
        if($this->coupons->count_coupons(array('valid'=>1))>0) {
            $this->design->assign('coupon_request', true);
        }
        
        // Выводим корзину
        return $this->design->fetch('checkout.tpl');
    }
    
}
