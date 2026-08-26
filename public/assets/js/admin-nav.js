/* ==========================================================================
   Tech4TIME — admin-nav.js
   The width of the admin's icon rail.

   The rail is fully labelled without this file, which is why the button that
   narrows it starts hidden: a control that does nothing is worse than no
   control. This unhides it, remembers the choice, and does nothing else.

   The choice is per browser rather than per session, so the rail is the shape
   it was left in the next time someone signs in.
   ========================================================================== */

(function (global) {
  "use strict";

  var STORE_KEY = "t4t-admin-rail";
  var NARROW = "narrow";
  var WIDE = "wide";

  function stored() {
    try {
      return global.localStorage.getItem(STORE_KEY);
    } catch (error) {
      /* Private browsing, or storage switched off. The rail still works. */
      return null;
    }
  }

  function remember(state) {
    try {
      global.localStorage.setItem(STORE_KEY, state);
    } catch (error) {
      /* Nothing to do: the rail is correct for this page load either way. */
    }
  }

  function Rail(element, toggle) {
    this.rail = element;
    this.toggle = toggle;
    this.apply(stored() === NARROW ? NARROW : WIDE);

    toggle.hidden = false;
    toggle.addEventListener(
      "click",
      function () {
        var next = this.rail.getAttribute("data-rail") === NARROW ? WIDE : NARROW;
        this.apply(next);
        remember(next);
      }.bind(this)
    );
  }

  Rail.prototype.apply = function (state) {
    this.rail.setAttribute("data-rail", state);

    /* aria-expanded describes the rail, not the button: true means the labels
       are showing. The accessible name changes with it, so a screen reader
       announces what pressing it will do rather than what it did. */
    var wide = state === WIDE;
    this.toggle.setAttribute("aria-expanded", wide ? "true" : "false");

    var label = this.toggle.querySelector(".visually-hidden");
    if (label) {
      label.textContent = wide ? "Narrow the menu" : "Widen the menu";
    }
  };

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.adminNav = {
    init: function () {
      var rail = global.document.querySelector("[data-rail]");
      var toggle = global.document.querySelector("[data-rail-toggle]");
      if (rail && toggle) {
        new Rail(rail, toggle);
      }
    }
  };
})(window);
