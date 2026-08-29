/* ==========================================================================
   Tech4TIME — admin-swap.js
   Moves between admin screens without throwing the page away.

   WHAT IT IS FOR
   Every link in the admin — the five in the icon rail, "Edit" on an overview
   card, "Add a job post", "Cancel", "Discard", "Your account" — was a full
   navigation. The document was torn down and rebuilt, which meant the rail was
   parsed, painted and re-initialised on every one of them: the menu visibly
   flashed open and shut on its way to the page you asked for, and the browser
   threw away 200 kilobytes of chrome that had not changed a byte.

   admin-forms.js had already solved this for the buttons INSIDE an editor. It
   swapped the answer into <main> and left the rest of the document alone. This
   is the other half of the same idea, applied to the links, and the mechanism
   both of them use now lives here.

   WHAT CHANGES AND WHAT DOES NOT
   Between two admin screens only #admin-body changes: the bar across the top
   and the editing column under it. The rail is the same markup on every screen
   except for which row carries aria-current, so the rail is left standing and
   only that one attribute is brought across. That is what keeps the account
   menu's open state, the width the rail was left at, and the scroll position
   inside a long menu.

   IT CHANGES NOTHING ON THE SERVER
   Deliberately, and for the same reason admin-forms.js does not: what comes
   back is the ordinary page, the same PHP rendering the same markup. There is
   no second rendering path to keep in step with the first and no JSON schema
   to version. Delete this file and every link still works, by navigating —
   which is the hard rule this project holds to.

   AND IT ASKS BEFORE WALKING AWAY FROM AN EDIT
   Following a rail item has always thrown away whatever was typed and not
   saved — it was a full navigation, and nothing warned about it. Now that the
   move is instant it is easier to do by accident, so a screen with unsaved
   changes asks first. The question is the enhancement's own: with no script
   there is no swap to intercept and nothing has changed.

   WHAT IT REFUSES TO TOUCH
   Anything that is not a plain left click on a same-document admin URL. A
   middle click, a modifier held down, target="_blank", a download, a link to
   another origin, an in-page anchor, and every path that is not the admin's
   own front door — login.php, logout.php, the public site — are all left to
   the browser. The test is deliberately narrow: a link this file does not
   recognise navigates, which is the behaviour it is replacing.
   ========================================================================== */

