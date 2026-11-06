/**
 * Zorderz Prep — the install-date precedence, browser side.
 *
 * The server bakes each card's install payload with ZPREP_Install_Date::precedence(); this is
 * the same order, used when a later answer for the same job reaches the browser (a queue
 * reload while a job is open, a lookup of the job that is already on the queue):
 *
 *     3  a date the jobs app knows        (state 'known')
 *     2  a date read from billing paperwork (source 'billing', shown as a guess)
 *     1  jobs: "unscheduled"
 *     0  jobs: unknown / not applicable
 *    -1  nothing
 *
 * merge(cur, next) keeps the higher-ranked payload; on a tie the newer one. So a known date
 * is never replaced by a guess, and a guess only fills a job that has no known date.
 */
(function (root) {
  'use strict';

  function rank(p) {
    if (!p || typeof p !== 'object' || !p.line) return -1;
    if (p.source === 'billing') return 2;
    if (p.state === 'known') return 3;
    return p.status === 'unscheduled' ? 1 : 0;
  }

  function merge(cur, next) {
    var r = rank(next);
    if (r < 0) return cur || null;
    return r >= rank(cur) ? next : cur;
  }

  var api = { rank: rank, merge: merge };
  if (typeof module === 'object' && module && module.exports) module.exports = api;
  if (root) root.ZPREP_installMerge = api;
})(typeof window !== 'undefined' ? window : null);
