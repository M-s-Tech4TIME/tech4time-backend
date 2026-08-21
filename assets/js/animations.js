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

  /* The row grid runs its own, longer sequence: every card in it has its own
     step rather than sharing one with its row, so the ceiling has to allow for
     all of them and for the gap between rows. Nine logos in two rows reach
     eleven, which is under a second all told. */
  var MAX_ROW_STEP = 16;
  var ROW_GAP = 2;

  /* How long a figure takes to count up to its value. Long enough to read as
     counting, short enough that the number is the true one almost at once. */
  var COUNT_DURATION = 1400;

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
      /* The figures are already correct in the markup, so there is nothing to
         restore — they simply stay at their final value. */
      return;
    }

    countUp();

    var targets = document.querySelectorAll("[data-reveal]");
    if (!targets.length) {
      return;
    }

    stagger(targets);
    staggerRows();

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

  /* --------------------------------------------------------------------------
     Rows that arrive from alternating sides.

     For a grid whose rows should slide in rather than rise: the first row from
     the left, the next from the right, and so on down the block. The rows are
     not in the markup — the grid is auto-fit, so how many cards share a row is
     decided by the width of the screen. They have to be read back out of the
     layout, by grouping the cards on the offsetTop they ended up at.

     The row decides the direction; the card's place in the row decides when.
     So a row comes in from one side with its cards following one another, and
     the next row does the same from the other side — rather than each row
     arriving as a single block.
     -------------------------------------------------------------------------- */
  function staggerRows() {
    var groups = document.querySelectorAll("[data-reveal-rows]");

    Array.prototype.forEach.call(groups, function (group) {
      var kids = Array.prototype.filter.call(
        group.children,
        function (el) { return el.hasAttribute("data-reveal"); }
      );

      /* Group by where the layout actually put them. A couple of pixels of
         tolerance, because cards of unequal height in the same row do not all
         report an identical offset. */
      var rows = [];
      var lastTop = null;
      kids.forEach(function (kid) {
        var top = kid.offsetTop;
        if (lastTop === null || Math.abs(top - lastTop) > 4) {
          rows.push([]);
          lastTop = top;
        }
        rows[rows.length - 1].push(kid);
      });

      var step = 0;
      rows.forEach(function (row, index) {
        /* A beat between rows, so one finishes arriving before the next
           starts rather than the two overlapping into a single movement. */
        if (index > 0) {
          step += ROW_GAP;
        }
        row.forEach(function (kid) {
          kid.style.setProperty(
            "--reveal-delay",
            String(Math.min(step, MAX_ROW_STEP))
          );
          kid.style.setProperty("--reveal-dir", index % 2 === 0 ? "-1" : "1");
          step += 1;
        });
      });
    });
  }

  /* --------------------------------------------------------------------------
     Figures that count up to their value.

     The number in the markup is the real one, and it is what a visitor without
     JavaScript, or with reduced motion, sees from the start. This reads it,
     counts from zero, and puts it back — so the page is never showing a figure
     that is not the true one for longer than the animation lasts.

     The suffix is kept: "5+" counts to 5 and stays a 5+, "100%" to 100%.
     -------------------------------------------------------------------------- */
  function countUp() {
    var figures = document.querySelectorAll("[data-count-up]");
    if (!figures.length) return;

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          observer.unobserve(entry.target);
          run(entry.target);
        });
      },
      { threshold: 0, rootMargin: "0px 0px -10% 0px" }
    );

    Array.prototype.forEach.call(figures, function (figure) {
      observer.observe(figure);
    });

    function run(el) {
      var match = /^\s*(\d+)(.*)$/.exec(el.textContent);
      if (!match) return;

      var target = parseInt(match[1], 10);
      var suffix = match[2];
      var started = null;

      /* Held at the width the final value needs, so the block does not jog
         sideways as the digits go from one to three characters. */
      el.style.setProperty("min-width", el.getBoundingClientRect().width + "px");

      function frame(now) {
        if (started === null) started = now;
        var progress = Math.min((now - started) / COUNT_DURATION, 1);
        /* Ease out: quick to most of the way, then settling, which reads as
           counting rather than as a linear sweep. */
        var eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = String(Math.round(target * eased)) + suffix;
        if (progress < 1) {
          global.requestAnimationFrame(frame);
        }
      }

      el.textContent = "0" + suffix;
      global.requestAnimationFrame(frame);
    }
  }

  global.Tech4Time = global.Tech4Time || {};
  global.Tech4Time.animations = { init: init };
})(window);
