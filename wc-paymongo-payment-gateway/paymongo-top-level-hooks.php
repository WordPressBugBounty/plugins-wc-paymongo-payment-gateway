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

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

function cynder_paymongo_create_intent( $orderId, $utils = null, $client = null, $locked_client = null ) {
	// All three optional, injected only by tests (see tests/TopLevelHooksTest.php)
	// -- the hook this is registered on (woocommerce_checkout_order_processed)
	// only ever calls this with $orderId, so production behavior is
	// unchanged; this exists purely so a test can substitute a mocked
	// client instead of making a real PayMongo API call.
	$utils = $utils ?? new Utils();

	$testMode = get_option( 'woocommerce_cynder_paymongo_test_mode' );
	$testMode = ( ! empty( $testMode ) && $testMode === 'yes' ) ? true : false;

	$debugMode = get_option( 'woocommerce_cynder_paymongo_debug_mode' );
	$debugMode = ( ! empty( $debugMode ) && $debugMode === 'yes' ) ? true : false;

	$order = wc_get_order( $orderId );

	if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
		return;
	}

	if ( $order->is_paid() || $order->has_status( array( 'processing', 'completed' ) ) ) {
		return;
	}

	$paymentMethod = $order->get_payment_method();

	$hasPaymentMethod      = isset( $paymentMethod ) && $paymentMethod !== '';
	$paymentMethodSettings = get_option( "woocommerce_{$paymentMethod}_settings" );

	$isMethodEnabled = is_array( $paymentMethodSettings ) && isset( $paymentMethodSettings['enabled'] ) && $paymentMethodSettings['enabled'] === 'yes';

	/**
	 * Don't create a payment intent for the following scenarios:
	 *
	 * 1. Payment method setting is disabled
	 * 2. Has no payment method (ex. 100% discounts)
	 * 3. Payment method does not belong to methods that needs payment intents
	 */
	if (
		! $isMethodEnabled ||
		! $hasPaymentMethod ||
		( ! in_array( $paymentMethod, PAYMONGO_PAYMENT_METHODS, true ) )
	) {
		return;
	}

	// Prevent charging vastly incorrect amounts if the store/user is using the wrong currency.
	if ( ! in_array( $order->get_currency(), PAYMONGO_SUPPORTED_CURRENCIES, true ) ) {
		$currency_error = sprintf(
			/* translators: %s: Comma-separated list of supported currencies */
			__( 'PayMongo currently only supports the following currencies: %s. Please check your store settings.', 'paymongo' ),
			implode( ', ', PAYMONGO_SUPPORTED_CURRENCIES )
		);
		wc_get_logger()->log( 'error', '[Create Payment Intent] ' . $currency_error );
		wc_add_notice( esc_html( $currency_error ), 'error' );
		return;
	}

	$total = $order->get_total();

	if ( ! is_numeric( $total ) || floatval( $total ) <= 0 ) {
		$error_message = 'Invalid amount';
		wc_get_logger()->log( 'error', '[Create Payment Intent] ' . $error_message );
		wc_add_notice( esc_html__( 'Invalid amount', 'woocommerce' ), 'error' );
		return;
	}

	$genericErrorMessage = 'Something went wrong with the payment. Please try another payment method. If the issue persists, contact support.';

	try {
		$amount = floatval( $total );

		$pkKey     = $testMode ? 'woocommerce_cynder_paymongo_test_public_key' : 'woocommerce_cynder_paymongo_public_key';
		$skKey     = $testMode ? 'woocommerce_cynder_paymongo_test_secret_key' : 'woocommerce_cynder_paymongo_secret_key';
		$publicKey = get_option( $pkKey );
		$secretKey = get_option( $skKey );
		// create() below runs between the two per-order lock sections, not
		// inside either one -- no explicit timeout override, same as
		// before this lock work.
		$client = $client ?? new Phaymongo( $publicKey, $secretKey );
		// The pre-emptive check's retrieveById() and the rotation-commit's
		// re-verification retrieveById() both run while the per-order lock
		// is held -- see Utils::makeLockedClient().
		$locked_client = $locked_client ?? Utils::makeLockedClient( $publicKey, $secretKey );

		$existingIntentId  = $order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY );
		$existingClientKey = $order->get_meta( PAYMONGO_CLIENT_KEY_META_KEY );

		// Pre-emptive check: Don't create a new intent if the existing one is already successful or processing.
		if ( ! empty( $existingIntentId ) ) {
			$halt_rotation    = false;
			$after_completion = null;

			// Same per-order lock the rotation-commit step below (and
			// ReconciliationSweep) uses. Without it, this request could
			// retrieve $existingIntentId as succeeded, a concurrent request
			// could then win that lock and commit a whole new intent for
			// this order, and this request would still go on to reconcile
			// against the original, pre-lock $order -- completeOrder()
			// saving that stale object risks overwriting the other
			// request's new intent/reconciliation meta, the same race the
			// lock exists to close elsewhere.
			//
			// Deliberately not withLock(): reconcileOrderAgainstPayMongoDetailed()
			// below can itself scan every historical intent and run
			// completeOrder()'s payment-completion hooks, unbounded work
			// that can easily outlast this lock's 15-second TTL, and
			// withLock()'s callback has no way to renew the token it's
			// running under. acquireWithRetry() exposes that token so a
			// heartbeat can renew it during that work, the same way
			// ReconciliationSweep::run() renews its own per-order lock.
			$order_lock_key   = \Cynder\PayMongo\Lock::orderIntentKey( $orderId );
			$order_lock_token = \Cynder\PayMongo\Lock::acquireWithRetry( $order_lock_key, PAYMONGO_ORDER_LOCK_TTL, 5, 30000 );

			if ( false === $order_lock_token ) {
				// Silently falling through here would call PayMongo to
				// create a brand new intent without ever having checked
				// whether the existing one is already processing/succeeded
				// -- worse, the lock being held right now most likely means
				// the sweep or another rotation is already acting on this
				// order, so this new intent could easily end up orphaned
				// (never committed, if the rotation-commit step below then
				// loses the same race) or published over work already in
				// flight. Abort instead of racing it.
				wc_get_logger()->log(
					'error',
					'[Create Payment Intent] Could not acquire order lock for Order ID ' . $orderId . '; deferring intent creation for this attempt.'
				);
				throw new Exception( __( $genericErrorMessage, 'woocommerce' ) );
			}

			try {
				$status = '';

				// A fresh read, taken now that this lock is actually held --
				// not $existingIntentId as captured at the top of this
				// function, before this lock was ever a possibility to
				// acquire. Another request could have already rotated this
				// order to a new intent (and committed that rotation,
				// releasing this same lock) in that gap; checking the
				// stale intent's status below would check the wrong one,
				// and -- if that stale intent isn't itself
				// succeeded/processing -- fall through to create yet
				// another new, chargeable intent even though the order's
				// actual current intent might already be paid or in
				// progress. Reused below for the 'succeeded' branch too,
				// rather than reloading a second time.
				// Utils::reloadOrderUncached() -- see its own docblock for
				// why both the status and meta reloads it does are each
				// necessary; without the forced meta reread specifically,
				// $existingIntentId below could still come from this
				// order's cached meta rather than a true DB read, stale
				// relative to a concurrent rotation commit in the gap the
				// comment above describes, defeating the whole point of
				// re-reading here.
				$fresh_order = $utils->reloadOrderUncached( $orderId );

				if ( ! $fresh_order ) {
					throw new Exception( 'Order ' . $orderId . ' could not be reloaded to verify its existing intent.' );
				}

				$existingIntentId = $fresh_order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY );

				// Empty here means a concurrent rotation already reset this
				// order's intent meta entirely (or there was never
				// anything to check to begin with) -- nothing left to
				// pre-check against; fall through and create a new intent
				// normally, same as if $existingIntentId had been empty
				// from the start.
				if ( ! empty( $existingIntentId ) ) {
					// Define the heartbeat early to renew the lock around the bounded retrieval
					$heartbeat = \Cynder\PayMongo\Lock::heartbeat(
						$order_lock_key,
						$order_lock_token,
						PAYMONGO_ORDER_LOCK_TTL,
						'Order lock could not be renewed while verifying the existing intent for order ' . $order_lock_key . '.'
					);

					$heartbeat();

					// Deliberately narrow: only the retrieval call and
					// classifying its response belong in this try -- the
					// reconcile call below (and its heartbeat) must not be
					// caught here, or a lock-loss signal (or any other
					// completion-side failure) would be logged as "failed
					// to verify existing intent status" and swallowed,
					// silently falling through to create a redundant new
					// intent instead of reporting the failure. Mirrors the
					// same narrowing in Utils::reconcileOrderAgainstPayMongoDetailed().
					try {
						$existingIntent = $locked_client->paymentIntent()->retrieveById( $existingIntentId );
						$status         = isset( $existingIntent['attributes']['status'] ) ? $existingIntent['attributes']['status'] : '';
					} catch ( \Throwable $e ) {
						if ( $debugMode ) {
							wc_get_logger()->log( 'warning', '[Create Payment Intent] Failed to verify existing intent status: ' . $e->getMessage() );
						}
					}

					$heartbeat();

					if ( in_array( $status, array( 'succeeded', 'processing' ), true ) ) {
						// Deliberately not gated on $existingIntentId still
						// being this order's *current* intent by the time we
						// reach here: a concurrent rotation could move it
						// into the historical list between the fresh read
						// above and now, but a succeeded historical intent
						// still represents payment for this order --
						// reconcileOrderAgainstPayMongoDetailed() itself
						// scans both current and historical intents, so it
						// finds $existingIntentId wherever it now sits and
						// completes the order correctly either way. Gating
						// on an exact match here would skip reconciliation
						// entirely for that case, halting rotation without
						// ever completing the order and leaving a newer
						// intent chargeable. The one thing worth still
						// checking first is whether the order is already
						// paid, so a completion that already happened
						// elsewhere isn't redundantly re-scanned.
						//
						// awaiting_next_action deliberately does NOT halt
						// rotation here (unlike succeeded/processing above) --
						// see the identical branch in the rotation-commit
						// step below for why, and its own comment for the
						// PayMongo documentation this is based on.
						// See Utils::isOrderPaidAuthoritative()'s own docblock
						// for why both checks, and why this -- a pre-check,
						// not post-completion-failure recovery -- is the
						// right call here.
						if ( 'succeeded' === $status && ! $utils->isOrderPaidAuthoritative( $fresh_order ) ) {
							// Authoritatively check and complete the order.
							// The returned result's own deferred work (see
							// ReconcileResult::$after_completion) is invoked
							// below, only once this lock is released.
							$after_completion = $utils->reconcileOrderAgainstPayMongoDetailed( $fresh_order, $locked_client, null, null, $heartbeat )->after_completion;
						}

						if ( $debugMode ) {
							wc_get_logger()->log( 'info', '[Create Payment Intent] Order ' . $orderId . ' intent is ' . $status . '. Halting intent rotation.' );
						}

						$halt_rotation = true;
					}
				}
			} finally {
				\Cynder\PayMongo\Lock::release( $order_lock_key, $order_lock_token );
			}

			if ( null !== $after_completion ) {
				try {
					$after_completion();
				} catch ( \Throwable $e ) {
					$utils->log( 'warning', '[Create Payment Intent] Order ' . $orderId . ' was completed, but post-completion callbacks failed: ' . $e->getMessage() );
				}
			}

			if ( $halt_rotation ) {
				return; // Halt creation of a new intent.
			}
		}

		/**
		 * Used by Card Installments. Configure
		 * when card installments is working.
		 */
		$payment_method_options = null;

		if ( $paymentMethod == 'paymongo_card_installment' ) {
			$cc_installment_tenure = isset( $_POST['paymongo_cc_installment_tenure'] ) ? absint( wp_unslash( $_POST['paymongo_cc_installment_tenure'] ) ) : null;
			$cc_installment_issuer = isset( $_POST['paymongo_cc_installment_issuer'] ) ? sanitize_text_field( wp_unslash( $_POST['paymongo_cc_installment_issuer'] ) ) : null;

			if (
				is_string( $cc_installment_issuer ) &&
				$cc_installment_issuer !== '' &&
				is_int( $cc_installment_tenure ) &&
				$cc_installment_tenure > 0
			) {
				$payment_method_options =
					array(
						'card' => array(
							'request_three_d_secure' => 'any',
							'installments'           => array(
								'enabled' => true,
							),
						),
					);
			}
		}

		$shopName      = get_bloginfo( 'name' );
		$paymentIntent = $client->paymentIntent()->create(
			$amount,
			array( 'card', 'paymaya', 'atome', 'dob', 'billease', 'gcash', 'grab_pay' ),
			$payment_method_options,
			$shopName . ' - ' . $orderId,
			array(
				'agent'       => 'cynder_woocommerce',
				'version'     => CYNDER_PAYMONGO_VERSION,
				'store_name'  => $shopName,
				'order_id'    => strval( $orderId ),
				'customer_id' => strval( $order->get_customer_id() ),
			)
		);

		if ( $debugMode ) {
			wc_get_logger()->log( 'info', '[Create Payment Intent] Response ' . wc_print_r( $paymentIntent, true ) );
		}

		if ( $paymentIntent
			&& array_key_exists( 'attributes', $paymentIntent )
			&& array_key_exists( 'status', $paymentIntent['attributes'] )
			&& $paymentIntent['attributes']['status'] == 'awaiting_payment_method'
		) {
			$clientKey = $paymentIntent['attributes']['client_key'];

			// Everything below -- reading what's about to become history,
			// appending to it, and publishing the new current intent --
			// must happen together, under the same per-order lock
			// ReconciliationSweep holds while it reads this order's current
			// intent and writes its own attempt/cooldown meta (see
			// Lock::orderIntentKey()). Two concurrent rotations for this
			// same order (each already holding its own freshly-created
			// $paymentIntent by this point) can otherwise both read the
			// same pre-rotation history, append the same "existing" intent
			// to it, and then serialize one after another here -- the
			// second save then overwrites the first rotation's newly
			// published intent right back out of history, and
			// reconciliation can never inspect it again. Building the old
			// arrays from a fresh, in-lock read (not $existingIntentId /
			// $existingClientKey, captured before this request even called
			// PayMongo to create $paymentIntent) means whichever rotation
			// gets here second sees the *other's* current intent, not the
			// one both started from, and rolls that into history instead
			// of clobbering it.
			//
			// Deliberately not withLock(): the current-intent re-verification
			// below makes its own retrieveById() call while this lock is
			// held, which (with PAYMONGO_ORDER_LOCKED_API_TIMEOUT allowing
			// up to 10s, on top of the fresh reload and meta save around
			// it) can run long enough to outlast PAYMONGO_ORDER_LOCK_TTL's
			// 15s, and withLock()'s callback has no way to renew the token
			// it's running under -- the same reason the pre-emptive check
			// above uses acquireWithRetry() instead.
			$rotation_discarded = false;
			$rotation_after_completion = null;
			// Only ever set inside the existing-intent re-verification
			// below; stays '' for the "already paid" discard further
			// down, which never reaches that re-verification at all.
			$current_status = '';
			$order_lock_key     = \Cynder\PayMongo\Lock::orderIntentKey( $orderId );
			$order_lock_token   = \Cynder\PayMongo\Lock::acquireWithRetry( $order_lock_key, PAYMONGO_ORDER_LOCK_TTL, 5, 30000 );

			if ( false === $order_lock_token ) {
				// Fatal, not silently skipped: the alternative is publishing
				// the new intent id unguarded, which is the exact race this
				// lock exists to close (see the comment above). Losing this
				// race is rare -- it needs an hourly cron tick to land
				// inside this one order's rotation -- so failing this one
				// checkout attempt outweighs reintroducing the race to
				// avoid it.
				wc_get_logger()->log(
					'error',
					'[Create Payment Intent] Could not acquire order lock for Order ID ' . $orderId . '; intent rotation aborted for this attempt.'
				);
				throw new Exception( __( $genericErrorMessage, 'woocommerce' ) );
			}

			try {
				// Utils::reloadOrderUncached() -- this is the critical
				// reload for this function: $current_intent_id,
				// $current_client_key, and both _old history meta below all
				// come from this same $fresh_order, and are about to be
				// committed as this order's new current/history state.
				// Served stale here (e.g. from this same process's own
				// earlier read of this order, before this lock was a
				// possibility to acquire), committing on top of it can
				// silently discard whatever a concurrent rotation already
				// committed in that gap -- burying its intent out of this
				// order's history entirely, not just racing a status flag.
				$fresh_order = $utils->reloadOrderUncached( $orderId );

				if ( ! $fresh_order ) {
					// Thrown, not silently skipped -- a bare return here
					// would leave checkout carrying on with an intent whose
					// id and client_key were never persisted to this order.
					// Caught by the existing \Exception handler below, which
					// reports the failure the same way every other failure
					// in this function does.
					throw new Exception( 'Order ' . $orderId . ' could not be reloaded to commit its intent rotation.' );
				}

				// See Utils::isOrderPaidAuthoritative()'s own docblock for
				// why both checks, and why this is the right call here
				// specifically (pre-check, not post-completion-failure
				// recovery).
				if ( $utils->isOrderPaidAuthoritative( $fresh_order ) ) {
					// Completed elsewhere (a sweep, a webhook, a
					// concurrent checkout attempt) in the gap between
					// the pre-emptive check above releasing this same
					// lock and this callback re-acquiring it -- a gap
					// that includes the entire external create() call,
					// unguarded by any lock, that produced
					// $paymentIntent. Publishing it now would let
					// checkout continue against a redundant intent on
					// an already-settled order and risk a second
					// payment; discard it instead of committing.
					$rotation_discarded = true;
				} else {
					$current_intent_id  = $fresh_order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY );
					$current_client_key = $fresh_order->get_meta( PAYMONGO_CLIENT_KEY_META_KEY );
					$proceed            = true;

					if ( ! empty( $current_intent_id ) ) {
						// is_paid() only reflects WooCommerce's own order
						// status, which a webhook updates -- it lags behind
						// PayMongo's own view of the current intent, which
						// can have moved to processing (e.g. a bank
						// transfer, awaiting_next_action having since
						// resolved) or even succeeded (a webhook simply
						// hasn't landed yet) in the same gap $paymentIntent
						// was created in, without WooCommerce knowing yet.
						// Rotating that intent into history here would bury
						// an in-flight or already-paid intent and publish
						// $paymentIntent as a second, redundant, chargeable
						// one. Re-verified directly against PayMongo, under
						// this same lock, immediately before committing --
						// not reused from the earlier pre-emptive check,
						// which read a possibly different intent before
						// this lock was a possibility to acquire at all.
						$current_status = '';

						// Renews this lock immediately before the
						// retrieveById() call below, which -- unlike the
						// fresh reload/meta-save work around it -- can run
						// long enough on its own to need it; see the
						// comment where this lock was acquired above.
						$heartbeat = \Cynder\PayMongo\Lock::heartbeat(
							$order_lock_key,
							$order_lock_token,
							PAYMONGO_ORDER_LOCK_TTL,
							'Order lock could not be renewed while re-verifying the current intent before rotating for order ' . $order_lock_key . '.'
						);

						$heartbeat();

						try {
							$current_intent_check = $locked_client->paymentIntent()->retrieveById( $current_intent_id );
							$current_status       = isset( $current_intent_check['attributes']['status'] ) ? $current_intent_check['attributes']['status'] : '';
						} catch ( \Throwable $e ) {
							if ( $debugMode ) {
								wc_get_logger()->log(
									'warning',
									'[Create Payment Intent] Failed to re-verify current intent before rotating for Order ID ' . $orderId . ': ' . $e->getMessage()
								);
							}
							throw $e;
						}

						$heartbeat();

						if ( in_array( $current_status, array( 'succeeded', 'processing' ), true ) ) {
							// See Utils::isOrderPaidAuthoritative()'s own
							// docblock for why both checks, and why this is
							// the right call here (pre-check, not
							// post-completion-failure recovery).
							if ( 'succeeded' === $current_status && ! $utils->isOrderPaidAuthoritative( $fresh_order ) ) {
								$rotation_after_completion = $utils->reconcileOrderAgainstPayMongoDetailed( $fresh_order, $locked_client, null, null, $heartbeat )->after_completion;
							}
							$rotation_discarded = true;
							$proceed            = false;
						} elseif ( in_array( $current_status, array( 'awaiting_payment_method', 'awaiting_next_action', 'cancelled' ), true ) ) {
							// awaiting_next_action rotates (commits the new
							// intent already created this call) rather than
							// halting alongside succeeded/processing above.
							// PayMongo's documented Payment Intent statuses are
							// awaiting_payment_method, awaiting_next_action,
							// processing and succeeded. 'cancelled' is handled
							// defensively here rather than from a documented
							// guarantee: this plugin itself now cancels intents
							// when an order is cancelled
							// (cynder_paymongo_cancel_order_intents()), and a
							// cancelled intent can't be paid, so rotating away
							// from it is the right call regardless. Their own documented recovery flow
							// (https://docs.paymongo.com/docs/payment-acceptance-troubleshooting)
							// is: once a stalled awaiting_next_action intent's
							// own expiry window lapses, it falls back to
							// awaiting_payment_method and the merchant creates
							// a fresh payment method for a new attach attempt
							// -- nothing documents attaching again while an
							// intent is still actively awaiting_next_action
							// (unexpired). A customer who simply abandoned the
							// redirect and immediately retries checkout,
							// rather than waiting out that window, would hit
							// exactly that undocumented case if rotation
							// halted here instead -- a real risk given how
							// often PH bank-card OTP times out. Rotating
							// instead is safe either way: the old intent is
							// preserved in _old history just below, so
							// reconciliation (the sweep, a late webhook, or
							// this order's own mismatch checks) still finds
							// and completes it if the customer's original
							// 3DS attempt resolves successfully after all.
							$old_intents      = $fresh_order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY . '_old', false );
							$flat_old_intents = Utils::flattenMetaValues( $old_intents );
							$flat_old_intents[] = strval( $current_intent_id );
							$unique_old_intents = array_values( array_unique( $flat_old_intents ) );
							$fresh_order->delete_meta_data( PAYMONGO_PAYMENT_INTENT_META_KEY . '_old' );
							$fresh_order->update_meta_data( PAYMONGO_PAYMENT_INTENT_META_KEY . '_old', $unique_old_intents );
						} else {
							// Fail closed: Unknown, missing, or unexpected payment status.
							// Do not rotate to prevent creating duplicate chargeable intents.
							// Logged unconditionally, not just in debug mode --
							// this blocks a paying customer (the new intent
							// this call already created is discarded, and
							// checkout keeps using the old one), so it must be
							// visible in production logs, not only when
							// debug mode happens to be on.
							wc_get_logger()->warning(
								'[Create Payment Intent] Order ' . $orderId . ' current intent has unhandled status "' . $current_status . '"; rotation refused.',
								array( 'source' => 'cynder-paymongo' )
							);
							$rotation_discarded = true;
							$proceed            = false;
						}
					}

					if ( $proceed ) {
						if ( ! empty( $current_client_key ) ) {
							// Same shape of meta value, same normalization need,
							// as the intent-id history this order also keeps.
							$old_keys       = $fresh_order->get_meta( PAYMONGO_CLIENT_KEY_META_KEY . '_old', false );
							$flat_old_keys  = Utils::flattenMetaValues( $old_keys );
							$flat_old_keys[] = strval( $current_client_key );
							$unique_old_keys = array_values( array_unique( $flat_old_keys ) );
							$fresh_order->delete_meta_data( PAYMONGO_CLIENT_KEY_META_KEY . '_old' );
							$fresh_order->update_meta_data( PAYMONGO_CLIENT_KEY_META_KEY . '_old', $unique_old_keys );
						}

						$fresh_order->update_meta_data( PAYMONGO_PAYMENT_INTENT_META_KEY, $paymentIntent['id'] );
						$fresh_order->update_meta_data( PAYMONGO_CLIENT_KEY_META_KEY, $clientKey );

						// A fresh intent deserves a fresh sweep budget: without
						// this, an order that exhausted its reconciliation
						// attempts against a previous (dead) intent would have
						// every later intent skipped too, even though the cron
						// has never actually checked it.
						$fresh_order->delete_meta_data( PAYMONGO_RECONCILE_ATTEMPTS_META_KEY );
						$fresh_order->delete_meta_data( PAYMONGO_RECONCILE_LAST_ATTEMPT_META_KEY );
						$fresh_order->delete_meta_data( PAYMONGO_RECONCILE_ATTEMPTS_INTENT_META_KEY );

						// The rotation just changed which intent is "current" and
						// shifted every historical intent's position, so a capped
						// scan's saved cursor no longer points at what it used to
						// -- start it over too.
						$fresh_order->delete_meta_data( PAYMONGO_RECONCILE_INTENT_OFFSET_META_KEY );

						$fresh_order->save_meta_data();
					}
				}
			} finally {
				\Cynder\PayMongo\Lock::release( $order_lock_key, $order_lock_token );
			}

			if ( null !== $rotation_after_completion ) {
				try {
					$rotation_after_completion();
				} catch ( \Throwable $e ) {
					$utils->log( 'warning', '[Create Payment Intent] Order ' . $orderId . ' was completed, but post-completion callbacks failed: ' . $e->getMessage() );
				}
			}

			if ( $rotation_discarded ) {
				// $paymentIntent was deliberately never published anywhere
				// either way; it sits idle, unattached to any payment
				// method, and needs no further cleanup on PayMongo's side.
				// Two distinct reasons land here, so the log message
				// distinguishes them rather than assuming the common one:
				// the unknown-status refusal already logged its own
				// unconditional warning above and isn't an error either,
				// but it isn't "already completed or in progress" --
				// nothing here actually confirmed that for an unrecognized
				// status.
				if ( $debugMode && in_array( $current_status, array( 'succeeded', 'processing' ), true ) ) {
					wc_get_logger()->log(
						'info',
						'[Create Payment Intent] Order ' . $orderId . ' was already completed or in progress elsewhere before intent rotation could commit; discarding the new intent.'
					);
				}
				return;
			}
		} else {
			wc_get_logger()->log( 'error', '[Create Payment Intent] ' . json_encode( $paymentIntent['errors'] ) );
			throw new Exception( __( $genericErrorMessage, 'woocommerce' ) );
		}
	} catch ( PaymongoException $e ) {
		$formatted_messages = $e->format_errors();
		$utils->log( 'error', '[Create Payment Intent] Response - ' . join( ',', $formatted_messages ) );
		throw new Exception( __( $genericErrorMessage, 'woocommerce' ) );
	} catch ( \Throwable $e ) {
		// Catch all other exceptions and PHP 7+ errors (TypeError, Error, etc.)
		// so checkout aborts safely with a user notice instead of fatal-crashing the request.
		$utils->log( 'error', '[Create Payment Intent] Unexpected error: ' . $e->getMessage() );
		throw new Exception( __( $genericErrorMessage, 'woocommerce' ) );
	}
}

