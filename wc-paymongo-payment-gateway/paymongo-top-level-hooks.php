<?php
/**
 * PHP version 7
 * 
 * PayMongo - Top Level Hooks File
 * 
 * @category Plugin
 * @package  PayMongo
 * @author   PayMongo <devops@cynder.io>
 * @license  n/a (http://127.0.0.0)
 * @link     n/a
 */

use Cynder\PayMongo\Utils;
use GuzzleHttp\Exception\ClientException;
use Paymongo\Phaymongo\PaymongoException;
use Paymongo\Phaymongo\Phaymongo;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

function cynder_paymongo_create_intent($orderId) {
    $utils = new Utils();

    $testMode = get_option('woocommerce_cynder_paymongo_test_mode');
    $testMode = (!empty($testMode) && $testMode === 'yes') ? true : false;

    $debugMode = get_option('woocommerce_cynder_paymongo_debug_mode');
    $debugMode = (!empty($debugMode) && $debugMode === 'yes') ? true : false;

    $order = wc_get_order($orderId);

    $paymentMethod = $order->get_payment_method();

    $hasPaymentMethod = isset($paymentMethod) && $paymentMethod !== '' && $paymentMethod !== null;
    $paymentMethodSettings = get_option("woocommerce_{$paymentMethod}_settings");

    /**
     * Don't create a payment intent for the following scenarios:
     * 
     * 1. Payment method setting is disabled
     * 2. Has no payment method (ex. 100% discounts)
     * 3. Payment method does not belong to methods that needs payment intents
     */
    if (
        $paymentMethodSettings['enabled'] !== 'yes' ||
        !$hasPaymentMethod ||
        (!in_array($paymentMethod, PAYMONGO_PAYMENT_METHODS))
    ) {
        return;
    }

    $total = $order->get_total();

    if (!is_numeric($total) || floatval($total) <= 0) {
        $errorMessage = 'Invalid amount';
        wc_get_logger()->log('error', '[Create Payment Intent] ' . $errorMessage);
        throw new Exception(__($errorMessage, 'woocommerce'));
    }

    $amount = floatval($total);

    $pkKey = $testMode ? 'woocommerce_cynder_paymongo_test_public_key' : 'woocommerce_cynder_paymongo_public_key';
    $skKey = $testMode ? 'woocommerce_cynder_paymongo_test_secret_key' : 'woocommerce_cynder_paymongo_secret_key';
    $publicKey = get_option($pkKey);
    $secretKey = get_option($skKey);
    $client = new Phaymongo($publicKey, $secretKey);

    $genericErrorMessage = 'Something went wrong with the payment. Please try another payment method. If issue persist, contact support.';

    try {
        $existingIntentId = $order->get_meta(PAYMONGO_PAYMENT_INTENT_META_KEY);
        $existingClientKey = $order->get_meta(PAYMONGO_CLIENT_KEY_META_KEY);

        // Pre-emptive check: Don't create a new intent if the existing one is already successful.
        if ( ! empty( $existingIntentId ) ) {
            try {
                $existingIntent = $client->paymentIntent()->retrieveById( $existingIntentId );
                $status = isset($existingIntent['attributes']['status']) ? $existingIntent['attributes']['status'] : '';

                if ( in_array( $status, array( 'succeeded', 'processing' ) ) ) {
                    // Idempotency guard: Complete the order right here if it succeeded.
                    if ( 'succeeded' === $status && ! $order->is_paid() ) {
                        $payments = isset( $existingIntent['attributes']['payments'] ) ? $existingIntent['attributes']['payments'] : array();
                        if ( ! empty( $payments ) && isset( $payments[0]['id'] ) ) {
                            $payment = $payments[0];
                            $send_invoice = get_option( 'woocommerce_cynder_paymongo_send_invoice_after_payment' ) === 'yes';
                            $utils->completeOrder( $order, $payment['id'], $send_invoice );
                            $utils->emptyCart();
                            $intent_amount = isset( $existingIntent['attributes']['amount'] ) ? floatval( $existingIntent['attributes']['amount'] ) / 100 : $amount;
                            $utils->trackPaymentResolution( 'successful', $payment['id'], $intent_amount, $paymentMethod, $testMode );
                            $utils->callAction( 'cynder_paymongo_successful_payment', $payment );
                        } elseif ( $debugMode ) {
                             wc_get_logger()->log( 'warning', '[Create Payment Intent] Existing intent is succeeded but has no payment record; skipping local completion and relying on webhooks.' );
                        }
                    }
                    return; // Halt creation of a new intent.
                }
            } catch ( \Throwable $e ) {
                if ( $debugMode ) {
                    wc_get_logger()->log( 'warning', '[Create Payment Intent] Failed to verify existing intent status: ' . $e->getMessage() );
                }
            }
        }

        /**
         * Used by Card Installments. Configure
         * when card installments is working.
         */
        $payment_method_options = null;

        if ($paymentMethod == 'paymongo_card_installment') {
            $cc_installment_tenure = isset($_POST['paymongo_cc_installment_tenure']) ? absint(wp_unslash($_POST['paymongo_cc_installment_tenure'])) : null;
            $cc_installment_issuer = isset($_POST['paymongo_cc_installment_issuer']) ? sanitize_text_field(wp_unslash($_POST['paymongo_cc_installment_issuer'])) : null;

            if (
                is_string($cc_installment_issuer) &&
                $cc_installment_issuer !== '' &&
                is_int($cc_installment_tenure) &&
                $cc_installment_tenure > 0
            ) {
                $payment_method_options =
                    array(
                        "card" => array(
                            "request_three_d_secure" => "any",
                            "installments" => array(
                                "enabled" => true,
                            )
                        )
                    );
            }
        }

        $shopName = get_bloginfo('name');
        $paymentIntent = $client->paymentIntent()->create(
            $amount,
            array( 'card', 'paymaya', 'atome', 'dob', 'billease', 'gcash', 'grab_pay' ),
            $payment_method_options,
            $shopName . ' - ' . $orderId,
            array(
                'agent' => 'cynder_woocommerce',
                'version' => CYNDER_PAYMONGO_VERSION,
                'store_name' => $shopName,
                'order_id' => strval( $orderId ),
                'customer_id' => strval( $order->get_customer_id() ),
            )
        );

        if ( $debugMode ) {
            wc_get_logger()->log('info', '[Create Payment Intent] Response ' . wc_print_r($paymentIntent, true));
        }
    
        if ($paymentIntent
            && array_key_exists('attributes', $paymentIntent)
            && array_key_exists('status', $paymentIntent['attributes'])
            && $paymentIntent['attributes']['status'] == 'awaiting_payment_method'
        ) {
            $clientKey = $paymentIntent['attributes']['client_key'];

            // Safely rotate to arrays using update_meta_data.
            if ( ! empty( $existingIntentId ) ) {
                $old_intents = $order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY . '_old', false );
                $old_intents_array = ! empty( $old_intents ) ? (array) $old_intents : array();

                // Flatten array in case get_meta(_, false) returned nested arrays from old data.
                $flat_old_intents = array();
                array_walk_recursive(
                    $old_intents_array,
                    function ( $vv ) use ( &$flat_old_intents ) {
                        if ( null !== $vv && '' !== $vv ) {
                            $flat_old_intents[] = strval( $vv );
                        }
                    }
                );
                $flat_old_intents[] = strval( $existingIntentId );
                $unique_old_intents = array_values( array_unique( $flat_old_intents ) );
                $order->delete_meta_data( PAYMONGO_PAYMENT_INTENT_META_KEY . '_old' );
                $order->update_meta_data( PAYMONGO_PAYMENT_INTENT_META_KEY . '_old', $unique_old_intents );
            }

            if ( ! empty( $existingClientKey ) ) {
                $old_keys = $order->get_meta( PAYMONGO_CLIENT_KEY_META_KEY . '_old', false );
                $old_keys_array = ! empty( $old_keys ) ? (array) $old_keys : array();

                $flat_old_keys = array();
                array_walk_recursive(
                    $old_keys_array,
                    function ( $vv ) use ( &$flat_old_keys ) {
                        if ( null !== $vv && '' !== $vv ) {
                            $flat_old_keys[] = strval( $vv );
                        }
                    }
                );
                $flat_old_keys[] = strval( $existingClientKey );
                $unique_old_keys = array_values( array_unique( $flat_old_keys ) );
                $order->delete_meta_data( PAYMONGO_CLIENT_KEY_META_KEY . '_old' );
                $order->update_meta_data( PAYMONGO_CLIENT_KEY_META_KEY . '_old', $unique_old_keys );
            }

            $order->update_meta_data(PAYMONGO_PAYMENT_INTENT_META_KEY, $paymentIntent['id']);
            $order->update_meta_data(PAYMONGO_CLIENT_KEY_META_KEY, $clientKey);
            $order->save_meta_data();
        } else {
            wc_get_logger()->log('error', '[Create Payment Intent] ' . json_encode($paymentIntent['errors']));
            throw new Exception(__($genericErrorMessage, 'woocommerce'));
        }
    } catch (PaymongoException $e) {
        $formatted_messages = $e->format_errors();
        $utils->log('error', '[Create Payment Intent] Response - ' . join(',', $formatted_messages));
        throw new Exception(__($genericErrorMessage, 'woocommerce'));
    }
}

