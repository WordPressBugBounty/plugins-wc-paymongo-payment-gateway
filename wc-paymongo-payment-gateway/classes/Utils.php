<?php

namespace Cynder\PayMongo;

use PostHog\PostHog;

class Utils {
	public function log( $level, $message ) {
		wc_get_logger()->log( $level, $message );
	}

	public function addNotice( $level, $message ) {
		wc_add_notice( $message, $level );
	}

	public function humanize( $message ) {
		return wc_print_r( $message, true );
	}

	public function callAction( $action, ...$args ) {
		call_user_func_array( 'do_action', array_merge( array( $action ), $args ) );
	}

	public function sendInvoice( $order_id ) {
		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails['WC_Email_Customer_Invoice'] ) ) {
			$emails['WC_Email_Customer_Invoice']->trigger( $order_id );
		}
	}

	public function emptyCart() {
		if ( function_exists( 'WC' ) && isset( WC()->cart ) ) {
			WC()->cart->empty_cart();
		}
	}

	public function completeOrder( $order, $payment_id, $send_invoice ) {
		$order_id = $order->get_id();

		$order->payment_complete( $payment_id );

		if ( $send_invoice ) {
			$this->sendInvoice( $order_id );
		}
	}

	public function trackProcessPayment( $amount, $payment_method, $test_mode ) {
		PostHog::capture(
			array(
				'distinctId' => base64_encode( get_bloginfo( 'wpurl' ) ),
				'event'      => 'process payment',
				'properties' => array(
					'amount'         => $amount,
					'payment_method' => $payment_method,
					'sandbox'        => $test_mode ? 'true' : 'false',
				),
			)
		);
	}

	public function trackPaymentResolution( $status, $payment_id, $amount, $payment_method, $test_mode ) {
		PostHog::capture(
			array(
				'distinctId' => base64_encode( get_bloginfo( 'wpurl' ) ),
				'event'      => $status . ' payment',
				'properties' => array(
					'payment_id'     => $payment_id,
					'amount'         => $amount,
					'payment_method' => $payment_method,
					'sandbox'        => $test_mode ? 'true' : 'false',
				),
			)
		);
	}

	/**
	 * Redact sensitive fields from an array
	 * * @param array $data The data to be cleaned
	 *
	 * @return array The cleaned data
	 */
	public function redactSensitiveData( array $data ) {
		$sensitiveKeys = array( 'key', 'order-key', 'wc-api', 'token' );

		foreach ( $sensitiveKeys as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$data[ $key ] = '****************';
			}
		}

		return $data;
	}

	/**
	 * Authoritatively checks the PayMongo API for any succeeded payment intent associated
	 * with the order (current or historical) and completes the order if found.
	 *
	 * @param \WC_Order                     $order
	 * @param \Paymongo\Phaymongo\Phaymongo $client
	 * @return bool True if the order was reconciled as paid, false otherwise.
	 */
	public function reconcileOrderAgainstPayMongo( $order, $client ) {
		if ( $order->is_paid() ) {
			return true;
		}

		$current_intent = $order->get_meta( 'paymongo_payment_intent_id' );
		$old_intents    = $order->get_meta( 'paymongo_payment_intent_id_old', false );
		$old_intents    = ! empty( $old_intents ) ? (array) $old_intents : array();

		// Flatten all intent IDs into a single array.
		$all_intents = array();
		if ( ! empty( $current_intent ) ) {
			$all_intents[] = $current_intent;
		}

		array_walk_recursive(
			$old_intents,
			function ( $vv ) use ( &$all_intents ) {
				if ( ! empty( $vv ) && is_string( $vv ) ) {
					$all_intents[] = $vv;
				}
			}
		);

		$all_intents = array_unique( $all_intents );

		$send_invoice = get_option( 'woocommerce_cynder_paymongo_send_invoice_after_payment' ) === 'yes';
		$test_mode    = get_option( 'woocommerce_cynder_paymongo_test_mode' ) === 'yes';

		foreach ( $all_intents as $intent_id ) {
			try {
				$intent = $client->paymentIntent()->retrieveById( $intent_id );
				$status = $intent['attributes']['status'] ?? '';

				if ( 'succeeded' === $status ) {
					$payments = $intent['attributes']['payments'] ?? array();

					if ( ! empty( $payments ) && isset( $payments[0]['id'] ) ) {
						$payment = $payments[0];
						$amount  = isset( $intent['attributes']['amount'] ) ? floatval( $intent['attributes']['amount'] ) / 100 : floatval( $order->get_total() );

						$this->completeOrder( $order, $payment['id'], $send_invoice );
						if ( function_exists( 'WC' ) && WC()->cart ) {
							WC()->cart->empty_cart();
						}
						$this->trackPaymentResolution( 'successful', $payment['id'], $amount, $order->get_payment_method(), $test_mode );
						$this->callAction( 'cynder_paymongo_successful_payment', $payment );

						return true; // Reconciled successfully.
					}
				}
			} catch ( \Throwable $e ) {
				$this->log( 'error', '[Reconciliation] Failed to retrieve intent ' . $intent_id . ' for Order ID ' . $order->get_id() . ': ' . $e->getMessage() );
			}
		}

		return false;
	}
}
