/* ==========================================================================
   Tech4TIME — animations.js
   Scroll-reveal via IntersectionObserver.

   Progressive enhancement: elements marked [data-reveal] are only hidden once
   this script has confirmed it can reveal them again (it adds .js-reveal to
   <html>, which is what actually applies the hidden state in animations.css).
   With JavaScript disabled, or without IntersectionObserver, every section
   renders normally — content is never dependent on script.

   Phase 1 ships the mechanism. Phase 4 applies [data-reveal] across the pages.

   Exposes window.Tech4Time.animations for main.js to initialise.
   ========================================================================== */

(function (global) {
  "use strict";

  var REVEAL_CLASS = "is-revealed";
  var ENABLED_CLASS = "js-reveal";

  function init() {
    var reduced =
      global.matchMedia &&
      global.matchMedia("(prefers-reduced-motion: reduce)").matches;

    /* No observer support, or the visitor asked for less motion: leave the
       content visible and do nothing. */
    if (!("IntersectionObserver" in global) || reduced) {
      return;
    }

    var targets = document.querySelectorAll("[data-reveal]");
    if (!targets.length) {
      return;
    }

    document.documentElement.classList.add(ENABLED_CLASS);

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
        threshold: 0.12,
        /* Start the reveal slightly before the element reaches the viewport
           edge, so it is already settled by the time it is fully visible. */
        rootMargin: "0px 0px -10% 0px",
      }
    );

    Array.prototype.forEach.call(targets, function (target, index) {
      /* Stagger siblings that opt in, without hard-coding delays in markup. */
      if (target.hasAttribute("data-reveal-delay")) {
        target.style.setProperty("--reveal-delay", String(index % 6));
      }
      observer.observe(target);
    });
  }

  global.Tech4Time = global.Tech4Time || {};
  global.Tech4Time.animations = { init: init };
})(window);