add_action('woocommerce_checkout_order_processed', 'cynder_paymongo_create_intent');

function cynder_paymongo_catch_redirect() {
    $utils = new Utils();

    if (empty($_GET['intent']) || empty($_GET['order']) || empty($_GET['key'])) {
        $missingParams = array();

        if (empty($_GET['intent'])) {
            $missingParams[] = 'intent';
        }

        if (empty($_GET['order'])) {
            $missingParams[] = 'order';
        }

        if (empty($_GET['key'])) {
            $missingParams[] = 'key';
        }

        wc_get_logger()->log('warning', '[Catch Redirect] Missing required query parameter(s): ' . implode(', ', $missingParams) . '.');
        wc_add_notice(__('Unable to verify your payment. Please try again.', 'paymongo'), 'error');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    $debugMode = get_option('woocommerce_cynder_paymongo_debug_mode');
    $debugMode = (!empty($debugMode) && $debugMode === 'yes') ? true : false;

    $sendInvoice = get_option('woocommerce_cynder_paymongo_send_invoice_after_payment');
    $sendInvoice = (!empty($sendInvoice) && $sendInvoice === 'yes') ? true : false;

    if ($debugMode) {
        $safeGet = $utils->redactSensitiveData($_GET);
        wc_get_logger()->log('info', '[Catch Redirect][Payload] ' . wc_print_r($safeGet, true));
    }

    $paymentIntentId = sanitize_text_field(wp_unslash($_GET['intent']));

    if (empty($paymentIntentId)) {
        wc_get_logger()->log('warning', '[Catch Redirect] Empty payment intent ID after sanitization.');
        wc_add_notice(__('Unable to verify your payment. Please try again.', 'paymongo'), 'error');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    $testMode = get_option('woocommerce_cynder_paymongo_test_mode');
    $testMode = (!empty($testMode) && $testMode === 'yes') ? true : false;

    $pkKey = $testMode ? 'woocommerce_cynder_paymongo_test_public_key' : 'woocommerce_cynder_paymongo_public_key';
    $skKey = $testMode ? 'woocommerce_cynder_paymongo_test_secret_key' : 'woocommerce_cynder_paymongo_secret_key';
    $publicKey = get_option($pkKey);
    $secretKey = get_option($skKey);
    $client = new Phaymongo($publicKey, $secretKey);

    $orderId = absint(wp_unslash($_GET['order']));
    $orderKey = sanitize_text_field(wp_unslash($_GET['key']));
    $resolvedOrderId = wc_get_order_id_by_order_key($orderKey);
    $order = wc_get_order($resolvedOrderId);

    if (!$order || $order->get_id() !== $orderId) {
        wc_add_notice(__('Invalid order reference.', 'paymongo'), 'error');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    // Verify that the Intent ID matches the current one OR any _old intent on the same order.
    $storedIntentId    = $order->get_meta( 'paymongo_payment_intent_id' );
    $old_intents       = $order->get_meta( 'paymongo_payment_intent_id_old', false );
    $old_intents_array = ! empty( $old_intents ) ? (array) $old_intents : array();

    // Flatten just in case.
    $flat_old_intents = array();
    array_walk_recursive(
        $old_intents_array,
        function ( $vv ) use ( &$flat_old_intents ) {
            if ( null !== $vv && '' !== $vv ) {
                $flat_old_intents[] = strval( $vv );
            }
        }
    );

    if ( $paymentIntentId !== $storedIntentId && ! in_array( $paymentIntentId, $flat_old_intents, true ) ) {
        wc_get_logger()->log( 'error', '[Catch Redirect] Payment intent mismatch for Order ID: ' . $orderId );
        wc_add_notice( __( 'Payment verification failed.', 'paymongo' ), 'error' );
        wp_safe_redirect( $order->get_checkout_payment_url() );
        exit;
    }

    try {
        $paymentIntent = $client->paymentIntent()->retrieveById($paymentIntentId);

        if ($debugMode) {
            wc_get_logger()->log('info', '[Catch Redirect][Response] ' . wc_print_r($paymentIntent, true));
        }

        $responseAttr = $paymentIntent['attributes'];
        $status = $responseAttr['status'];
        $intentAmount = $responseAttr['amount'];

        /** If payment intent status is succeeded or processing, just empty cart and redirect to confirmation page */
        if ($status === 'succeeded' || $status === 'processing') {
            if ($status === 'succeeded') {
                $payment = $responseAttr['payments'][0];

                $utils->completeOrder($order, $payment['id'], $sendInvoice);
                $utils->trackPaymentResolution('successful', $payment['id'], floatval($intentAmount) / 100, $order->get_payment_method(), $testMode);
                $utils->callAction('cynder_paymongo_successful_payment', $payment);
            }

            // Empty cart
            $utils->emptyCart();

            // Redirect to the thank you page
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        } else if ($status === 'awaiting_payment_method' || $status === 'awaiting_next_action') {
            wc_add_notice('Something went wrong with the payment. Please try another payment method. If issue persist, contact support.', 'error');
            wp_safe_redirect($order->get_checkout_payment_url());
            exit;
        }
    } catch (PaymongoException $e) {
        /** 
         * Log the error but confirm the order placement. This will fallback
         * to the webhooks for proper resolution.
         */
        $formatted_messages = $e->format_errors();
        $utils->log('error', '[Catch Redirect for Payment Intent] Order ID: ' . $order->get_id() . ' - Response: ' . join(',', $formatted_messages));
        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }
}

add_action(
    'woocommerce_api_cynder_paymongo_catch_redirect',
    'cynder_paymongo_catch_redirect'
);


function cynder_paymongo_catch_source_redirect() {
    if (empty($_GET['order']) || empty($_GET['status']) || empty($_GET['key'])) {
        wc_add_notice(__('Missing payment or order reference.', 'paymongo'), 'error');
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    $orderId = absint(wp_unslash($_GET['order']));
    $status = sanitize_text_field(wp_unslash($_GET['status']));
    $orderKey = sanitize_text_field(wp_unslash($_GET['key']));

    $order = wc_get_order($orderId);

    if (!$order || $order->get_order_key() !== $orderKey) {
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    if ($status === 'success') {
        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    } else if ($status === 'failed') {
        wc_add_notice('Something went wrong with the payment. Please try another payment method. If issue persist, contact support.', 'error');
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    } else {
        wc_add_notice(__('Invalid payment redirect status. Please try again or choose another payment method.', 'paymongo'), 'error');
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }
}

add_action(
    'woocommerce_api_cynder_paymongo_catch_source_redirect',
    'cynder_paymongo_catch_source_redirect'
);

function add_webhook_settings($settings, $current_section) {
    if (in_array($current_section, PAYMONGO_PAYMENT_METHODS)) {
        $webhookUrl = add_query_arg(
            'wc-api',
            'cynder_paymongo',
            trailingslashit(get_home_url())
        );

        $settings_webhooks = array(
            array(
                'name' => 'API Settings',
                'id' => 'paymongo_api_settings_title',
                'type' => 'title',
                'desc' => 'PayMongo API settings'
            ),
            array(
                'id' => 'live_env',
                'title' => 'Live Environment',
                'type' => 'title',
                'description' => 'Use live keys for actual payments'
            ),
            array(
                'id'          => 'woocommerce_cynder_paymongo_public_key',
                'title'       => 'Live Public Key',
                'type'        => 'text'
            ),
            array(
                'id'          => 'woocommerce_cynder_paymongo_secret_key',
                'title'       => 'Live Secret Key',
                'type'        => 'text'
            ),
            array(
                'name' => 'Live Webhook Secret',
                'id' => 'paymongo_webhook_secret_key',
                'type' => 'text',
                'desc_tip' => 'This is required to properly process payments and update order statuses accordingly',
                'desc' => '<a target="_blank" href="https://paymongo-webhook-tool.cynder.io?url=' 	
                . $webhookUrl	
                . '">Go here to generate a webhook secret</a>',
            ),
            array(
                'id' => 'live_env_end',
                'type' => 'sectionend'
            ),
            array(
                'id' => 'test_env',
                'title' => 'Test Environment',
                'type' => 'title',
                'desc' => 'Use the plugin in <b>Test Mode</b><br/>In test mode, you can transact using the PayMongo payment methods in checkout without actual payments'
            ),
            array(
                'id' => 'woocommerce_cynder_paymongo_test_mode',
                'title'       => 'Test mode',
                'label'       => 'Enable Test Mode',
                'type'        => 'checkbox',
                'desc' => 'Place the payment gateway in test mode using <b>Test API keys</b>',
                'default'     => 'yes',
            ),
            array(
                'id'          => 'woocommerce_cynder_paymongo_test_public_key',
                'title'       => 'Test Public Key',
                'type'        => 'text'
            ),
            array(
                'id'          => 'woocommerce_cynder_paymongo_test_secret_key',
                'title'       => 'Test Secret Key',
                'type'        => 'text'
            ),
            array(
                'name' => 'Test Webhook Secret',
                'id' => 'paymongo_test_webhook_secret_key',
                'type' => 'text',
                'desc_tip' => 'This is required to properly process payments and update order statuses accordingly',
                'desc' => '<a target="_blank" href="https://paymongo-webhook-tool.cynder.io?url=' 	
                . $webhookUrl	
                . '">Go here to generate a webhook secret</a>',
            ),
            array(
                'id' => 'test_env_end',
                'type' => 'sectionend'
            ),
            array(
                'id' => 'paymongo_misc',
                'title' => 'Other Options',
                'type' => 'title',
            ),
            array(
                'id' => 'woocommerce_cynder_paymongo_debug_mode',
                'title'       => 'Debug mode',
                'label'       => 'Enable Debug Mode',
                'type'        => 'checkbox',
                'desc_tip' => 'This enables additional logs in WC logger for developer analysis',
                'desc' => 'Enable additional logs',
                'default'     => 'no',
            ),
            array(
                'id' => 'woocommerce_cynder_paymongo_send_invoice_after_payment',
                'title' => 'Send Invoice',
                'desc' => 'Enables automatic invoice sending after payment',
                'desc_tip' => 'This enables automatic sending of an invoice to the customer via e-mail after payment is resolved',
                'type' => 'checkbox',
                'default' => 'yes',
            ),
            array(
                'type' => 'sectionend',
                'id' => 'paymongo_api_settings',
            ),
        );

        return $settings_webhooks;
    } else {
        return $settings;
    }
}

add_filter(
    'woocommerce_get_settings_checkout',
    'add_webhook_settings',
    10,
    2
);

function update_cynder_paymongo_plugin() {
    $oldVersion = get_option('cynder_paymongo_version');

    /**
     * Prior to 1.4.8, API settings are in credit/debit card screen only
     * 
     * Updating the plugin to 1.4.8 or higher moves the settings as shared ones on
     * all PayMongo payment methods
     */
    if (version_compare($oldVersion, '1.5.0', '<')) {
        $mainPluginSettings = get_option('woocommerce_paymongo_settings');

        /** Migrate old settings to new settings */
        $settingsToMigrage = array(
            'public_key' => 'woocommerce_cynder_paymongo_public_key',
            'secret_key' => 'woocommerce_cynder_paymongo_secret_key',
            'test_public_key' => 'woocommerce_cynder_paymongo_test_public_key',
            'test_secret_key' => 'woocommerce_cynder_paymongo_test_secret_key',
            'testmode' => 'woocommerce_cynder_paymongo_test_mode'
        );

        foreach ($settingsToMigrage as $oldKey => $newKey) {
            $newSetting = get_option($newKey);

            if (!$newSetting) {
                update_option($newKey, $mainPluginSettings[$oldKey], true);
            }
        }
    }
}

add_action('woocommerce_paymongo_updated', 'update_cynder_paymongo_plugin');

function cynder_paymongo_notices() {
    $version = get_option('cynder_paymongo_version');

    if (version_compare($version, '1.5.0', '<')) {
        echo '<div class="notice notice-warning">'
        . '<p><strong>You are using an outdated version of the PayMongo payment plugin</strong>. Please upgrade immediately using '
        . '<a target="_blank" href="https://cynder.atlassian.net/servicedesk/customer/portal/1/article/709656577">this guide</a>.</p>'
        . '</div>';
    }
}

add_action('admin_notices', 'cynder_paymongo_notices');