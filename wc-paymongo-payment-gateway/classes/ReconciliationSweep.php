<?php

namespace Cynder\PayMongo;

/**
 * Runs one pass of the reconciliation cron over a batch of stranded orders.
 *
 * Split out of the cron closure so the skip/attempt bookkeeping -- the part
 * most likely to hide a regression -- can be unit tested without firing
 * WP-Cron.
 *
 * This is also the reason this plugin can't rely on webhook delivery
 * alone: PayMongo does not re-send a missed event (one that arrived while
 * the webhook was disabled, misconfigured, or simply never fired for some
 * other reason), and its own documented fallback for exactly that case is
 * retrieving the resource by ID to check its status directly -- see
 * https://docs.paymongo.com/reference/webhook-resource. That's this
 * class's whole job.
 */
class ReconciliationSweep {
	/**
	 * @var Utils
	 */
	private $utils;

	public function __construct( Utils $utils ) {
		$this->utils = $utils;
	}

	/**
	 * The sweep lock's TTL, clamped to its own computed minimum -- pulled
	 * out of the cron callback so this one piece of arithmetic (previously
	 * inline and untested) can be asserted directly, rather than only
	 * indirectly through whatever Lock::acquire() happens to do with it.
	 *
	 * @param int $filtered Result of applying the cynder_paymongo_reconcile_lock_ttl
	 *                       filter -- untrusted: may be zero, negative, or
	 *                       not actually numeric.
	 * @param int $min      The minimum this lock must last to cover one
	 *                       bounded API call plus margin (see the cron
	 *                       callback's own $min_lock_ttl).
	 * @return int
	 */
	public static function resolveLockTtl( $filtered, $min ) {
		// absint() first so a non-numeric or negative filter result can't
		// bypass max() by failing the comparison silently.
		return max( $min, absint( $filtered ) );
	}

	/**
	 * Whether the cron callback's own run summary should be logged at
	 * warning (surfaced to anyone watching logs) rather than debug (routine
	 * noise). Pulled out of the cron callback into its own tested static
	 * method -- the condition is easy to get subtly wrong (see the
	 * REASON_INCOMPLETE carve-out below), and a dedicated test on it catches
	 * that directly instead of only through the callback as a whole.
	 *
	 * Deliberately narrower than "$result['inconclusive'] > 0": that count
	 * also includes a capped intent list, an intent still processing or
	 * awaiting the customer's next action, and a malformed succeeded
	 * response -- all normal, expected states that say nothing about
	 * PayMongo's own health. A site with a single order mid-3DS-redirect
	 * and nothing else to check would otherwise log a false outage warning
	 * every run. $result['transient_errors'] counts only actual failed API
	 * calls (see ReconcileResult::$transient_error); "nothing considered"
	 * alongside at least one of those is what actually looks like an
	 * outage.
	 *
	 * Also warns whenever $result['skipped'] is non-zero -- see run()'s own
	 * comment on that count: every order it sees already matched
	 * buildOrderQueryArgs()'s own query, so finding one to skip in-loop
	 * means the storage backend didn't apply that filter the way the query
	 * args assume. Unlike the transient-error condition above, this is
	 * never expected to be non-zero; any value above 0 is worth surfacing.
	 *
	 * Also warns whenever $result['errored'] is non-zero -- an order whose
	 * own processing threw something run()'s per-order try/catch isn't a
	 * routine outcome either, and is worth a human noticing regardless of
	 * how many other orders in the same run were considered cleanly.
	 *
	 * @param array{considered: int, transient_errors: int, skipped: int, errored: int} $result
	 * @return string 'warning' or 'debug'.
	 */
	public static function summaryLogLevel( array $result ) {
		if (
			( $result['transient_errors'] > 0 && 0 === $result['considered'] )
			|| $result['skipped'] > 0
			|| $result['errored'] > 0
		) {
			return 'warning';
		}

		return 'debug';
	}

