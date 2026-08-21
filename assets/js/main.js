/* ==========================================================================
   Tech4TIME — main.js
   Bootstrap. Loaded last, after every other module has registered itself on
   window.Tech4Time, and runs each module's init().

   Every module is optional: a page that does not ship forms.js simply has no
   Tech4Time.forms to initialise, and a module that throws is contained so one
   broken feature cannot take the rest of the page's behaviour down with it.
   ========================================================================== */

(function (global) {
  "use strict";

  var MODULES = ["theme", "nav", "animations", "forms", "dashboard",
                 "techSphere", "slider", "terminal"];

  /**
   * Keep the footer copyright current.
   *
   * The year is written into the HTML so it is present for crawlers and for
   * visitors without JavaScript; this only corrects it once the calendar moves
   * past the year the pages were generated in.
   */
  function refreshCopyrightYear() {
    var year = String(new Date().getFullYear());
    var nodes = document.querySelectorAll("[data-current-year]");
    Array.prototype.forEach.call(nodes, function (node) {
      if (node.textContent.trim() !== year) {
        node.textContent = year;
      }
    });
  }

  function start() {
    var api = global.Tech4Time || {};

    refreshCopyrightYear();

    MODULES.forEach(function (name) {
      var module = api[name];
      if (!module || typeof module.init !== "function") {
        return;
      }
      try {
        module.init();
      } catch (error) {
        /* Keep the failure visible to developers without breaking the page. */
        if (global.console && global.console.error) {
          global.console.error("Tech4Time: " + name + " failed to init", error);
        }
      }
    });
  }

  /* Scripts are deferred, so the parser has finished by the time this runs.
     The readyState guard covers the case where a page loads them differently. */
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})(window);
