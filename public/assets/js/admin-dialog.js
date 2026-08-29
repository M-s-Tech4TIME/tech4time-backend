/* ==========================================================================
   Tech4TIME — admin-dialog.js
   The admin's own question box, instead of the browser's.

   WHAT IT REPLACES
   window.confirm(). That box belongs to the browser: it is drawn in the
   browser's colours, sits outside the page, cannot carry the difference
   between "Delete this post" and "Leave without saving", and in several
   browsers announces the site's own domain above the question as though the
   page were something to be suspicious of. It is also the one piece of this
   admin that never learned the dark theme.

   WHAT IT IS BUILT ON
   <dialog> and showModal(). The element is the browser's, and that is the
   point: focus is trapped for as long as it is open, Escape closes it, the
   rest of the page is made inert, and focus returns to whatever opened it —
   all without a line of code here. What is ours is the markup, the wording,
   the colours and the blurred backdrop. Reimplementing the first list is how
   custom dialogs end up unusable with a keyboard.

   IT IS ASYNCHRONOUS AND confirm() IS NOT, which is the whole cost of the
   change: every caller has to be written as "ask, then act" rather than "ask
   and carry on". ask() always answers a promise, including on the fallback
   path, so no caller has to know which one it got.

   WITHOUT IT — no <dialog>, no showModal, no Promise — ask() falls back to
   window.confirm(). Plain, ugly, and still asks the question.

   ONE THING IT CANNOT REPLACE: the prompt shown when a page is reloaded or
   closed with unsaved work. That one is the browser's by design — a page is
   not allowed to draw it, word it, or style it, precisely so that a site
   cannot fake or suppress it. See admin-swap.js.
   ========================================================================== */

(function (global) {
  "use strict";

  var doc = global.document;

  function usable() {
    return (
      typeof global.Promise === "function" &&
      typeof global.HTMLDialogElement === "function" &&
      typeof global.HTMLDialogElement.prototype.showModal === "function"
    );
  }

  function build(options) {
    var dialog = doc.createElement("dialog");
    dialog.className = "dialog" + (options.tone === "danger" ? " dialog--danger" : "");

    var card = doc.createElement("div");
    card.className = "dialog__card";

    if (options.title) {
      var heading = doc.createElement("h2");
      heading.className = "dialog__title";
      heading.textContent = options.title;
      card.appendChild(heading);
    }

    /* textContent throughout. Everything shown here is written in this
       repository, but a question box that can be handed markup is a question
       box that will one day be handed a field value. */
    var text = doc.createElement("p");
    text.className = "dialog__text";
    text.textContent = options.message || "";
    card.appendChild(text);

    var actions = doc.createElement("div");
    actions.className = "dialog__actions";

    /* The way out is first in the source, so it is the first thing reached by
       a keyboard and the one Escape agrees with. */
    var no = doc.createElement("button");
    no.className = "btn btn--ghost";
    no.type = "button";
    no.textContent = options.cancel || "Cancel";
    no.setAttribute("data-answer", "no");

    /* btn--primary either way. This palette is monochrome by design — there
       is no red in it and inventing one would be the first hex outside
       theme.css. What marks a destructive answer is the wording on it and the
       fact that the safe button holds the focus. */
    var yes = doc.createElement("button");
    yes.className = "btn btn--primary";
    yes.type = "button";
    yes.textContent = options.confirm || "Continue";
    yes.setAttribute("data-answer", "yes");

    actions.appendChild(no);
    actions.appendChild(yes);
    card.appendChild(actions);
    dialog.appendChild(card);

    return { dialog: dialog, yes: yes, no: no };
  }

  /**
   * Ask a yes/no question. Answers a promise for true or false.
   *
   * ask("Delete this post permanently?")
   * ask({ title: …, message: …, confirm: "Delete", tone: "danger" })
   */
  function ask(options) {
    var opts = typeof options === "string" ? { message: options } : (options || {});

    if (!usable()) {
      var answer = global.confirm(
        (opts.title ? opts.title + "\n\n" : "") + (opts.message || "")
      );
      return {
        then: function (fn) { fn(answer); return this; }
      };
    }

    return new global.Promise(function (resolve) {
      var parts = build(opts);
      var settled = false;

      function finish(value) {
        if (settled) {
          return;
        }
        settled = true;
        resolve(value);

        /* Removed rather than kept and reused: the next question may be a
           different one, and a stale dialog in the document is a stale dialog
           somebody's script will one day open. */
        if (parts.dialog.open) {
          parts.dialog.close();
        }
        if (parts.dialog.parentNode) {
          parts.dialog.parentNode.removeChild(parts.dialog);
        }
      }

      parts.yes.addEventListener("click", function () { finish(true); });
      parts.no.addEventListener("click", function () { finish(false); });

      /* Escape, and the backdrop being clicked, both arrive here. Neither is
         a yes. */
      parts.dialog.addEventListener("cancel", function (event) {
        event.preventDefault();
        finish(false);
      });
      parts.dialog.addEventListener("close", function () { finish(false); });

      /* A click that lands on the <dialog> itself rather than on the card
         inside it is a click on the backdrop. */
      parts.dialog.addEventListener("click", function (event) {
        if (event.target === parts.dialog) {
          finish(false);
        }
      });

      doc.body.appendChild(parts.dialog);
      parts.dialog.showModal();

      /* The safe answer takes the focus. Nobody should be able to dismiss
         unsaved work by pressing Enter on a dialog they have not read. */
      parts.no.focus();
    });
  }

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.adminDialog = {
    ask: ask,
    usable: usable
  };
})(window);
