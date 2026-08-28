/* ==========================================================================
   Tech4TIME — admin-forms.js
   Submits the editors without throwing the page away.

   WHAT IT IS FOR
   Every control in these editors is a submit button: adding a row, removing
   one, moving one up, saving. Each of those was a full navigation, and a full
   navigation lands at the top of the document. On the company profile — ten
   bands, 282 rows, 448 fields — that meant pressing "Move down" on a client
   logo and arriving back at the page title, several thousand pixels away from
   the thing you were arranging. Doing it twice was a scroll each way. The
   effect was that the page appeared to contain only its first field group,
   because that is the only part anybody ever saw.

   This posts the same form to the same URL, puts the answer back in place,
   and leaves the scroll position and the focus where they were.

   IT CHANGES NOTHING ON THE SERVER
   Deliberately. The response is the ordinary page: the same PHP, the same
   redirect-after-save, the same markup. fetch() follows the redirect exactly
   as the browser would, and what comes back is swapped into <main>. So there
   is no second rendering path to keep in step with the first, no JSON schema
   to version, and nothing that can be true of one and false of the other.
   Delete this file and every button still works, by navigating — which is the
   hard rule this project holds to, and the reason it was built this way.

   WHY NOT innerHTML ON THE WHOLE DOCUMENT
   The rail carries the open/closed state of the account menu and the width the
   person chose. Only #admin-main changes between these responses, so only
   #admin-main is replaced.
   ========================================================================== */