	/**
	 * Args for the wc_get_orders() query the cron callback runs to find
	 * stranded orders. A fixed-size, date-ordered window on its own isn't
	 * enough to guarantee every eligible order eventually gets checked:
	 * unlike run()'s own in-loop skip check (which only filters an already-fetched batch),
	 * an order that's merely in cooldown -- not yet at its attempt limit,
	 * just checked too recently -- still matches the status/payment_method
	 * filters below and would otherwise keep occupying the same slot in
	 * the window run after run, starving anything behind it. The
	 * meta_query excludes both max-attempts-exhausted orders (they'll
	 * never resolve via the sweep) and orders still in cooldown (they'll
	 * be eligible again once PAYMONGO_RECONCILE_RETRY_INTERVAL elapses) --
	 * only orders actually worth an API call this run are ever fetched, so
	 * the window's composition rotates on its own as each run's checked
	 * orders start their own cooldown and free their slots for the next.
	 *
	 * DESC, not ASC: with the window now eligible-only, either direction
	 * rotates without starving anything, so this is free to favor the
	 * newest stranded orders, which are statistically the most likely to
	 * still be paid and worth surfacing first.
	 *
	 * @param int|null $now Unix timestamp to measure the date-created cutoff
	 *                      and cooldown window against. Null (the default)
	 *                      uses the real current time; a caller asserting
	 *                      on the exact query built (a test) should pass an
	 *                      explicit value instead of depending on wall-clock
	 *                      time at the moment the test happens to run.
	 * @return array
	 */
	public static function buildOrderQueryArgs( $now = null ) {
		$now = $now ?? time();

		$limit = apply_filters('cynder_paymongo_reconcile_order_limit', PAYMONGO_RECONCILE_ORDER_LIMIT);
		$limit = max(1, min((int) $limit, PAYMONGO_RECONCILE_ORDER_LIMIT));

		return array(
			'status'         => array( 'pending', 'failed', 'cancelled' ),
			'payment_method' => array(
				'paymongo',
				'paymongo_card_installment',
				'paymongo_gcash',
				'paymongo_grab_pay',
				'paymongo_paymaya',
				'paymongo_atome',
				'paymongo_bpi',
				'paymongo_unionbank',
				'paymongo_billease',
			),
			'limit'          => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_created'   => '>=' . ( $now - 7 * DAY_IN_SECONDS ),
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'relation' => 'OR',
					array(
						'key'     => PAYMONGO_RECONCILE_ATTEMPTS_META_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => PAYMONGO_RECONCILE_ATTEMPTS_META_KEY,
						'value'   => PAYMONGO_RECONCILE_MAX_ATTEMPTS,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => PAYMONGO_RECONCILE_LAST_ATTEMPT_META_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => PAYMONGO_RECONCILE_LAST_ATTEMPT_META_KEY,
						'value'   => $now - PAYMONGO_RECONCILE_RETRY_INTERVAL,
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			),
		);
	}

	/**
	 * @param int         $attempts
	 * @param int         $last_attempt
	 * @param string|null $attempts_intent Intent $attempts/$last_attempt were recorded against.
	 * @param string|null $current_intent  The order's current intent.
	 * @return bool
	 */
	private function isSkippable( $attempts, $last_attempt, $attempts_intent, $current_intent ) {
		if ( $attempts_intent !== $current_intent ) {
			// $attempts and $last_attempt describe a check against a
			// different intent -- most likely one the order has since
			// rotated away from. cynder_paymongo_create_intent()'s reset is
			// atomic with the rotation commit today (see
			// PAYMONGO_RECONCILE_ATTEMPTS_INTENT_META_KEY's own docblock),
			// so in practice the reset has already run by the time this
			// runs too -- this check is defense-in-depth against that
			// invariant ever breaking, not something routinely reachable.
			// Either way, a count that doesn't belong to the current intent
			// must not gate it: that's what would otherwise let a maxed-out
			// count on a dead intent permanently strand every intent
			// created after it, with no cooldown ever bringing it back.
			return false;
		}

		if ( $attempts >= PAYMONGO_RECONCILE_MAX_ATTEMPTS ) {
			return true;
		}

		return ( time() - $last_attempt ) < PAYMONGO_RECONCILE_RETRY_INTERVAL;
	}

