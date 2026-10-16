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
    protected $locked_client;

    private $payment_methods = [
        'paymongo_paymaya' => 'paymaya',
        'paymongo_atome' => 'atome',
        'paymongo_bpi' => 'dob',
        'paymongo_unionbank' => 'dob',
        'paymongo_billease' => 'billease',
        'paymongo_gcash' => 'gcash',
        'paymongo_grab_pay' => 'grab_pay',
    ];

    public function __construct($type, $utils, $debug_mode, $test_mode, $client, $locked_client = null)
    {
        $this->type = $type;
        $this->utils = $utils;
        $this->debug_mode = $debug_mode;
        $this->test_mode = $test_mode;
        $this->client = $client;
        // Only reconcileOrderAgainstPayMongoLocked()'s "already succeeded"
        // recovery runs while the per-order lock is held; everything else
        // on $client runs before any lock is acquired. Defaults to $client
        // so existing callers that don't pass one keep working.
        $this->locked_client = $locked_client ?? $client;
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

        $payment_intent_id = $order->get_meta(PAYMONGO_PAYMENT_INTENT_META_KEY);

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
                $payment_intent_amount = floatval($payment_intent_attributes['amount']) / 100;

                // Serialized with the same per-order lock the reconciliation
                // sweep and webhook handler use -- without it, this
                // customer-driven completion could race one of those
                // completing the exact same order concurrently.
                if (!$this->utils->completeOrderLocked($order, $payment, $send_invoice, $payment_intent_amount, $payment_method, $this->test_mode)) {
                    $this->utils->log('warning', 'Order lock is busy after successful payment for Order ID: ' . $order_id . '; deferring completion to webhook/reconciliation.');
                    $this->utils->addNotice('notice', __('Payment is processing. You will receive an email once confirmed.', 'paymongo'));
                    // PayMongo has already confirmed this payment succeeded --
                    // only the order's own bookkeeping is deferred (to the
                    // webhook or sweep), not the payment itself. Emptying the
                    // cart now, not only on the immediate-completion path
                    // below, is what stops a customer who lands on the
                    // thank-you page with a still-full cart from checking
                    // out again before that bookkeeping catches up.
                    $this->utils->emptyCart();
                    $return_obj['redirect'] = $original_return_url;
                    return $return_obj;
                }

                $this->utils->emptyCart();

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
                // Authoritatively check the API. If paid, it completes the
                // order -- under the same per-order lock completeOrderLocked()
                // uses, for the same reason.
                $reconciled = $this->utils->reconcileOrderAgainstPayMongoLocked( $order, $this->locked_client );

                if ( true === $reconciled ) {
                    return array( 'result' => 'success', 'redirect' => $original_return_url );
                }

                if ( null === $reconciled ) {
                    // Genuine uncertainty only (a transient error, lock
                    // contention, an in-flight 3DS redirect with nothing
                    // else ambiguous) -- see
                    // Utils::reconcileOrderAgainstPayMongoLocked()'s own
                    // docblock. Telling the customer their payment is
                    // processing is accurate here; it is not for false
                    // below, which means the scan definitively found
                    // nothing paid despite PayMongo reporting this intent
                    // "already succeeded" moments ago.
                    $this->utils->log( 'warning', 'Order reconciliation is deferred after PayMongo reported the intent already succeeded for Order ID: ' . $order->get_id() );
                    $this->utils->addNotice( 'notice', __( 'Payment is processing. You will receive an email once confirmed.', 'paymongo' ) );
                    return array( 'result' => 'success', 'redirect' => $original_return_url );
                }

                // $reconciled === false: a definitive miss. Falls through to
                // the same error formatting below as any other
                // PaymongoException -- telling this customer "processing"
                // here would be actively wrong, the same customer-misrouting
                // this method's other recovery paths already guard against.
                $this->utils->log( 'warning', 'Order reconciliation found no paid intent despite PayMongo reporting this one already succeeded, for Order ID: ' . $order->get_id() );
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