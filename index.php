<?php
/*
Plugin Name: افزونه پرداخت پی‌پینگ برای ووکامرس (اصلاح شده)
Version: 1.1
Description: افزونه درگاه پرداخت پی‌پینگ برای ووکامرس با گزینه‌های جدید و رفع باگ کال‌بک
Plugin URI: https://www.payping.ir/
Author: Rick Sanchez
Author URI: http://m4tinbeigi.ir
Text Domain: woo-payping-gateway
*/

if (!defined('ABSPATH')) {
    exit;
}

function Load_payping_Gateway() {
    if (class_exists('WC_Payment_Gateway') && !class_exists('WC_PPal') && !function_exists('Woocommerce_Add_payping_Gateway')) {
        add_filter('woocommerce_payment_gateways', 'Woocommerce_Add_payping_Gateway');
        function Woocommerce_Add_payping_Gateway($methods) {
            $methods[] = 'WC_PPal';
            return $methods;
        }

        add_filter('woocommerce_currencies', 'add_IR_currency_For_PayPing');
        function add_IR_currency_For_PayPing($currencies) {
            $currencies['IRR'] = __('ریال', 'woo-payping-gateway');
            $currencies['IRT'] = __('تومان', 'woo-payping-gateway');
            $currencies['IRHR'] = __('هزار ریال', 'woo-payping-gateway');
            $currencies['IRHT'] = __('هزار تومان', 'woo-payping-gateway');
            return $currencies;
        }

        add_filter('woocommerce_currency_symbol', 'add_IR_currency_symbol_For_PayPing', 10, 2);
        function add_IR_currency_symbol_For_PayPing($currency_symbol, $currency) {
            switch ($currency) {
                case 'IRR':
                    $currency_symbol = 'ریال';
                    break;
                case 'IRT':
                    $currency_symbol = 'تومان';
                    break;
                case 'IRHR':
                    $currency_symbol = 'هزار ریال';
                    break;
                case 'IRHT':
                    $currency_symbol = 'هزار تومان';
                    break;
            }
            return $currency_symbol;
        }

        class WC_PPal extends WC_Payment_Gateway {
            private $baseurl = 'https://api.payping.ir/v3';
            private $paypingToken;
            private $success_massage;
            private $failed_massage;

            public function __construct() {
                $this->id = 'WC_PPal';
                $this->method_title = __('پرداخت از طریق درگاه پی‌پینگ', 'woo-payping-gateway');
                $this->method_description = __('تنظیمات درگاه پرداخت پی‌پینگ برای افزونه فروشگاه ساز ووکامرس', 'woo-payping-gateway');
                $this->icon = apply_filters('WC_PPal_logo', WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/logo.png');
                $this->has_fields = false;

                $this->init_form_fields();
                $this->init_settings();

                $this->title = $this->settings['title'];
                $this->description = $this->settings['description'];
                $this->paypingToken = $this->settings['paypingToken'];
                $this->success_massage = $this->settings['success_massage'];
                $this->failed_massage = $this->settings['failed_massage'];

                // Fix: Check if ioserver exists to avoid undefined index
                $checkserver = isset($this->settings['ioserver']) ? $this->settings['ioserver'] : 'no';
                if ($checkserver === 'yes') {
                    $this->baseurl = 'https://api.payping.ir/v3';
                }

                if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>='))
                    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                else
                    add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));

                add_action('woocommerce_receipt_' . $this->id, array($this, 'Send_to_payping_Gateway'));
                add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'Return_from_payping_Gateway'));
            }

            public function admin_options() {
                parent::admin_options();
            }

            public function init_form_fields() {
                $this->form_fields = apply_filters('WC_PPal_Config', array(
                    'base_confing' => array(
                        'title' => __('تنظیمات پایه ای', 'woo-payping-gateway'),
                        'type' => 'title',
                        'description' => '',
                    ),
                    'enabled' => array(
                        'title' => __('فعالسازی/غیرفعالسازی', 'woo-payping-gateway'),
                        'type' => 'checkbox',
                        'label' => __('فعالسازی درگاه پی‌پینگ', 'woo-payping-gateway'),
                        'description' => __('برای فعالسازی درگاه پرداخت پی‌پینگ باید چک باکس را تیک بزنید', 'woo-payping-gateway'),
                        'default' => 'yes',
                        'desc_tip' => true,
                    ),
                    'ioserver' => array(
                        'title' => __('سرور خارج', 'woo-payping-gateway'),
                        'type' => 'checkbox',
                        'label' => __('اتصال به سرور خارج', 'woo-payping-gateway'),
                        'description' => __('در صورت تیک خوردن، درگاه به سرور خارج از کشور متصل می‌شود.', 'woo-payping-gateway'),
                        'default' => 'no',
                        'desc_tip' => true,
                    ),
                    'title' => array(
                        'title' => __('عنوان درگاه', 'woo-payping-gateway'),
                        'type' => 'text',
                        'description' => __('عنوان درگاه که در طی خرید به مشتری نمایش داده میشود', 'woo-payping-gateway'),
                        'default' => __('پرداخت از طریق پی‌پینگ', 'woo-payping-gateway'),
                        'desc_tip' => true,
                    ),
                    'description' => array(
                        'title' => __('توضیحات درگاه', 'woo-payping-gateway'),
                        'type' => 'text',
                        'desc_tip' => true,
                        'description' => __('توضیحاتی که در طی عملیات پرداخت برای درگاه نمایش داده خواهد شد', 'woo-payping-gateway'),
                        'default' => __('پرداخت به وسیله کلیه کارت های عضو شتاب از طریق درگاه پی‌پینگ', 'woo-payping-gateway')
                    ),
                    'account_confing' => array(
                        'title' => __('تنظیمات حساب پی‌پینگ', 'woo-payping-gateway'),
                        'type' => 'title',
                        'description' => '',
                    ),
                    'paypingToken' => array(
                        'title' => __('توکن', 'woo-payping-gateway'),
                        'type' => 'text',
                        'description' => __('توکن درگاه پی‌پینگ', 'woo-payping-gateway'),
                        'default' => '',
                        'desc_tip' => true
                    ),
                    'payment_confing' => array(
                        'title' => __('تنظیمات عملیات پرداخت', 'woo-payping-gateway'),
                        'type' => 'title',
                        'description' => '',
                    ),
                    'success_massage' => array(
                        'title' => __('پیام پرداخت موفق', 'woo-payping-gateway'),
                        'type' => 'textarea',
                        'description' => __('متن پیامی که میخواهید بعد از پرداخت موفق به کاربر نمایش دهید را وارد نمایید . همچنین می توانید از شورت کد {transaction_id} برای نمایش کد رهگیری (توکن) پی‌پینگ استفاده نمایید .', 'woo-payping-gateway'),
                        'default' => __('با تشکر از شما . سفارش شما با موفقیت پرداخت شد .', 'woo-payping-gateway'),
                    ),
                    'failed_massage' => array(
                        'title' => __('پیام پرداخت ناموفق', 'woo-payping-gateway'),
                        'type' => 'textarea',
                        'description' => __('متن پیامی که میخواهید بعد از پرداخت ناموفق به کاربر نمایش دهید را وارد نمایید . همچنین می توانید از شورت کد {fault} برای نمایش دلیل خطای رخ داده استفاده نمایید .', 'woo-payping-gateway'),
                        'default' => __('پرداخت شما ناموفق بوده است . لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید .', 'woo-payping-gateway'),
                    ),
                ));
            }

            public function process_payment($order_id) {
                $order = wc_get_order($order_id);
                return array(
                    'result' => 'success',
                    'redirect' => $order->get_checkout_payment_url(true)
                );
            }

            public function isJson($string) {
                json_decode($string);
                return (json_last_error() == JSON_ERROR_NONE);
            }

            public function status_message($code) {
                switch ($code) {
                    case 200:
                        return 'عملیات با موفقیت انجام شد';
                    case 400:
                        return 'مشکلی در ارسال درخواست وجود دارد';
                    case 500:
                        return 'مشکلی در سرور رخ داده است';
                    case 503:
                        return 'سرور در حال حاضر قادر به پاسخگویی نمی‌باشد';
                    case 401:
                        return 'عدم دسترسی';
                    case 403:
                        return 'دسترسی غیر مجاز';
                    case 404:
                        return 'آیتم درخواستی مورد نظر موجود نمی‌باشد';
                    default:
                        return 'خطای نامشخص';
                }
            }

            public function payping_check_currency($Amount, $currency) {
                if (strtolower($currency) == strtolower('IRT') || 
                    strtolower($currency) == strtolower('TOMAN') || 
                    strtolower($currency) == strtolower('Iran TOMAN') || 
                    strtolower($currency) == strtolower('Iranian TOMAN') || 
                    strtolower($currency) == strtolower('Iran-TOMAN') || 
                    strtolower($currency) == strtolower('Iranian-TOMAN') || 
                    strtolower($currency) == strtolower('Iran_TOMAN') || 
                    strtolower($currency) == strtolower('Iranian_TOMAN') || 
                    strtolower($currency) == strtolower('تومان') || 
                    strtolower($currency) == strtolower('تومان ایران')) {
                    $Amount = $Amount * 1;
                } elseif (strtolower($currency) == strtolower('IRHT')) {
                    $Amount = $Amount * 1000;
                } elseif (strtolower($currency) == strtolower('IRHR')) {
                    $Amount = $Amount * 100;
                } elseif (strtolower($currency) == strtolower('IRR')) {
                    $Amount = $Amount / 10;
                }
                return $Amount;
            }

            public function Send_to_payping_Gateway($order_id) {
                global $woocommerce;
                $woocommerce->session->order_id_payping = $order_id;
                $order = wc_get_order($order_id);
                // Fix: Use get_currency() instead of get_order_currency()
                $currency = $order->get_currency();
                $currency = apply_filters('WC_PPal_Currency', $currency, $order_id);

                $paypingpayCode = get_post_meta($order_id, '_payping_payCode', true);
                if (!empty($paypingpayCode)) {
                    wp_redirect(sprintf('%s/pay/start/%s', $this->baseurl, $paypingpayCode));
                    exit;
                }

                $form = '<form action="" method="POST" class="payping-checkout-form" id="payping-checkout-form">
                        <input type="submit" name="payping_submit" class="button alt" id="payping-payment-button" value="' . __('پرداخت', 'woo-payping-gateway') . '"/>
                        <a class="button cancel" href="' . $woocommerce->cart->get_checkout_url() . '">' . __('بازگشت', 'woo-payping-gateway') . '</a>
                     </form><br/>';
                $form = apply_filters('WC_PPal_Form', $form, $order_id, $woocommerce);

                do_action('WC_PPal_Gateway_Before_Form', $order_id, $woocommerce);
                echo $form;
                do_action('WC_PPal_Gateway_After_Form', $order_id, $woocommerce);

                // Fix: Use get_total() instead of order_total
                $Amount = intval($order->get_total());
                $Amount = apply_filters('woocommerce_order_amount_total_IRANIAN_gateways_before_check_currency', $Amount, $currency);
                $Amount = $this->payping_check_currency($Amount, $currency);
                $Amount = apply_filters('woocommerce_order_amount_total_IRANIAN_gateways_after_check_currency', $Amount, $currency);
                $Amount = apply_filters('woocommerce_order_amount_total_IRANIAN_gateways_irt', $Amount, $currency);
                $Amount = apply_filters('woocommerce_order_amount_total_payping_gateway', $Amount, $currency);

                $CallbackUrl = add_query_arg('wc_order', $order_id, WC()->api_request_url('WC_PPal'));

                $products = array();
                $order_items = $order->get_items();
                foreach ((array)$order_items as $item) {
                    $products[] = $item['name'] . ' (' . $item['qty'] . ')';
                }
                $order_fees = $order->get_fees();
                foreach ((array)$order_fees as $fee) {
                    $products[] = $fee['name'];
                }
                $products = implode(' - ', $products);

                $Description = sprintf(
                    'خرید به شماره سفارش: %s | توسط: %s %s | خرید از %s',
                    $order->get_order_number(),
                    $order->get_billing_first_name(),
                    $order->get_billing_last_name(),
                    get_bloginfo('name')
                );
                $Mobile = get_post_meta($order_id, '_billing_phone', true) ?: '-';
                $Email = $order->get_billing_email();
                $Paymenter = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                $ResNumber = intval($order->get_order_number());

                $Description = apply_filters('WC_PPal_Description', $Description, $order_id);
                $Mobile = apply_filters('WC_PPal_Mobile', $Mobile, $order_id);
                $Email = apply_filters('WC_PPal_Email', $Email, $order_id);
                $Paymenter = apply_filters('WC_PPal_Paymenter', $Paymenter, $order_id);
                $ResNumber = apply_filters('WC_PPal_ResNumber', $ResNumber, $order_id);
                do_action('WC_PPal_Gateway_Payment', $order_id, $Description, $Mobile);

                $payerIdentity = '';
                if (filter_var($Email, FILTER_VALIDATE_EMAIL)) {
                    $payerIdentity = $Email;
                } elseif (preg_match('/^09[0-9]{9}$/', $Mobile)) {
                    $payerIdentity = $Mobile;
                } else {
                    $payerIdentity = 'guest@payping.ir';
                }

                $data = array(
                    'PayerName'      => $Paymenter,
                    'Amount'         => $Amount,
                    'PayerIdentity'  => $payerIdentity,
                    'ReturnUrl'      => $CallbackUrl,
                    'Description'    => $Description,
                    'ClientRefId'    => (string)$order->get_order_number(),
                    'NationalCode'   => ''
                );

                try {
                    $curl = curl_init();
                    curl_setopt_array($curl, array(
                        CURLOPT_URL => $this->baseurl . "/pay",
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING => "",
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST => "POST",
                        CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
                        CURLOPT_HTTPHEADER => array(
                            "X-Platform: woocommerce",
                            "X-Platform-Version: 1.1",
                            "accept: application/json",
                            "authorization: Bearer " . $this->paypingToken,
                            "content-type: application/json",
                            "cache-control: no-cache"
                        ),
                    ));

                    error_log('PayPing Request Data: ' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                    $response = curl_exec($curl);
                    $header = curl_getinfo($curl);
                    $err = curl_error($curl);
                    curl_close($curl);

                    error_log('PayPing Response: ' . $response);
                    error_log('PayPing HTTP Code: ' . $header['http_code']);

                    if ($err) {
                        $Message = 'خطا در ارتباط به پی‌پینگ: ' . $err;
                    } else {
                        if ($header['http_code'] == 200) {
                            $response = json_decode($response, true);
                            if (isset($response["paymentCode"]) && $response["paymentCode"] != '') {
                                update_post_meta($order_id, '_payping_payCode', $response["paymentCode"]);
                                $order->add_order_note('ساخت موفق پرداخت، کد پرداخت: ' . $response["paymentCode"]);
                                wp_redirect(sprintf('%s/pay/start/%s', $this->baseurl, $response["paymentCode"]));
                                exit;
                            } else {
                                $Message = 'تراکنش ناموفق بود - شرح خطا: عدم وجود کد ارجاع';
                            }
                        } else {
                            $Message = 'تراکنش ناموفق بود - شرح خطا: ' . $this->status_message($header['http_code']) . ' (' . $header['http_code'] . ')';
                            $Message .= ' | پاسخ سرور: ' . $response;
                        }
                    }
                } catch (Exception $e) {
                    $Message = 'تراکنش ناموفق بود - شرح خطا سمت برنامه شما: ' . $e->getMessage();
                }

                if (!empty($Message)) {
                    $Note = sprintf(__('خطا در هنگام ارسال به بانک: %s', 'woo-payping-gateway'), $Message);
                    $order->add_order_note($Note);
                    $Notice = sprintf(__('در هنگام اتصال به بانک خطای زیر رخ داده است: <br/>%s', 'woo-payping-gateway'), $Message);
                    $Notice = apply_filters('WC_PPal_Send_to_Gateway_Failed_Notice', $Notice, $order_id, '');
                    wc_add_notice($Notice, 'error');
                    do_action('WC_PPal_Send_to_Gateway_Failed', $order_id, '');
                }
            }

            public function Return_from_payping_Gateway() {
                error_log('PayPing Callback Started: ' . print_r($_REQUEST, true));

                global $woocommerce;
                if (!isset($woocommerce)) {
                    error_log('Error: WooCommerce session not available in callback');
                    wp_die('WooCommerce not initialized', 'PayPing Error', array('response' => 500));
                }

                $paypingResponse = isset($_REQUEST['data']) ? wp_unslash($_REQUEST['data']) : '';
                error_log('PayPing Raw Data: ' . $paypingResponse);

                $responseData = json_decode($paypingResponse, true) ?: [];
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log('JSON Decode Error: ' . json_last_error_msg());
                }
                error_log('PayPing Decoded Data: ' . print_r($responseData, true));

                if (isset($_REQUEST['wc_order'])) {
                    $order_id = absint($_REQUEST['wc_order']);
                    error_log('Order ID from wc_order: ' . $order_id);
                } elseif (isset($responseData['clientRefId'])) {
                    $order_id = absint($responseData['clientRefId']);
                    error_log('Order ID from clientRefId: ' . $order_id);
                } else {
                    $order_id = isset($woocommerce->session->order_id_payping) ? absint($woocommerce->session->order_id_payping) : 0;
                    unset($woocommerce->session->order_id_payping);
                    error_log('Order ID from session: ' . $order_id);
                }

                if ($order_id < 1) {
                    $fault = 'شماره سفارش معتبر یافت نشد (order_id: ' . $order_id . ')';
                    error_log('PayPing Callback Error: ' . $fault);
                    $notice = wpautop(wptexturize($this->failed_massage));
                    $notice = str_replace("{fault}", $fault, $notice);
                    wc_add_notice($notice, 'error');
                    do_action('WC_PPal_Return_from_Gateway_No_Order_ID', 0, '', $fault);
                    wp_redirect($woocommerce->cart->get_checkout_url());
                    exit;
                }

                $order = wc_get_order($order_id);
                if (!$order || $order->get_id() != $order_id) {
                    $fault = 'سفارش با ID ' . $order_id . ' یافت نشد.';
                    error_log('PayPing Callback Error: ' . $fault);
                    $notice = wpautop(wptexturize($this->failed_massage));
                    $notice = str_replace("{fault}", $fault, $notice);
                    wc_add_notice($notice, 'error');
                    do_action('WC_PPal_Return_from_Gateway_No_Order_ID', $order_id, '', $fault);
                    wp_redirect($woocommerce->cart->get_checkout_url());
                    exit;
                }

                error_log('Order Found: ID ' . $order_id . ', Status: ' . $order->get_status());

                // Fix: Use get_currency() instead of get_order_currency()
                $currency = $order->get_currency();
                $currency = apply_filters('WC_PPal_Currency', $currency, $order_id);

                // Fix: Use get_status() instead of status
                if ($order->get_status() != 'completed') {
                    $stored_payment_code = get_post_meta($order_id, '_payping_payCode', true);
                    $refid = isset($responseData['paymentRefId']) ? sanitize_text_field($responseData['paymentRefId']) : null;
                    $refid = apply_filters('WC_PPal_return_refid', $refid);
                    $Transaction_ID = $refid;

                    error_log('Transaction ID: ' . $Transaction_ID . ', Stored PayCode: ' . $stored_payment_code);

                    $clientRefId = isset($responseData['clientRefId']) ? sanitize_text_field($responseData['clientRefId']) : null;
                    $expectedRefId = $this->get_expected_client_ref_id($order);

                    if (!$clientRefId || $clientRefId != $expectedRefId) {
                        $error_message = sprintf(
                            'شناسه سفارش برگشتی (%s) با شناسه سفارش اصلی (%s) مطابقت ندارد',
                            $clientRefId ?: 'ندارد',
                            $expectedRefId
                        );
                        error_log('PayPing Validation Error: ' . $error_message);
                        $this->handle_verification_error($order, $Transaction_ID, $error_message);
                        return;
                    }

                    $status = isset($_REQUEST['status']) ? absint($_REQUEST['status']) : null;
                    if (0 === $status) {
                        $this->handle_payment_failure(
                            $order,
                            $Transaction_ID,
                            'کاربر در صفحه بانک از پرداخت انصراف داده است.',
                            'تراکنش توسط شما لغو شد.'
                        );
                        return;
                    }

                    // Fix: Use get_total() instead of order_total
                    $Amount = intval($order->get_total());
                    $Amount = apply_filters('woocommerce_order_amount_total_IRANIAN_gateways_before_check_currency', $Amount, $currency);
                    $Amount = $this->payping_check_currency($Amount, $currency);

                    $validation_error = $this->validate_return_data($order, $responseData, $Amount, $currency, $stored_payment_code);
                    if ($validation_error) {
                        error_log('PayPing Validation Error: ' . $validation_error);
                        $this->handle_verification_error($order, $Transaction_ID, $validation_error);
                        return;
                    }

                    $this->verify_payment($order, $Transaction_ID, $responseData, $stored_payment_code, $Amount);
                } else {
                    $Transaction_ID = get_post_meta($order_id, '_transaction_id', true);
                    $Notice = wpautop(wptexturize($this->success_massage));
                    $Notice = str_replace("{transaction_id}", $Transaction_ID, $Notice);
                    $Notice = apply_filters('WC_PPal_Return_from_Gateway_ReSuccess_Notice', $Notice, $order_id, $Transaction_ID);
                    wc_add_notice($Notice, 'success');
                    do_action('WC_PPal_Return_from_Gateway_ReSuccess', $order_id, $Transaction_ID);
                    wp_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
                    exit;
                }
            }

            private function validate_return_data($order, $responseData, $Amount, $currency, $stored_payment_code) {
                // Fix: Use get_id() and get_total() instead of id and order_total
                $order_id = $order->get_id();
                $expected_amount = $this->payping_check_currency(intval($order->get_total()), $currency);

                if (empty($stored_payment_code)) {
                    return 'کد پرداخت ذخیره شده یافت نشد';
                }

                if (!isset($responseData['paymentCode'])) {
                    return 'پارامتر کد پرداخت در پاسخ درگاه وجود ندارد';
                }

                if ($responseData['paymentCode'] !== $stored_payment_code) {
                    return sprintf(
                        'کد پرداخت برگشتی (%s) با کد ذخیره شده (%s) مطابقت ندارد',
                        $responseData['paymentCode'],
                        $stored_payment_code
                    );
                }

                if (!isset($responseData['amount'])) {
                    return 'پارامتر مبلغ در پاسخ درگاه وجود ندارد';
                }

                $returned_amount = intval($responseData['amount']);
                if ($returned_amount !== $expected_amount) {
                    return sprintf(
                        'مبلغ پرداختی (%s) با مبلغ سفارش (%s) مطابقت ندارد',
                        number_format($returned_amount),
                        number_format($expected_amount)
                    );
                }

                return false;
            }

            private function verify_payment($order, $Transaction_ID, $responseData, $stored_payment_code, $Amount) {
                // Fix: Use get_id() instead of id
                $order_id = $order->get_id();

                $data = array(
                    'PaymentRefId' => $Transaction_ID,
                    'PaymentCode'  => $stored_payment_code,
                    'Amount'       => $Amount
                );

                try {
                    $curl = curl_init();
                    curl_setopt_array($curl, array(
                        CURLOPT_URL => $this->baseurl . "/pay/verify",
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING => "",
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST => "POST",
                        CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
                        CURLOPT_HTTPHEADER => array(
                            "accept: application/json",
                            "authorization: Bearer " . $this->paypingToken,
                            "content-type: application/json",
                            "cache-control: no-cache"
                        ),
                    ));

                    error_log('PayPing Verify Request Data: ' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                    $response = curl_exec($curl);
                    $header = curl_getinfo($curl);
                    $err = curl_error($curl);
                    curl_close($curl);

                    error_log('PayPing Verify Response: ' . $response);
                    error_log('PayPing Verify HTTP Code: ' . $header['http_code']);

                    if ($err) {
                        $this->handle_verification_error($order, $Transaction_ID, 'خطا در ارتباط به پی‌پینگ: ' . $err);
                        return;
                    }

                    $rbody = json_decode($response, true) ?: [];
                    $code = $header['http_code'];

                    if (isset($rbody['status'], $rbody['metaData']['code']) && $rbody['status'] == 409) {
                        $error_code = (int) $rbody['metaData']['code'];
                        switch ($error_code) {
                            case 110:
                                $this->handle_duplicate_payment($order, $Transaction_ID);
                                return;
                            case 133:
                            default:
                                $error_message = $rbody['metaData']['errors'][0]['message'] ?? 'خطایی رخ داده است.';
                                $this->handle_payment_failure(
                                    $order,
                                    $Transaction_ID,
                                    $error_message,
                                    'اطلاعات پرداخت نامعتبر است، لطفاً مجدد تلاش کنید.'
                                );
                                return;
                        }
                    }

                    if (!isset($rbody['code']) || $rbody['code'] !== $stored_payment_code) {
                        $this->handle_verification_error($order, $Transaction_ID, 'کد پرداخت برگشتی با کد ذخیره شده مطابقت ندارد');
                        return;
                    }

                    $response_amount = isset($rbody['amount']) ? intval($rbody['amount']) : 0;
                    if ($response_amount !== $Amount) {
                        $this->handle_verification_error(
                            $order,
                            $Transaction_ID,
                            sprintf(
                                'مبلغ پرداختی (%s) با مبلغ سفارش (%s) مطابقت ندارد',
                                number_format($response_amount),
                                number_format($Amount)
                            )
                        );
                        return;
                    }

                    $cardNumber = isset($rbody['cardNumber']) ? sanitize_text_field($rbody['cardNumber']) : '-';
                    $CardHashPan = isset($rbody['cardHashPan']) ? sanitize_text_field($rbody['cardHashPan']) : '-';
                    update_post_meta($order_id, 'payping_payment_card_number', $cardNumber);
                    update_post_meta($order_id, 'payping_payment_card_hashpan', $CardHashPan);

                    if (200 === $code) {
                        $this->handle_payment_success($order, $Transaction_ID, $cardNumber, $this->status_message($code));
                    } else {
                        $this->handle_verification_error($order, $Transaction_ID, $this->status_message($code) ?: 'خطای نامشخص در تایید پرداخت!');
                    }
                } catch (Exception $e) {
                    $this->handle_verification_error($order, $Transaction_ID, 'خطا در تایید پرداخت: ' . $e->getMessage());
                }
            }

            private function get_expected_client_ref_id($order) {
                // Fix: Use get_id() instead of id
                $parent_order_id = method_exists($order, 'get_parent_id') ? $order->get_parent_id() : 0;
                if ($parent_order_id > 0) {
                    $parent_order = wc_get_order($parent_order_id);
                    if ($parent_order) {
                        return (string) $parent_order->get_id();
                    }
                }
                return (string) $order->get_id();
            }

            private function handle_payment_success($order, $Transaction_ID, $cardNumber, $message) {
                global $woocommerce;
                // Fix: Use get_id() instead of id
                $order_id = $order->get_id();
                $full_message = sprintf('%s<br>شماره کارت: <b dir="ltr">%s</b>', $message, $cardNumber);

                update_post_meta($order_id, '_transaction_id', $Transaction_ID);
                $woocommerce->cart->empty_cart();
                $order->payment_complete($Transaction_ID);

                $note = sprintf(
                    __('%s <br>شماره پیگیری پرداخت: %s', 'woo-payping-gateway'),
                    $full_message,
                    $Transaction_ID
                );
                $order->add_order_note($note);

                $notice = wpautop(wptexturize($this->success_massage));
                $notice = str_replace('{transaction_id}', $Transaction_ID, $notice);
                // Fix: Pass $order instead of $order_id to avoid fatal error
                $notice = apply_filters('woocommerce_thankyou_order_received_text', $notice, $order, $Transaction_ID);
                wc_add_notice($notice, 'success');

                wp_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
                exit;
            }

            private function handle_verification_error($order, $Transaction_ID, $error_message) {
                global $woocommerce;
                // Fix: Use get_id() instead of id
                $order_id = $order->get_id();
                $user_friendly_message = 'خطایی در تأیید پرداخت رخ داده است. لطفاً با مدیریت سایت تماس بگیرید.';

                $tr_id = ($Transaction_ID && $Transaction_ID != 0) ? '<br/>کد پیگیری: ' . $Transaction_ID : '';
                $note = sprintf(
                    __('خطا در تأیید پرداخت: %s %s', 'woo-payping-gateway'),
                    $error_message,
                    $tr_id
                );

                $notice = wpautop(wptexturize($this->failed_massage));
                $notice = str_replace('{transaction_id}', $Transaction_ID, $notice);
                $notice = str_replace('{fault}', $error_message, $notice);

                $order->add_order_note($note);
                wc_add_notice($user_friendly_message, 'error');

                wp_redirect($woocommerce->cart->get_checkout_url());
                exit;
            }

            private function handle_duplicate_payment($order, $Transaction_ID) {
                global $woocommerce;
                // Fix: Use get_id() instead of id
                $order_id = $order->get_id();
                $message = 'این سفارش قبلا تایید شده است.';

                update_post_meta($order_id, '_transaction_id', $Transaction_ID);
                // Fix: Use get_status() instead of status
                if ($order->get_status() != 'completed') {
                    $order->payment_complete($Transaction_ID);
                }

                $order->add_order_note($message);
                wc_add_notice($message, 'success');

                wp_redirect(add_query_arg('wc_status', 'success', $this->get_return_url($order)));
                exit;
            }

            private function handle_payment_failure($order, $Transaction_ID, $message, $fault) {
                global $woocommerce;
                // Fix: Use get_id() instead of id
                $order_id = $order->get_id();

                $tr_id = ($Transaction_ID && $Transaction_ID != 0) ? '<br/>کد پیگیری: ' . $Transaction_ID : '';
                $note = sprintf(
                    __('خطا در هنگام تایید پرداخت: %s %s', 'woo-payping-gateway'),
                    $message,
                    $tr_id
                );

                $notice = wpautop(wptexturize($this->failed_massage));
                $notice = str_replace("{transaction_id}", $Transaction_ID, $notice);
                $notice = str_replace("{fault}", $fault, $notice);

                $order->add_order_note($note);
                wc_add_notice($fault, 'error');

                wp_redirect($woocommerce->cart->get_checkout_url());
                exit;
            }
        }
    }
}

add_action('plugins_loaded', 'Load_payping_Gateway', 0);
?>