	/**
	 * @param \WC_Order[]                   $orders
	 * @param \Paymongo\Phaymongo\Phaymongo $client
	 * @param float                         $deadline              microtime(true) value past which the run stops
	 *                                                              starting new orders.
	 * @param int                           $max_intents_per_order Passed through to Utils::reconcileOrderAgainstPayMongoDetailed().
	 * @param string                        $lock_key              The sweep-level lock's key (see Lock class), so it
	 *                                                              can be renewed mid-run.
	 * @param string                        $lock_token            Token currently held for $lock_key. Passed by
	 *                                                              reference and updated in place on every successful
	 *                                                              renewal, not just once at return time -- if this
	 *                                                              method throws partway through a renewed run, the
	 *                                                              caller's variable must already reflect whatever
	 *                                                              token is actually current in storage, or its own
	 *                                                              cleanup (releasing the lock) would compare-and-delete
	 *                                                              against a token the database no longer has, leaving
	 *                                                              the lock orphaned until it expires on its own.
	 * @param int                           $lock_ttl              Seconds to extend the sweep-level lock by on each renewal.
	 * @param callable|null                 $clock                 Returns the current microtime(true)-style timestamp,
	 *                                                              checked against $deadline. Defaults to the real
	 *                                                              wall clock; a test asserting on deadline-cutoff
	 *                                                              behavior passes a fake one instead of actually
	 *                                                              sleeping past a real $deadline.
	 *
	 * @return array{considered: int, reconciled: int, abandoned: int, inconclusive: int, transient_errors: int, skipped: int, errored: int, deadline_hit: bool}
	 */
	public function run( array $orders, $client, $deadline, $max_intents_per_order, $lock_key, &$lock_token, $lock_ttl, $clock = null ) {
		$clock = $clock ?? function () {
			return microtime( true );
		};

		$considered       = 0;
		$reconciled       = 0;
		$abandoned        = 0;
		$inconclusive     = 0;
		$transient_errors = 0;
		// Distinct from $transient_errors (a classified PayMongo-side
		// failure): this counts an order whose own processing threw
		// something this loop didn't otherwise plan for -- a malformed
		// meta value, completeOrder() throwing when the database still
		// says unpaid, or any other bug. Isolated per-order (see the
		// try/catch around each order's processing below) specifically so
		// one such order can't take down the rest of the run.
		$errored          = 0;
		// A production canary for buildOrderQueryArgs()'s own meta_query:
		// every order this run actually sees already matched that query, so
		// isSkippable() below should never find one to skip. If it does,
		// the storage backend (HPOS vs. legacy post-based, or a WooCommerce
		// version that handles this meta_query differently) didn't apply
		// the filter the way the query args assume -- a signal this count
		// is specifically watching for, not a normal/expected outcome the
		// way $inconclusive is.
		$skipped          = 0;
		$deadline_hit     = false;

		foreach ( $orders as $order ) {
			if ( $clock() >= $deadline ) {
				$deadline_hit = true;
				break;
			}

			if ( ! ( $order instanceof \WC_Order ) ) {
				continue;
			}

			// Read once and reuse below, instead of this and the attempt
			// increment each re-reading the same order meta.
			$attempts        = (int) $order->get_meta( PAYMONGO_RECONCILE_ATTEMPTS_META_KEY );
			$last_attempt    = (int) $order->get_meta( PAYMONGO_RECONCILE_LAST_ATTEMPT_META_KEY );
			$attempts_intent = $order->get_meta( PAYMONGO_RECONCILE_ATTEMPTS_INTENT_META_KEY );

			// Captured here (before the skip check) rather than only later,
			// so isSkippable() can tell whether $attempts even applies to
			// the order's current intent.
			$intent_before_check = $order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY );

			if ( $attempts_intent !== $intent_before_check ) {
				// $attempts describes a check against a different intent --
				// isSkippable() already treats this order as unskippable
				// below regardless of the stale count, but the count itself
				// must also be normalized here, or the increment further
				// down would continue counting from a number that belongs
				// to an intent this order has since moved on from (e.g.
				// starting a fresh intent already at the abandonment
				// threshold instead of at zero).
				$attempts     = 0;
				$last_attempt = 0;
			}

			if ( $this->isSkippable( $attempts, $last_attempt, $attempts_intent, $intent_before_check ) ) {
				++$skipped;
				continue;
			}

			// Renewed before the (potentially unbounded) work below -- a
			// paid result runs payment_complete(), an optional invoice
			// email, PostHog tracking, and any third-party
			// cynder_paymongo_successful_payment callbacks, none of which
			// is bounded by $api_timeout the way a PayMongo API call is.
			// The sweep-level lock's TTL was sized assuming the only
			// overrun is one bounded API call; without renewing here, a
			// single slow order could let that TTL lapse mid-run and a
			// concurrent trigger reclaim the lock while this run is still
			// going. Renewing per-order means only the gap *between*
			// orders needs to fit the TTL, not the whole run.
			$renewed_lock_token = Lock::renew( $lock_key, $lock_token, $lock_ttl );

			if ( false === $renewed_lock_token ) {
				// The lock is already gone -- reclaimed by a concurrent
				// trigger, which may now be running against the same
				// orders. Stop immediately rather than race its writes.
				wc_get_logger()->warning(
					'Reconciliation sweep lost its lock mid-run; stopping.',
					array( 'source' => 'cynder-paymongo' )
				);
				break;
			}

			$lock_token = $renewed_lock_token;

			// Isolates this order's own processing from every other order
			// in the batch: an exception here previously propagated
			// straight out of run() uncaught, leaving nothing written for
			// this order -- which stays eligible and, sorted DESC, blocks
			// every older eligible order behind it from being reached on
			// this run or any later one, until it ages out of the 7-day
			// window on its own. LockLostException is the one exception
			// that must still abort the whole run (see its own throw
			// site below); anything else is isolated to this order alone.
			try {
				// Acquired before any PayMongo API call or completion work for
				// this order, and held for as long as we spend on it -- a paid
				// result runs completeOrder() (payment_complete(), which fires
				// arbitrary WooCommerce order-status hooks), an optional
				// invoice email, PostHog tracking, and third-party
				// cynder_paymongo_successful_payment callbacks. $order itself
				// (hydrated once at the start of this sweep) is only used below
				// to decide whether to skip and to derive this lock's key --
				// everything that actually reads or writes the order's state
				// past this point uses the fresh, uncached $current_order
				// obtained after this lock is won, not this stale copy.
				// cynder_paymongo_create_intent() takes this exact lock before
				// publishing a new intent; without holding it here too, a
				// rotation could land mid-completion and have its new
				// intent/reconciliation meta silently overwritten by a stale
				// save. The non-paid write below needs the same protection
				// against the same race, so one acquisition here covers both
				// paths instead of only the non-paid one.
				$order_lock_key   = Lock::orderIntentKey( $order->get_id() );
				$order_lock_token = Lock::acquire( $order_lock_key, PAYMONGO_ORDER_LOCK_TTL );

				if ( false === $order_lock_token ) {
					// A rotation is actively in flight for this order -- skip;
					// the next run will see whatever it leaves behind.
					continue;
				}

				// Set from inside the try below on a paid result -- deliberately
				// invoked only after the try/finally closes (see just past it),
				// once this order's own lock is released: it carries
				// trackPaymentResolution()/the third-party action, neither of
				// which needs that lock (see ReconcileResult::$after_completion).
				$after_completion = null;

				// Set only once $order below has actually been reassigned to
				// the reloaded, current, unpaid $current_order -- the catch
				// below's own cooldown writes must never fire before that:
				// too early, and $order is still the stale copy from this
				// sweep's own bulk query (not the fresh read this order's
				// current state demands), and the order hasn't even been
				// confirmed current/unpaid/lockable yet at all.
				$revalidated = false;

				try {
					// A fresh, independent read, not $order's own cached copy --
					// $order was loaded once at the start of the sweep, so its
					// in-memory meta is exactly as stale as $intent_before_check
					// and would never disagree with it. Now that the lock above
					// rules out a rotation landing *during* this comparison, this
					// is the one point where checking against the real, current
					// value is both meaningful and safe -- and, since nothing
					// else can win this same lock from here until it's released
					// below, the one point this needs to happen at all: no
					// rotation can land between here and then.
					//
					// A fresh, independent, uncached read -- see
					// Utils::reloadOrderUncached()'s own docblock for why both
					// its status and meta reloads are each necessary; without
					// the forced meta reread specifically, a concurrent
					// rotation that committed between this request's own
					// earlier bulk order query and winning this lock just now
					// could still go undetected by the intent-mismatch check
					// just below, risking exactly the double-submit race that
					// check exists to close.
					$current_order = $this->utils->reloadOrderUncached( $order->get_id() );

					if ( ! $current_order ) {
						// Can't confirm the order is still what $intent_before_check
						// assumes it is (e.g. deleted between the sweep's own query
						// and winning this lock). Everything below -- the
						// reconciliation call, its completion work, and the
						// cooldown/attempt writes -- reads and writes through this
						// object, so proceeding on the stale $order instead would
						// risk completing (or re-completing) an order whose real,
						// current state we never actually looked at.
						continue;
					}

					if ( $current_order->get_meta( PAYMONGO_PAYMENT_INTENT_META_KEY ) !== $intent_before_check ) {
						// Rotated (and its meta already reset) between when we
						// captured $intent_before_check and when we won this
						// lock. Still stale; don't act on it.
						continue;
					}

					// See Utils::isOrderPaidAuthoritative()'s own docblock for
					// why both checks, and why this -- not isOrderPaidInDatabase()
					// alone -- is the right call here specifically (pre-check,
					// not post-completion-failure recovery).
					if ( $this->utils->isOrderPaidAuthoritative( $current_order ) ) {
						// Paid through some other path (a webhook, a customer
						// retrying checkout) since $order was hydrated at the
						// start of this sweep -- nothing left to reconcile, and
						// completing it again from here would risk a duplicate
						// completion.
						continue;
					}

					// From here on, $current_order -- not $order -- is what
					// gets read from and written to: it's the one just
					// confirmed to be both current and unpaid, under a lock
					// that rules out either changing again until it's released
					// below.
					$order       = $current_order;
					$revalidated = true;

					// Renews both locks this order's processing depends on --
					// the sweep-level one (else a concurrent trigger could
					// reclaim it and start an overlapping run while this
					// order's completion work is still going, see
					// LockLostException) and this order's own
					// lock acquired above (else that same work could outlive
					// it and let a rotation slip in right after all, recreating
					// the exact race just closed). Invoked between each
					// unbounded step inside Utils rather than once upfront, so
					// a single slow step doesn't leave the rest of them
					// unguarded for its own duration too.
					// Thrown, not just logged: Utils is about to run (or is in
					// the middle of) completeOrder() and third-party callbacks
					// for a paid order, and a concurrent trigger may already
					// hold one of these locks and be doing the same work. Utils
					// never catches LockLostException, so it propagates out of
					// reconcileOrderAgainstPayMongoDetailed() and out of this
					// method, uncaught, aborting the sweep before (or during)
					// that work instead of racing it. The sweep-level lock is
					// checked first -- if it's already gone, the order lock
					// renewal below is never even attempted.
					$sweep_heartbeat = Lock::heartbeat(
						$lock_key,
						$lock_token,
						$lock_ttl,
						'Reconciliation sweep lock could not be renewed mid-order; a concurrent trigger may now be running.'
					);
					$order_heartbeat = Lock::heartbeat(
						$order_lock_key,
						$order_lock_token,
						PAYMONGO_ORDER_LOCK_TTL,
						'Reconciliation order lock could not be renewed mid-order; a concurrent intent rotation may now be running.'
					);

					$heartbeat = function () use ( $sweep_heartbeat, $order_heartbeat ) {
						$sweep_heartbeat();
						$order_heartbeat();
					};

					$result = $this->utils->reconcileOrderAgainstPayMongoDetailed( $current_order, $client, $max_intents_per_order, $deadline, $heartbeat );

					if ( $result->paid ) {
						++$considered;
						++$reconciled;
						// Not continue-d here -- the deferred work above still
						// needs to run once this order's own lock is released,
						// just past the finally below.
						$after_completion = $result->after_completion;
					} elseif ( ReconcileResult::REASON_DEADLINE === $result->reason ) {
						// The order wasn't examined at all -- don't count it as
						// considered, and don't touch its meta. It's simply
						// reconsidered on the next run, with no cooldown needed.
						$deadline_hit = true;
						break;
					} elseif ( ReconcileResult::REASON_INCOMPLETE === $result->reason ) {
						// Attempted but inconclusive (a transient PayMongo error, a
						// capped intent list, or the deadline cutting the scan short
						// after at least one intent was already checked): back off so
						// this order isn't retried again immediately, but don't spend
						// its abandonment budget -- a run of outages must not be able
						// to get a still possibly-paid order abandoned.
						//
						// Counted separately from considered/reconciled/abandoned,
						// not folded into either: during an outage every eligible
						// order takes this path, and "0 considered, 0 reconciled"
						// would read identically to an empty run with nothing to do.
						++$inconclusive;
						if ( $result->transient_error ) {
							// Narrower than $inconclusive: excludes a capped intent
							// list, an intent still processing or awaiting the
							// customer's next action, and a malformed succeeded
							// response -- all normal, expected states, not signs of
							// a PayMongo-side problem. See ReconcileResult::$transient_error.
							++$transient_errors;
						}
						$order->update_meta_data( PAYMONGO_RECONCILE_LAST_ATTEMPT_META_KEY, time() );
						// Tagged with the intent this cooldown applies to, same as
						// the miss branch below -- without this, the next run's
						// isSkippable() sees $attempts_intent disagree with the
						// (unchanged) current intent and treats the cooldown as
						// belonging to a since-rotated intent, retrying this order
						// on every sweep instead of backing off.
						$order->update_meta_data( PAYMONGO_RECONCILE_ATTEMPTS_INTENT_META_KEY, $intent_before_check );
						// Also persists $attempts, not just the tag above -- when
						// the top-of-loop mismatch check normalized it to 0 (a
						// stale count from an intent this order has since moved
						// on from), leaving that reset unsaved here would have
						// the tag now agree with the current intent while the
						// count in storage still reads the old, already-maxed
						// value: next run, isSkippable() sees a matching tag and
						// a maxed-out count and skips this order permanently,
						// even though it was never actually re-checked against
						// its current intent. Unincremented, unlike the miss
						// branch below -- an inconclusive result must not spend
						// abandonment budget, whether or not this normalized it.
						$order->update_meta_data( PAYMONGO_RECONCILE_ATTEMPTS_META_KEY, $attempts );
						$order->save_meta_data();

						// An incomplete result can itself be the deadline having cut
						// the scan short mid-order (after some progress -- see
						// Utils::reconcileOrderAgainstPayMongoDetailed()). The top-of-loop
						// check above would normally catch that before the next
						// order, but not if this was the last order in the batch, so
						// re-check here rather than let deadline_hit go unreported.
						if ( $clock() >= $deadline ) {
							$deadline_hit = true;
							break;
						}

						continue;
					} else {
						// REASON_MISS: every selected intent was checked and confirmed
						// resolved (cleanly, or permanently failed on PayMongo's side)
						// with none paid. Writes the same cooldown key as the incomplete
						// branch above -- both back off the next check the same way --
						// but this is the only path that also advances the attempt
						// counter, which is what actually spends the order's
						// abandonment budget.
						++$considered;
						++$attempts;
						$order->update_meta_data( PAYMONGO_RECONCILE_LAST_ATTEMPT_META_KEY, time() );
						$order->update_meta_data( PAYMONGO_RECONCILE_ATTEMPTS_META_KEY, $attempts );
						// Tags the count above with the intent it was just spent
						// against -- $intent_before_check was reconfirmed current
						// by the fresh read a few lines up, inside this same lock.
						$order->update_meta_data( PAYMONGO_RECONCILE_ATTEMPTS_INTENT_META_KEY, $intent_before_check );

						if ( $attempts >= PAYMONGO_RECONCILE_MAX_ATTEMPTS ) {
							++$abandoned;
							$order->add_order_note(
								sprintf(
									/* translators: %d: number of reconciliation attempts. */
									__( 'PayMongo reconciliation gave up after %d attempts. Manual check required.', 'paymongo' ),
									PAYMONGO_RECONCILE_MAX_ATTEMPTS
								)
							);
							wc_get_logger()->warning(
								sprintf( 'Reconciliation abandoned for order %d after %d attempts.', $order->get_id(), $attempts ),
								array( 'source' => 'cynder-paymongo' )
							);
						}

						$order->save_meta_data();
					}
				} catch ( LockLostException $e ) {
					// Must still abort the whole run -- a concurrent trigger
					// may already be running against the same orders; see
					// every throw site's own comment above for why. Falls
					// through to this try's own finally below (releasing a
					// lock a concurrent trigger may already consider its
					// own), then to the identical rethrow in the outer catch
					// a few lines down.
					throw $e;
				} catch ( \Throwable $e ) {
					// Caught here, before the finally below releases this
					// order's own lock -- not in the outer catch further
					// down, which by the time it runs no longer holds it.
					// Writing this cooldown meta unlocked would break the
					// rule every other write in this method follows: a
					// concurrent rotation landing in that gap could commit
					// a brand-new intent and have this write land a
					// cooldown on it under the old intent's id instead.
					wc_get_logger()->error(
						'Reconciliation failed unexpectedly for order ' . $order->get_id() . ': ' . $e->getMessage(),
						array( 'source' => 'cynder-paymongo' )
					);
					++$inconclusive;
					++$errored;

					// Guards against writing onto the stale, pre-reload
					// $order from this sweep's own bulk query -- a failure
					// before $order was reassigned to $current_order above
					// (e.g. the reload itself throwing) has nothing
					// confirmed current/unpaid/lockable to write a cooldown
					// onto at all.
					if ( $revalidated ) {
						// Same cooldown meta as the REASON_INCOMPLETE branch
						// above -- back off without spending abandonment
						// budget, so this one order's own bug can't itself
						// get it abandoned, and isn't retried again
						// immediately on every subsequent run either.
						$order->update_meta_data( PAYMONGO_RECONCILE_LAST_ATTEMPT_META_KEY, time() );
						$order->update_meta_data( PAYMONGO_RECONCILE_ATTEMPTS_INTENT_META_KEY, $intent_before_check );
						$order->update_meta_data( PAYMONGO_RECONCILE_ATTEMPTS_META_KEY, $attempts );
						$order->save_meta_data();
					}
				} finally {
					\Cynder\PayMongo\Lock::release( $order_lock_key, $order_lock_token );
				}

				if ( null !== $after_completion ) {
					// The per-order lock is already released above -- this
					// renews only the sweep-level one (reusing $order_lock_key
					// here would just fail, since that lock is gone by now).
					// Without it, this deferred call -- PostHog and an
					// arbitrary third-party callback, neither bounded by
					// $deadline -- could still let the sweep-level lock lapse
					// mid-call and a concurrent trigger start an overlapping
					// run while this one is still going, the same class of
					// race the per-order lock's own heartbeat exists to close.
					$sweep_lock_heartbeat = Lock::heartbeat(
						$lock_key,
						$lock_token,
						$lock_ttl,
						'Reconciliation sweep lock could not be renewed during deferred completion work; a concurrent trigger may now be running.'
					);

					// Own try/catch, deliberately outside the one around this
					// order's own processing above (and not sharing its catch
					// blocks at the bottom of this loop iteration): the order
					// itself is already reconciled and its per-order lock
					// already released by this point, so a failure here is a
					// deferred-callback (PostHog, a third-party
					// cynder_paymongo_successful_payment listener) problem, not
					// a reconciliation one. Treating it as this order's own
					// $errored/$inconclusive, or writing cooldown meta onto an
					// order that's already paid, would both be wrong -- there's
					// nothing left to retry here. LockLostException is the one
					// exception that still must abort the whole run, same as
					// every other lock-loss signal in this method.
					try {
						$after_completion( $sweep_lock_heartbeat );
					} catch ( LockLostException $e ) {
						throw $e;
					} catch ( \Throwable $e ) {
						wc_get_logger()->warning(
							'Order ' . $order->get_id() . ' was reconciled, but its deferred post-completion callbacks failed: ' . $e->getMessage(),
							array( 'source' => 'cynder-paymongo' )
						);
					}
				}
			} catch ( LockLostException $e ) {
				// Must still abort the whole run -- a concurrent trigger may
				// already be running against the same orders; see every
				// throw site's own comment above for why.
				throw $e;
			} catch ( \Throwable $e ) {
				// Reached only by a failure before the inner try above ever
				// started (acquiring this order's own lock, or $order->get_id()
				// itself) -- the inner try's own catch just above handles
				// everything after that, including every cooldown-meta
				// write, while its own per-order lock is still held. Log
				// only here: there is no lock held at this point to write
				// safely under, and nothing yet confirmed current/unpaid to
				// write a cooldown onto in the first place.
				wc_get_logger()->error(
					'Reconciliation failed unexpectedly for order ' . $order->get_id() . ': ' . $e->getMessage(),
					array( 'source' => 'cynder-paymongo' )
				);
				++$inconclusive;
				++$errored;
			}
		}

		return array(
			'considered'       => $considered,
			'reconciled'       => $reconciled,
			'abandoned'        => $abandoned,
			'inconclusive'     => $inconclusive,
			'transient_errors' => $transient_errors,
			'skipped'          => $skipped,
			'errored'          => $errored,
			'deadline_hit'     => $deadline_hit,
		);
	}
}
