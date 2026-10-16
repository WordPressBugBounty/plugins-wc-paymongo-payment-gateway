<?php

namespace Cynder\PayMongo;

/**
 * Thrown by a heartbeat callback (ReconciliationSweep::run(),
 * Utils::completeOrderLocked()/reconcileOrderAgainstPayMongoLocked(), the
 * webhook handler, and checkout's intent rotation) when the per-order or
 * sweep-level lock it was renewing could not be renewed mid-run. By the
 * time the heartbeat runs, the caller is typically about to run or has just
 * run completeOrder() and third-party callbacks for a paid order, and a
 * concurrent process may already hold the lock and be doing the same work --
 * this must abort (or, where the order turned out paid anyway, be recovered
 * from) rather than just logged and continued past.
 *
 * A distinct type, not a bare \RuntimeException, so it can't be confused
 * with an unrelated failure further up the call stack; it is also
 * deliberately never caught inside the lock-renewal site itself, so it
 * always reaches the caller uncaught rather than being swallowed as "this
 * intent's retrieval failed."
 */
class LockLostException extends \RuntimeException {
}
