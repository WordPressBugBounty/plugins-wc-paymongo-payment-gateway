<?php

namespace Cynder\PayMongo;

use Paymongo\Phaymongo\PaymongoException;
use Paymongo\Phaymongo\PaymongoUtils;

class PaymentIntent {
    use ErrorsTrait;

    protected $type;
    protected $order;
    protected $utils;
    protected $debug_mode;
    protected $test_mode;
    protected $client;

    private $payment_methods = [
        'paymongo_paymaya' => 'paymaya',
        'paymongo_atome' => 'atome',
        'paymongo_bpi' => 'dob',
        'paymongo_unionbank' => 'dob',
        'paymongo_billease' => 'billease',
        'paymongo_gcash' => 'gcash',
        'paymongo_grab_pay' => 'grab_pay',
    ];

    public function __construct($type, $utils, $debug_mode, $test_mode, $client)
    {
        $this->type = $type;
        $this->utils = $utils;
        $this->debug_mode = $debug_mode;
        $this->test_mode = $test_mode;
        $this->client = $client;
    }

    public function getPaymentMethod($order, $callback = null) {
        if (!array_key_exists($this->type, $this->payment_methods)) {
            $this->utils->log('error', '[Processing Payment] Unsupported payment method type: ' . $this->type);
            return null;
        }
        $cb_args = [$this->payment_methods[$this->type]];

        if ($callback !== null) {
            array_push($cb_args, $callback($order));
        } else {
            array_push($cb_args, null);
        }

        array_push($cb_args, PaymongoUtils::generateBillingObject($order, 'woocommerce'));

        try {
            $payment_method = call_user_func_array(array($this->client->paymentMethod(), 'create'), $cb_args);

            if ($this->debug_mode) {
                $this->utils->log('info', '[Processing Payment] Payment method response ' . $this->utils->humanize($payment_method));
            }

            return $payment_method;
        } catch (PaymongoException $e) {
            $formatted_messages = $e->format_errors();

            $this->utils->log('error', '[Processing Payment] Order ID: ' . $order->get_id() . ' - Response: ' . join(',', $formatted_messages));

            foreach ($formatted_messages as $message) {
                $this->utils->addNotice('error', $message);
            }

			return null;
		} catch ( \Exception $e ) {
			$this->utils->log( 'error', '[Processing Payment] Network or System Error during create payment method: ' . $e->getMessage() );
			$this->utils->addNotice( 'error', __( 'A network error occurred while reaching the payment provider. Please try again.', 'paymongo' ) );
			return null;
		}
	}

    public function processPayment($order, $payment_method_id, $return_url_for_gateway, $original_return_url, $send_invoice) {
        $order_id = $order->get_id();

        if (!isset($payment_method_id)) {
            $error_code = 'PI001';
            $this->utils->log('error', $this->getLogError($error_code, [$order_id]));
            $this->utils->addNotice('error', $this->getUserError($error_code));
            return null;
        }

        $payment_intent_id = $order->get_meta('paymongo_payment_intent_id');

        if (!isset($payment_intent_id)) {
            $error_code = 'PI002';
            $this->utils->log('error', $this->getLogError($error_code, [$order_id]));
            $this->utils->addNotice('error', $this->getUserError($error_code));
            return null;
        }

        $amount = floatval($order->get_total());
        $payment_method = $order->get_payment_method();

        $this->utils->trackProcessPayment($amount, $payment_method, $this->test_mode);

        try {
            $payment_method_options = null;

            if ($payment_method == 'paymongo_card_installment') {
                $cc_installment_tenure = isset($_POST['paymongo_cc_installment_tenure']) ? absint(wp_unslash($_POST['paymongo_cc_installment_tenure'])) : null;
                $cc_installment_issuer = isset($_POST['paymongo_cc_installment_issuer']) ? sanitize_text_field(wp_unslash($_POST['paymongo_cc_installment_issuer'])) : null;

                if (isset($cc_installment_issuer) && isset($cc_installment_tenure)) {
                    $payment_method_options =
                        array(
                            "card" => array(
                                "installments" => array(
                                    "plan" => array(
                                        "issuer_id" => $cc_installment_issuer,
                                        "tenure" => intval($cc_installment_tenure),
                                    )
                                )
                            )
                        );
                }
            }

            $payment_intent = $this->client->paymentIntent()->attachPaymentMethod($payment_intent_id, $payment_method_id, $payment_method_options, $return_url_for_gateway);

            if ($this->debug_mode) {
                $this->utils->log('info', '[Processing Payment] Attach payment method response ' . $this->utils->humanize($payment_intent));
            }

            $payment_intent_attributes = $payment_intent['attributes'];
            $payment_intent_status = $payment_intent_attributes['status'];

            $return_obj = ['result' => 'success'];

            if ( $payment_intent_status == 'succeeded' ) {
                $payments = $payment_intent_attributes['payments'] ?? [];

                if ( empty( $payments ) || ! isset( $payments[0]['id'] ) ) {
                    $this->utils->log( 'warning', 'No payments array found despite succeeded status for Order ID: ' . $order_id . '. Relying on webhooks.' );
                    $this->utils->addNotice( 'notice', __( 'Payment is processing. You will receive an email once confirmed.', 'paymongo' ) );
                    
                    // Redirect away from the pay page to prevent re-attempts.
                    $return_obj['redirect'] = $original_return_url;
                    $return_obj['result']   = 'success';
                    return $return_obj;
                }

                $payment = $payments[0];
                $payment_id = $payment['id'];
                $payment_intent_amount = floatval($payment_intent_attributes['amount']) / 100;

                $this->utils->completeOrder($order, $payment_id, $send_invoice);
                $this->utils->emptyCart();
                $this->utils->trackPaymentResolution('successful', $payment_id, $payment_intent_amount, $payment_method, $this->test_mode);

                $this->utils->callAction('cynder_paymongo_successful_payment', $payment);

                $return_obj['redirect'] = $original_return_url;
            } else if ($payment_intent_status == 'awaiting_next_action') {
                $return_obj['redirect'] = $payment_intent_attributes['next_action']['redirect']['url'];
            }

            return $return_obj;
        } catch ( PaymongoException $e ) {
            $formatted_messages   = $e->format_errors();
            $is_already_succeeded = false;

            foreach ( $formatted_messages as $message ) {
                if ( stripos( $message, 'already succeeded' ) !== false ) {
                    $is_already_succeeded = true;
                    break;
                }
            }

            if ( $is_already_succeeded ) {
                // Authoritatively check the API. If paid, it completes the order.
                if ( $this->utils->reconcileOrderAgainstPayMongo( $order, $this->client ) ) {
                    $return_obj = array( 'result' => 'success', 'redirect' => $original_return_url );
                    return $return_obj;
                }
            }

            // Fallback to error formatting if it was an actual failure.
            foreach ($formatted_messages as $message) {
                $this->utils->log( 'error', $this->getLogError( 'PI003', array( 'POST /payment_intent/{id}/attach', $message ) ) );
				$this->utils->addNotice( 'error', $message );
			}

			return null;
		} catch ( \Exception $e ) {
			// Catch all other network/system exceptions so checkout doesn't fatally crash.
			$this->utils->log( 'error', '[Processing Payment] Network or System Error during attach: ' . $e->getMessage() );
            $this->utils->addNotice( 'error', __( 'A network or system error occurred while processing your payment. Please try again or contact support.', 'paymongo' ) );

			return null;
		}
    }
}