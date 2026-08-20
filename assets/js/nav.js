/* ==========================================================================
   Tech4TIME — nav.js
   Site header behaviour: the mobile navigation drawer, the scrolled-header
   state, and the back-to-top control.

   The drawer is a modal surface on small screens, so it takes the full set of
   dialog affordances: focus moves into it, Tab is trapped inside it, Escape
   closes it, background scrolling is locked, and focus returns to the toggle
   on close.

   Exposes window.Tech4Time.nav for main.js to initialise.
   ========================================================================== */

(function (global) {
  "use strict";

  var FOCUSABLE = [
    "a[href]",
    "button:not([disabled])",
    "input:not([disabled])",
    "select:not([disabled])",
    "textarea:not([disabled])",
    '[tabindex]:not([tabindex="-1"])',
  ].join(",");

  /* Matches the max-width of the drawer breakpoint in layout.css (64em). */
  var DRAWER_QUERY = "(max-width: 63.999em)";

  var doc = document;
  var header;
  var toggle;
  var drawer;
  var lastFocused = null;

  function isOpen() {
    return drawer && drawer.getAttribute("data-open") === "true";
  }

  function focusableIn(element) {
    return Array.prototype.filter.call(
      element.querySelectorAll(FOCUSABLE),
      function (node) {
        return node.offsetParent !== null || node === doc.activeElement;
      }
    );
  }

  function open() {
    if (!drawer || isOpen()) return;

    lastFocused = doc.activeElement;
    drawer.setAttribute("data-open", "true");
    toggle.setAttribute("aria-expanded", "true");

    /* Lock the page behind the drawer without losing scroll position. */
    doc.body.style.overflow = "hidden";

    var focusable = focusableIn(drawer);
    if (focusable.length) {
      focusable[0].focus();
    }
  }

  function close(returnFocus) {
    if (!drawer || !isOpen()) return;

    drawer.setAttribute("data-open", "false");
    toggle.setAttribute("aria-expanded", "false");
    doc.body.style.overflow = "";

    if (returnFocus !== false && lastFocused && lastFocused.focus) {
      lastFocused.focus();
    }
    lastFocused = null;
  }

  function trapTab(event) {
    if (event.key !== "Tab" || !isOpen()) return;

    var focusable = focusableIn(drawer);
    if (!focusable.length) return;

    var first = focusable[0];
    var last = focusable[focusable.length - 1];

    if (event.shiftKey && doc.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && doc.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function onScroll() {
    if (!header) return;
    header.classList.toggle("site-header--scrolled", global.scrollY > 8);
  }

  function initDrawer() {
    toggle = doc.querySelector("[data-nav-toggle]");
    drawer = doc.querySelector("[data-nav-drawer]");
    if (!toggle || !drawer) return;

    toggle.addEventListener("click", function () {
      if (isOpen()) {
        close();
      } else {
        open();
      }
    });

    /* Following a link should dismiss the drawer; focus belongs to the
       destination page, so it is not restored to the toggle here. */
    drawer.addEventListener("click", function (event) {
      if (event.target.closest("a")) {
        close(false);
      }
    });

    doc.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && isOpen()) {
        close();
      }
      trapTab(event);
    });

    /* Resizing up to the desktop layout reveals the inline nav, so the drawer
       state must be cleared or the body would stay scroll-locked. */
    if (global.matchMedia) {
      var query = global.matchMedia(DRAWER_QUERY);
      var onChange = function (event) {
        if (!event.matches) {
          close(false);
        }
      };
      if (query.addEventListener) {
        query.addEventListener("change", onChange);
      } else if (query.addListener) {
        query.addListener(onChange);
      }
    }
  }

  function initBackToTop() {
    var button = doc.querySelector("[data-back-to-top]");
    if (!button) return;

    button.addEventListener("click", function () {
      var reduced =
        global.matchMedia &&
        global.matchMedia("(prefers-reduced-motion: reduce)").matches;

      global.scrollTo({ top: 0, behavior: reduced ? "auto" : "smooth" });

      /* Scrolling alone does not move focus, which would strand keyboard and
         screen-reader users at the bottom of the document. */
      var target = doc.getElementById("top") || doc.body;
      target.setAttribute("tabindex", "-1");
      target.focus({ preventScroll: true });
    });
  }

  function init() {
    header = doc.querySelector(".site-header");

    initDrawer();
    initBackToTop();

    global.addEventListener("scroll", onScroll, { passive: true });
    onScroll();
  }

  global.Tech4Time = global.Tech4Time || {};
  global.Tech4Time.nav = { init: init, close: close };
})(window);
