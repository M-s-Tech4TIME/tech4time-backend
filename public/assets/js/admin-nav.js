/* ==========================================================================
   Tech4TIME — admin-nav.js
   The admin shell's chrome: the width of the icon rail, and the account menu.

   THE RAIL is fully labelled without this file, which is why the button that
   narrows it starts hidden: a control that does nothing is worse than no
   control. This unhides it, remembers the choice, and does nothing else.

   THE CHOICE IS A COOKIE, AND THAT IS THE WHOLE POINT.
   It was localStorage, read here, on a deferred script — which runs after the
   document has been parsed and, on anything but a fast machine, after it has
   been painted. So a rail left narrow was drawn at its full width, and then
   snapped shut. On every single page load. It was described as the menu
   opening and closing "within a blink of an eye, almost immediately, but
   fully noticeable", and that is exactly what it was.

   A cookie goes up with the request, so lib/admin.php renders data-rail before
   it renders the rail. The first frame is already right and there is nothing
   left for this file to correct. Nothing here reads the state at all now: the
   attribute the server wrote IS the state.

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

  var COOKIE = "t4t_rail";
  var NARROW = "narrow";
  var WIDE = "wide";

  /* A year, because the alternative is a rail that forgets. Nothing secret is
     in it — it is a width — but SameSite=Lax keeps it off cross-site requests
     anyway, and Secure keeps it off the wire wherever there is a wire. It is
     read by lib/admin.php and by nothing else. */
  var YEAR = 60 * 60 * 24 * 365;

  function remember(state) {
    try {
      global.document.cookie =
        COOKIE + "=" + state +
        "; path=/; max-age=" + YEAR + "; SameSite=Lax" +
        (global.location.protocol === "https:" ? "; Secure" : "");
    } catch (error) {
      /* Cookies switched off. The rail is correct for this page load either
         way; it simply will not be next time. */
    }
  }

  function Rail(element, toggle) {
    this.rail = element;
    this.toggle = toggle;

    /* NOT applied from storage here. The server has already written the
       attribute; this only reads it, so that the button says the right thing
       about a rail that is already the right shape. */
    this.apply(element.getAttribute("data-rail") === NARROW ? NARROW : WIDE);

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
