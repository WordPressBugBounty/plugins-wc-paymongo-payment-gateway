<?php
/**
 * PHP version 7
 *
 * PayMongo - Payment Intent Base Class
 *
 * @category Plugin
 * @package  PayMongo
 * @author   PayMongo <devops@cynder.io>
 * @license  n/a (http://127.0.0.0)
 * @link     n/a
 */

namespace Cynder\PayMongo;

use Paymongo\Phaymongo\Phaymongo;
use WC_Payment_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * PayMongo - Payment Intent Base Class
 *
 * @category Class
 * @package  PayMongo
 * @author   PayMongo <devops@cynder.io>
 * @license  n/a (http://127.0.0.0)
 * @link     n/a
 */
class CynderPayMongoPaymentIntentGateway extends WC_Payment_Gateway {

	/**
	 * Singleton instance
	 *
	 * @var Singleton The reference the *Singleton* instance of this class
	 */
	private static $_instance;

	private $client;

	public $hasDetailsPayload = false;

	protected $testmode;
	protected $secret_key;
	protected $public_key;
	protected $sendInvoice;
	protected $debugMode;
	protected $paymentIntent;
	protected $utils;

	/**
	 * Returns the *Singleton* instance of this class.
	 *
	 * @return Singleton The *Singleton* instance.
	 */
	public static function getInstance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Starting point of the payment gateway
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->supports = array(
			'products',
		);

		$this->initFormFields();

		$this->init_settings();
		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		$testMode       = get_option( 'woocommerce_cynder_paymongo_test_mode' );
		$this->testmode = ( ! empty( $testMode ) && $testMode === 'yes' ) ? true : false;

		$skKey            = $this->testmode ? 'woocommerce_cynder_paymongo_test_secret_key' : 'woocommerce_cynder_paymongo_secret_key';
		$this->secret_key = get_option( $skKey );

		$pkKey            = $this->testmode ? 'woocommerce_cynder_paymongo_test_public_key' : 'woocommerce_cynder_paymongo_public_key';
		$this->public_key = get_option( $pkKey );

		$sendInvoice       = get_option( 'woocommerce_cynder_paymongo_send_invoice_after_payment' );
		$this->sendInvoice = ( ! empty( $sendInvoice ) && $sendInvoice === 'yes' ) ? true : false;

		$debugMode       = get_option( 'woocommerce_cynder_paymongo_debug_mode' );
		$this->debugMode = ( ! empty( $debugMode ) && $debugMode === 'yes' ) ? true : false;

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);

		if ( 'yes' === $this->enabled ) {
			add_filter(
				'woocommerce_thankyou_order_received_text',
				array( $this, 'orderReceivedText' ),
				10,
				2
			);
		}

		$this->utils         = new Utils();
		$this->client        = new Phaymongo( $this->public_key, $this->secret_key );
		$this->paymentIntent = new PaymentIntent( $this->id, $this->utils, $debugMode, $testMode, $this->client );
	}

	/**
	 * Override for certain payment methods
	 */
	public function initFormFields() {
	}

	/**
	 * Override for certain payment methods
	 */
	public function getPaymentMethodId( $orderId ) {
		$order = wc_get_order( $orderId );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			$this->utils->addNotice( 'error', __( 'Invalid order. Please try again.', 'paymongo' ) );
			return null;
		}
		$paymentMethod = $this->paymentIntent->getPaymentMethod( $order, $this->hasDetailsPayload ? array( $this, 'generatePaymentMethodDetailsPayload' ) : null );

		if ( isset( $paymentMethod ) ) {
			$paymentMethodId = $paymentMethod['id'];
			return $paymentMethodId;
		} else {
            $this->utils->addNotice( 'error', __( 'Payment processing error. Please contact your site administrator for further details.', 'paymongo' ) );
			return null;
		}
	}

	/** Override on certain payment methods */
	public function generatePaymentMethodDetailsPayload( $order ) {
		return array();
	}

	/**
	 * Process PayMongo Payment
	 *
	 * @param string $orderId WooCommerce Order ID
	 *
	 * @return array
	 *
	 * @link  https://developers.paymongo.com/reference#the-payment-intent-object
	 * @since 1.0.0
	 */
    public function process_payment($orderId) { // phpcs:ignore
		if ( $this->debugMode ) {
			$this->utils->log( 'info', '[Processing Payment] Processing payment for order ID ' . $orderId );
		}

		$order = wc_get_order( $orderId );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			$this->utils->addNotice( 'error', __( 'Invalid order.', 'paymongo' ) );
			return array(
				'result' => 'failure',
			);
		}

		if ( $order->is_paid() || $order->has_status( array( 'processing', 'completed' ) ) ) {
			if ( $this->debugMode ) {
				$this->utils->log( 'info', '[Processing Payment] Order ID ' . $orderId . ' is already paid. Skipping payment method creation.' );
			}
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$paymentMethodId = $this->getPaymentMethodId( $orderId );

		// Guard against null/empty payment method IDs
		if ( empty( $paymentMethodId ) ) {
			if ( $this->debugMode ) {
				$this->utils->log( 'error', '[Processing Payment] Failed to create or retrieve payment method ID for order ID ' . $orderId );
			}
			return array(
				'result' => 'failure',
			);
		}

		if ( $this->debugMode ) {
			$this->utils->log( 'info', '[Processing Payment] Payment method ID ' . $paymentMethodId . ' successfully created for order ID ' . $orderId );
		}

		$paymentIntentId = $order->get_meta( 'paymongo_payment_intent_id' );
		$returnUrl       = add_query_arg(
			array(
				'wc-api'  => 'cynder_paymongo_catch_redirect',
				'order'   => $orderId,
				'intent'  => $paymentIntentId,
				'key'     => $order->get_order_key(),
				'agent'   => 'cynder_woocommerce',
				'version' => CYNDER_PAYMONGO_VERSION,
			),
			get_home_url()
		);

		$returnObj = $this->paymentIntent->processPayment( $order, $paymentMethodId, $returnUrl, $this->get_return_url( $order ), $this->sendInvoice );

		// Ensure processPayment doesn't return null.
		if ( empty( $returnObj ) ) {
			return array(
				'result' => 'failure',
			);
		}

		return $returnObj;
	}

	/**
	 * Get Icon for checkout page
	 *
	 * @return string
	 */
    public function get_icon() { // phpcs:ignore
		$icons_str = '<img class="paymongo-method-logo payment-method-' . esc_attr( $this->id ) . '" src="'
			. esc_url( CYNDER_PAYMONGO_PLUGIN_URL )
			. '/assets/images/' . esc_attr( $this->id ) . '.png" alt="'
			. esc_attr( $this->title )
			. '" />';

		return apply_filters( 'woocommerce_gateway_icon', $icons_str, $this->id );
	}

	/**
	 * Custom Credit Card order received text.
	 *
	 * @param string       $text  Default text.
	 * @param Cynder_Order $order Order data.
	 *
	 * @return string
	 */
	public function orderReceivedText( $text, $order ) {
		if ( $order && $this->id === $order->get_payment_method() ) {
			return esc_html__(
				'Thank You! Order has been received.',
				'woocommerce'
			);
		}

		return $text;
	}
}
