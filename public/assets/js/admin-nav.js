/* ==========================================================================
   Tech4TIME — admin-nav.js
   The admin shell's chrome: the width of the icon rail, and the account menu.

   THE RAIL is fully labelled without this file, which is why the button that
   narrows it starts hidden: a control that does nothing is worse than no
   control. This unhides it, remembers the choice, and does nothing else.

   The choice is per browser rather than per session, so the rail is the shape
   it was left in the next time someone signs in.

   THE ACCOUNT MENU is a <details>, and the browser already opens it, closes
   it on Escape and moves focus through it. The one thing <details> does not
   do is close when a click lands somewhere else on the page, which is the
   only behaviour added here. With this file absent the menu still opens and
   still signs out; it simply waits to be pressed again.
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

  /* Close the account menu on a click anywhere outside it. Bound once, on the
     document, rather than per-menu: there is one of these, and a listener that
     outlives a swapped-in page is a listener that leaks. */
  function closeOnOutsideClick(doc) {
    doc.addEventListener("click", function (event) {
      var menus = doc.querySelectorAll("details[data-account][open]");

      Array.prototype.forEach.call(menus, function (menu) {
        if (!menu.contains(event.target)) {
          menu.open = false;
        }
      });
    });
  }

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.adminNav = {
    init: function () {
      var doc = global.document;
      var rail = doc.querySelector("[data-rail]");
      var toggle = doc.querySelector("[data-rail-toggle]");
      if (rail && toggle) {
        new Rail(rail, toggle);
      }

      if (!api.adminNav.bound) {
        closeOnOutsideClick(doc);
        api.adminNav.bound = true;
      }
    },
    bound: false
  };
})(window);