(function (global) {
  "use strict";

  var doc = global.document;

  var BODY = "admin-body";
  var MAIN = "admin-main";
  var RAIL_NAV = ".rail__nav";
  var BUSY = "admin__status--busy";
  var BAD = "admin__status--bad";

  /* A response from any of these is a session that has ended. Swapping a
     sign-in form into the editing column would leave somebody typing a
     password into a page that is not going to submit it anywhere. */
  var SIGNED_OUT = /\/(login|setup|reset|forgot)\.php/;

  /* WHETHER THIS SCREEN HOLDS SOMETHING THAT IS NOT ON DISK.
     Set by typing in the editing column, and by admin-forms.js after any post
     that was not a save — adding a row and moving one both leave the form
     holding something content/*.json does not. Cleared by a save, and by
     arriving somewhere new. */
  var touched = false;

  /* Rises with every request started here. A response whose number is no
     longer the current one is a screen nobody is waiting for any more —
     press three rail items quickly and only the last one may land. */
  var ticket = 0;

  function usable() {
    return (
      typeof global.fetch === "function" &&
      typeof global.DOMParser === "function" &&
      typeof global.URL === "function" &&
      global.history &&
      typeof global.history.pushState === "function" &&
      typeof global.history.replaceState === "function"
    );
  }

  /* ------------------------------------------------------------- the notice */

  function status(text, className) {
    var slots = doc.querySelectorAll("[data-form-status]");

    Array.prototype.forEach.call(slots, function (slot) {
      slot.textContent = text || "";
      slot.className = "admin__status" + (className ? " " + className : "");
    });
  }

  /* ------------------------------------------------- state the server cannot know

     The theme toggle is inside the bar, so a swap of #admin-body replaces the
     button with a fresh one carrying the markup's defaults — "Switch to dark
     mode", aria-pressed="false" — regardless of which mode is actually on.
     theme-toggle.js listens on the document and so still works, but its label
     would be a lie until the next full load.

     Carried across as attributes rather than by asking theme-toggle.js to run
     again: init() there adds a document-level listener, and a second one would
     make every press toggle twice and appear to do nothing. Copying what the
     outgoing button said needs no knowledge of what it means. */
  var CARRIED = ["aria-label", "aria-pressed", "title"];

  function carryState(from, to) {
    var old = from.querySelectorAll("[data-theme-toggle]");
    var fresh = to.querySelectorAll("[data-theme-toggle]");

    if (!old.length || old.length !== fresh.length) {
      return;
    }

    Array.prototype.forEach.call(old, function (button, i) {
      CARRIED.forEach(function (name) {
        var value = button.getAttribute(name);
        if (value !== null) {
          fresh[i].setAttribute(name, value);
        }
      });
    });
  }

  /* ------------------------------------------------------------- the swap */

  /**
   * Put a rendered admin page in place of the one on screen.
   *
   * region  "admin-body" for a move between screens, "admin-main" for the
   *         answer to a form post — the bar holds the status line that told
   *         somebody the post was working, and replacing it would wipe it.
   * entry   "push" adds a history entry (a link was followed), "replace"
   *         rewrites the current one (a form was posted, or the Back button
   *         brought us here), and anything else leaves history alone.
   */
  function apply(html, url, options) {
    var opts = options || {};
    var id = opts.region === MAIN ? MAIN : BODY;

    var incoming = new global.DOMParser().parseFromString(html, "text/html");
    var fresh = incoming.getElementById(id);
    var here = doc.getElementById(id);

    if (!fresh || !here) {
      /* Not a page of the shape we expected — an error page, a session that
         ended between the click and the answer. Let the browser have it. */
      global.location.href = url;
      return false;
    }

    carryState(here, fresh);
    here.innerHTML = fresh.innerHTML;

    if (incoming.title) {
      doc.title = incoming.title;
    }

    /* THE RAIL IS NOT REPLACED, so the row that is now current has to be told.
       Its list is the one part of the rail that differs between screens, and
       it holds nothing a person has changed — no open state, no scroll of its
       own — so taking the server's copy of it wholesale is both safe and
       proof against the two drifting. */
    if (id === BODY) {
      var railFrom = incoming.querySelector(RAIL_NAV);
      var railTo = doc.querySelector(RAIL_NAV);
      if (railFrom && railTo) {
        railTo.innerHTML = railFrom.innerHTML;
      }

      /* Arriving somewhere new with the account menu still hanging open is
         the menu outliving the press that used it. */
      Array.prototype.forEach.call(
        doc.querySelectorAll("details[data-account][open]"),
        function (menu) { menu.open = false; }
      );
    }

    /* The address bar should say what was actually served, so that a reload
       repeats the state on screen rather than the one before it. */
    try {
      if (opts.entry === "push") {
        global.history.pushState({ t4t: true }, "", url);
      } else if (opts.entry === "replace") {
        global.history.replaceState({ t4t: true }, "", url);
      }
    } catch (error) {
      /* A cross-origin URL would throw, and we have already refused those. */
    }

    var api = global.Tech4Time;
    if (api && api.editor) {
      api.editor.init();
    }

    return true;
  }

  /* --------------------------------------------------------------- going */

  function busy(state) {
    var body = doc.getElementById(BODY);
    if (!body) {
      return;
    }
    if (state) {
      body.setAttribute("aria-busy", "true");
    } else {
      body.removeAttribute("aria-busy");
    }
  }

  /**
   * Where to look after arriving.
   *
   * <main> rather than the heading: it is what the skip link already points
   * at, so a screen reader is put at the top of the new screen and reads its
   * heading from there, and a sighted keyboard user's next Tab is the first
   * control on the page rather than wherever the rail had got to.
   */
  function land() {
    global.scrollTo(0, 0);

    var main = doc.getElementById(MAIN);
    if (main && typeof main.focus === "function") {
      main.focus();
    }
  }

  function visit(url, options) {
    var opts = options || {};
    var mine = ++ticket;

    busy(true);
    status("Loading…", BUSY);

    global
      .fetch(url, {
        credentials: "same-origin",
        redirect: "follow",
        headers: { "X-Requested-With": "fetch" }
      })
      .then(function (response) {
        var landed = response.url || url;

        if (SIGNED_OUT.test(landed)) {
          global.location.href = landed;
          return null;
        }

        if (!response.ok) {
          throw new Error("The server answered " + response.status + ".");
        }

        return response.text().then(function (html) {
          return { html: html, url: landed };
        });
      })
      .then(function (result) {
        if (!result || mine !== ticket) {
          return;
        }

        busy(false);

        if (apply(result.html, result.url, {
          region: BODY,
          entry: opts.entry === false ? null : (opts.entry || "push")
        })) {
          touched = false;
          status("");
          land();
        }
      })
      .catch(function (error) {
        if (mine !== ticket) {
          return;
        }
        busy(false);
        status(
          "Not loaded — " +
            (error && error.message ? error.message : "the connection failed") +
            " Press again, or reload.",
          BAD
        );
      });
  }

  /* ---------------------------------------------------------------- wiring */

  /**
   * Whether this click is one to answer instead of the browser.
   *
   * Everything here is a reason to LEAVE IT ALONE, and the list is meant to
   * be long: a link this does not recognise navigates, and navigating is
   * correct. The dangerous mistake would be the other way round.
   */
  function swappable(link, event) {
    if (event.defaultPrevented || event.button !== 0) {
      return false;
    }
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return false;
    }
    if (!link || !link.href || link.hasAttribute("download")) {
      return false;
    }
    if (link.target && link.target !== "_self") {
      return false;
    }
    if (link.hasAttribute("data-no-swap")) {
      return false;
    }

    /* An in-page anchor — the "On this page" column. Tested on the attribute
       rather than the resolved URL: `#milestones` resolves to a URL with no
       query string at all, which would read as a link to the overview. */
    var written = link.getAttribute("href") || "";
    if (written.charAt(0) === "#") {
      return false;
    }

    var url;
    try {
      url = new global.URL(link.href, global.location.href);
    } catch (error) {
      return false;
    }

    if (url.origin !== global.location.origin) {
      return false;
    }

    /* THE ADMIN'S OWN FRONT DOOR AND NOTHING ELSE. Every screen is the same
       PHP file with a different ?s=, so a link that leaves this path is a
       different document — login.php, logout.php, setup.php — and those are
       whole-page navigations by right. */
    if (url.pathname !== global.location.pathname) {
      return false;
    }

    return new global.URLSearchParams(url.search).has("s");
  }

  var LEAVING =
    "This screen has changes that have not been saved.\n\n" +
    "Leave it and lose them?";

  function noteChange(event) {
    var main = doc.getElementById(MAIN);
    if (main && event.target && main.contains(event.target)) {
      touched = true;
    }
  }

  function wire() {
    doc.addEventListener("click", function (event) {
      var link = event.target.closest && event.target.closest("a[href]");
      if (!link || !swappable(link, event)) {
        return;
      }

      event.preventDefault();

      /* preventDefault FIRST, and unconditionally. Asking before deciding
         whether to leave means a "no" leaves the page exactly as it was,
         rather than following the link anyway while the answer was being
         given. */
      if (touched && !global.confirm(LEAVING)) {
        return;
      }

      visit(link.href, { entry: "push" });
    });

    /* Typing anywhere in the editing column. On the document and in the bubble
       phase, so it survives every swap and so it hears a contenteditable — the
       rich-text surfaces raise `input` like any other field, and what they are
       about to write into their textarea is exactly the kind of work worth not
       losing. */
    doc.addEventListener("input", noteChange);
    doc.addEventListener("change", noteChange);

    /* Back and Forward. The entry being returned to is already in history, so
       this must not add one — and it must not replace it either, or Forward
       would have nothing to return to. */
    global.addEventListener("popstate", function () {
      visit(global.location.href, { entry: false });
    });
  }

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.adminSwap = {
    usable: usable,
    apply: apply,
    visit: visit,
    status: status,

    /* admin-forms.js says so after every post: a save clears it, and adding,
       moving or removing a row sets it, because the row exists in the form and
       nowhere else until Save is pressed. */
    touched: function (state) {
      touched = state === true;
    },
    unsaved: function () {
      return touched;
    },

    init: function () {
      if (!usable() || api.adminSwap.wired) {
        return;
      }

      /* The entry the browser started on carries no state of ours. Marking it
         means a popstate back to it is recognisably ours rather than
         something another script pushed. */
      try {
        global.history.replaceState({ t4t: true }, "", global.location.href);
      } catch (error) {
        /* Nothing to do: history is only how the address bar keeps up. */
      }

      wire();
      api.adminSwap.wired = true;
    },
    wired: false
  };
})(window);
