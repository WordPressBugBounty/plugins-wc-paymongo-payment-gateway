<?php
/**
 * PHP version 7
 * 
 * Handles all incoming webhooks for PayMongo
 * 
 * @category Plugin
 * @package  PayMongo
 * @author   PayMongo <devops@cynder.io>
 * @license  n/a (http://127.0.0.0)
 * @link     n/a
 */

namespace Cynder\PayMongo;

use GuzzleHttp\Exception\ClientException;
use Paymongo\Phaymongo\PaymongoException;
use Paymongo\Phaymongo\Phaymongo;
use PostHog\PostHog;
use WC_Payment_Gateway;
use WC_Order;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * PHP version 7
 * 
 * @category Plugin
 * @package  PayMongo
 * @author   PayMongo <devops@cynder.io>
 * @license  n/a (http://127.0.0.0)
 * @link     n/a
 */
class Cynder_PayMongo_Webhook_Handler extends WC_Payment_Gateway
{
    /**
     * Singleton
     * 
     * @var Singleton The reference the *Singleton* instance of this class
     */
    private static $_instance;

    private $client;

    protected $testmode;
    protected $public_key;
    protected $secret_key;
    protected $webhook_secret;
    protected $sendInvoice;
    protected $debugMode;
    protected $utils;

    /**
     * Returns the *Singleton* instance of this class.
     *
     * @return Singleton The *Singleton* instance.
     */
    public static function getInstance()
    {
        if (null === self::$_instance) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Starting point of the webhook handler
     * 
     * @since 1.0.0
     */
    public function __construct()
    {
        $testMode = get_option('woocommerce_cynder_paymongo_test_mode');
        $this->testmode = (!empty($testMode) && $testMode === 'yes') ? true : false;

        $pkKey = $this->testmode ? 'woocommerce_cynder_paymongo_test_public_key' : 'woocommerce_cynder_paymongo_public_key';
        $skKey = $this->testmode ? 'woocommerce_cynder_paymongo_test_secret_key' : 'woocommerce_cynder_paymongo_secret_key';
        $this->public_key = get_option($pkKey);
        $this->secret_key = get_option($skKey);

        $wsKey = $this->testmode ? 'paymongo_test_webhook_secret_key' : 'paymongo_webhook_secret_key';

        $this->webhook_secret = get_option($wsKey);

        $sendInvoice = get_option('woocommerce_cynder_paymongo_send_invoice_after_payment');
        $this->sendInvoice = (!empty($sendInvoice) && $sendInvoice === 'yes') ? true : false;

        $debugMode = get_option('woocommerce_cynder_paymongo_debug_mode');
        $this->debugMode = (!empty($debugMode) && $debugMode === 'yes') ? true : false;

        add_action(
            'woocommerce_api_cynder_paymongo',
            array($this, 'checkForWebhook')
        );

        add_filter(
            'woocommerce_order_data_store_cpt_get_orders_query',
            array($this, 'queryOrderBySource'),
            10,
            2
        );

        add_filter('woocommerce_order_query_args', array($this, 'queryOrderBySourceHpos'));

        $this->client = new Phaymongo($this->public_key, $this->secret_key);
        $this->utils = new Utils();
    }

    /**
     * Check incoming request for PayMongo request data
     * 
     * @since 1.0.0
     * 
     * @return void
     */
    public function checkForWebhook()
    {
        if (
            ('POST' !== $_SERVER['REQUEST_METHOD'])
            || !isset($_GET['wc-api'])
            || ('cynder_paymongo' !== $_GET['wc-api'])
        ) {
            status_header(400);
            die();
        }

        $requestBody = file_get_contents('php://input');
        $requestHeaders = $this->getRequestHeaders();

        if ($this->debugMode) {
            wc_get_logger()->log('info', '[checkForWebhook] Headers ' . ' ' . wc_print_r($requestHeaders, true));
        }

        // Validate it to make sure it is legit.
        if ($this->isValidRequest($requestBody, $requestHeaders)) {
            // false specifically means this delivery hit a transient
            // condition (lock contention, a failed order reload) rather
            // than a final outcome -- see processWebhook()'s payment.failed
            // branch. Unlike payment.paid, nothing else ever retries a
            // skipped failed-payment status transition, so acknowledging
            // it here (as every other path in this method still does, via
            // the implicit true/null) would strand the order permanently.
            // A non-2xx tells PayMongo to redeliver this event later.
            //
            // processWebhook() itself already recovers the one case where
            // a thrown failure shouldn't be reported as one (the order
            // ended up paid anyway -- see its own payment.paid branch);
            // anything that still propagates past that is a genuine
            // failure (e.g. a lock lost while the order remains unpaid),
            // and by this point the order may already be marked paid in
            // WooCommerce even though completion didn't finish cleanly.
            // Letting this reach PHP's own handler would fatal with a 500
            // (and, depending on site config, a "technical issue" email to
            // the admin) instead of this clean 503 -- worse, on redelivery
            // the handler's own is_paid() recheck would then return early
            // without ever running trackPaymentResolution()/the
            // cynder_paymongo_successful_payment action for this order.
            try {
                $handled = $this->processWebhook($requestBody);
            } catch ( \Throwable $e ) {
                wc_get_logger()->error(
                    '[checkForWebhook] processWebhook() failed: ' . $e->getMessage(),
                    array('source' => 'cynder-paymongo')
                );
                status_header(503);
                die();
            }

            if (false === $handled) {
                status_header(503);
                die();
            }

            // PayMongo's own documented contract
            // (https://docs.paymongo.com/reference/webhook-resource) is a
            // status in 200-209 "along with a JSON response" to
            // acknowledge the event -- a bare 200 with no body doesn't
            // actually meet it, even though deliveries have evidently
            // succeeded against it in practice.
            status_header(200);
            header('Content-Type: application/json');
            echo wp_json_encode(array());
            die();
        } else {
            wc_get_logger()->log('error', '[checkForWebhook] ' . ' ' . wc_print_r($requestBody, true));
            status_header(400);
            die();
        }
    }

    /**
     * Actual Processing of webhook request
     *
     * @param string $payload JSON String
     *
     * @return void|bool False if the webhook should be retried.
     *
     * @link  https://docs.paymongo.com/reference/webhook-resource
     * @since 1.0.0
     */
    public function processWebhook($payload)
    {
        global $woocommerce;

        $decoded = json_decode($payload, true);

        // Defensive check ensuring the deeply nested payload structure exists
        if (!is_array($decoded) || !isset($decoded['data']['attributes']['data']['attributes'])) {
            wc_get_logger()->log('error', '[processWebhook] Invalid webhook payload structure received.');
            status_header(400);
            die();
        }

        $eventData = $decoded['data']['attributes'];
        $resourceData = $eventData['data'];
        $resourceAttributes = $resourceData['attributes'];
        $paymentIntentId = isset($resourceAttributes['payment_intent_id']) ? $resourceAttributes['payment_intent_id'] : null;
        $resourceMetadata = isset($resourceAttributes['metadata'])
            ? $resourceAttributes['metadata']
            : array();

        $shopName = get_bloginfo('name');
        $orderId = isset($resourceMetadata['order_id'])
            ? $resourceMetadata['order_id']
            : null;

        // If metadata is missing, resolve the order from the payment intent ID
        // instead of the (possibly absent) store_name equality check.
        $order = $orderId ? wc_get_order($orderId) : false;

        // Validate that the webhook payment intent ID belongs to the resolved order (current or _old).
        if ( $order && ! empty( $paymentIntentId ) ) {
            $storedIntentId = $order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY );
            $old_intents = $order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY . '_old', false );
            $flat_old_intents = Utils::flattenMetaValues( $old_intents );

            if ( empty( $storedIntentId ) && empty( $flat_old_intents ) ) {
                wc_get_logger()->log( 'error', '[processWebhook] Order ID ' . $order->get_id() . ' is missing stored payment intent meta; refusing to complete order for webhook intent ID ' . $paymentIntentId );
                status_header( 400 );
                die();
            } else if ( strval( $storedIntentId ) !== strval( $paymentIntentId ) && ! in_array( strval( $paymentIntentId ), $flat_old_intents, true ) ) {
                wc_get_logger()->log( 'error', '[processWebhook] Mismatch! Webhook payment intent ID ' . $paymentIntentId . ' does not match stored payment intent ID ' . $storedIntentId . ' (and was not found in paymongo_payment_intent_id_old) for Order ID ' . $order->get_id() );
                status_header( 400 );
                die();
            }
        }

        if (!$order && !empty($paymentIntentId)) {
            $order = $this->getOrderByMeta(PAYMONGO_PAYMENT_INTENT_META_KEY, $paymentIntentId);

            if (!$order) {
                $order = $this->getOrderByMeta(PAYMONGO_PAYMENT_INTENT_META_KEY . '_old', $paymentIntentId);
            }
        }

        /** Check if metadata store_name is similar to the shop name and if there
         *  is an existing order with the order_id from payload metadata.
         */
        if ($order) {
            $customer = $order->get_customer_id();

            // Validate metadata if it exists
            if (!empty($resourceMetadata)) {
                if (empty($paymentIntentId)) {
                    $this->utils->log('error', 'No payment intent ID found for payment ID ' . $resourceData['id']);
                    return;
                }

                if (isset($resourceMetadata['agent']) && $resourceMetadata['agent'] !== 'cynder_woocommerce') {
                    $this->utils->log('warning', 'Unexpected webhook metadata agent "' . $resourceMetadata['agent'] . '" for payment intent ID ' . $paymentIntentId);
                    return;
                }

                $metaKeysToCheck = array('store_name', 'customer_id');

                $metadataMap = array(
                    'store_name' => array(
                        'tag' => 'shop',
                        'value' => $shopName,
                    ),
                    'customer_id' => array(
                        'tag' => 'customer ID',
                        'value' => strval($customer),
                    ),
                );

                foreach ($metaKeysToCheck as $key) {
                    if (isset($resourceMetadata[$key])) {
                        $originalValue = $resourceMetadata[$key];
                        $metadataMapItem = $metadataMap[$key];
                        $metaValue = $metadataMapItem['value'];
                        $metaTag = $metadataMapItem['tag'];

                        if ($originalValue !== $metaValue) {
                            $this->utils->log('warning', 'Payment Intent ID ' . $paymentIntentId . ' did not originate from ' . $metaTag . ' ' . $metaValue . ' but originated from ' . $metaTag . ' ' . $originalValue);
                            status_header(200);
                            die();
                        }
                    }
                }
            }

            if ($this->debugMode) {
                wc_get_logger()->log('info', '[processWebhook] Webhook payload ' . wc_print_r($decoded, true));
            }

            $validEventTypes = [
                'payment.paid',
                'payment.failed',
            ];

            if (in_array($eventData['type'], $validEventTypes)) {
                $sourceType = isset($resourceAttributes['source']['type']) ? $resourceAttributes['source']['type'] : 'unknown';
                $amount = $resourceAttributes['amount'];

                // Explicit metadata orderId match is skipped if orderId is missing in the payload
                if ($orderId && strval($order->get_id()) !== strval($orderId)) {
                    wc_get_logger()->log('error', '[processWebhook] Mismatch! Payment Intent ID ' . $paymentIntentId . ' belongs to Order ID ' . $order->get_id() . ' but webhook metadata claims Order ID ' . $orderId);
                    status_header(400);
                    die();
                }

                if ($eventData['type'] === 'payment.paid') {
                    if ($this->debugMode) {
                        $this->utils->log('info', '[processWebhook] Found Order ID! ' . $order->get_id());
                    }

                    wc_get_logger()->log('info', '[processWebhook] event: payment.paid with payment intent ID ' . $paymentIntentId);

                    /**
                     * Only process unpaid orders -- this would happen if payment intent has processing
                     * status on redirect from the payment authorization page back to the woocommerce shop
                     *
                     * Any paid orders should be ignored
                     */
                    if ($order->is_paid()) {
                        if ($this->debugMode) {
                            $this->utils->log('info', '[processWebhook] Order ' . $order->get_id() . ' is already paid');
                        }

                        return;
                    }

                    // Same per-order lock ReconciliationSweep and
                    // cynder_paymongo_create_intent() use. Without it, this
                    // delivery and a concurrent sweep pass (or another
                    // delivery of this same webhook) can both observe
                    // $order as unpaid above and both run completeOrder()
                    // -- payment_complete(), the invoice email, tracking,
                    // and the third-party action -- a second time each,
                    // exactly the duplicate completion this lock elsewhere
                    // exists to prevent.
                    $order_id = $order->get_id();
                    $order_lock_key = Lock::orderIntentKey($order_id);
                    $order_lock_token = Lock::acquireWithRetry($order_lock_key, PAYMONGO_ORDER_LOCK_TTL, 5, 30000);

                    if (false === $order_lock_token) {
                        // Contended -- most likely the sweep, or another
                        // delivery of this same webhook, is already
                        // completing this order right now. Transient, the
                        // same as the payment.failed branch's identical
                        // case below: false asks PayMongo to redeliver
                        // (see checkForWebhook()) instead of silently
                        // acknowledging a completion this delivery never
                        // actually ran. The sweep remains a backstop
                        // regardless, but this order shouldn't have to wait
                        // for its own next cooldown-gated pass (up to
                        // PAYMONGO_RECONCILE_RETRY_INTERVAL) when a
                        // redelivery could resolve it much sooner.
                        //
                        // PayMongo retries each failed delivery (any non-2xx
                        // response) up to 12 times, and only disables the
                        // webhook if 3 consecutive events each exhaust all
                        // 12 retries without ever getting a successful
                        // response -- see
                        // https://docs.paymongo.com/reference/webhook-resource.
                        // A single delivery 503-ing because this lock is
                        // genuinely busy (e.g. the sweep mid-scan of this
                        // same order) is exactly the transient case that
                        // policy exists for; PayMongo's own backoff schedule
                        // across those 12 attempts isn't documented there,
                        // so how much real time that buys isn't itself
                        // verified -- only the retry count and disable
                        // threshold are.
                        wc_get_logger()->log(
                            'warning',
                            '[processWebhook] Could not acquire order lock for Order ID ' . $order_id . '; skipping payment.paid completion for this delivery.'
                        );

                        return false;
                    }

                    try {
                        // A fresh, independent, uncached read, not $order's
                        // own cached copy -- $order was resolved earlier in
                        // this method, before this lock was ever a
                        // possibility to acquire, so a concurrent
                        // completion could have already landed in that gap.
                        // See Utils::reloadOrderUncached()'s own docblock
                        // for why both its status and meta reloads are each
                        // necessary.
                        $fresh_order = $this->utils->reloadOrderUncached($order_id);

                        if (!$fresh_order) {
                            // Reload failed -- transient, same as lock
                            // contention above; ask for a redelivery instead
                            // of acknowledging a completion that never ran.
                            return false;
                        }

                        // See Utils::isOrderPaidAuthoritative()'s own
                        // docblock for why both checks, and why this is the
                        // right call here specifically (pre-check, not
                        // post-completion-failure recovery).
                        if ($this->utils->isOrderPaidAuthoritative($fresh_order)) {
                            if ($this->debugMode) {
                                $this->utils->log('info', '[processWebhook] Order ' . $order_id . ' is already paid');
                            }

                            return;
                        }

                        // Renews this lock before and during the unbounded
                        // work below -- payment_complete() (fires arbitrary
                        // WooCommerce hooks), an optional invoice email, and
                        // PostHog tracking are none of them bounded by
                        // anything this method controls; see
                        // Utils::completeOrder()'s own $heartbeat param and
                        // ReconciliationSweep::run()'s identical use of one.
                        $heartbeat = Lock::heartbeat(
                            $order_lock_key,
                            $order_lock_token,
                            PAYMONGO_ORDER_LOCK_TTL,
                            'Order lock could not be renewed while completing order ' . $order_lock_key . ' from a webhook.'
                        );

                        $heartbeat();

                        try {
                            $this->utils->completeOrder($fresh_order, $resourceData['id'], $this->sendInvoice, $heartbeat);
                        } catch ( \Throwable $e ) {
                            // Mirrors Utils::completeOrderLocked()'s own
                            // recovery: a lock-loss heartbeat (or any other
                            // failure) firing after payment_complete() itself
                            // already ran must not be treated as "this
                            // delivery failed" -- PayMongo has already
                            // reported the payment succeeded, so a genuine
                            // duplicate payment risk outweighs this one
                            // delivery reporting itself cleanly. Still defer
                            // tracking/the action below, same as a clean
                            // completion; only rethrow (handled by
                            // checkForWebhook()'s own catch, below) when the
                            // order genuinely isn't paid.
                            //
                            // isOrderPaidInDatabase() alone, not
                            // $fresh_order->is_paid() too -- see
                            // Utils::completeOrderLocked()'s identical
                            // recovery for why this object's own in-memory
                            // status can't be trusted here: WC_Order::payment_complete()
                            // updates it via set_status() before save(), and
                            // can return false (triggering completeOrder()'s
                            // own exception) without that save ever landing.
                            if ($this->utils->isOrderPaidInDatabase($order_id)) {
                                wc_get_logger()->warning(
                                    'Order ' . $order_id . ' was marked paid but completion threw during a webhook delivery: ' . $e->getMessage(),
                                    array('source' => 'cynder-paymongo')
                                );
                            } else {
                                throw $e;
                            }
                        }

                        // Captured, not run here -- neither trackPaymentResolution()
                        // nor the third-party action reads or writes anything
                        // about the order, so neither needs this lock, and
                        // PostHog's own request can itself run long enough
                        // (see Utils::trackPaymentResolution()) to have
                        // outlived one sized for completeOrder() above. Run
                        // after the lock is released instead (see the
                        // finally below), the same as
                        // ReconcileResult::$after_completion defers the
                        // identical pair for the sweep and checkout paths.
                        $payment_method = $fresh_order->get_payment_method();
                    } finally {
                        Lock::release($order_lock_key, $order_lock_token);
                    }

                    $this->utils->trackPaymentResolution('successful', $resourceData['id'], floatval($amount) / 100, $payment_method, $this->testmode);

                    $this->utils->callAction('cynder_paymongo_successful_payment', $resourceData);

                    return;
                }

                if ($eventData['type'] === 'payment.failed') {
                    wc_get_logger()->log('info', '[processWebhook] event: payment.failed with payment intent ID ' . $paymentIntentId);

                    /**
                     * Only unpaid orders should be processed for failed payments
                     */
                    if ($order->is_paid()) {
                        return true;
                    }

                    // Same per-order lock the payment.paid branch above (and
                    // ReconciliationSweep/cynder_paymongo_create_intent())
                    // uses. The validation earlier in this method
                    // deliberately accepts a payment intent ID matching
                    // either the order's current or an _old (historical)
                    // one, so a failure on a stale, already-superseded
                    // intent can reach here right as a concurrent
                    // payment.paid webhook or sweep pass is completing the
                    // order against its current intent -- without this
                    // lock and a fresh recheck, this delivery could still
                    // call update_status('failed') over that completion.
                    $order_id = $order->get_id();
                    $order_lock_key = Lock::orderIntentKey($order_id);
                    $order_lock_token = Lock::acquireWithRetry($order_lock_key, PAYMONGO_ORDER_LOCK_TTL, 5, 30000);

                    if (false === $order_lock_token) {
                        // Transient -- most likely a concurrent completion
                        // holds this lock right now. Unlike payment.paid,
                        // no sweep or other mechanism ever reproduces a
                        // failed-payment status transition, so this must
                        // ask PayMongo to redeliver rather than
                        // acknowledge; see checkForWebhook()'s handling of
                        // this return value. This branch is the one case
                        // with no other backstop if redelivery itself ever
                        // ran out -- see the identical 503/redelivery
                        // comment in the payment.paid branch above for
                        // PayMongo's documented retry/disable policy.
                        wc_get_logger()->log(
                            'warning',
                            '[processWebhook] Could not acquire order lock for Order ID ' . $order_id . '; aborting payment.failed handling to trigger a webhook retry.'
                        );

                        return false;
                    }

                    $payment_method = null;

                    try {
                        // A fresh, independent, uncached read -- $order was
                        // resolved before this lock was ever a possibility
                        // to acquire, so a concurrent completion could have
                        // already landed in that gap. See
                        // Utils::reloadOrderUncached()'s own docblock.
                        $fresh_order = $this->utils->reloadOrderUncached($order_id);

                        if (!$fresh_order) {
                            // Reload failed -- transient, same as lock
                            // contention above; ask for a redelivery
                            // instead of acknowledging a status update that
                            // never ran.
                            return false;
                        }

                        // See Utils::isOrderPaidAuthoritative()'s own
                        // docblock for why both checks, and why this is the
                        // right call here specifically (pre-check, not
                        // post-completion-failure recovery).
                        if ($this->utils->isOrderPaidAuthoritative($fresh_order)) {
                            return true;
                        }

                        if ( strval( $fresh_order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY ) ) !== strval( $paymentIntentId ) ) {
                            return true;
                        }
                        $fresh_order->update_status('failed', 'Payment failed', true);
                        $payment_method = $fresh_order->get_payment_method();
                    } finally {
                        Lock::release($order_lock_key, $order_lock_token);
                    }

                    if (null !== $payment_method) {
                        $this->utils->trackPaymentResolution('failed', $resourceData['id'], floatval($amount) / 100, $payment_method, $this->testmode);
                        $this->utils->callAction('cynder_paymongo_failed_payment', $resourceData);
                    }

                    return true;
                }

                wc_get_logger()->log('info', '[processWebhook] Passthrough event type ' . $eventData['type'] . ' with source type ' . $sourceType);
                return;
            }
        } else {
            wc_get_logger()->log('error', '[checkForWebhook] No order with payment intent ID ' . $paymentIntentId . ' found for shop ' . $shopName);
            // No status_header to prevent the webhook from being disabled
            return;
        }

        wc_get_logger()->log('error', '[processWebhook] Invalid event type = ' . $eventData['type']);
        status_header(422);
        die();
    }

    /**
     * Checks if request is from paymongo servers
     * 
     * @param array $payload Source data from event data sent by paymongo
     * @param array $headers Request headers
     * 
     * @return bool
     * 
     * @link  https://docs.paymongo.com/docs/developer-tools-webhook-setup-management#securing-a-webhook
     * @since 1.0.0
     */
    public function isValidRequest($payload, $headers)
    {
        $webhookSecret = $this->webhook_secret;
        if (empty($webhookSecret)) {
            wc_get_logger()->log('critical', '[isValidRequest] Webhook secret is not configured.');
            return false;
        }

        if (!isset($headers['paymongo-signature']) || empty($headers['paymongo-signature'])) {
            wc_get_logger()->log('error', '[isValidRequest] PayMongo-Signature header is missing.');
            return false;
        }

        // manually created raw signature
        $rawSignature = $this->assembleSignature($payload, $headers);

        // Fail fast if the raw signature couldn't be assembled (e.g., missing timestamp).
        if (empty($rawSignature)) {
            return false;
        }

        if ($this->debugMode) {
            wc_get_logger()->log('info', '[isValidRequest] Raw Signature ' . wc_print_r($rawSignature, true));
        }

        // hashed rawSignature
        $encryptedSignature = hash_hmac('sha256', $rawSignature, $webhookSecret);

        if ($this->debugMode) {
            wc_get_logger()->log('info', '[isValidRequest] Encrypted Signature ' . wc_print_r($encryptedSignature, true));
        }

        $requestSignature = $this->testmode ?
            $this->getFromPayMongoSignature('test', $headers)
            : $this->getFromPayMongoSignature('live', $headers);

        if ($this->debugMode) {
            wc_get_logger()->log('info', '[isValidRequest] Request Signature ' . wc_print_r($requestSignature, true));
        }

        if (!is_string($requestSignature) || '' === $requestSignature) {
            wc_get_logger()->log('critical', '[isValidRequest] Request signature is missing or invalid.');
            return false;
        }

        return hash_equals($encryptedSignature, $requestSignature);
    }

    /**
     * Combines timestamp and payload to be hashed
     * 
     * @param array $payload Source data from event data sent by paymongo
     * @param array $headers request headers
     * 
     * @return string
     * 
     * @link  https://docs.paymongo.com/docs/developer-tools-webhook-setup-management#securing-a-webhook
     * @since 1.0.0
     */
    public function assembleSignature($payload, $headers)
    {
        $timestamp = $this->getFromPayMongoSignature('timestamp', $headers);

        if (empty($timestamp)) {
            wc_get_logger()->log('error', '[assembleSignature] Timestamp (t=) is missing from the PayMongo-Signature header.');
            return '';
        }

        $raw = $timestamp . '.' . $payload;

        return $raw;
    }

	/**
	 * Get Property from PayMongo-Signature Header
	 *
	 * @param string $key     values('timestamp', 'live', 'test') or raw header keys ('t', 'te', 'li').
	 * @param array  $headers request headers.
	 *
	 * @return string|null
	 *
	 * @link  https://docs.paymongo.com/docs/developer-tools-webhook-setup-management#securing-a-webhook
	 * @since 1.0.0
	 */
	public function getFromPayMongoSignature( $key, $headers ) {
		if ( empty( $headers['paymongo-signature'] ) ) {
			return null;
		}

		$signature = $headers['paymongo-signature'];
		$parts     = explode( ',', $signature );
		$parsed    = array();

		// Safely parse key-value pairs (e.g., t=123, te=abc).
		foreach ( $parts as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( count( $kv ) === 2 ) {
				$parsed[ $kv[0] ] = $kv[1];
			}
		}

		// Map the requested key to PayMongo's signature keys.
		$key_map = array(
			'timestamp' => 't',
			'test'      => 'te',
			'live'      => 'li',
		);

		$mapped_key = $key_map[ $key ] ?? $key;

		return $parsed[ $mapped_key ] ?? null;
	}

    /**
     * Gets request headers
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function getRequestHeaders()
    {
        $headers = array();

        if (!function_exists('getallheaders')) {
            foreach ($_SERVER as $name => $value) {
                if ('HTTP_' === substr($name, 0, 5)) {
                    $headerKey = str_replace(
                        ' ',
                        '-',
                        strtolower(str_replace('_', ' ', substr($name, 5)))
                    );
                    $headers[$headerKey] = $value;
                }
            }
        } else {
            $originalHeaders = getallheaders();
            if (!empty($originalHeaders) && is_array($originalHeaders)) {
                foreach ($originalHeaders as $key => $value) {
                    $headers[strtolower($key)] = $value;
                }
            }
        }

        return $headers;
    }

    /** 
     * Get Order by meta values
     *
     * @param string $metaKey Metadata key
     * @param string $metaValue Metadata value
     *
     * @return WC_Order|false
     *
     * @since 1.5.0
     */
    public function getOrderByMeta($metaKey, $metaValue)
    {
        $queryParams = array('limit' => 2);
        $queryParams[$metaKey] = $metaValue;

        $orders = wc_get_orders($queryParams);

        if (empty($orders)) {
            wc_get_logger()->log('error', '[getOrderBySource] Failed to find order with metadata ID ' . $metaKey . ' ' . $metaValue);
            return false;
        }

        // Assert the single-match assumption and log a warning if violated
        if (count($orders) > 1) {
            wc_get_logger()->log('warning', '[getOrderByMeta] Multiple orders found for ' . $metaKey . ' = ' . $metaValue . '. Falling back to the first matched order (Order ID: ' . $orders[0]->get_id() . ').');
        }

        return $orders[0];
    }

    public function queryOrderBySource($query, $query_vars)
    {
        $validPaymongoMeta = ['source_id', PAYMONGO_PAYMENT_INTENT_META_KEY, PAYMONGO_PAYMENT_INTENT_META_KEY . '_old'];

        if (empty($query['meta_query']) || !is_array($query['meta_query'])) {
            $query['meta_query'] = array();
       }

        foreach ($validPaymongoMeta as $metaKey) {
            if (!empty($query_vars[$metaKey])) {
                $value = sanitize_text_field(wp_unslash($query_vars[$metaKey]));

                if ($metaKey === PAYMONGO_PAYMENT_INTENT_META_KEY . '_old') {
                    // Use an OR relation to check for both legacy flat strings and new serialized arrays
                    $query['meta_query'][] = array(
                        'relation' => 'OR',
                        array(
                            'key' => $metaKey,
                            'value' => $value,
                            'compare' => '=',
                        ),
                        array(
                            'key' => $metaKey,
                            'value' => '"' . $value . '"',
                            'compare' => 'LIKE',
                        ),
                    );
                } else {
                    $query['meta_query'][] = array(
                        'key' => $metaKey,
                        'value' => $value,
                        'compare' => '=',
                    );
                }
            }
        }

        // wc_get_logger()->log('info', 'Query ' . wc_print_r($query, true));
        return $query;
    }

    public function queryOrderBySourceHpos($args)
    {
        // Avoid running HPOS checks for unrelated order queries.
        if (empty($args[PAYMONGO_PAYMENT_INTENT_META_KEY]) && empty($args['source_id']) && empty($args[PAYMONGO_PAYMENT_INTENT_META_KEY . '_old'])) {
            return $args;
        }

        if (
            !class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')
            || !\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        ) {
            return $args; // legacy store handled by the existing CPT filter
        }

        if (empty($args['meta_query']) || !is_array($args['meta_query'])) {
            $args['meta_query'] = array();
        }

        foreach (array(PAYMONGO_PAYMENT_INTENT_META_KEY, 'source_id', PAYMONGO_PAYMENT_INTENT_META_KEY . '_old') as $key) {
            if (!empty($args[$key])) {
                $value = sanitize_text_field(wp_unslash($args[$key]));

                if ($key === PAYMONGO_PAYMENT_INTENT_META_KEY . '_old') {
                    // Use an OR relation to check for both legacy flat strings and new serialized arrays
                    $args['meta_query'][] = array(
                        'relation' => 'OR',
                        array(
                            'key' => $key,
                            'value' => $value,
                            'compare' => '=',
                        ),
                        array(
                            'key' => $key,
                            'value' => '"' . $value . '"',
                            'compare' => 'LIKE',
                        ),
                    );
                } else {
                    $args['meta_query'][] = array(
                        'key' => $key,
                        'value' => $value,
                        'compare' => '=',
                    );
                }
                unset($args[$key]);
            }
        }

        return $args;
    }
}

Cynder_PayMongo_Webhook_Handler::getInstance();
