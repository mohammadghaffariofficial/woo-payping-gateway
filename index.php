<?php
/*
Plugin Name: افزونه پرداخت پی‌پینگ نسخه اصلاح شده
Version: 1.1
Description: افزونه درگاه پرداخت پی‌پینگ برای ووکامرس با گزینه‌های جدید و رفع باگ کال‌بک برای ووکامرس قدیمی
Plugin URI: https://www.payping.ir/
Author: Rick Sanchez
Author URI: http://m4tinbeigi.ir
Text Domain: woo-payping-gateway
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 5.0
Tested up to: 6.6
Requires PHP: 7.2
WC requires at least: 3.0
WC tested up to: 9.3
*/

if (!defined('ABSPATH')) {
    exit;
}

// Check PHP version
if (version_compare(PHP_VERSION, '7.2', '<')) {
    add_action('admin_init', function() {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(__('این افزونه به PHP نسخه 7.2 یا بالاتر نیاز دارد.', 'woo-payping-gateway'), __('خطای افزونه', 'woo-payping-gateway'), array('back_link' => true));
    });
    return;
}

// Check if WooCommerce is active
function payping_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_init', function() {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('این افزونه به ووکامرس نیاز دارد. لطفاً ووکامرس را نصب و فعال کنید.', 'woo-payping-gateway'), __('خطای افزونه', 'woo-payping-gateway'), array('back_link' => true));
        });
    }
}
add_action('plugins_loaded', 'payping_check_woocommerce', 0);

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
                    $currency_symbol = __('ریال', 'woo-payping-gateway');
                    break;
                case 'IRT':
                    $currency_symbol = __('تومان', 'woo-payping-gateway');
                    break;
                case 'IRHR':
                    $currency_symbol = __('هزار ریال', 'woo-payping-gateway');
                    break;
                case 'IRHT':
                    $currency_symbol = __('هزار تومان', 'woo-payping-gateway');
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
                $this->icon = apply_filters('WC_PPal_logo', plugin_dir_url(__FILE__) . 'logo.png');
                $this->has_fields = false;

                $this->init_form_fields();
                $this->init_settings();

                $this->title = $this->settings['title'];
                $this->description = $this->settings['description'];
                $this->paypingToken = $this->settings['paypingToken'];
                $this->success_massage = $this->settings['success_massage'];
                $this->failed_massage = $this->settings['failed_massage'];

                $checkserver = isset($this->settings['ioserver']) ? $this->settings['ioserver'] : 'no';
                if ($checkserver == 'yes') {
                    $this->baseurl = 'https://api.payping.ir/v3';
                }

                if (version_compare(WC_VERSION, '2.0.0', '>='))
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
                        return __('عملیات با موفقیت انجام شد', 'woo-payping-gateway');
                    case 400:
                        return __('مشکلی در ارسال درخواست وجود دارد', 'woo-payping-gateway');
                    case 500:
                        return __('مشکلی در سرور رخ داده است', 'woo-payping-gateway');
                    case 503:
                        return __('سرور در حال حاضر قادر به پاسخگویی نمی‌باشد', 'woo-payping-gateway');
                    case 401:
                        return __('عدم دسترسی', 'woo-payping-gateway');
                    case 403:
                        return __('دسترسی غیر مجاز', 'woo-payping-gateway');
                    case 404:
                        return __('آیتم درخواستی مورد نظر موجود نمی‌باشد', 'woo-payping-gateway');
                    default:
                        return __('خطای نامشخص', 'woo-payping-gateway');
                }
            }

            public function payping_check_currency($Amount, $currency) {
                $currency = strtolower($currency);
                if ($currency == 'irt' || 
                    $currency == 'toman' || 
                    $currency == 'iran toman' || 
                    $currency == 'iranian toman' || 
                    $currency == 'iran-toman' || 
                    $currency == 'iranian-toman' || 
                    $currency == 'iran_toman' || 
                    $currency == 'iranian_toman' || 
                    $currency == 'تومان' || 
                    $currency == 'تومان ایران') {
                    $Amount = $Amount * 1;
                } elseif ($currency == 'irht') {
                    $Amount = $Amount * 1000;
                } elseif ($currency == 'irhr') {
                    $Amount = $Amount * 100;
                } elseif ($currency == 'irr') {
                    $Amount = $Amount / 10;
                }
                return $Amount;
            }

            public function Send_to_payping_Gateway($order_id) {
                global $woocommerce;

                if (isset($_POST['payping_submit']) && isset($_POST['payping_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['payping_nonce'])), 'payping_checkout')) {
                    // Proceed with payment
                    $woocommerce->session->order_id_payping = $order_id;
                    $order = wc_get_order($order_id);
                    $currency = $order->get_currency();
                    $currency = apply_filters('WC_PPal_Currency', $currency, $order_id);

                    $paypingpayCode = get_post_meta($order_id, '_payping_payCode', true);
                    if (!empty($paypingpayCode)) {
                        wp_safe_redirect(esc_url_raw(sprintf('%s/pay/start/%s', $this->baseurl, $paypingpayCode)));
                        exit;
                    }

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
                        __('خرید به شماره سفارش: %s | توسط: %s %s | خرید از %s', 'woo-payping-gateway'),
                        $order->get_order_number(),
                        $order->billing_first_name,
                        $order->billing_last_name,
                        get_bloginfo('name')
                    );
                    $Mobile = get_post_meta($order_id, '_billing_phone', true) ?: '-';
                    $Email = $order->billing_email;
                    $Paymenter = $order->billing_first_name . ' ' . $order->billing_last_name;
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

                    $args = array(
                        'headers' => array(
                            'X-Platform' => 'woocommerce',
                            'X-Platform-Version' => '1.1',
                            'Accept' => 'application/json',
                            'Authorization' => 'Bearer ' . $this->paypingToken,
                            'Content-Type' => 'application/json',
                            'Cache-Control' => 'no-cache'
                        ),
                        'body' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
                        'timeout' => 30,
                    );

                    $response = wp_remote_post($this->baseurl . '/pay', $args);

                    if (is_wp_error($response)) {
                        $Message = __('خطا در ارتباط به پی‌پینگ: ', 'woo-payping-gateway') . $response->get_error_message();
                    } else {
                        $header = wp_remote_retrieve_response_code($response);
                        $body = wp_remote_retrieve_body($response);

                        if ($header == 200) {
                            $response_data = json_decode($body, true);
                            if (isset($response_data["paymentCode"]) && $response_data["paymentCode"] != '') {
                                update_post_meta($order_id, '_payping_payCode', $response_data["paymentCode"]);
                                $order->add_order_note(__('ساخت موفق پرداخت، کد پرداخت: ', 'woo-payping-gateway') . $response_data["paymentCode"]);
                                wp_safe_redirect(esc_url_raw(sprintf('%s/pay/start/%s', $this->baseurl, $response_data["paymentCode"])));
                                exit;
                            } else {
                                $Message = __('تراکنش ناموفق بود - شرح خطا: عدم وجود کد ارجاع', 'woo-payping-gateway');
                            }
                        } else {
                            $Message = __('تراکنش ناموفق بود - شرح خطا: ', 'woo-payping-gateway') . $this->status_message($header) . ' (' . $header . ')';
                            $Message .= ' | ' . __('پاسخ سرور: ', 'woo-payping-gateway') . esc_html($body);
                        }
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

                // Display form
                $form = '<form action="" method="POST" class="payping-checkout-form" id="payping-checkout-form">';
                $form .= wp_nonce_field('payping_checkout', 'payping_nonce', true, false);
                $form .= '<input type="submit" name="payping_submit" class="button alt" id="payping-payment-button" value="' . esc_attr__('پرداخت', 'woo-payping-gateway') . '"/>';
                $form .= '<a class="button cancel" href="' . esc_url($woocommerce->cart->get_checkout_url()) . '">' . esc_html__('بازگشت', 'woo-payping-gateway') . '</a>';
                $form .= '</form><br/>';
                $form = apply_filters('WC_PPal_Form', $form, $order_id, $woocommerce);

                do_action('WC_PPal_Gateway_Before_Form', $order_id, $woocommerce);
                echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form is filtered and contains escaped elements
                do_action('WC_PPal_Gateway_After_Form', $order_id, $woocommerce);
            }

            public function Return_from_payping_Gateway() {
                global $woocommerce;

                $paypingResponse = isset($_REQUEST['data']) ? sanitize_text_field(wp_unslash($_REQUEST['data'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

                $responseData = json_decode($paypingResponse, true) ?: [];
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // Handle JSON error
                }

                if (isset($_REQUEST['wc_order'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    $order_id = absint($_REQUEST['wc_order']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                } elseif (isset($responseData['clientRefId'])) {
                    $order_id = absint($responseData['clientRefId']);
                } else {
                    $order_id = isset($woocommerce->session->order_id_payping) ? absint($woocommerce->session->order_id_payping) : 0;
                    unset($woocommerce->session->order_id_payping);
                }

                if ($order_id < 1) {
                    $fault = __('شماره سفارش معتبر یافت نشد', 'woo-payping-gateway');
                    $notice = wpautop(wptexturize($this->failed_massage));
                    $notice = str_replace("{fault}", $fault, $notice);
                    wc_add_notice($notice, 'error');
                    do_action('WC_PPal_Return_from_Gateway_No_Order_ID', 0, '', $fault);
                    wp_safe_redirect(esc_url_raw($woocommerce->cart->get_checkout_url()));
                    exit;
                }

                $order = wc_get_order($order_id);
                if (!$order || $order->get_id() != $order_id) {
                    $fault = sprintf(__('سفارش با ID %s یافت نشد.', 'woo-payping-gateway'), $order_id);
                    $notice = wpautop(wptexturize($this->failed_massage));
                    $notice = str_replace("{fault}", $fault, $notice);
                    wc_add_notice($notice, 'error');
                    do_action('WC_PPal_Return_from_Gateway_No_Order_ID', $order_id, '', $fault);
                    wp_safe_redirect(esc_url_raw($woocommerce->cart->get_checkout_url()));
                    exit;
                }

                $currency = $order->get_currency();
                $currency = apply_filters('WC_PPal_Currency', $currency, $order_id);

                if ($order->get_status() != 'completed') {
                    $stored_payment_code = get_post_meta($order_id, '_payping_payCode', true);
                    $refid = isset($responseData['paymentRefId']) ? sanitize_text_field($responseData['paymentRefId']) : null;
                    $refid = apply_filters('WC_PPal_return_refid', $refid);
                    $Transaction_ID = $refid;

                    $clientRefId = isset($responseData['clientRefId']) ? sanitize_text_field($responseData['clientRefId']) : null;
                    $expectedRefId = $this->get_expected_client_ref_id($order);

                    if (!$clientRefId || $clientRefId != $expectedRefId) {
                        $error_message = sprintf(
                            __('شناسه سفارش برگشتی (%s) با شناسه سفارش اصلی (%s) مطابقت ندارد', 'woo-payping-gateway'),
                            $clientRefId ?: __('ندارد', 'woo-payping-gateway'),
                            $expectedRefId
                        );
                        $this->handle_verification_error($order, $Transaction_ID, $error_message);
                        return;
                    }

                    $status = isset($_REQUEST['status']) ? absint($_REQUEST['status']) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                    if (0 === $status) {
                        $this->handle_payment_failure(
                            $order,
                            $Transaction_ID,
                            __('کاربر در صفحه بانک از پرداخت انصراف داده است.', 'woo-payping-gateway'),
                            __('تراکنش توسط شما لغو شد.', 'woo-payping-gateway')
                        );
                        return;
                    }

                    $Amount = intval($order->get_total());
                    $Amount = apply_filters('woocommerce_order_amount_total_IRANIAN_gateways_before_check_currency', $Amount, $currency);
                    $Amount = $this->payping_check_currency($Amount, $currency);

                    $validation_error = $this->validate_return_data($order, $responseData, $Amount, $currency, $stored_payment_code);
                    if ($validation_error) {
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
                    wp_safe_redirect(esc_url_raw(add_query_arg('wc_status', 'success', $this->get_return_url($order))));
                    exit;
                }
            }

            private function validate_return_data($order, $responseData, $Amount, $currency, $stored_payment_code) {
                $order_id = $order->get_id();
                $expected_amount = $this->payping_check_currency(intval($order->get_total()), $currency);

                if (empty($stored_payment_code)) {
                    return __('کد پرداخت ذخیره شده یافت نشد', 'woo-payping-gateway');
                }

                if (!isset($responseData['paymentCode'])) {
                    return __('پارامتر کد پرداخت در پاسخ درگاه وجود ندارد', 'woo-payping-gateway');
                }

                if ($responseData['paymentCode'] !== $stored_payment_code) {
                    return sprintf(
                        __('کد پرداخت برگشتی (%s) با کد ذخیره شده (%s) مطابقت ندارد', 'woo-payping-gateway'),
                        $responseData['paymentCode'],
                        $stored_payment_code
                    );
                }

                if (!isset($responseData['amount'])) {
                    return __('پارامتر مبلغ در پاسخ درگاه وجود ندارد', 'woo-payping-gateway');
                }

                $returned_amount = intval($responseData['amount']);
                if ($returned_amount !== $expected_amount) {
                    return sprintf(
                        __('مبلغ پرداختی (%s) با مبلغ سفارش (%s) مطابقت ندارد', 'woo-payping-gateway'),
                        number_format($returned_amount),
                        number_format($expected_amount)
                    );
                }

                return false;
            }

            private function verify_payment($order, $Transaction_ID, $responseData, $stored_payment_code, $Amount) {
                $order_id = $order->get_id();

                $data = array(
                    'PaymentRefId' => $Transaction_ID,
                    'PaymentCode'  => $stored_payment_code,
                    'Amount'       => $Amount
                );

                $args = array(
                    'headers' => array(
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->paypingToken,
                        'Content-Type' => 'application/json',
                        'Cache-Control' => 'no-cache'
                    ),
                    'body' => wp_json_encode($data, JSON_UNESCAPED_UNICODE),
                    'timeout' => 30,
                );

                $response = wp_remote_post($this->baseurl . '/pay/verify', $args);

                if (is_wp_error($response)) {
                    $this->handle_verification_error($order, $Transaction_ID, __('خطا در ارتباط به پی‌پینگ: ', 'woo-payping-gateway') . $response->get_error_message());
                    return;
                }

                $header = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $rbody = json_decode($body, true) ?: [];
                $code = $header;

                if (isset($rbody['status'], $rbody['metaData']['code']) && $rbody['status'] == 409) {
                    $error_code = (int) $rbody['metaData']['code'];
                    switch ($error_code) {
                        case 110:
                            $this->handle_duplicate_payment($order, $Transaction_ID);
                            return;
                        case 133:
                        default:
                            $error_message = $rbody['metaData']['errors'][0]['message'] ?? __('خطایی رخ داده است.', 'woo-payping-gateway');
                            $this->handle_payment_failure(
                                $order,
                                $Transaction_ID,
                                $error_message,
                                __('اطلاعات پرداخت نامعتبر است، لطفاً مجدد تلاش کنید.', 'woo-payping-gateway')
                            );
                            return;
                    }
                }

                if (!isset($rbody['code']) || $rbody['code'] !== $stored_payment_code) {
                    $this->handle_verification_error($order, $Transaction_ID, __('کد پرداخت برگشتی با کد ذخیره شده مطابقت ندارد', 'woo-payping-gateway'));
                    return;
                }

                $response_amount = isset($rbody['amount']) ? intval($rbody['amount']) : 0;
                if ($response_amount !== $Amount) {
                    $this->handle_verification_error(
                        $order,
                        $Transaction_ID,
                        sprintf(
                            __('مبلغ پرداختی (%s) با مبلغ سفارش (%s) مطابقت ندارد', 'woo-payping-gateway'),
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
                    $this->handle_verification_error($order, $Transaction_ID, $this->status_message($code) ?: __('خطای نامشخص در تایید پرداخت!', 'woo-payping-gateway'));
                }
            }

            private function get_expected_client_ref_id($order) {
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
                $order_id = $order->get_id();
                $full_message = sprintf(__('%s<br>شماره کارت: <b dir="ltr">%s</b>', 'woo-payping-gateway'), $message, $cardNumber);

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
                $notice = apply_filters('woocommerce_thankyou_order_received_text', $notice, $order);
                wc_add_notice($notice, 'success');

                wp_safe_redirect(esc_url_raw(add_query_arg('wc_status', 'success', $this->get_return_url($order))));
                exit;
            }

            private function handle_verification_error($order, $Transaction_ID, $error_message) {
                global $woocommerce;
                $order_id = $order->get_id();
                $user_friendly_message = __('خطایی در تأیید پرداخت رخ داده است. لطفاً با مدیریت سایت تماس بگیرید.', 'woo-payping-gateway');

                $tr_id = ($Transaction_ID && $Transaction_ID != 0) ? '<br/>' . __('کد پیگیری: ', 'woo-payping-gateway') . $Transaction_ID : '';
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

                wp_safe_redirect(esc_url_raw($woocommerce->cart->get_checkout_url()));
                exit;
            }

            private function handle_duplicate_payment($order, $Transaction_ID) {
                global $woocommerce;
                $order_id = $order->get_id();
                $message = __('این سفارش قبلا تایید شده است.', 'woo-payping-gateway');

                update_post_meta($order_id, '_transaction_id', $Transaction_ID);
                if ($order->get_status() != 'completed') {
                    $order->payment_complete($Transaction_ID);
                }

                $order->add_order_note($message);
                wc_add_notice($message, 'success');

                wp_safe_redirect(esc_url_raw(add_query_arg('wc_status', 'success', $this->get_return_url($order))));
                exit;
            }

            private function handle_payment_failure($order, $Transaction_ID, $message, $fault) {
                global $woocommerce;
                $order_id = $order->get_id();

                $tr_id = ($Transaction_ID && $Transaction_ID != 0) ? '<br/>' . __('کد پیگیری: ', 'woo-payping-gateway') . $Transaction_ID : '';
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

                wp_safe_redirect(esc_url_raw($woocommerce->cart->get_checkout_url()));
                exit;
            }
        }
    }
}

add_action('plugins_loaded', 'Load_payping_Gateway', 0);
?>
