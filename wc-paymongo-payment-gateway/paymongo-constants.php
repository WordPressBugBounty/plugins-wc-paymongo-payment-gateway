<?php

const PAYMONGO_CARD             = 'paymongo';
const PAYMONGO_CARD_INSTALLMENT = 'paymongo_card_installment';
const PAYMONGO_GCASH            = 'paymongo_gcash';
const PAYMONGO_GRABPAY          = 'paymongo_grab_pay';
const PAYMONGO_PAYMAYA          = 'paymongo_paymaya';
const PAYMONGO_ATOME            = 'paymongo_atome';
const PAYMONGO_BPI              = 'paymongo_bpi';
const PAYMONGO_UNIONBANK        = 'paymongo_unionbank';
const PAYMONGO_BILLEASE         = 'paymongo_billease';

const PAYMONGO_PAYMENT_METHODS = array(
	PAYMONGO_CARD,
	PAYMONGO_CARD_INSTALLMENT,
	PAYMONGO_GCASH,
	PAYMONGO_GRABPAY,
	PAYMONGO_PAYMAYA,
	PAYMONGO_ATOME,
	PAYMONGO_BPI,
	PAYMONGO_UNIONBANK,
	PAYMONGO_BILLEASE,
);

const PAYMONGO_PAYMENT_METHOD_LABELS = array(
	PAYMONGO_CARD             => 'Credit/Debit Card via PayMongo',
	PAYMONGO_CARD_INSTALLMENT => 'Card Installment via PayMongo',
	PAYMONGO_GCASH            => 'GCash via PayMongo',
	PAYMONGO_GRABPAY          => 'GrabPay via PayMongo',
	PAYMONGO_PAYMAYA          => 'Maya via PayMongo',
	PAYMONGO_ATOME            => 'Atome via PayMongo',
	PAYMONGO_BPI              => 'BPI Direct Online Banking via PayMongo',
	PAYMONGO_UNIONBANK        => 'UnionBank Direct Online Banking via PayMongo',
	PAYMONGO_BILLEASE         => 'BillEase via PayMongo',
);

const PAYMONGO_PAYMENT_INTENT_META_KEY = 'paymongo_payment_intent_id';
const PAYMONGO_CLIENT_KEY_META_KEY     = 'paymongo_client_key';

const PAYMONGO_CARD_INSTALLMENT_MINIMUM_AMOUNT = 3000;
const PAYMONGO_SUPPORTED_CURRENCIES            = array( 'PHP' );

// Reconciliation sweep tuning.
/**
 * Orders hydrated per sweep run.
 *
 * Sized to what PAYMONGO_RECONCILE_RUN_DEADLINE (20s) can actually get
 * through in one run, not to how many could theoretically be eligible --
 * the run stops starting new orders once the deadline passes, so hydrating
 * more than that just pays DB/memory/query-time cost (see
 * PAYMONGO_RECONCILE_LOCK_TTL_MARGIN below) for orders this run was never
 * going to reach anyway. An order the deadline cuts off before reaching is
 * simply reconsidered next run (see run()'s own deadline handling), so this
 * limit doesn't strand anything -- it only bounds how much is over-fetched.
 *
 * Tune via the 'cynder_paymongo_reconcile_order_limit' filter; raising it
 * back up should come with revisiting PAYMONGO_RECONCILE_LOCK_TTL_MARGIN
 * together, not on its own (see its own comment).
 */
