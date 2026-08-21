/* ==========================================================================
   Tech4TIME — animations.js
   Scroll-reveal via IntersectionObserver.

   The hidden state is NOT applied here. theme-init.js arms it before first
   paint by adding .js-reveal to <html>; see the note there for why. This file
   only reveals, and marks the document as its watchdog expects so that a
   failure to load this script lifts the hidden state instead of stranding it.

   Content is never dependent on script: with JavaScript off, or reduced motion
   requested, or no IntersectionObserver, nothing is hidden in the first place.

   [data-reveal] is applied across the pages by tools/apply_reveals.py, which
   documents which elements are deliberately left out.

   Exposes window.Tech4Time.animations for main.js to initialise.
   ========================================================================== */

(function (global) {
  "use strict";

  var REVEAL_CLASS = "is-revealed";
  var ENABLED_CLASS = "js-reveal";
  var READY_ATTR = "data-reveal-ready";

  /* Beyond this many siblings the stagger stops being a flourish and becomes a
     queue, so later cards in a long run share the last step rather than each
     waiting longer than the one before. */
  var MAX_STEP = 7;

  function init() {
    var root = document.documentElement;

    /* Tell the watchdog in theme-init.js that this script arrived. Set before
       any early return: "we got here and made a decision" is what it asks, not
       "an observer was created". */
    root.setAttribute(READY_ATTR, "");

    var reduced =
      global.matchMedia &&
      global.matchMedia("(prefers-reduced-motion: reduce)").matches;

    if (!("IntersectionObserver" in global) || reduced) {
      root.classList.remove(ENABLED_CLASS);
      return;
    }

    var targets = document.querySelectorAll("[data-reveal]");
    if (!targets.length) {
      return;
    }

    stagger(targets);

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          entry.target.classList.add(REVEAL_CLASS);
          /* One-shot: sections do not re-hide when scrolled back past. */
          observer.unobserve(entry.target);
        });
      },
      {
        /* Zero, not a fraction. A fraction is a share of the TARGET's area, so
           an element taller than the viewport can never reach it — the privacy
           policy's body is one, and asking for 12% of it would have left the
           whole document invisible. The bottom margin is what holds the reveal
           back until the element is properly in view. */
        threshold: 0,
        rootMargin: "0px 0px -10% 0px",
      }
    );

    Array.prototype.forEach.call(targets, function (target) {
      observer.observe(target);
    });
  }

  /* Delay each element by its position among its own marked siblings.

     Position within the parent, not within the document: a grid that happens to
     be the fourth thing on the page would otherwise start its first card on the
     fourth step and wrap round to zero partway through, so the cards would
     arrive out of order. Grouping by parent is what makes a row read left to
     right.

     Written to the CSSOM rather than a style attribute in the markup, which the
     Content-Security-Policy would refuse. */
  function stagger(targets) {
    /* WeakMap is safe to assume: this function is only reached once
       IntersectionObserver has been found, and nothing ships one without the
       other. */
    var counts = new WeakMap();

    Array.prototype.forEach.call(targets, function (target) {
      if (!target.hasAttribute("data-reveal-delay")) return;

      var parent = target.parentNode;
      var index = counts.get(parent) || 0;
      counts.set(parent, index + 1);

      target.style.setProperty(
        "--reveal-delay",
        String(Math.min(index, MAX_STEP))
      );
    });
  }

  global.Tech4Time = global.Tech4Time || {};
  global.Tech4Time.animations = { init: init };
})(window);
