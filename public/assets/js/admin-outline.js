/* ==========================================================================
   Tech4TIME — admin-outline.js
   Marks where you are in the "On this page" column.

   WHAT IT IS FOR
   The company editor is twelve bands and 282 rows; the technology band alone
   is more than thirty pictures, one under the next. Scrolling through it, the
   column on the right tells you what the page CONTAINS but not where in it you
   have got to — and after a screen or two of near-identical rows that is the
   thing you actually want to know. This keeps the entry for the band you are
   looking at marked for as long as you are in it.

   IT IS A HOVER STATE THAT STAYS. Deliberately the same treatment the column
   already uses when a pointer is over an entry, because that is the one the
   eye has already been taught means "this one".

   HOW IT DECIDES
   The last band whose top has passed under the bar. Not "the band nearest the
   middle" and not IntersectionObserver's first entry: a band can be four
   screens tall, so at most moments only one of them crosses the line at all,
   and the answer has to be the one you are inside rather than the one whose
   edge happens to be visible.

   The line is scroll-padding-block-start read off the document — the same
   number the anchors themselves scroll to. Taking it from there rather than
   repeating it means the mark and the jump cannot disagree, including at the
   width where the bar wraps to two rows and the number changes.

   WITHOUT THIS FILE the column is exactly what it was: a list of anchors that
   work. Nothing here is a route to anything.
   ========================================================================== */

(function (global) {
  "use strict";

  var doc = global.document;

  function Outline(nav) {
    this.nav = nav;
    this.entries = [];

    var links = nav.querySelectorAll(".outline__link");

    Array.prototype.forEach.call(links, function (link) {
      var href = link.getAttribute("href") || "";
      if (href.charAt(0) !== "#") {
        return;
      }
      var target = doc.getElementById(href.slice(1));
      if (target) {
        this.entries.push({ link: link, target: target });
      }
    }, this);

    if (!this.entries.length) {
      return;
    }

    this.current = null;
    this.ticking = false;

    this.onScroll = this.schedule.bind(this);
    global.addEventListener("scroll", this.onScroll, { passive: true });
    global.addEventListener("resize", this.onScroll, { passive: true });

    this.mark();
  }

  /* Once per frame at most. Twelve getBoundingClientRect() calls is nothing,
     but doing it for every scroll event a wheel produces is how a page starts
     feeling heavy. */
  Outline.prototype.schedule = function () {
    if (this.ticking) {
      return;
    }
    this.ticking = true;

    global.requestAnimationFrame(
      function () {
        this.ticking = false;
        this.mark();
      }.bind(this)
    );
  };

  Outline.prototype.line = function () {
    var padding = global.getComputedStyle(doc.documentElement).scrollPaddingTop;
    var px = parseFloat(padding);
    return (isNaN(px) ? 0 : px) + 16;
  };

  Outline.prototype.mark = function () {
    var line = this.line();
    var found = this.entries[0];

    for (var i = 0; i < this.entries.length; i += 1) {
      if (this.entries[i].target.getBoundingClientRect().top <= line) {
        found = this.entries[i];
      }
    }

    /* THE LAST BAND CAN BE SHORTER THAN THE SCREEN, in which case its top
       never passes the line and it can never be reached by scrolling — the
       page simply runs out first. At the bottom, it is the answer. */
    var bottom = global.innerHeight + global.scrollY;
    if (bottom >= doc.documentElement.scrollHeight - 2) {
      found = this.entries[this.entries.length - 1];
    }

    if (found === this.current) {
      return;
    }
    this.current = found;

    this.entries.forEach(function (entry) {
      if (entry === found) {
        /* aria-current and not a class alone: a screen reader moving through
           the column is told which one it is on, which is the same question
           the colour answers for everybody else. */
        entry.link.setAttribute("aria-current", "true");
      } else {
        entry.link.removeAttribute("aria-current");
      }
    });
  };

  Outline.prototype.stop = function () {
    global.removeEventListener("scroll", this.onScroll);
    global.removeEventListener("resize", this.onScroll);
  };

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.adminOutline = {
    init: function () {
      /* The column is inside the part admin-swap.js replaces, so this runs
         again after every move between screens — and the listeners the last
         one left on `window` would otherwise be measuring elements that are no
         longer in the document. */
      if (api.adminOutline.live) {
        api.adminOutline.live.stop();
        api.adminOutline.live = null;
      }

      var nav = doc.querySelector(".outline");
      if (!nav || !global.requestAnimationFrame) {
        return;
      }

      api.adminOutline.live = new Outline(nav);
    },
    live: null
  };
})(window);