add_action( 'woocommerce_checkout_order_processed', 'cynder_paymongo_create_intent' );

/**
 * Thin hook callback, deliberately argument-free. WordPress's own
 * do_action() inserts a single '' argument when a hook fires with none
 * (see do_action() in wp-includes/plugin.php: `if ( empty( $arg ) ) {
 * $arg[] = ''; }`) -- woocommerce_api_{$wc_api} fires with no arguments
 * at all, so a callback registered with its default accepted_args (1)
 * receives that '' as its first parameter, not null. An earlier version
 * of this function took $utils as an optional first parameter defaulted
 * with `$utils ?? new Utils()` specifically for test injection; `??`
 * only replaces null, so in production $utils stayed the string '',
 * and every $utils-> call below fatally errored for every customer
 * returning from PayMongo. Real injection now only happens through
 * cynder_paymongo_handle_catch_redirect() directly (see
 * tests/TopLevelHooksTest.php), never through this hook callback.
 */
function cynder_paymongo_catch_redirect() {
	cynder_paymongo_handle_catch_redirect( new Utils() );
}

function cynder_paymongo_handle_catch_redirect( Utils $utils, $client = null, $locked_client = null ) {

	if ( empty( $_GET['intent'] ) || empty( $_GET['order'] ) || empty( $_GET['key'] ) ) {
		$missingParams = array();

		if ( empty( $_GET['intent'] ) ) {
			$missingParams[] = 'intent';
		}

		if ( empty( $_GET['order'] ) ) {
			$missingParams[] = 'order';
		}

		if ( empty( $_GET['key'] ) ) {
			$missingParams[] = 'key';
		}

		wc_get_logger()->log( 'warning', '[Catch Redirect] Missing required query parameter(s): ' . implode( ', ', $missingParams ) . '.' );
		wc_add_notice( __( 'Unable to verify your payment. Please try again.', 'paymongo' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	$debugMode = get_option( 'woocommerce_cynder_paymongo_debug_mode' );
	$debugMode = ( ! empty( $debugMode ) && $debugMode === 'yes' ) ? true : false;

	$sendInvoice = get_option( 'woocommerce_cynder_paymongo_send_invoice_after_payment' );
	$sendInvoice = ( ! empty( $sendInvoice ) && $sendInvoice === 'yes' ) ? true : false;

	if ( $debugMode ) {
		$safeGet = $utils->redactSensitiveData( $_GET );
		wc_get_logger()->log( 'info', '[Catch Redirect][Payload] ' . wc_print_r( $safeGet, true ) );
	}

	$paymentIntentId = sanitize_text_field( wp_unslash( $_GET['intent'] ) );

	// Hardening: Verify the Intent ID is not empty and matches PayMongo's exact prefix format.
	if ( empty( $paymentIntentId ) || strpos( $paymentIntentId, 'pi_' ) !== 0 ) {
		wc_get_logger()->log( 'warning', '[Catch Redirect] Empty or malformed payment intent ID after sanitization. Value: ' . $paymentIntentId );
		wc_add_notice( __( 'Unable to verify your payment. Please try again.', 'paymongo' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	$testMode = get_option( 'woocommerce_cynder_paymongo_test_mode' );
	$testMode = ( ! empty( $testMode ) && $testMode === 'yes' ) ? true : false;

	$pkKey     = $testMode ? 'woocommerce_cynder_paymongo_test_public_key' : 'woocommerce_cynder_paymongo_public_key';
	$skKey     = $testMode ? 'woocommerce_cynder_paymongo_test_secret_key' : 'woocommerce_cynder_paymongo_secret_key';
	$publicKey = get_option( $pkKey );
	$secretKey = get_option( $skKey );
	// The initial retrieveById() below runs before any per-order lock is
	// acquired, on the customer's redirect back from PayMongo -- no
	// explicit timeout override, same as before this lock work.
	$client = $client ?? new Phaymongo( $publicKey, $secretKey );
	// Both reconcileOrderAgainstPayMongoLocked() calls further down run
	// while the per-order lock is held -- see Utils::makeLockedClient().
	$locked_client = $locked_client ?? Utils::makeLockedClient( $publicKey, $secretKey );

	$orderId         = absint( wp_unslash( $_GET['order'] ) );
	$orderKey        = sanitize_text_field( wp_unslash( $_GET['key'] ) );
	$resolvedOrderId = wc_get_order_id_by_order_key( $orderKey );
	$order           = wc_get_order( $resolvedOrderId );

	if ( ! $order || $order->get_id() !== $orderId ) {
		wc_add_notice( __( 'Invalid order reference.', 'paymongo' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	// Verify that the Intent ID matches the current one OR any _old intent on the same order.
	$storedIntentId    = $order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY );
	$old_intents       = $order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY . '_old', false );
	$flat_old_intents  = Utils::flattenMetaValues( $old_intents );

	if ( $paymentIntentId !== $storedIntentId && ! in_array( $paymentIntentId, $flat_old_intents, true ) ) {
		try {
			// Locked -- serialized with the same per-order lock the
			// reconciliation sweep and webhook handler use, so this
			// customer-driven recheck can't race one of those completing
			// the exact same order concurrently.
			$reconcile_result = $utils->reconcileOrderAgainstPayMongoLocked( $order, $locked_client );
			if ( true === $reconcile_result ) {
				wp_safe_redirect( $order->get_checkout_order_received_url() );
				exit;
			}

			if ( null === $reconcile_result ) {
				// Genuine uncertainty only -- see
				// Utils::reconcileOrderAgainstPayMongoLocked()'s own
				// docblock. An unambiguous awaiting_next_action (nothing
				// else ambiguous in the scan) returns false instead, and
				// falls through below to the mismatch/"verification
				// failed" handling, not this notice.
				wc_add_notice( __( 'Payment is processing. You will receive an email once confirmed.', 'paymongo' ), 'notice' );
				wp_safe_redirect( $order->get_checkout_order_received_url() );
				exit;
			}
		} catch ( \Throwable $e ) {
			wc_get_logger()->log( 'error', '[Catch Redirect] Failed to verify Order ID ' . $orderId . ' against a mismatched intent: ' . $e->getMessage() );
		}

		wc_get_logger()->log( 'error', '[Catch Redirect] Payment intent mismatch for Order ID: ' . $orderId );
		wc_add_notice( __( 'Payment verification failed.', 'paymongo' ), 'error' );
		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}

	try {
		$paymentIntent = $client->paymentIntent()->retrieveById( $paymentIntentId );

		if ( $debugMode ) {
			wc_get_logger()->log( 'info', '[Catch Redirect][Response] ' . wc_print_r( $paymentIntent, true ) );
		}

		$responseAttr = $paymentIntent['attributes'];
		$status       = $responseAttr['status'];
		$intentAmount = $responseAttr['amount'];

		if ( $status === 'succeeded' || $status === 'processing' ) {
			if ( $status === 'succeeded' ) {
				$payments = $responseAttr['payments'] ?? array();
				if ( empty( $payments ) || ! isset( $payments[0]['id'] ) ) {
					wc_add_notice( __( 'Payment was successful but could not be verified correctly. Contact support.', 'paymongo' ), 'error' );
					wp_safe_redirect( $order->get_checkout_payment_url() );
					exit;
				}

				$payment = $payments[0];

				// Locked -- see the mismatch branch above for why.
				if ( ! $utils->completeOrderLocked( $order, $payment, $sendInvoice, floatval( $intentAmount ) / 100, $order->get_payment_method(), $testMode ) ) {
					wc_get_logger()->log( 'warning', '[Catch Redirect] Order lock is busy after successful payment for Order ID: ' . $orderId . '; deferring completion to webhook/reconciliation.' );
					wc_add_notice( __( 'Payment is processing. You will receive an email once confirmed.', 'paymongo' ), 'notice' );
					// PayMongo already confirmed this payment succeeded --
					// only the order's own bookkeeping is deferred, not the
					// payment, so the cart empties now too, same as the
					// immediate-completion path below -- otherwise a
					// customer landing on the thank-you page with a still-
					// full cart could check out again before that
					// bookkeeping catches up.
					$utils->emptyCart();
					wp_safe_redirect( $order->get_checkout_order_received_url() );
					exit;
				}
			}

			// Empty cart and redirect regardless (even if missed/already paid)
			// as PayMongo confirms this specific payment intent is settled or processing
			$utils->emptyCart();
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		} elseif ( $status === 'awaiting_payment_method' || $status === 'awaiting_next_action' ) {
			$reconcile_result = null;

			try {
				// Locked -- serialized with the same per-order lock the reconciliation
				// sweep and webhook handler use, so this customer-driven recheck can't
				// race one of those completing the exact same order concurrently.
				$reconcile_result = $utils->reconcileOrderAgainstPayMongoLocked( $order, $locked_client );
			} catch ( \Throwable $e ) {
				wc_get_logger()->log( 'error', '[Catch Redirect] Failed to verify Order ID ' . $orderId . ': ' . $e->getMessage() );
			}

			if ( $reconcile_result === true ) {
				wp_safe_redirect( $order->get_checkout_order_received_url() );
				exit;
			} elseif ( $reconcile_result === null ) {
				// Null indicates genuine uncertainty -- lock contention, a
				// scan failure, a transient PayMongo-side error, or a
				// capped/malformed scan -- not simply "the customer's own
				// attempt is still awaiting_next_action." See
				// Utils::reconcileOrderAgainstPayMongoLocked()'s own
				// docblock: that specific, unambiguous case returns false
				// below instead, so it isn't mistaken for this one.
				wc_add_notice( __( 'Payment is processing. You will receive an email once confirmed.', 'paymongo' ), 'notice' );
				wp_safe_redirect( $order->get_checkout_order_received_url() );
				exit;
			}

			// False indicates a definitive miss (unpaid), or a confirmed
			// awaiting_next_action with nothing else ambiguous about this
			// order's scan -- either way, this specific attempt didn't go
			// through.
			wc_add_notice( __( 'Something went wrong with the payment. Please try another payment method. If the issue persists, contact support.', 'paymongo' ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
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
	} catch ( \Throwable $e ) {
		// Covers completeOrderLocked()/reconcileOrderAgainstPayMongoLocked()
		// losing the order lock mid-call, or any other unexpected failure
		// from either -- same fallback as the PaymongoException case above:
		// this doesn't confirm the order is actually paid, but a failure
		// here shouldn't strand the customer on an error page either, and
		// the reconciliation sweep or a webhook delivery remains the
		// authoritative path to actually complete it.
		$utils->log( 'error', '[Catch Redirect for Payment Intent] Order ID: ' . $order->get_id() . ' - Unexpected error: ' . $e->getMessage() );
		// Same notice as every other genuinely-uncertain path here -- the
		// customer lands on the same order-received page either way, and
		// shouldn't be left to guess whether their payment went through.
		wc_add_notice( __( 'Payment is processing. You will receive an email once confirmed.', 'paymongo' ), 'notice' );
		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	}
}

add_action(
	'woocommerce_api_cynder_paymongo_catch_redirect',
	'cynder_paymongo_catch_redirect'
);


/**
 * Thin hook callback, deliberately argument-free -- see
 * cynder_paymongo_catch_redirect()'s identical own comment for why: a
 * do_action()-fired hook with no WordPress-side arguments at all still
 * hands its callback a single '' (empty string), not null, making an
 * optional `$utils = null` parameter here fatally unsafe in production.
 */
function cynder_paymongo_catch_source_redirect() {
	cynder_paymongo_handle_catch_source_redirect( new Utils() );
}

function cynder_paymongo_handle_catch_source_redirect( Utils $utils, $client = null ) {
	if ( empty( $_GET['order'] ) || empty( $_GET['status'] ) || empty( $_GET['key'] ) ) {
		wc_add_notice( __( 'Missing payment or order reference.', 'paymongo' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	$orderId  = absint( wp_unslash( $_GET['order'] ) );
	$status   = sanitize_text_field( wp_unslash( $_GET['status'] ) );
	$orderKey = sanitize_text_field( wp_unslash( $_GET['key'] ) );

	$order = wc_get_order( $orderId );

	if ( ! $order || $order->get_order_key() !== $orderKey ) {
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	// Initialize PayMongo client and utilities to verify the payment.
	$test_mode = get_option( 'woocommerce_cynder_paymongo_test_mode' ) === 'yes';
	$pk_key    = $test_mode ? 'woocommerce_cynder_paymongo_test_public_key' : 'woocommerce_cynder_paymongo_public_key';
	$sk_key    = $test_mode ? 'woocommerce_cynder_paymongo_test_secret_key' : 'woocommerce_cynder_paymongo_secret_key';

	// reconcileOrderAgainstPayMongoLocked() below can run this client's
	// retrieval while the per-order lock is held -- see
	// Utils::makeLockedClient().
	$client = $client ?? Utils::makeLockedClient( get_option( $pk_key ), get_option( $sk_key ) );

	// Authoritatively check the PayMongo API for the payment status.
	// Locked -- serialized with the same per-order lock the reconciliation
	// sweep and webhook handler use, so this customer-driven recheck can't
	// race one of those completing the exact same order concurrently.
	$is_paid = false;
	$inconclusive = false;
	try {
		$reconcile_result = $utils->reconcileOrderAgainstPayMongoLocked( $order, $client );
		if ( $reconcile_result === true ) {
			$is_paid = true;
		} elseif ( $reconcile_result === null ) {
			// Genuine uncertainty only -- see
			// Utils::reconcileOrderAgainstPayMongoLocked()'s own docblock.
			// An unambiguous awaiting_next_action (nothing else ambiguous
			// in the scan) returns false instead, which $inconclusive
			// correctly leaves untouched below.
			$inconclusive = true;
		}
	} catch ( \Throwable $e ) {
		wc_get_logger()->log( 'error', '[Catch Source Redirect] Failed to verify Order ID ' . $orderId . ': ' . $e->getMessage() );
		$inconclusive = true;
	}

	if ( 'success' === $status ) {
		// If the URL claims success but the API hasn't fully cleared it yet.
		if ( ! $is_paid ) {
			wc_add_notice( __( 'Your payment is processing. You will receive an email once it is confirmed.', 'paymongo' ), 'notice' );
		}

		wp_safe_redirect( $order->get_checkout_order_received_url() );
		exit;
	} else {
		// If the URL claims failure, but the API says it actually succeeded.
		if ( $is_paid ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		// Only fail if the API confirms it is not paid.
		if ( 'failed' === $status ) {
			// Distinguish the lock failure vs the confirmed miss to protect the checkout flow
			if ( $inconclusive ) {
				wc_add_notice( __( 'Your payment is processing. You will receive an email once it is confirmed.', 'paymongo' ), 'notice' );
				wp_safe_redirect( $order->get_checkout_order_received_url() );
				exit;
			}
			wc_add_notice( __( 'Something went wrong with the payment. Please try another payment method. If the issue persists, contact support.', 'paymongo' ), 'error' );
		} else {
			wc_add_notice( __( 'Invalid payment redirect status. Please try again or choose another payment method.', 'paymongo' ), 'error' );
		}

		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}
}

add_action(
	'woocommerce_api_cynder_paymongo_catch_source_redirect',
	'cynder_paymongo_catch_source_redirect'
);

/**
 * Order IDs WooCommerce's unpaid-order timeout is about to cancel in this
 * request. The 'woocommerce_cancel_unpaid_order' filter is the only signal
 * that distinguishes that cancel from a merchant's: it fires just before
 * update_status('cancelled'), which then fires the status hook below.
 */
final class Cynder_PayMongo_Auto_Cancel_Registry {
	/** @var array<int, true> */
	private static $ids = array();

	/**
	 * @param int $order_id
	 */
	public static function remember( $order_id ) {
		self::$ids[ $order_id ] = true;
	}

	/**
	 * Whether $order_id was remembered; forgets it either way.
	 *
	 * @param int $order_id
	 * @return bool
	 */
	public static function consume( $order_id ) {
		$known = isset( self::$ids[ $order_id ] );
		unset( self::$ids[ $order_id ] );

		return $known;
	}
}

/**
 * Pass-through filter: notes an automatic cancel without changing WooCommerce's decision.
 *
 * @param bool           $should_cancel
 * @param \WC_Order|null $order
 * @return bool
 */
function cynder_paymongo_note_auto_cancel( $should_cancel, $order = null ) {
	if ( $should_cancel && $order instanceof \WC_Order ) {
		Cynder_PayMongo_Auto_Cancel_Registry::remember( $order->get_id() );
	}

	return $should_cancel;
}

add_filter( 'woocommerce_cancel_unpaid_order', 'cynder_paymongo_note_auto_cancel', 10, 2 );

/**
 * Thin hook callback -- see cynder_paymongo_catch_source_redirect()'s comment
 * on why hook callbacks stay free of injectable optional parameters.
 *
 * @param int   $order_id
 * @param mixed $order    The order WooCommerce is transitioning, if it passed one.
 */
function cynder_paymongo_on_order_cancelled( $order_id, $order = null ) {
	cynder_paymongo_handle_order_cancelled( $order_id, new Utils(), $order instanceof \WC_Order ? $order : null );
}

/**
 * An order was cancelled. Two jobs:
 *
 * 1. Record who cancelled it ('auto' timeout or 'merchant'; the customer's
 *    own cancel upgrades it to 'customer' just after, see
 *    cynder_paymongo_on_customer_cancelled()). Utils::deferLatePayment()
 *    uses this to decide whether a payment that still lands may reinstate
 *    the order. Done synchronously, in the cancel itself.
 * 2. Cancel the order's payment intents at PayMongo, so the customer can't
 *    keep paying from a tab or app that is still open -- queued as an
 *    Action Scheduler async action (cynder_paymongo_cancel_order_intents()),
 *    because up to PAYMONGO_CANCEL_MAX_INTENTS x 2 API calls per order would
 *    otherwise run inline in the unpaid-order cron's whole batch of
 *    cancels, or in an admin bulk cancel. The trade-off: the intent stays
 *    payable for the few seconds until the queue runs; a payment landing
 *    in that window is held for review by Utils::deferLatePayment().
 *    Without Action Scheduler (or if queueing fails) it runs inline.
 *
 * The marker left by an earlier parked payment is deliberately NOT cleared
 * here: it records that payment, so seeing it again on the now-cancelled
 * order doesn't re-park it.
 *
 * @param int            $order_id
 * @param Utils          $utils
 * @param \WC_Order|null $order  The order object being transitioned; re-read when null.
 * @param mixed          $client  Test seam: a Phaymongo-compatible client, used for the inline fallback.
 */
function cynder_paymongo_handle_order_cancelled( $order_id, Utils $utils, $order = null, $client = null ) {
	$order = $order ?? wc_get_order( $order_id );

	if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
		return;
	}

	if ( ! in_array( $order->get_payment_method(), PAYMONGO_PAYMENT_METHODS, true ) ) {
		return;
	}

	$order->update_meta_data( PAYMONGO_CANCEL_SOURCE_META_KEY, Cynder_PayMongo_Auto_Cancel_Registry::consume( $order_id ) ? 'auto' : 'merchant' );
	$order->save_meta_data();

	if ( function_exists( 'as_enqueue_async_action' ) && as_enqueue_async_action( PAYMONGO_CANCEL_INTENTS_ACTION, array( (int) $order_id ), 'cynder-paymongo' ) ) {
		return;
	}

	cynder_paymongo_cancel_order_intents( $order_id, $utils, $order, $client );
}

/**
 * Action Scheduler callback for PAYMONGO_CANCEL_INTENTS_ACTION.
 *
 * @param int $order_id
 * @param int $attempt  Times the job has already been retried because the order lock was busy.
 */
function cynder_paymongo_run_cancel_order_intents( $order_id, $attempt = 0 ) {
	cynder_paymongo_cancel_order_intents( $order_id, new Utils(), null, null, (int) $attempt );
}

add_action( PAYMONGO_CANCEL_INTENTS_ACTION, 'cynder_paymongo_run_cancel_order_intents', 10, 2 );

/**
 * Cancels the order's payment intents at PayMongo. Best-effort: PayMongo's
 * docs don't say which intent statuses or methods accept a cancel, so every
 * failure here is logged and noted on the order, never raised -- it must not
 * block the WooCommerce cancel itself. Intents already succeeded/processing
 * are left alone: that money is real and the late-payment handling decides
 * what happens to it.
 *
 * @param int            $order_id
 * @param Utils          $utils
 * @param \WC_Order|null $order   Re-read when null (the queued run).
 * @param mixed          $client   Test seam: a Phaymongo-compatible client, used for both reading and cancelling.
 * @param int            $attempt  Times this job has already been retried because the order lock was busy.
 */
function cynder_paymongo_cancel_order_intents( $order_id, Utils $utils, $order = null, $client = null, $attempt = 0 ) {
	$order = $order ?? wc_get_order( $order_id );

	if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
		return;
	}

	// Queued, so this runs in a later request than the cancel that checked it.
	if ( ! in_array( $order->get_payment_method(), PAYMONGO_PAYMENT_METHODS, true ) ) {
		return;
	}

	// Queued, so by now the merchant may have reinstated the order.
	if ( ! $order->has_status( 'cancelled' ) ) {
		return;
	}

	$current = $order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY );
	$old     = Utils::flattenMetaValues( $order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY . '_old', false ) );

	// Current first, then most recently replaced.
	$intent_ids = array_values( array_unique( array_filter( array_merge( array( strval( $current ) ), array_reverse( $old ) ) ) ) );
	$intent_ids = array_slice( $intent_ids, 0, PAYMONGO_CANCEL_MAX_INTENTS );

	if ( empty( $intent_ids ) ) {
		return;
	}

	$lock_key   = \Cynder\PayMongo\Lock::orderIntentKey( $order_id );
	$lock_token = \Cynder\PayMongo\Lock::acquire( $lock_key, PAYMONGO_ORDER_LOCK_TTL );

	if ( false === $lock_token ) {
		// A rotation or completion is in flight for this order. Retry the
		// queued job shortly rather than leaving the intents payable.
		if ( $attempt < PAYMONGO_CANCEL_MAX_RETRIES && function_exists( 'as_schedule_single_action' )
			&& as_schedule_single_action( time() + 60, PAYMONGO_CANCEL_INTENTS_ACTION, array( (int) $order_id, $attempt + 1 ), 'cynder-paymongo' ) ) {
			wc_get_logger()->warning(
				'Order ' . $order_id . ': order lock busy; retrying PayMongo intent cancel (attempt ' . ( $attempt + 1 ) . ').',
				array( 'source' => 'cynder-paymongo' )
			);
			return;
		}

		wc_get_logger()->warning(
			'Order ' . $order_id . ': order lock busy; PayMongo intents not cancelled.',
			array( 'source' => 'cynder-paymongo' )
		);
		$order->add_order_note( __( 'Could not cancel the PayMongo payment intents (order busy). A payment received later will be held for review.', 'paymongo' ) );
		return;
	}

	$cancelled = array();
	$kept      = array();
	$failed    = array();
	$done      = array(); // Intents the loop got to, whatever the outcome.
	$aborted   = array(); // Intents never reached because the loop stopped early.

	try {
		if ( null === $client ) {
			$test_mode = get_option( 'woocommerce_cynder_paymongo_test_mode' ) === 'yes';
			$pk_key    = $test_mode ? 'woocommerce_cynder_paymongo_test_public_key' : 'woocommerce_cynder_paymongo_public_key';
			$sk_key    = $test_mode ? 'woocommerce_cynder_paymongo_test_secret_key' : 'woocommerce_cynder_paymongo_secret_key';
			$client    = Utils::makeLockedClient( get_option( $pk_key ), get_option( $sk_key ) );
			// The cancel response body is never used; see Utils::cancelPaymentIntent().
			$cancel_client = Utils::makeLockedClient( get_option( $pk_key ), get_option( $sk_key ), array( 'return_response' => true ) );
		}

		$cancel_client = isset( $cancel_client ) ? $cancel_client : $client;

		$heartbeat = \Cynder\PayMongo\Lock::heartbeat(
			$lock_key,
			$lock_token,
			PAYMONGO_ORDER_LOCK_TTL,
			'Order lock could not be renewed while cancelling PayMongo intents for order ' . $order_id . '.'
		);

		foreach ( $intent_ids as $intent_id ) {
			$heartbeat();
			$done[] = $intent_id;

			try {
				$intent = $client->paymentIntent()->retrieveById( $intent_id );
				$status = isset( $intent['attributes']['status'] ) ? $intent['attributes']['status'] : '';

				if ( in_array( $status, array( 'succeeded', 'processing' ), true ) ) {
					$kept[] = $intent_id;
					continue;
				}

				if ( 'cancelled' === $status ) {
					continue;
				}

				$utils->cancelPaymentIntent( $cancel_client, $intent_id );
				$cancelled[] = $intent_id;
			} catch ( \Cynder\PayMongo\LockLostException $e ) {
				throw $e;
			} catch ( \Throwable $e ) {
				$failed[] = $intent_id;
				wc_get_logger()->warning(
					'Order ' . $order_id . ': could not cancel PayMongo intent ' . $intent_id . ': ' . $e->getMessage(),
					array( 'source' => 'cynder-paymongo' )
				);
			}
		}
	} catch ( \Throwable $e ) {
		// Lost lock, or the client could not even be built.
		$aborted = array_values( array_diff( $intent_ids, $done ) );
		wc_get_logger()->warning(
			'Order ' . $order_id . ': stopped cancelling PayMongo intents: ' . $e->getMessage(),
			array( 'source' => 'cynder-paymongo' )
		);
	} finally {
		\Cynder\PayMongo\Lock::release( $lock_key, $lock_token );
	}

	if ( ! empty( $aborted ) ) {
		/* translators: %s: comma-separated PayMongo payment intent IDs */
		$order->add_order_note( sprintf( __( 'Stopped before cancelling PayMongo payment intent(s) %s. A payment received later will be held for review.', 'paymongo' ), implode( ', ', $aborted ) ) );
	}

	if ( ! empty( $cancelled ) ) {
		/* translators: %s: comma-separated PayMongo payment intent IDs */
		$order->add_order_note( sprintf( __( 'Cancelled PayMongo payment intent(s): %s.', 'paymongo' ), implode( ', ', $cancelled ) ) );
	}

	if ( ! empty( $kept ) ) {
		if ( $order->get_date_paid() ) {
			// Completed before the cancel: already handled, nothing is held.
			/* translators: %s: comma-separated PayMongo payment intent IDs */
			$order->add_order_note( sprintf( __( 'PayMongo payment intent(s) %s were already paid before the order was cancelled, so not cancelled.', 'paymongo' ), implode( ', ', $kept ) ) );
		} else {
			/* translators: %s: comma-separated PayMongo payment intent IDs */
			$order->add_order_note( sprintf( __( 'PayMongo payment intent(s) %s already paid or in progress, so not cancelled. The payment will be held for review.', 'paymongo' ), implode( ', ', $kept ) ) );
		}
	}

	if ( ! empty( $failed ) ) {
		/* translators: %s: comma-separated PayMongo payment intent IDs */
		$order->add_order_note( sprintf( __( 'Could not cancel PayMongo payment intent(s) %s. A payment received later will be held for review.', 'paymongo' ), implode( ', ', $failed ) ) );
	}
}

add_action( 'woocommerce_order_status_cancelled', 'cynder_paymongo_on_order_cancelled', 10, 2 );

/**
 * woocommerce_cancelled_order fires from the customer's own cancel, after
 * the status change above already recorded it as 'merchant'.
 *
 * @param int $order_id
 */
function cynder_paymongo_on_customer_cancelled( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order || ! is_a( $order, 'WC_Order' ) || ! $order->has_status( 'cancelled' ) ) {
		return;
	}

	if ( ! in_array( $order->get_payment_method(), PAYMONGO_PAYMENT_METHODS, true ) ) {
		return;
	}

	$order->update_meta_data( PAYMONGO_CANCEL_SOURCE_META_KEY, 'customer' );
	$order->save_meta_data();
}

add_action( 'woocommerce_cancelled_order', 'cynder_paymongo_on_customer_cancelled' );

/**
 * Leaving 'cancelled' (e.g. a merchant reinstating the order) ends the
 * cancellation, so its source no longer applies to a later one; going back
 * to pending/failed also ends a parked late payment's hold.
 *
 * @param int       $order_id
 * @param string    $from
 * @param string    $to
 * @param \WC_Order $order
 */
function cynder_paymongo_on_order_status_changed( $order_id, $from, $to, $order = null ) {
	if ( ! $order instanceof \WC_Order ) {
		return;
	}

	$changed = false;

	if ( 'cancelled' === $from ) {
		$order->delete_meta_data( PAYMONGO_CANCEL_SOURCE_META_KEY );
		$changed = true;
	}

	// A merchant moving a parked order back to a status the customer can pay
	// from ends that late payment's hold; otherwise its marker would make a
	// legitimate payment on the reopened order skip completion.
	if ( in_array( $to, array( 'pending', 'failed' ), true ) && '' !== (string) $order->get_meta( PAYMONGO_LATE_PAYMENT_META_KEY ) ) {
		$order->delete_meta_data( PAYMONGO_LATE_PAYMENT_META_KEY );
		$changed = true;
	}

	if ( $changed ) {
		$order->save_meta_data();
	}
}

add_action( 'woocommerce_order_status_changed', 'cynder_paymongo_on_order_status_changed', 10, 4 );

/**
 * WooCommerce hooks its customer "on-hold" and admin "new order" emails to
 * cancelled -> on-hold. For a late payment parked by
 * Utils::deferLatePayment() they would tell the customer their payment is
 * being confirmed (the merchant may be about to refund it) and send the
 * admin a "new order" on top of the plugin's own email, so they're turned
 * off for on-hold orders carrying the marker.
 *
 * @param bool           $enabled
 * @param \WC_Order|null $order
 * @return bool
 */
function cynder_paymongo_suppress_parked_order_email( $enabled, $order = null ) {
	// on-hold only: a merchant later reinstating a marked order straight to
	// processing still gets the normal "new order" email.
	if ( $enabled && $order instanceof \WC_Order && $order->has_status( 'on-hold' ) && '' !== (string) $order->get_meta( PAYMONGO_LATE_PAYMENT_META_KEY ) ) {
		return false;
	}

	return $enabled;
}

add_filter( 'woocommerce_email_enabled_customer_on_hold_order', 'cynder_paymongo_suppress_parked_order_email', 10, 2 );
add_filter( 'woocommerce_email_enabled_new_order', 'cynder_paymongo_suppress_parked_order_email', 10, 2 );

/**
 * WooCommerce hooks wc_maybe_reduce_stock_levels() to on-hold, and
 * cancelling released the order's stock, so parking a late payment
 * (cancelled -> on-hold) would take the stock off the shelf again while the
 * merchant decides whether to refund -- or push it negative if the stock was
 * already resold. Skipped for a parked on-hold order; moving it to
 * Processing/Completed reduces stock then, as usual.
 *
 * This deliberately uses 'woocommerce_payment_complete_reduce_order_stock',
 * not 'woocommerce_can_reduce_order_stock': WooCommerce marks the order's
 * stock as reduced right after wc_reduce_stock_levels() returns, even when
 * the latter filter made it do nothing, so the later move to Processing
 * would then skip the reduction for good. This filter is checked before
 * that flag is touched.
 *
 * @param bool $trigger_reduce Whether WooCommerce is about to reduce stock.
 * @param int  $order_id
 * @return bool
 */
function cynder_paymongo_hold_stock_for_parked_order( $trigger_reduce, $order_id = 0 ) {
	if ( ! $trigger_reduce ) {
		return $trigger_reduce;
	}

	$order = wc_get_order( $order_id );

	if ( $order instanceof \WC_Order && $order->has_status( 'on-hold' ) && '' !== (string) $order->get_meta( PAYMONGO_LATE_PAYMENT_META_KEY ) ) {
		return false;
	}

	return $trigger_reduce;
}

add_filter( 'woocommerce_payment_complete_reduce_order_stock', 'cynder_paymongo_hold_stock_for_parked_order', 10, 2 );

function add_webhook_settings( $settings, $current_section ) {
	if ( in_array( $current_section, PAYMONGO_PAYMENT_METHODS ) ) {
		$webhookUrl = add_query_arg(
			'wc-api',
			'cynder_paymongo',
			trailingslashit( get_home_url() )
		);

		$settings_webhooks = array(
			array(
				'name' => 'API Settings',
				'id'   => 'paymongo_api_settings_title',
				'type' => 'title',
				'desc' => 'PayMongo API settings',
			),
			array(
				'id'          => 'live_env',
				'title'       => 'Live Environment',
				'type'        => 'title',
				'description' => 'Use live keys for actual payments',
			),
			array(
				'id'    => 'woocommerce_cynder_paymongo_public_key',
				'title' => 'Live Public Key',
				'type'  => 'text',
			),
			array(
				'id'    => 'woocommerce_cynder_paymongo_secret_key',
				'title' => 'Live Secret Key',
				'type'  => 'password',
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			),
			array(
				'name'     => 'Live Webhook Secret',
				'id'       => 'paymongo_webhook_secret_key',
				'type'     => 'password',
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
				'desc_tip' => 'This is required to properly process payments and update order statuses accordingly',
				'desc'     => '<a target="_blank" href="https://paymongo-webhook-tool.cynder.io?url='
				. $webhookUrl
				. '">Go here to generate a webhook secret</a>',
			),
			array(
				'id'   => 'live_env_end',
				'type' => 'sectionend',
			),
			array(
				'id'    => 'test_env',
				'title' => 'Test Environment',
				'type'  => 'title',
				'desc'  => 'Use the plugin in <b>Test Mode</b><br/>In test mode, you can transact using the PayMongo payment methods in checkout without actual payments',
			),
			array(
				'id'      => 'woocommerce_cynder_paymongo_test_mode',
				'title'   => 'Test mode',
				'label'   => 'Enable Test Mode',
				'type'    => 'checkbox',
				'desc'    => 'Place the payment gateway in test mode using <b>Test API keys</b>',
				'default' => 'yes',
			),
			array(
				'id'    => 'woocommerce_cynder_paymongo_test_public_key',
				'title' => 'Test Public Key',
				'type'  => 'text',
			),
			array(
				'id'    => 'woocommerce_cynder_paymongo_test_secret_key',
				'title' => 'Test Secret Key',
				'type'  => 'password',
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
			),
			array(
				'name'     => 'Test Webhook Secret',
				'id'       => 'paymongo_test_webhook_secret_key',
				'type'     => 'password',
				'custom_attributes' => array( 'autocomplete' => 'new-password' ),
				'desc_tip' => 'This is required to properly process payments and update order statuses accordingly',
				'desc'     => '<a target="_blank" href="https://paymongo-webhook-tool.cynder.io?url='
				. $webhookUrl
				. '">Go here to generate a webhook secret</a>',
			),
			array(
				'id'   => 'test_env_end',
				'type' => 'sectionend',
			),
			array(
				'id'    => 'paymongo_misc',
				'title' => 'Other Options',
				'type'  => 'title',
			),
			array(
				'id'       => 'woocommerce_cynder_paymongo_debug_mode',
				'title'    => 'Debug mode',
				'label'    => 'Enable Debug Mode',
				'type'     => 'checkbox',
				'desc_tip' => 'This enables additional logs in WC logger for developer analysis',
				'desc'     => 'Enable additional logs',
				'default'  => 'no',
			),
			array(
				'id'       => 'woocommerce_cynder_paymongo_send_invoice_after_payment',
				'title'    => 'Send Invoice',
				'desc'     => 'Enables automatic invoice sending after payment',
				'desc_tip' => 'This enables automatic sending of an invoice to the customer via e-mail after payment is resolved',
				'type'     => 'checkbox',
				'default'  => 'yes',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'paymongo_api_settings',
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
	$oldVersion = get_option( 'cynder_paymongo_version' );

	/**
	 * Prior to 1.4.8, API settings are in credit/debit card screen only
	 *
	 * Updating the plugin to 1.4.8 or higher moves the settings as shared ones on
	 * all PayMongo payment methods
	 */
	if ( version_compare( $oldVersion, '1.5.0', '<' ) ) {
		$mainPluginSettings = get_option( 'woocommerce_paymongo_settings' );

		/** Migrate old settings to new settings */
		$settingsToMigrate = array(
			'public_key'      => 'woocommerce_cynder_paymongo_public_key',
			'secret_key'      => 'woocommerce_cynder_paymongo_secret_key',
			'test_public_key' => 'woocommerce_cynder_paymongo_test_public_key',
			'test_secret_key' => 'woocommerce_cynder_paymongo_test_secret_key',
			'testmode'        => 'woocommerce_cynder_paymongo_test_mode',
		);

		foreach ( $settingsToMigrate as $oldKey => $newKey ) {
			$newSetting = get_option( $newKey );

			if ( ! $newSetting && is_array( $mainPluginSettings ) && isset( $mainPluginSettings[ $oldKey ] ) ) {
				update_option( $newKey, $mainPluginSettings[ $oldKey ], true );
			}
		}
	}
}

add_action( 'woocommerce_paymongo_updated', 'update_cynder_paymongo_plugin' );

function cynder_paymongo_notices() {
	$version = get_option( 'cynder_paymongo_version' );

	if ( version_compare( $version, '1.5.0', '<' ) ) {
		echo '<div class="notice notice-warning">'
		. '<p><strong>You are using an outdated version of the PayMongo payment plugin</strong>. Please upgrade immediately using '
		. '<a target="_blank" href="https://cynder.atlassian.net/servicedesk/customer/portal/1/article/709656577">this guide</a>.</p>'
		. '</div>';
	}
}

add_action( 'admin_notices', 'cynder_paymongo_notices' );
