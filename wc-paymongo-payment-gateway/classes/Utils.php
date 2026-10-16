<?php

namespace Cynder\PayMongo;

use Paymongo\Phaymongo\Phaymongo;
use PostHog\PostHog;

class Utils {
	/**
	 * Builds a Phaymongo client bounded to PAYMONGO_ORDER_LOCKED_API_TIMEOUT,
	 * for the one call sites make while holding a per-order lock --
	 * without an explicit timeout, the underlying HTTP client's own
	 * default applies, which isn't itself bounded to something shorter
	 * than that lock's TTL, so a stalled request could outlast it. See
	 * PAYMONGO_ORDER_LOCKED_API_TIMEOUT's own comment.
	 *
	 * @param string $public_key
	 * @param string $secret_key
	 * @return Phaymongo
	 */
	public static function makeLockedClient( $public_key, $secret_key ) {
		return new Phaymongo(
			$public_key,
			$secret_key,
			array(),
			array(
				'timeout'         => PAYMONGO_ORDER_LOCKED_API_TIMEOUT,
				'connect_timeout' => 5,
			)
		);
	}

	/**
	 * Priorities a heartbeat is hooked onto an action at, spread across the
	 * range third-party listeners commonly register at (WordPress' own
	 * default is 10) -- see runWithActionHeartbeat().
	 */
	private const ACTION_HEARTBEAT_PRIORITIES = array( 1, 15, 30, 50, 75, 100, 150, 200, 999999 );

