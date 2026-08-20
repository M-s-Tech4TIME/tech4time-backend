/* ==========================================================================
   Tech4TIME — dashboard.js
   The tabbed panels on the service detail pages (the NextJS dashboards'
   Proactive/Reactive style switches, ported to static markup).

   PROGRESSIVE ENHANCEMENT
   The markup ships as an ordinary list of in-page links followed by every
   panel, all visible. That works with no JavaScript at all, and it means the
   full text of every panel is in the document for crawlers and for Find-in-page
   before this file runs. Only once it does run are the links promoted to an
   ARIA tab set and the inactive panels hidden.

   Keyboard behaviour follows the APG tabs pattern: arrows move between tabs,
   Home/End jump to the ends, and the selected tab is the only one in the tab
   order, so Tab moves out of the tab list into the panel.
   ========================================================================== */

(function (global) {
  "use strict";

  var doc = global.document;

  /* Panels are revealed by removing `hidden`, so nothing here depends on a
     class the stylesheet has to know about. */
  function show(panel, visible) {
    if (visible) {
      panel.removeAttribute("hidden");
    } else {
      panel.setAttribute("hidden", "");
    }
  }

  function Tabs(root) {
    this.root = root;
    this.tabs = Array.prototype.slice.call(
      root.querySelectorAll("[data-tabs-tab]")
    );
    this.panels = this.tabs.map(function (tab) {
      var id = (tab.getAttribute("href") || "").replace(/^#/, "");
      return id ? doc.getElementById(id) : null;
    });
  }

  Tabs.prototype.usable = function () {
    return (
      this.tabs.length > 1 &&
      this.panels.every(function (panel) {
        return Boolean(panel);
      })
    );
  };

  Tabs.prototype.select = function (index, moveFocus) {
    var self = this;

    this.tabs.forEach(function (tab, i) {
      var selected = i === index;
      tab.setAttribute("aria-selected", selected ? "true" : "false");
      /* Roving tabindex: only the selected tab is reachable with Tab. */
      tab.setAttribute("tabindex", selected ? "0" : "-1");
      show(self.panels[i], selected);
    });

    if (moveFocus) {
      this.tabs[index].focus();
    }
    this.index = index;
  };

  Tabs.prototype.onKeydown = function (event) {
    var last = this.tabs.length - 1;
    var next;

    switch (event.key) {
      case "ArrowRight":
      case "ArrowDown":
        next = this.index === last ? 0 : this.index + 1;
        break;
      case "ArrowLeft":
      case "ArrowUp":
        next = this.index === 0 ? last : this.index - 1;
        break;
      case "Home":
        next = 0;
        break;
      case "End":
        next = last;
        break;
      default:
        return;
    }

    event.preventDefault();
    this.select(next, true);
  };

  Tabs.prototype.init = function () {
    var self = this;
    var list = this.root.querySelector("[data-tabs-list]");

    if (list) {
      list.setAttribute("role", "tablist");
    }

    this.tabs.forEach(function (tab, i) {
      var panel = self.panels[i];

      tab.setAttribute("role", "tab");
      tab.setAttribute("aria-controls", panel.id);
      if (!tab.id) {
        tab.id = panel.id + "-tab";
      }

      panel.setAttribute("role", "tabpanel");
      panel.setAttribute("aria-labelledby", tab.id);
      /* Panels hold headings and links, so they are not focusable themselves;
         tabindex="0" would add a stop that lands on nothing useful. */

      tab.addEventListener("click", function (event) {
        event.preventDefault();
        self.select(i, false);
      });

      tab.addEventListener("keydown", function (event) {
        self.onKeydown(event);
      });
    });

    /* A link straight to one of the panels — from another page, or a shared
       URL — should open on that panel rather than the first one. */
    var fromHash = this.panels.findIndex(function (panel) {
      return "#" + panel.id === global.location.hash;
    });

    this.select(fromHash > -1 ? fromHash : 0, false);
  };

  /* ------------------------------------------------------------------------
     Solution map

     Each node on the ring points at one of the solution cards further down the
     page by id. Moving onto a node copies that card into the detail slot beside
     the map — the same gesture as the NextJS dashboard, but with the card's
     markup as the single source of truth, so the two can never disagree.

     Nodes are real buttons, so this answers to pointer, keyboard and touch
     alike. The detail slot is deliberately NOT a live region: the button's own
     name already says which solution you are on, aria-pressed says it is the
     selected one, and the same card sits in the grid below — announcing the
     whole card again on every hover would be noise, not help.
     ---------------------------------------------------------------------- */

  function SolutionMap(root) {
    this.root = root;
    this.detail = root.querySelector("[data-solution-detail]");
    this.nodes = Array.prototype.slice.call(
      root.querySelectorAll("[data-solution]")
    );
  }

  SolutionMap.prototype.usable = function () {
    return Boolean(this.detail) && this.nodes.length > 0;
  };

  SolutionMap.prototype.show = function (node) {
    var card = doc.getElementById(node.getAttribute("data-solution"));
    if (!card || node === this.current) {
      return;
    }

    var copy = card.cloneNode(true);
    /* The original keeps the id; a duplicate of it would be invalid. */
    copy.removeAttribute("id");
    copy.classList.add("tool-card--detail");

    this.detail.innerHTML = "";
    this.detail.appendChild(copy);

    this.nodes.forEach(function (other) {
      other.setAttribute("aria-pressed", other === node ? "true" : "false");
    });
    this.current = node;
  };

  SolutionMap.prototype.init = function () {
    var self = this;

    this.nodes.forEach(function (node) {
      node.setAttribute("aria-pressed", "false");

      /* mouseenter for hover, focus for the keyboard, click for touch — where
         no hover exists and focus follows the tap anyway. */
      ["mouseenter", "focus", "click"].forEach(function (type) {
        node.addEventListener(type, function () {
          self.show(node);
        });
      });
    });

    /* The slot ships with the first solution already rendered, so it is never
       empty; mark the matching node to match. */
    this.nodes[0].setAttribute("aria-pressed", "true");
    this.current = this.nodes[0];
  };

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.dashboard = {
    init: function () {
      var roots = doc.querySelectorAll("[data-tabs]");

      Array.prototype.forEach.call(roots, function (root) {
        var tabs = new Tabs(root);
        /* Markup that does not line up (a tab pointing at a panel that is not
           on the page) is left exactly as it shipped: everything visible. */
        if (tabs.usable()) {
          tabs.init();
        }
      });

      var maps = doc.querySelectorAll("[data-solution-map]");

      Array.prototype.forEach.call(maps, function (root) {
        var map = new SolutionMap(root);
        if (map.usable()) {
          map.init();
        }
      });
    }
  };
})(window);