(function (global) {
  "use strict";

  var doc = global.document;

  var MAIN = "admin-main";
  var BUSY = "admin__status--busy";
  var BAD = "admin__status--bad";

  /* Everything this needs. Any one of them missing and the forms are left
     alone, which means they navigate — the behaviour this replaces. */
  function usable() {
    return (
      typeof global.fetch === "function" &&
      typeof global.FormData === "function" &&
      typeof global.DOMParser === "function" &&
      global.history &&
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

  /* ------------------------------------------------- where to look afterwards

     A reorder renumbers the rows it moves between, so the button that was
     pressed is no longer the button for that row: press "down" on row 3 and
     the row is now row 4. Following it means a second press does what the
     first appeared to promise, which is what makes arranging fifty logos with
     the keyboard possible at all.

     Anything not understood here falls back to the field with the same name,
     and failing that to nothing. Guessing wrongly costs a focus ring; it
     cannot lose an edit. */
  function nextFocus(submitter) {
    if (!submitter || submitter.name !== "do") {
      return null;
    }

    var parts = /^([a-z]+)-(up|down|add|remove):(\d+)$/.exec(submitter.value || "");
    if (!parts) {
      return null;
    }

    var band = parts[1];
    var action = parts[2];
    var index = parseInt(parts[3], 10);

    if (action === "up") {
      return { selector: 'button[name="do"][value="' + band + "-up:" + Math.max(0, index - 1) + '"]' };
    }
    if (action === "down") {
      return { selector: 'button[name="do"][value="' + band + "-down:" + (index + 1) + '"]' };
    }
    if (action === "add") {
      /* The new row is the last one in its band; its first text field is
         where somebody is about to type. */
      return { lastRowOf: band };
    }
    /* Removed. The row that took its place, or the one before it if the list
       just lost its tail. */
    return {
      selector:
        'button[name="do"][value="' + band + "-remove:" + index + '"], ' +
        'button[name="do"][value="' + band + "-remove:" + Math.max(0, index - 1) + '"]'
    };
  }

  /* What had focus, and where the page was. */
  function snapshot() {
    var active = doc.activeElement;
    var shot = { scroll: global.scrollY || global.pageYOffset || 0, name: "", start: null, end: null };

    if (active && active.name && active.form) {
      shot.name = active.name;
      try {
        shot.start = active.selectionStart;
        shot.end = active.selectionEnd;
      } catch (error) {
        /* Not a field with a caret — a select, a checkbox, a button. */
      }
    }

    return shot;
  }

  function focusFirstFieldOf(band) {
    var rows = doc.querySelectorAll('input[name^="' + band + '[items]["]');
    if (!rows.length) {
      return false;
    }

    /* The last row's first field that somebody can actually type in: the id
       is a hidden input and would swallow the focus silently. */
    var last = rows[rows.length - 1].closest(".admin-card");
    var field = last && last.querySelector("input:not([type=hidden]), textarea, select");

    if (field) {
      field.focus();
      field.scrollIntoView({ block: "center" });
      return true;
    }

    return false;
  }

  function restore(shot, plan) {
    if (plan && plan.lastRowOf && focusFirstFieldOf(plan.lastRowOf)) {
      return;
    }

    if (plan && plan.selector) {
      var target = doc.querySelector(plan.selector);
      if (target) {
        global.scrollTo(0, shot.scroll);
        target.focus();
        return;
      }
    }

    global.scrollTo(0, shot.scroll);

    if (!shot.name) {
      return;
    }

    var field = doc.querySelector('[name="' + shot.name.replace(/"/g, '\\"') + '"]');
    if (!field || typeof field.focus !== "function") {
      return;
    }

    field.focus();

    if (shot.start !== null && typeof field.setSelectionRange === "function") {
      try {
        field.setSelectionRange(shot.start, shot.end);
      } catch (error) {
        /* A field type that has no caret. Focus is the part that mattered. */
      }
    }
  }

  /* ------------------------------------------------------------- the swap */

  function swap(html, url) {
    var incoming = new global.DOMParser().parseFromString(html, "text/html");
    var fresh = incoming.getElementById(MAIN);
    var here = doc.getElementById(MAIN);

    if (!fresh || !here) {
      /* Not a page of the shape we expected — a session that ended, an error
         page from the server. Let the browser have it. */
      global.location.href = url;
      return false;
    }

    here.innerHTML = fresh.innerHTML;

    if (incoming.title) {
      doc.title = incoming.title;
    }

    /* The address bar should say what was actually served, so that a reload
       repeats the state on screen rather than the one before it. */
    try {
      global.history.replaceState({}, "", url);
    } catch (error) {
      /* A cross-origin URL would throw, and we have already refused those. */
    }

    var api = global.Tech4Time;
    if (api && api.editor) {
      api.editor.init();
    }

    return true;
  }

  /* An error the person needs to read is worth moving the page for. */
  function showProblem() {
    var problem = doc.querySelector(".admin__notice--error, .admin__notice--warn");
    if (problem) {
      problem.scrollIntoView({ block: "center" });
      return true;
    }
    return false;
  }

  /* --------------------------------------------------------------- sending */

  function send(form, submitter) {
    var confirmation = form.getAttribute("data-confirm");
    if (confirmation && !global.confirm(confirmation)) {
      return;
    }

    var body = new global.FormData(form);

    /* FormData never includes submit buttons, so the one that was pressed has
       to be added by hand — and it is the whole instruction here: "do" is what
       says save, or add, or move this row up. */
    if (submitter && submitter.name) {
      body.append(submitter.name, submitter.value);
    }

    var shot = snapshot();
    var plan = nextFocus(submitter);

    form.setAttribute("aria-busy", "true");
    if (submitter) {
      submitter.disabled = true;
    }
    status("Working…", BUSY);

    global
      .fetch(form.action || global.location.href, {
        method: "POST",
        body: body,
        credentials: "same-origin",
        redirect: "follow",
        headers: { "X-Requested-With": "fetch" }
      })
      .then(function (response) {
        var url = response.url || form.action;

        /* A session that has ended redirects to the sign-in page. Swapping
           that into <main> would leave somebody typing into a form that is no
           longer attached to anything. */
        if (/\/(login|setup|reset|forgot)\.php/.test(url)) {
          global.location.href = url;
          return null;
        }

        if (!response.ok) {
          throw new Error("The server answered " + response.status + ".");
        }

        return response.text().then(function (html) {
          return { html: html, url: url };
        });
      })
      .then(function (result) {
        if (!result) {
          return;
        }

        if (!swap(result.html, result.url)) {
          return;
        }

        if (!showProblem()) {
          restore(shot, plan);
        }
      })
      .catch(function (error) {
        form.removeAttribute("aria-busy");
        if (submitter) {
          submitter.disabled = false;
        }
        status(
          "Not sent — " + (error && error.message ? error.message : "the connection failed") +
            " Nothing was lost; press again.",
          BAD
        );
      });
  }

  /* ----------------------------------------------------------------- wiring

     One listener on the document, in the bubble phase, so it survives every
     swap and so editor.js's own submit handler — which copies the rich text
     back into its textarea — has already run by the time this reads the form.
     Binding per form would mean rebinding after each swap and would put this
     first. */
  function wire() {
    var pressed = null;

    doc.addEventListener("click", function (event) {
      var button = event.target.closest && event.target.closest("button, input[type=submit]");
      pressed = button && button.form ? button : null;
    });

    doc.addEventListener("submit", function (event) {
      var form = event.target;

      if (!form.matches || !form.matches("form[data-async]")) {
        return;
      }

      var submitter = event.submitter || pressed;
      pressed = null;

      event.preventDefault();
      send(form, submitter);
    });
  }

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.adminForms = {
    init: function () {
      if (!usable() || api.adminForms.wired) {
        return;
      }
      wire();
      api.adminForms.wired = true;
    },
    wired: false
  };
})(window);
