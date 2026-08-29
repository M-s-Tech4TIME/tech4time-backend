/* ==========================================================================
   Tech4TIME — theme-toggle.js
   Wires the header's light/dark switch.

   Works alongside theme-init.js, which has already applied any stored choice
   before paint. This module owns the interactive half: resolving the current
   mode, flipping it, persisting the choice, and keeping the button's accessible
   label in step.

   Exposes window.Tech4Time.theme for main.js to initialise.
   ========================================================================== */

(function (global) {
  "use strict";

  var STORAGE_KEY = "tech4time-theme";
  var TOGGLE_SELECTOR = "[data-theme-toggle]";
  var TRANSITION_CLASS = "theme-transition";

  /**
   * The mode currently in effect: an explicit choice if one is set, otherwise
   * whatever the OS prefers.
   */
  function current() {
    var explicit = document.documentElement.getAttribute("data-theme");
    if (explicit === "light" || explicit === "dark") {
      return explicit;
    }
    return global.matchMedia &&
      global.matchMedia("(prefers-color-scheme: dark)").matches
      ? "dark"
      : "light";
  }

  function persist(theme) {
    try {
      global.localStorage.setItem(STORAGE_KEY, theme);
    } catch (error) {
      /* Storage unavailable — the choice simply will not survive a reload. */
    }
  }

  function labelFor(theme) {
    return theme === "dark" ? "Switch to light mode" : "Switch to dark mode";
  }

  function syncButtons(theme) {
    var buttons = document.querySelectorAll(TOGGLE_SELECTOR);
    Array.prototype.forEach.call(buttons, function (button) {
      button.setAttribute("aria-label", labelFor(theme));
      button.setAttribute("title", labelFor(theme));
      /* The button controls a site-wide setting rather than a widget, so
         aria-pressed communicates the current state to screen readers. */
      button.setAttribute("aria-pressed", theme === "dark" ? "true" : "false");
    });
  }

  function apply(theme) {
    document.documentElement.setAttribute("data-theme", theme);
    persist(theme);
    syncButtons(theme);
  }

  /**
   * Flip the mode.
   *
   * WHY A VIEW TRANSITION. Changing the mode changes a colour on very nearly
   * every element, and animating that with CSS means animating it per element:
   * on the largest admin screen that is 3,024 of them, five properties each,
   * all at once. It was slow enough to read as the page struggling, and it was
   * reported that way.
   *
   * startViewTransition() takes a picture of the page as it is, applies the
   * change, takes another, and cross-fades the two on the compositor. One
   * layer, one animation, and the cost no longer has anything to do with how
   * much is on the page.
   *
   * Where it does not exist the change is applied directly and theme.css eases
   * the page's own background and text colour — a cheap two-element fallback
   * rather than the fifteen thousand animations this replaced.
   *
   * Skipped outright for anyone who asked for less motion: a cross-fade of the
   * whole screen is exactly the kind of thing that setting is about.
   */
  function toggle() {
    var next = current() === "dark" ? "light" : "dark";

    var wants = !(
      global.matchMedia &&
      global.matchMedia("(prefers-reduced-motion: reduce)").matches
    );

    if (wants && typeof document.startViewTransition === "function") {
      document.startViewTransition(function () { apply(next); });
      return;
    }

    apply(next);
  }

  function init() {
    syncButtons(current());

    /* Enable colour transitions only after the first paint, so loading the page
       does not animate every surface from the wrong colour. */
    global.requestAnimationFrame(function () {
      global.requestAnimationFrame(function () {
        document.documentElement.classList.add(TRANSITION_CLASS);
      });
    });

    document.addEventListener("click", function (event) {
      var button = event.target.closest(TOGGLE_SELECTOR);
      if (button) {
        toggle();
      }
    });

    /* Follow the OS if the visitor has never made an explicit choice. */
    if (global.matchMedia) {
      var query = global.matchMedia("(prefers-color-scheme: dark)");
      var onChange = function () {
        var stored = null;
        try {
          stored = global.localStorage.getItem(STORAGE_KEY);
        } catch (error) {
          /* ignore */
        }
        if (stored !== "light" && stored !== "dark") {
          syncButtons(current());
        }
      };

      if (query.addEventListener) {
        query.addEventListener("change", onChange);
      } else if (query.addListener) {
        query.addListener(onChange);
      }
    }
  }

  global.Tech4Time = global.Tech4Time || {};
  global.Tech4Time.theme = { init: init, toggle: toggle, current: current };
})(window);