	/**
	 * Whether $order_id is, right now, in a status WooCommerce considers
	 * paid -- read directly from the orders table via $wpdb, bypassing
	 * every layer of application-level object cache that a normal
	 * `wc_get_order($id)` (or `$order->get_data_store()->read($order)`,
	 * which still goes through `get_post()` under legacy post-based
	 * storage, or HPOS's own `OrdersTableDataStore` cache) can still serve
	 * a value from.
	 *
	 * Exists specifically because those caches are keyed per-process at
	 * best: if this process already read this order once earlier in the
	 * same request (e.g. the sweep's own bulk wc_get_orders() query, or
	 * checkout loading $order before ever taking a lock), WordPress'
	 * object cache (even its non-persistent, request-scoped fallback, not
	 * just a persistent Redis/Memcached one) memoizes that first read and
	 * serves it again on a later `get_post()`/HPOS-cache call in the same
	 * request -- regardless of whether a *different* process (a webhook
	 * delivery, a concurrent checkout attempt) has since written a newer
	 * status directly to the database. A caller deciding whether to run
	 * payment_complete() a second time needs this read, not the cached
	 * object's own is_paid(), as the authoritative gate: a raw SQL read is
	 * the one thing every layer of in-process memoization can't intercept.
	 *
	 * Mirrors WC_Order::is_paid()'s own definition (status matches
	 * wc_get_is_paid_statuses()) as closely as a raw status column allows;
	 * the one thing it can't replicate is the `woocommerce_order_is_paid`
	 * filter WC_Order::is_paid() also applies, since that filter expects a
	 * hydrated $order object to pass to listeners, not just a status
	 * string. A store using that filter to add atypical paid statuses
	 * needs those also added to the `woocommerce_order_is_paid_statuses`
	 * filter (which wc_get_is_paid_statuses() already reads) for this
	 * check to recognize them too.
	 *
	 * @param int $order_id
	 * @return bool
	 */
	public function isOrderPaidInDatabase( $order_id ) {
		global $wpdb;

		if (
			class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			$table  = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
			$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$status = $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $order_id ) );
		}

		if ( null === $status ) {
			// No such row -- not this method's place to decide what that
			// means (a caller already requires a successful reload before
			// ever reaching here); conservatively not paid rather than
			// guessing.
			return false;
		}

		foreach ( wc_get_is_paid_statuses() as $paid_status ) {
			// Both the legacy posts table and the HPOS orders table store
			// the 'wc-' prefixed form; wc_get_is_paid_statuses() (like
			// WC_Order::get_status()) returns the bare slug.
			if ( 'wc-' . $paid_status === $status ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reloads $order_id uncached: wc_get_order() plus a forced, true
	 * reread of both its core props (get_data_store()->read(), which
	 * re-fetches status -- what is_paid() reads, which meta alone never
	 * touches) and its meta (read_meta_data(true), which -- unlike
	 * read()'s own default of force_read left false -- actually bypasses
	 * WC's per-object-id meta cache group rather than potentially serving
	 * a value this same process already cached earlier in the request).
	 *
	 * This exact sequence was previously duplicated at 7 call sites across
	 * this class, the webhook handler, the sweep, and checkout's intent
	 * rotation, each carrying its own copy of the above reasoning. See
	 * isOrderPaidAuthoritative() for the matching "is it already paid"
	 * check most of those callers run immediately afterward.
	 *
	 * @param int $order_id
	 * @return \WC_Order|null Null if the order could not be reloaded at all.
	 */
	public function reloadOrderUncached( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return null;
		}

		$order->get_data_store()->read( $order );
		$order->read_meta_data( true );

		return $order;
	}

	/**
	 * Whether $order is, right now, authoritatively paid: either its own
	 * (freshly-reloaded, via reloadOrderUncached()) in-memory status says
	 * so, or the database itself does -- see isOrderPaidInDatabase()'s own
	 * docblock for why both are checked.
	 *
	 * For a pre-completion "has this already been handled elsewhere"
	 * check only. A *post*-completion-throw recovery check must use
	 * isOrderPaidInDatabase() alone, not this method -- WC_Order::payment_complete()
	 * updates $order's in-memory status via set_status() before its own
	 * save() call, and can still fail after that, so $order->is_paid()
	 * can read "paid" here when nothing was actually persisted; see
	 * completeOrderLocked()'s own recovery catch for where that matters.
	 *
	 * @param \WC_Order $order
	 * @return bool
	 */
	public function isOrderPaidAuthoritative( \WC_Order $order ) {
		return $order->is_paid() || $this->isOrderPaidInDatabase( $order->get_id() );
	}

	/**
	 * Normalizes a `get_meta( $key, false )` value -- every caller passes
	 * `false` for a key tracking history (`paymongo_payment_intent_id_old`,
	 * `paymongo_client_key_old`) -- into a flat array of strings.
	 *
	 * `WC_Data::get_meta( $key, false )` doesn't return raw values at all:
	 * per-row, it returns the actual WC_Meta_Data wrapper objects, one per
	 * matching row, regardless of how many rows exist. Each one is
	 * unwrapped to its own ->value first; what's left can still be a mix of
	 * a single string, an array, or a nested array (legacy data where
	 * add_meta_data() stored a whole array as one row's value), which is
	 * what array_walk_recursive() below actually normalizes.
	 *
	 * @param mixed $meta_values Raw `get_meta( $key, false )` return value.
	 * @return string[] Flat, in the same order array_walk_recursive() visits
	 *                   them in (depth-first) -- a caller needing a
	 *                   particular order (e.g. most-recently-replaced
	 *                   first) applies that after calling this.
	 */
	public static function flattenMetaValues( $meta_values ) {
		$meta_values = ! empty( $meta_values ) ? (array) $meta_values : array();

		$meta_values = array_map(
			function ( $entry ) {
				return $entry instanceof \WC_Meta_Data ? $entry->value : $entry;
			},
			$meta_values
		);

		$flat = array();
		array_walk_recursive(
			$meta_values,
			function ( $vv ) use ( &$flat ) {
				if ( null !== $vv && '' !== $vv && ! is_object( $vv ) ) {
					$flat[] = strval( $vv );
				}
			}
		);

		return $flat;
	}

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

		if ( ! isset( $emails['WC_Email_Customer_Invoice'] ) ) {
			return;
		}

		// wp_mail() has no timeout of its own to configure -- phpmailer_init
		// is the supported hook for reaching into the PHPMailer instance it
		// builds. $phpmailer->Timeout bounds each individual socket
		// operation the SMTP transport makes (via stream_set_timeout()),
		// not the call to trigger() as a whole -- several operations each
		// individually inside this bound can still add up to longer than
		// it in total. It still meaningfully shortens how long a stalled
		// connection can block this call versus the site's own (often
		// much longer, e.g. 300s) default. See PAYMONGO_INVOICE_MAIL_TIMEOUT.
		//
		// $phpmailer is WordPress' own global, shared instance (see
		// wp_mail()), reused for every mail sent in this request, not a
		// fresh one per call -- restoring its previous value in finally
		// keeps this change scoped to this one trigger() call, so a later,
		// unrelated wp_mail() elsewhere in the same request (a WooCommerce
		// notification, a password reset, ...) doesn't inherit this short
		// timeout.
		//
		// Only $Timeout is set, not a $SMTPTimeout too -- neither PHPMailer
		// nor its SMTP class declares that second property, so setting it
		// would create an undeclared dynamic property: no functional
		// effect, and a deprecation notice on PHP 8.2+.
		// Captured by reference, not re-fetched from the global afterward --
		// phpmailer_init hands this closure the same single shared instance
		// wp_mail() keeps in $GLOBALS['phpmailer'], so holding onto it here
		// restores the right object either way, without this method needing
		// to know that global even exists.
		$mailer_to_restore = null;
		$previous_timeout  = null;
		$bound_mailer_timeout = function ( $phpmailer ) use ( &$mailer_to_restore, &$previous_timeout ) {
			// Only captured on the first call: if phpmailer_init somehow
			// fires more than once during this one trigger() (the same
			// shared $phpmailer, not a fresh one each time -- see above),
			// a second call would otherwise record our own
			// PAYMONGO_INVOICE_MAIL_TIMEOUT override as "previous", and
			// the finally block below would then restore that short
			// timeout instead of the site's real original one.
			if ( null === $mailer_to_restore ) {
				$mailer_to_restore = $phpmailer;
				$previous_timeout  = $phpmailer->Timeout;
			}
			$phpmailer->Timeout = PAYMONGO_INVOICE_MAIL_TIMEOUT;
		};

		add_action( 'phpmailer_init', $bound_mailer_timeout );

		try {
			$emails['WC_Email_Customer_Invoice']->trigger( $order_id );
		} finally {
			remove_action( 'phpmailer_init', $bound_mailer_timeout );

			if ( null !== $mailer_to_restore ) {
				$mailer_to_restore->Timeout = $previous_timeout;
			}
		}
	}

	public function emptyCart() {
		if ( function_exists( 'WC' ) && isset( WC()->cart ) ) {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * Runs $work() while $heartbeat is hooked onto $hook_name at several
	 * priorities (see ACTION_HEARTBEAT_PRIORITIES) -- $work() is expected
	 * to fire $hook_name itself (directly or several calls deep), with
	 * arbitrary third-party listeners on it that this class has no other
	 * checkpoint inside. A single early-priority hook only renews once,
	 * before anything else on the hook runs; if a *later* listener (not
	 * just the first) is itself slow, nothing would renew again until
	 * $work() returns. Spreading several checkpoints across the priority
	 * range means a slow listener anywhere in the middle of the list still
	 * has a renewal shortly before it fires, not just once at the very
	 * start.
	 *
	 * This reduces, but -- short of not using a TTL-based lock at all --
	 * can't eliminate, the risk of one single slow listener alone
	 * outlasting the caller's lock between two of these checkpoints; see
	 * the callers of this method for the residual risk each accepts.
	 * Hooks are removed again as soon as $work() returns (or throws) so
	 * they never fire for an unrelated order elsewhere in the same
	 * request.
	 *
	 * @param string        $hook_name
	 * @param callable|null $heartbeat
	 * @param callable      $work
	 * @return mixed $work()'s own return value.
	 */
	private function runWithActionHeartbeat( $hook_name, $heartbeat, callable $work ) {
		if ( null === $heartbeat ) {
			return $work();
		}

		$lock_lost_exception = null;

		// Use variadic arguments to prevent ArgumentCountError
		$mid_heartbeat = function (...$args) use ( $heartbeat, &$lock_lost_exception ) {
			if ( $lock_lost_exception ) {
				return;
			}
			try {
				$heartbeat();
			} catch ( LockLostException $e ) {
				// Defer exception propagation to protect WooCommerce action dispatch
				$lock_lost_exception = $e;
			}
		};

		foreach ( self::ACTION_HEARTBEAT_PRIORITIES as $priority ) {
			// High accepted_args (999) ensures WP passes all arguments to the variadic closure
			add_action( $hook_name, $mid_heartbeat, $priority, 999 );
		}

		try {
			$result = $work();

			if ( $lock_lost_exception ) {
				throw $lock_lost_exception;
			}

			return $result;
		} finally {
			foreach ( self::ACTION_HEARTBEAT_PRIORITIES as $priority ) {
				remove_action( $hook_name, $mid_heartbeat, $priority );
			}
		}
	}

	public function completeOrder( $order, $payment_id, $send_invoice, $heartbeat = null ) {
		$order_id = $order->get_id();

		// Covers a slow *third-party* listener on woocommerce_payment_complete
		// itself -- not WooCommerce's own status-transition work (which
		// includes its transactional emails), which already ran inside
		// payment_complete()'s own save() call, before this action ever
		// fires. That earlier phase has no heartbeat checkpoint of its
		// own and can't practically get one (WC's own email-sending hooks
		// fire from inside status_transition(), called synchronously by
		// save(), with no save()-internal extension point to hang a
		// renewal on) -- it's covered differently: save() persists the
		// order's new status before any of those hooks run, so if the
		// lock lapses during them, isOrderPaidInDatabase() already sees
		// the order as paid and refuses to let a second process complete
		// it again, lock or no lock. See runWithActionHeartbeat().
		$payment_completed = $this->runWithActionHeartbeat( 'woocommerce_payment_complete', $heartbeat, function () use ( $order, $payment_id ) {
			return $order->payment_complete( $payment_id );
		} );

		if ( ! $payment_completed ) {
			// WC_Order::payment_complete() catches its own Exception
			// internally (a failing status-transition hook, or save()
			// itself failing) and returns false rather than letting it
			// propagate -- silently ignoring that return value would mean
			// sending the invoice and reporting this payment as
			// successfully tracked for an order that may still be unpaid
			// in the database. Thrown, not just logged, so every caller's
			// existing recovery (checking isOrderPaidInDatabase() before
			// treating this as "already succeeded, don't retry") applies
			// here the same as any other completion failure.
			throw new \Exception( 'Order ' . $order_id . ': payment_complete() returned false -- the order was not actually marked paid.' );
		}

		// Also called before and after, same as always.
		if ( null !== $heartbeat ) {
			$heartbeat();
		}

		if ( $send_invoice ) {
			$this->sendInvoice( $order_id );
		}
	}

	/**
	 * completeOrder(), serialized under the same per-order lock
	 * ReconciliationSweep, the checkout pre-emptive check, and the webhook
	 * handler all use (see Lock::orderIntentKey()). Every entry point that
	 * can mark an order paid needs to hold this lock while doing so, or a
	 * customer's own checkout completion (PaymentIntent::processPayment(),
	 * the redirect handlers) can race a background sweep or webhook
	 * completing the exact same order, reintroducing the duplicate
	 * completion those other paths already guard against.
	 *
	 * @param \WC_Order $order
	 * @param array     $payment       A PayMongo payment record, with at
	 *                                 least an 'id' key -- passed to the
	 *                                 cynder_paymongo_successful_payment
	 *                                 action exactly as callers already
	 *                                 pass it to callAction() themselves.
	 * @param bool      $send_invoice
	 * @param float     $amount
	 * @param string    $payment_method
	 * @param bool      $test_mode
	 * @return true|null True once the order is confirmed paid -- whether this
	 *                   call completed it, or it was already paid by the time
	 *                   this ran (another process got there first; nothing
	 *                   further to do). Null if the lock could not be acquired
	 *                   at all, or if completion failed after PayMongo already
	 *                   reported the payment succeeded (the order couldn't be
	 *                   reloaded, or this lock was lost mid-completion) -- never
	 *                   throws for either, since by that point telling the
	 *                   customer to retry risks a duplicate payment; a webhook
	 *                   delivery or the reconciliation sweep remains the
	 *                   authoritative path to actually complete it.
	 */
	public function completeOrderLocked( $order, $payment, $send_invoice, $amount, $payment_method, $test_mode ) {
		$order_id          = $order->get_id();
		$order_lock_key    = Lock::orderIntentKey( $order_id );
		$order_lock_token  = Lock::acquireWithRetry( $order_lock_key, PAYMONGO_ORDER_LOCK_TTL, 5, 30000 );

		if ( false === $order_lock_token ) {
			return null;
		}

		$already_paid = false;

		try {
			try {
				// A fresh, independent, uncached read -- $order may have
				// been loaded well before this lock was ever a possibility
				// to acquire, so a concurrent completion could have
				// already landed in that gap. See reloadOrderUncached()'s
				// own docblock for why both the status and meta reloads it
				// does are each necessary.
				$fresh_order = $this->reloadOrderUncached( $order_id );

				if ( ! $fresh_order ) {
					throw new \Exception( 'Order ' . $order_id . ' could not be reloaded to complete it.' );
				}

				// See isOrderPaidAuthoritative()'s own docblock for why
				// both checks, and why this -- not isOrderPaidInDatabase()
				// alone -- is the right call here specifically (this runs
				// before completeOrder() is ever called, not in recovery
				// from one of its failures).
				$already_paid = $this->isOrderPaidAuthoritative( $fresh_order );

				if ( ! $already_paid ) {
					$heartbeat = Lock::heartbeat(
						$order_lock_key,
						$order_lock_token,
						PAYMONGO_ORDER_LOCK_TTL,
						'Order lock could not be renewed while completing order ' . $order_lock_key . '.'
					);

					$heartbeat();
					$this->completeOrder( $fresh_order, $payment['id'], $send_invoice, $heartbeat );
				}
			} catch ( \Throwable $e ) {
				// PayMongo has already reported this payment as succeeded by
				// the time this method is called -- reporting this as a
				// failure (the usual "please try again") risks a genuine
				// duplicate payment if the customer does. isOrderPaidInDatabase()
				// alone, not $fresh_order->is_paid() too: WC_Order::payment_complete()
				// calls set_status() -- which updates this same in-memory
				// object -- before save(), and catches its own Exception
				// internally, returning false rather than throwing. If
				// save() itself is what failed, $fresh_order->is_paid()
				// would report true from that in-memory set_status() call
				// even though nothing was actually persisted -- exactly the
				// false "recovered" positive this check exists to rule out.
				// A raw DB read is the only one of the two that can't be
				// fooled by this object's own dirtied, unsaved state.
				if ( isset( $fresh_order ) && $fresh_order && $this->isOrderPaidInDatabase( $order_id ) ) {
					wc_get_logger()->warning(
						'Order ' . $order_id . ' was marked paid but completion threw: ' . $e->getMessage(),
						array( 'source' => 'cynder-paymongo' )
					);
					// Do not return false here; allow post-completion tracking and actions to run.
				} else {
					// Treated the same as lock contention above: signal "not confirmed complete
					// by this call" instead, and let a webhook delivery or the
					// reconciliation sweep actually complete it.
					wc_get_logger()->error(
						'completeOrderLocked() failed for order ' . $order_id . ' after PayMongo reported the payment succeeded: ' . $e->getMessage(),
						array( 'source' => 'cynder-paymongo' )
					);
					return null;
				}
			}
		} finally {
			Lock::release( $order_lock_key, $order_lock_token );
		}

		if ( ! $already_paid ) {
			// Deferred until after release, same reasoning as
			// ReconcileResult::$after_completion: neither call needs this
			// lock, and this caller holds no other (e.g. sweep-level) lock
			// across it either, so there's nothing else to wait for. Not
			// run at all if the order was already paid -- whoever actually
			// completed it already fired both.
			try {
				$this->trackPaymentResolution( 'successful', $payment['id'], $amount, $payment_method, $test_mode );
				$this->callAction( 'cynder_paymongo_successful_payment', $payment );
			} catch ( \Throwable $e ) {
				$this->log( 'warning', 'Order ' . $order_id . ' was completed, but post-completion callbacks failed: ' . $e->getMessage() );
			}
		}

		return true;
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
	 * Authoritatively checks the PayMongo API for any succeeded payment intent
	 * associated with the order (current or historical) and completes the
	 * order if found. Returns a plain bool, matching this method's original,
	 * documented contract -- existing callers (checkout, webhook recovery)
	 * that only care whether the order is now paid can keep using it
	 * unchanged, including any `if ($utils->reconcileOrderAgainstPayMongo(...))`
	 * check outside this codebase that predates the reason-carrying result.
	 *
	 * A caller that needs to know *why* the order isn't paid -- to decide
	 * whether the attempt is worth spending retry budget on, e.g. the
	 * reconciliation sweep -- should call
	 * reconcileOrderAgainstPayMongoDetailed() instead.
	 *
	 * @param \WC_Order                     $order
	 * @param \Paymongo\Phaymongo\Phaymongo $client
	 * @return bool True if the order was reconciled as paid, false otherwise
	 *              (including if the per-order lock reconcileOrderAgainstPayMongoLocked()
	 *              acquires around this couldn't be won at all -- existing
	 *              callers already treat any false return as "could not
	 *              verify," which covers this the same way).
	 */
	public function reconcileOrderAgainstPayMongo( $order, $client ) {
		// Routed through the locked variant, not reconcileOrderAgainstPayMongoDetailed()
		// directly -- this method's own callers (checkout, webhook
		// recovery) hold no lock of their own, but completing the order is
		// still exactly what a background sweep or webhook delivery might
		// be doing for the same order at the same time. Without this same
		// per-order lock, this "backward-compatible" path could run
		// payment completion twice despite its own doc above promising
		// existing integrations remain supported -- being unlocked was
		// never part of that promise.
		return $this->reconcileOrderAgainstPayMongoLocked( $order, $client ) === true;
	}

	/**
	 * Identical check to reconcileOrderAgainstPayMongo(), serialized under
	 * the same per-order lock as completeOrderLocked() -- see that method's
	 * own docblock for why. Suited to callers that don't already know a
	 * specific succeeded payment to complete with (e.g. a "did this already
	 * go through out-of-band" recovery check after a PaymongoException),
	 * where completeOrderLocked() doesn't apply.
	 *
	 * @param \WC_Order                     $order
	 * @param \Paymongo\Phaymongo\Phaymongo $client
	 * @return bool|null True if the order is confirmed paid. False if it
	 *              genuinely isn't -- a definitive miss, or every intent
	 *              the scan found incomplete was specifically
	 *              awaiting_next_action with nothing else ambiguous (see
	 *              ReconcileResult::$awaiting_customer): the customer's own
	 *              attempt on this specific intent simply hasn't gone
	 *              through, which a caller recovering a customer-facing
	 *              redirect must tell apart from genuine uncertainty, or it
	 *              ends up telling a customer whose payment was declined or
	 *              abandoned that it's merely "processing." Null for every
	 *              other kind of uncertainty: the lock couldn't be acquired
	 *              at all, the scan itself failed (the order couldn't be
	 *              reloaded, or this lock was lost mid-scan), a transient
	 *              PayMongo-side failure occurred on some intent, a capped
	 *              scan didn't examine every intent, or a malformed
	 *              "succeeded" response came back -- never throws for any
	 *              of these, since a caller using this method to recover
	 *              from PayMongo already reporting a payment succeeded must
	 *              not treat a failure here as "confirmed unpaid, safe to
	 *              retry" (see completeOrderLocked(), which this mirrors).
	 */
	public function reconcileOrderAgainstPayMongoLocked( $order, $client ) {
		$order_id         = $order->get_id();
		$order_lock_key   = Lock::orderIntentKey( $order_id );
		$order_lock_token = Lock::acquireWithRetry( $order_lock_key, PAYMONGO_ORDER_LOCK_TTL, 5, 30000 );

		if ( false === $order_lock_token ) {
			return null;
		}

		try {
			try {
				// A fresh, independent, uncached read -- see
				// reloadOrderUncached()'s own docblock for why.
				$fresh_order = $this->reloadOrderUncached( $order_id );

				if ( ! $fresh_order ) {
					throw new \Exception( 'Order ' . $order_id . ' could not be reloaded to reconcile it.' );
				}

				// See isOrderPaidAuthoritative()'s own docblock for why
				// both checks, and why this is the right call here
				// specifically (pre-check, not post-failure recovery).
				if ( $this->isOrderPaidAuthoritative( $fresh_order ) ) {
					return true;
				}

				$heartbeat = Lock::heartbeat(
					$order_lock_key,
					$order_lock_token,
					PAYMONGO_ORDER_LOCK_TTL,
					'Order lock could not be renewed while reconciling order ' . $order_lock_key . '.'
				);

				$result = $this->reconcileOrderAgainstPayMongoDetailed( $fresh_order, $client, null, null, $heartbeat );
			} catch ( \Throwable $e ) {
				wc_get_logger()->error(
					'reconcileOrderAgainstPayMongoLocked() failed for order ' . $order_id . ': ' . $e->getMessage(),
					array( 'source' => 'cynder-paymongo' )
				);

				return null;
			}
		} finally {
			Lock::release( $order_lock_key, $order_lock_token );
		}

		if ( $result->paid && null !== $result->after_completion ) {
			// No outer heartbeat needed -- this caller holds no lock beyond
			// the one just released above.
			try {
				( $result->after_completion )();
			} catch ( \Throwable $e ) {
				$this->log( 'warning', 'Order ' . $order_id . ' was reconciled, but post-completion callbacks failed: ' . $e->getMessage() );
			}
		}

		if ( $result->paid ) {
			return true;
		}

		if ( ReconcileResult::REASON_MISS === $result->reason ) {
			return false;
		}

		// Customer-side "not finished" (abandoned/failed 3DS, specifically
		// awaiting_next_action -- see ReconcileResult::$awaiting_customer)
		// is a definitive "not paid" from a redirect's point of view, not
		// genuine uncertainty: falling through to null here (as every other
		// REASON_INCOMPLETE case still does, just below) previously routed
		// a customer whose payment is abandoned or declined to "payment is
		// processing" and the thank-you page instead of back to checkout,
		// with no email ever following since nothing had actually gone
		// through. $transient_error excluded deliberately: if the scan also
		// hit a genuine PayMongo-side failure on some other intent, this
		// order's true status is still uncertain, and uncertainty must
		// route to null the same as any other inconclusive scan.
		if ( ReconcileResult::REASON_INCOMPLETE === $result->reason && $result->awaiting_customer && ! $result->transient_error ) {
			return false;
		}

		return null;
	}

	/**
	 * Same check as reconcileOrderAgainstPayMongo(), but reports why a
	 * non-paid result wasn't a paid one -- a caller (the reconciliation
	 * sweep) needs to tell a definitive miss apart from a scan that
	 * couldn't reach a definitive answer, so it doesn't spend an order's
	 * limited retry attempts on an inconclusive result. See ReconcileResult.
	 *
	 * @param \WC_Order                     $order
	 * @param \Paymongo\Phaymongo\Phaymongo $client
	 * @param int|null                      $max_intents Ceiling on how many stored intents to check. Null (the
	 *                                                    default) checks every stored intent, matching this
	 *                                                    method's existing checkout/webhook recovery contract.
	 *                                                    A caller sizing a time/TTL budget around this call
	 *                                                    (e.g. the reconciliation cron) should pass its own
	 *                                                    explicit value. When the order has more historical
	 *                                                    intents than this allows, which ones get checked
	 *                                                    rotates across calls via a persisted offset (see
	 *                                                    PAYMONGO_RECONCILE_INTENT_OFFSET_META_KEY below), so an
	 *                                                    order stuck beyond the cap still has every intent
	 *                                                    checked eventually, across enough retries, rather than
	 *                                                    the same newest ones forever.
	 * @param float|null                    $deadline    microtime(true) value past which no further intent is
	 *                                                    checked, even if $max_intents allows more. A caller
	 *                                                    sizing a wall-clock budget (e.g. the reconciliation
	 *                                                    cron) should pass this so one order can't consume the
	 *                                                    entire remaining budget across several API calls.
	 * @param callable|null                 $heartbeat   Invoked before every intent retrieval in the loop below
	 *                                                    (a capped scan can make several, and their total isn't
	 *                                                    bounded by any single call's own timeout the way one
	 *                                                    retrieval is), again before completeOrder(), between
	 *                                                    the payment_complete() and invoice-email halves of it,
	 *                                                    and once more right before this method returns a
	 *                                                    non-paid result -- rather than once upfront, since
	 *                                                    every one of those steps can itself run long and none
	 *                                                    of it is bounded by $deadline the way a single PayMongo
	 *                                                    API call is. A caller holding a time-limited lock
	 *                                                    across this call (the reconciliation sweep, for both
	 *                                                    the scan itself and the cooldown/attempt write it makes
	 *                                                    right after this returns) should pass a callback that
	 *                                                    renews it, so no single step -- or the scan as a whole
	 *                                                    -- leaves the rest of this method's work, or the
	 *                                                    caller's own next write, unguarded for its own
	 *                                                    duration. Notably not invoked around
	 *                                                    trackPaymentResolution()/the third-party action behind
	 *                                                    a paid result -- see ReconcileResult::$after_completion,
	 *                                                    which defers both until after the caller's own lock is
	 *                                                    released instead.
	 * @param callable|null                 $clock       Returns the current microtime(true)-style timestamp,
	 *                                                    checked against $deadline. Defaults to the real wall
	 *                                                    clock; a test asserting on deadline-cutoff behavior
	 *                                                    passes a fake one instead of actually sleeping past a
	 *                                                    real $deadline -- see ReconciliationSweep::run()'s
	 *                                                    identical parameter.
	 * @return ReconcileResult
	 */
	public function reconcileOrderAgainstPayMongoDetailed( $order, $client, $max_intents = null, $deadline = null, $heartbeat = null, $clock = null ) {
		$clock = $clock ?? function () {
			return microtime( true );
		};

		if ( $order->is_paid() ) {
			return ReconcileResult::paid();
		}

		if ( null !== $max_intents ) {
			// A budget of 1 alongside a current intent leaves zero room for
			// any historical one ($historical_budget below floors at 0) --
			// a capped scan would never advance its persisted rotation
			// offset, trapping the order in "incomplete" forever with no
			// historical intent ever actually examined, across any number
			// of retries. Not reachable with today's fixed
			// PAYMONGO_RECONCILE_MAX_INTENTS (20), but this method's
			// contract shouldn't depend on that.
			$max_intents = max( 2, (int) $max_intents );
		}

		$current_intent = $order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY );
		$old_intents    = $order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY . '_old', false );

		// Historical intents are stored oldest-to-newest -- rotation appends
		// the just-replaced intent to the end, see cynder_paymongo_create_intent()
		// in paymongo-top-level-hooks.php (the $flat_old_intents[] = strval(
		// $existingIntentId ) line). Flatten and reverse to check the most
		// recently replaced ones first -- a just-rotated intent is more
		// likely to still resolve than one from further back.
		$flat_old_intents = array_values( array_unique( array_reverse( self::flattenMetaValues( $old_intents ) ) ) );

		$has_current = ! empty( $current_intent );
		if ( $has_current ) {
			// Keep counts consistent if legacy data duplicated the current
			// intent into the historical list.
			$flat_old_intents = array_values( array_diff( $flat_old_intents, array( $current_intent ) ) );
		}

		// A cap that drops intents this order actually has means a scan that
		// checks every selected one clean can still miss a paid intent
		// sitting beyond the cap -- that's not the same guarantee as
		// checking everything, so it must not be reported as a definitive
		// miss below. Capped scans rotate which historical intents they
		// select via a persisted offset, advanced by however many were
		// actually checked this call (see $historicals_checked below), so
		// an order stuck beyond the cap eventually has every intent checked
		// across enough retries instead of the same newest ones forever.
		$capped             = false;
		$offset             = 0;
		$total_historicals  = count( $flat_old_intents );

		if ( null === $max_intents ) {
			$historicals_window = $flat_old_intents;
		} else {
			$historical_budget = $has_current ? max( 0, $max_intents - 1 ) : $max_intents;

			if ( $total_historicals <= $historical_budget ) {
				$historicals_window = $flat_old_intents;
			} else {
				$capped = true;
				$offset = (int) $order->get_meta( PAYMONGO_RECONCILE_INTENT_OFFSET_META_KEY ) % $total_historicals;

				$rotated             = array_merge(
					array_slice( $flat_old_intents, $offset ),
					array_slice( $flat_old_intents, 0, $offset )
				);
				$historicals_window = array_slice( $rotated, 0, $historical_budget );
			}
		}

		$all_intents = array();
		if ( $has_current ) {
			$all_intents[] = $current_intent;
		}
		$all_intents = array_merge( $all_intents, $historicals_window );

		// Everything from this index onward in $all_intents is a historical
		// intent, used below to count how many of $historicals_window were
		// actually reached before the deadline could truncate the loop.
		$historicals_start_index = $has_current ? 1 : 0;

		$send_invoice = get_option( 'woocommerce_cynder_paymongo_send_invoice_after_payment' ) === 'yes';
		$test_mode    = get_option( 'woocommerce_cynder_paymongo_test_mode' ) === 'yes';

		// Set when a call fails, or succeeds with a response this method
		// can't act on, in a way that leaves this order's status genuinely
		// unknown -- a network fault, 5xx, rate limit (or anything this
		// method can't confidently tell apart from those), or a "succeeded"
		// intent with no usable payment record. As opposed to a permanent
		// per-intent failure (e.g. a 404 on an intent PayMongo no longer
		// has), which is treated like "checked, not paid" for that one
		// intent and doesn't taint the overall scan.
		$incomplete = $capped;
		// Set true only when a PayMongo call itself failed non-permanently --
		// see ReconcileResult::$transient_error's own doc comment for why the
		// other causes of $incomplete don't set this.
		$transient_error = false;
		// Set true only when an intent is found in awaiting_next_action --
		// see ReconcileResult::$awaiting_customer's own doc comment. A
		// capped scan starts $incomplete already true above without this
		// method ever concluding anything about any intent, so it must
		// never set this regardless of what the loop below finds -- that's
		// genuine uncertainty (an untested portion of this order's
		// intents), not "the customer's own attempt simply hasn't gone
		// through yet."
		$awaiting_customer = false;

		$checked_count      = 0;
		$historicals_checked = 0;

		foreach ( $all_intents as $index => $intent_id ) {
			if ( null !== $deadline && $clock() >= $deadline ) {
				// Reserve "deadline" for a scan that never got anywhere: if
				// at least one intent was actually checked, that progress
				// (and, for a capped scan, the rotation offset it earned)
				// must be preserved rather than discarded on the next call --
				// falling through to "incomplete" is what lets the caller
				// persist it below instead of silently repeating this same
				// window forever under sustained slow requests.
				if ( 0 === $checked_count ) {
					return ReconcileResult::deadline();
				}

				$incomplete = true;
				break;
			}

			// Renews the caller's lock(s) before every retrieval, not just
			// before the paid-completion work below -- a capped scan can
			// make several of these calls, each bounded only by its own
			// API timeout, and their total can outlast a lock sized for
			// one in-flight call. Deliberately outside the try below, for
			// the same reason the paid path's heartbeat calls are: a
			// lock-loss signal must propagate, not be caught and treated
			// as "this intent's retrieval failed."
			if ( null !== $heartbeat ) {
				$heartbeat();
			}

			// Deliberately narrow: only the retrieval call and classifying
			// its response belong in this try. completeOrder() and
			// everything that follows a genuinely succeeded intent must
			// not be caught here -- an exception from any of that
			// (including the heartbeat's own LockLostException)
			// must reach this method's caller as-is, not be logged and
			// swallowed as "this intent's retrieval failed," which would
			// incorrectly move on to an older intent and, worse, could mask
			// a second completion attempt if payment_complete() had already
			// run.
			$succeeded_payment = null;

			try {
				$intent = $client->paymentIntent()->retrieveById( $intent_id );
				$status = $intent['attributes']['status'] ?? '';

				if ( 'succeeded' === $status ) {
					$payments = $intent['attributes']['payments'] ?? array();

					if ( ! empty( $payments ) && isset( $payments[0]['id'] ) ) {
						$succeeded_payment = $payments[0];
						$succeeded_amount   = isset( $intent['attributes']['amount'] ) ? floatval( $intent['attributes']['amount'] ) / 100 : floatval( $order->get_total() );
					} else {
						// PayMongo says this intent succeeded but didn't return a
						// usable payment record to complete the order with -- a
						// malformed or unexpected response, not a "not paid"
						// answer. Falling through to a definitive miss here would
						// let the sweep spend abandonment budget on, and
						// eventually give up on, an order PayMongo's own records
						// say was paid.
						$this->log(
							'error',
							'[Reconciliation] Intent ' . $intent_id . ' for Order ID ' . $order->get_id() . ' is succeeded but returned no usable payment record.'
						);
						$incomplete = true;
					}
				} elseif ( 'processing' === $status || 'awaiting_next_action' === $status ) {
					// The rest of this plugin (PaymentIntent::processPayment()'s
					// own redirect for this exact status, cynder_paymongo_catch_redirect())
					// treats both "processing" and "awaiting_next_action" as a
					// payment actively in flight -- the latter is a customer
					// mid-redirect for 3DS/bank authorization, not a settled
					// answer. Falling through to a miss here would spend
					// abandonment budget on an order the customer may still
					// complete moments later.
					$incomplete = true;

					if ( 'awaiting_next_action' === $status ) {
						// Narrower than "processing": the customer is
						// specifically mid-3DS/bank-authorization and hasn't
						// returned (or abandoned that redirect) -- see
						// ReconcileResult::$awaiting_customer's own doc
						// comment for why a caller recovering a customer
						// redirect needs this distinct from "processing"
						// (an async payment method still settling on
						// PayMongo's own side, not something the customer's
						// own action could resolve).
						$awaiting_customer = true;
					}
				}
			} catch ( \Throwable $e ) {
				$this->log( 'error', '[Reconciliation] Failed to retrieve intent ' . $intent_id . ' for Order ID ' . $order->get_id() . ': ' . $e->getMessage() );

				if ( ! $this->isPermanentFailure( $e ) ) {
					$incomplete = true;

					if ( $this->isTransientFailure( $e ) ) {
						$transient_error = true;
					}
				}
			}

			if ( null !== $succeeded_payment ) {
				// Outside the try above on purpose -- see the comment where
				// $succeeded_payment is declared. A heartbeat lock-loss
				// signal, or any failure from completeOrder()/tracking/the
				// third-party action below, propagates straight out of this
				// method instead of being treated as another intent miss.
				if ( null !== $heartbeat ) {
					$heartbeat();
				}

				try {
					$this->completeOrder( $order, $succeeded_payment['id'], $send_invoice, $heartbeat );
				} catch ( \Throwable $e ) {
					// isOrderPaidInDatabase(), not $order->is_paid() --
					// see completeOrderLocked()'s identical recovery for
					// why this object's own in-memory status can't be
					// trusted here: WC_Order::payment_complete() updates it
					// via set_status() before save(), and can return false
					// (triggering completeOrder()'s own exception) without
					// that save ever landing.
					if ( ! $this->isOrderPaidInDatabase( $order->get_id() ) ) {
						throw $e;
					}
					$this->log( 'warning', '[Reconciliation] Order ' . $order->get_id() . ' was marked paid but completion threw: ' . $e->getMessage() );
				}

				if ( function_exists( 'WC' ) && WC()->cart ) {
					WC()->cart->empty_cart();
				}

				// Deferred, not run here -- see ReconcileResult::$after_completion.
				// Neither of these two calls reads or writes anything about
				// $order, so neither needs whatever lock the caller holds
				// across this method; PostHog's own request in particular
				// can itself run long enough to have outlived one sized for
				// the rest of this method's work (see
				// trackPaymentResolution()). Captured by value/reference as
				// needed since this closure runs after this method (and the
				// loop's own local variables) have already gone out of scope.
				$payment_method = $order->get_payment_method();

				return ReconcileResult::paid(
					// Accepts its own, separate heartbeat -- the one this
					// method was called with renews a per-order lock that,
					// by the time a caller invokes this closure, has
					// typically already been released (see
					// ReconcileResult::$after_completion). A caller still
					// holding some other lock across this whole call (the
					// reconciliation sweep's own sweep-level lock, held
					// across every order it processes, not just this one)
					// should pass a callback that renews it instead.
					function ( $heartbeat = null ) use ( $succeeded_payment, $succeeded_amount, $payment_method, $test_mode ) {
						if ( null !== $heartbeat ) {
							$heartbeat();
						}

						$this->trackPaymentResolution( 'successful', $succeeded_payment['id'], $succeeded_amount, $payment_method, $test_mode );

						if ( null !== $heartbeat ) {
							$heartbeat();
						}

						// callAction() fires arbitrary third-party listeners,
						// unbounded and with no other checkpoint this class
						// can reach into -- see runWithActionHeartbeat().
						// Matters here specifically: unlike completeOrder()'s
						// own use of it, $heartbeat can still be non-null at
						// this point (the reconciliation sweep's sweep-level
						// one), since the per-order lock it renews is
						// already gone by the time this closure runs at all.
						$this->runWithActionHeartbeat( 'cynder_paymongo_successful_payment', $heartbeat, function () use ( $succeeded_payment ) {
							$this->callAction( 'cynder_paymongo_successful_payment', $succeeded_payment );
						} );
					}
				);
			}

			++$checked_count;
			if ( $index >= $historicals_start_index ) {
				++$historicals_checked;
			}
		}

		if ( $capped ) {
			// Advance only by what was actually checked this call, not the
			// full window -- if the deadline cut the loop short partway
			// through, the untouched remainder of the window must still be
			// checked next time, not skipped over.
			$next_offset = ( $offset + $historicals_checked ) % $total_historicals;

			// Queued, not saved -- the caller (ReconciliationSweep) already
			// calls save_meta_data() once for the incomplete result this
			// always produces, so this rides along with that write instead
			// of persisting independently.
			$order->update_meta_data( PAYMONGO_RECONCILE_INTENT_OFFSET_META_KEY, $next_offset );
		}

		// One more renewal right before returning a non-paid result -- the
		// caller (the reconciliation sweep) writes its own cooldown/attempt
		// meta immediately after this returns, under the same per-order
		// lock this heartbeat renews. Without this, a scan whose last
		// renewal was several retrievals ago (the loop above renews before
		// each one, not after the last one) could still let that lock lapse
		// in the gap between here and that write.
		if ( null !== $heartbeat ) {
			$heartbeat();
		}

		return $incomplete ? ReconcileResult::incomplete( $transient_error, $awaiting_customer && ! $capped ) : ReconcileResult::miss();
	}

	/**
	 * Whether a PayMongo call failure means this specific intent will never
	 * resolve, as opposed to a transient or unrelated condition worth
	 * retrying.
	 *
	 * Deliberately narrow: only 404 (this intent no longer exists) is
	 * treated as permanent. HTTP status alone doesn't make every other 4xx
	 * a per-intent failure -- 408 is a timeout (transient), 400/422 can be
	 * malformed-request noise, and 401/403 usually mean a site-wide
	 * credential or permission problem, not something specific to this
	 * intent. Classifying those as permanent would spend every order's
	 * abandonment budget on a failure that has nothing to do with whether
	 * it's actually paid, and could do so for every order at once during a
	 * single misconfiguration or outage. Only a code PayMongo can be relied
	 * on to mean "this exact resource is gone" belongs here.
	 *
	 * Only classifiable via the underlying Guzzle exception carrying a
	 * response. micahbule/phaymongo rewraps most 4xx bodies with an
	 * `errors` key into \Paymongo\Phaymongo\PaymongoException, which does
	 * not carry the response or status code -- so a 404 on a stale intent
	 * typically lands here, not in the branch above, and is conservatively
	 * treated as incomplete rather than guessed at (though not, on its own,
	 * as $transient_error -- see isTransientFailure(), which this call's
	 * caller also consults). That trades a permanently-dead intent being
	 * retried forever for never wrongly abandoning an order that might
	 * still be paid; see Utils::reconcileOrderAgainstPayMongoDetailed().
	 *
	 * This reaches into Guzzle, a transitive dependency arriving via
	 * micahbule/phaymongo rather than one this plugin requires directly.
	 * `instanceof` against a class that isn't loaded returns false rather
	 * than fatally erroring, so if Phaymongo ever swaps HTTP clients this
	 * silently stops classifying anything -- everything becomes incomplete,
	 * which is the same conservative default documented above, so it fails
	 * safe rather than fails loud.
	 *
	 * @param \Throwable $e
	 * @return bool
	 */
	private function isPermanentFailure( \Throwable $e ) {
		if ( ! ( $e instanceof \GuzzleHttp\Exception\RequestException ) || ! $e->hasResponse() ) {
			return false;
		}

		return 404 === $e->getResponse()->getStatusCode();
	}

	/**
	 * Whether a non-permanent failure actually looks like a PayMongo-side
	 * problem, as opposed to PayMongo responding normally with a structured
	 * application error for this one intent (a bad request, an auth issue,
	 * an unprocessable state).
	 *
	 * Deliberately narrower than "not a permanent failure": every non-404
	 * failure sets $incomplete (see reconcileOrderAgainstPayMongoDetailed()),
	 * but not every one of those is evidence PayMongo itself is unhealthy --
	 * micahbule/phaymongo rewraps most 4xx bodies with an `errors` key into
	 * \Paymongo\Phaymongo\PaymongoException, which means PayMongo *did*
	 * respond, just with an error this method can't act on for this intent.
	 * A site with one order stuck on such an intent would otherwise log a
	 * false outage warning every single run (see
	 * payments-paymongo-woocommerce.php's own use of this count). A
	 * connection fault (no response at all) or a 5xx/429/408 response are
	 * the cases that actually indicate PayMongo, or the path to it, isn't
	 * working right now.
	 *
	 * @param \Throwable $e
	 * @return bool
	 */
	private function isTransientFailure( \Throwable $e ) {
		if ( $e instanceof \GuzzleHttp\Exception\ConnectException ) {
			return true;
		}

		if ( $e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse() ) {
			$code = $e->getResponse()->getStatusCode();

			return $code >= 500 || 429 === $code || 408 === $code;
		}

		return false;
	}
}