const PAYMONGO_RECONCILE_ORDER_LIMIT           = 100;
const PAYMONGO_RECONCILE_RUN_DEADLINE          = 20; // Seconds a single sweep may run.
const PAYMONGO_RECONCILE_MAX_INTENTS           = 20; // Ceiling on stored intents checked per order.
const PAYMONGO_RECONCILE_API_TIMEOUT           = 10; // Seconds, per HTTP call.
// Extra seconds of slack added to the lock TTL. This is the *only* thing
// standing between a duration-unbounded wc_get_orders() query -- bounded
// in row count by PAYMONGO_RECONCILE_ORDER_LIMIT above, not in how long
// it can take to run -- and a lock stolen out from under a run still
// inside that query. The deadline check only takes effect once the loop
// starts, not while the query runs, and nothing can renew the lock
// mid-query either. Sized generously for that reason, not just as
// rounding, and scaled to PAYMONGO_RECONCILE_ORDER_LIMIT above: 60s is
// generous for a batch of that size; revisit (upward) if that limit is
// ever raised.
const PAYMONGO_RECONCILE_LOCK_TTL_MARGIN       = 60;
const PAYMONGO_RECONCILE_RETRY_INTERVAL        = 6 * HOUR_IN_SECONDS; // Minimum gap between attempts on the same order.
const PAYMONGO_RECONCILE_MAX_ATTEMPTS          = 10; // Attempts before an order is left for manual review.
const PAYMONGO_RECONCILE_ATTEMPTS_META_KEY      = '_cynder_paymongo_reconcile_attempts';
const PAYMONGO_RECONCILE_LAST_ATTEMPT_META_KEY  = '_cynder_paymongo_last_reconcile_attempt';
const PAYMONGO_RECONCILE_INTENT_OFFSET_META_KEY = '_cynder_paymongo_reconcile_intent_offset';

// Which intent PAYMONGO_RECONCILE_ATTEMPTS_META_KEY/LAST_ATTEMPT were last
// recorded against. cynder_paymongo_create_intent() resets those two
// whenever it commits a rotation, atomically with the new intent id itself
// (same per-order lock, one save_meta_data() call) -- so in the current
// code, that reset can no longer land without the new intent id also
// landing, or vice versa. This tag is kept anyway as defense-in-depth, not
// because that race is still reachable: it means a stale count simply
// doesn't apply to a different, newer intent even if some future change
// reintroduces a gap between the two writes, without this needing to be
// revisited at the same time. Correctness today rests on the atomic commit
// above; this is insurance against that invariant breaking later, not what
// currently prevents a fresh intent being skipped by a stale count.
const PAYMONGO_RECONCILE_ATTEMPTS_INTENT_META_KEY = '_cynder_paymongo_reconcile_attempts_intent';

// Guards a single order against concurrent completion/rotation: the
// per-order lock used by cynder_paymongo_create_intent(), the webhook
// handler, and ReconciliationSweep. Short on its own, because the lock is
// renewed via a heartbeat callback wherever the guarded work can run longer
// than this (completeOrder()'s payment_complete()/invoice/tracking, an
// intent rotation's own PayMongo calls) -- see each heartbeat's own comment
// for why that renewal, not this TTL, is what actually has to outlast the
// work.
const PAYMONGO_ORDER_LOCK_TTL = 15;

// Bounds every PayMongo client that can make a call while
// PAYMONGO_ORDER_LOCK_TTL above is held -- cynder_paymongo_create_intent()'s
// pre-emptive existing-intent check and rotation-commit re-verification,
// PaymentIntent's already-succeeded reconciliation client, and both
// paymongo-top-level-hooks.php redirect handlers when they call
// reconcileOrderAgainstPayMongoLocked(). Without an explicit timeout, the
// underlying HTTP client's default applies -- which is not itself bounded
// to something shorter than that lock's TTL, so a stalled request could
// outlive it with no heartbeat able to run until it returns (a heartbeat
// can only run between calls, never inside one).
const PAYMONGO_ORDER_LOCKED_API_TIMEOUT = 10; // Seconds, per HTTP call.

// Bounds the underlying mail transport's own network timeout for the
// customer invoice email Utils::sendInvoice() sends -- wp_mail() has no
// timeout of its own to configure, so without this a stalled SMTP
// connection can block that one synchronous call indefinitely. Matters
// most for the callers that send it while holding PAYMONGO_ORDER_LOCK_TTL
// (ReconciliationSweep, cynder_paymongo_create_intent()'s pre-emptive
// check): a heartbeat can only run between calls, never inside one, so an
// unbounded send there could let the lock lapse mid-call with no chance
// to renew. Sized well under that lock's own TTL to leave margin for the
// heartbeat renewals immediately before and after this call.
const PAYMONGO_INVOICE_MAIL_TIMEOUT = 8; // Seconds.
