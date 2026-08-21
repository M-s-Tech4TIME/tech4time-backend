/* ==========================================================================
   Tech4TIME — editor.js
   A small rich text editor for the job post fields in /admin/.

   PROGRESSIVE ENHANCEMENT
   Every field ships as an ordinary <textarea> holding HTML. This replaces it
   with a formatting surface and keeps the two in sync; if the file never
   loads, the textarea is still there and still saves. Nothing depends on this
   running.

   WHY NOT A LIBRARY
   The CSP is script-src 'self', so nothing loads from a CDN, and there is no
   build step to bundle a package with. Writing the few commands actually
   needed is smaller than the machinery required to vendor an editor.

   ALIGNMENT IS A CLASS, NOT A STYLE
   document.execCommand("justifyCenter") writes style="text-align:center".
   The CSP is style-src 'self', so that attribute is blocked on the public
   page: it would look right here and do nothing there. Alignment is applied
   by toggling a class on the block element instead, which is also what the
   server keeps — see careers_sanitise_html() in lib/careers.php.

   ON execCommand
   Deprecated, and still the only thing every browser implements for inline
   formatting. The alternative is a selection-and-range engine of our own,
   which is a large amount of subtle code to write and get wrong. Where its
   output is unacceptable — alignment — it is not used.

   WHATEVER THIS PRODUCES IS RE-SANITISED ON THE SERVER. This file is a
   convenience for whoever is typing, not a security boundary; the allow-list
   that matters runs in PHP.
   ========================================================================== */

(function (global) {
  "use strict";

  var doc = global.document;

  var ALIGNMENTS = ["ta-left", "ta-center", "ta-right", "ta-justify"];

  /* label, aria-label, command. `block` marks the ones that act on the
     paragraph rather than the selection. */
  var TOOLS = [
    { name: "bold", label: "B", title: "Bold (Ctrl+B)", command: "bold", className: "rte__btn--bold" },
    { name: "italic", label: "I", title: "Italic (Ctrl+I)", command: "italic", className: "rte__btn--italic" },
    { name: "underline", label: "U", title: "Underline (Ctrl+U)", command: "underline", className: "rte__btn--underline" },
    { separator: true },
    { name: "ul", label: "•—", title: "Bulleted list", command: "insertUnorderedList" },
    { name: "ol", label: "1.", title: "Numbered list", command: "insertOrderedList" },
    { name: "link", label: "🔗", title: "Insert link", command: "createLink" },
    { separator: true },
    { name: "ta-left", label: "◧", title: "Align left", align: "ta-left" },
    { name: "ta-center", label: "◫", title: "Align centre", align: "ta-center" },
    { name: "ta-right", label: "◨", title: "Align right", align: "ta-right" },
    { name: "ta-justify", label: "▤", title: "Justify", align: "ta-justify" }
  ];

  function Editor(textarea) {
    this.textarea = textarea;
    this.buttons = [];

    this.root = doc.createElement("div");
    this.root.className = "rte";

    this.toolbar = doc.createElement("div");
    this.toolbar.className = "rte__toolbar";
    this.toolbar.setAttribute("role", "toolbar");
    this.toolbar.setAttribute("aria-label", "Text formatting");

    this.surface = doc.createElement("div");
    this.surface.className = "rte__surface";
    this.surface.setAttribute("contenteditable", "true");
    this.surface.setAttribute("role", "textbox");
    this.surface.setAttribute("aria-multiline", "true");

    /* The textarea's own <span class="admin__label"> already names this field;
       pointing at it means the editor is announced with the same name rather
       than as an anonymous text box. */
    var label = textarea.closest(".admin__field");
    var labelText = label ? label.querySelector(".admin__label") : null;
    if (labelText) {
      if (!labelText.id) {
        labelText.id = "rte-label-" + textarea.name;
      }
      this.surface.setAttribute("aria-labelledby", labelText.id);
    }
  }

  Editor.prototype.build = function () {
    var self = this;

    TOOLS.forEach(function (tool) {
      if (tool.separator) {
        var hr = doc.createElement("span");
        hr.className = "rte__separator";
        hr.setAttribute("aria-hidden", "true");
        self.toolbar.appendChild(hr);
        return;
      }

      var button = doc.createElement("button");
      button.type = "button";
      button.className = "rte__btn" + (tool.className ? " " + tool.className : "");
      button.textContent = tool.label;
      button.title = tool.title;
      button.setAttribute("aria-label", tool.title);
      button.setAttribute("aria-pressed", "false");
      /* Buttons in a toolbar are one tab stop, arrow keys move within it. */
      button.tabIndex = -1;

      button.addEventListener("mousedown", function (event) {
        /* Keep the caret where it is: focusing the button would collapse the
           selection before the command could act on it. */
        event.preventDefault();
      });

      button.addEventListener("click", function () {
        self.run(tool);
      });

      self.buttons.push({ tool: tool, el: button });
      self.toolbar.appendChild(button);
    });

    if (this.buttons.length) {
      this.buttons[0].el.tabIndex = 0;
    }

    this.toolbar.addEventListener("keydown", this.onToolbarKey.bind(this));

    this.surface.innerHTML = this.textarea.value;
    this.root.appendChild(this.toolbar);
    this.root.appendChild(this.surface);

    this.textarea.parentNode.insertBefore(this.root, this.textarea);
    this.textarea.classList.add("rte__source");
    this.textarea.setAttribute("hidden", "hidden");
    this.textarea.setAttribute("aria-hidden", "true");
    this.textarea.tabIndex = -1;

    this.surface.addEventListener("input", this.sync.bind(this));
    this.surface.addEventListener("blur", this.sync.bind(this));
    this.surface.addEventListener("keydown", this.onKey.bind(this));

    ["keyup", "mouseup", "focus"].forEach(function (type) {
      self.surface.addEventListener(type, self.refresh.bind(self));
    });

    var form = this.textarea.form;
    if (form) {
      /* Belt and braces: the input handler has already written it, but a
         submit triggered before a blur would otherwise miss the last edit. */
      form.addEventListener("submit", this.sync.bind(this));
    }

    /* Produce tags, not inline styles, for the commands that offer a choice.
       Without this Chrome writes <span style="font-weight:bold">, which the
       CSP blocks and the sanitiser would strip — losing the formatting. */
    try {
      doc.execCommand("styleWithCSS", false, false);
    } catch (error) {
      /* Firefox throws if this is called with no editable focus; harmless. */
    }

    this.refresh();
  };

  Editor.prototype.sync = function () {
    this.textarea.value = this.surface.innerHTML;
  };

  /** The block element the caret sits in, within this editor. */
  Editor.prototype.currentBlock = function () {
    var selection = global.getSelection();
    if (!selection || !selection.rangeCount) {
      return null;
    }

    var node = selection.getRangeAt(0).startContainer;
    if (node.nodeType === 3) {
      node = node.parentNode;
    }

    while (node && node !== this.surface) {
      var tag = node.nodeName.toLowerCase();
      if (tag === "p" || tag === "li" || tag === "ul" || tag === "ol") {
        return node;
      }
      node = node.parentNode;
    }
    return null;
  };

  Editor.prototype.run = function (tool) {
    this.surface.focus();

    if (tool.align) {
      this.align(tool.align);
    } else if (tool.command === "createLink") {
      this.link();
    } else {
      doc.execCommand(tool.command, false, null);
    }

    this.sync();
    this.refresh();
  };

  Editor.prototype.align = function (className) {
    var block = this.currentBlock();

    /* Typing into an empty editor leaves bare text nodes with no block to
       align, so give them one first. */
    if (!block) {
      doc.execCommand("formatBlock", false, "p");
      block = this.currentBlock();
    }
    if (!block) {
      return;
    }

    var already = block.classList.contains(className);
    ALIGNMENTS.forEach(function (name) {
      block.classList.remove(name);
    });
    if (!already) {
      block.classList.add(className);
    }
    if (!block.className) {
      block.removeAttribute("class");
    }
  };

  Editor.prototype.link = function () {
    var selection = global.getSelection();
    var selected = selection ? selection.toString() : "";

    if (!selected) {
      global.alert("Select the words you want to link first.");
      return;
    }

    var url = global.prompt("Link address", "https://");
    if (!url) {
      return;
    }

    url = url.trim();
    if (!/^(https?:\/\/|mailto:|\/)/i.test(url)) {
      global.alert(
        "Links must start with https://, mailto: or / — anything else is " +
        "removed when the post is saved."
      );
      return;
    }

    doc.execCommand("createLink", false, url);
  };

  /** Reflect the state of the caret in the toolbar. */
  Editor.prototype.refresh = function () {
    var block = this.currentBlock();

    this.buttons.forEach(function (entry) {
      var on = false;

      if (entry.tool.align) {
        on = !!block && block.classList.contains(entry.tool.align);
      } else if (entry.tool.command && entry.tool.command !== "createLink") {
        try {
          on = doc.queryCommandState(entry.tool.command);
        } catch (error) {
          on = false;
        }
      }

      entry.el.setAttribute("aria-pressed", on ? "true" : "false");
      entry.el.classList.toggle("rte__btn--on", on);
    });
  };

  Editor.prototype.onKey = function (event) {
    if (!event.ctrlKey && !event.metaKey) {
      return;
    }

    var map = { b: "bold", i: "italic", u: "underline" };
    var command = map[event.key.toLowerCase()];

    if (command) {
      event.preventDefault();
      doc.execCommand(command, false, null);
      this.sync();
      this.refresh();
    }
  };

  /* A toolbar is one tab stop; the arrow keys move between its buttons. That
     is the expected behaviour for role="toolbar", and it keeps the editor
     itself only one Tab away from the field before it. */
  Editor.prototype.onToolbarKey = function (event) {
    var keys = { ArrowRight: 1, ArrowLeft: -1, Home: "first", End: "last" };
    if (!(event.key in keys)) {
      return;
    }

    event.preventDefault();

    var items = this.buttons.map(function (entry) { return entry.el; });
    var current = items.indexOf(doc.activeElement);
    if (current < 0) {
      current = 0;
    }

    var next;
    if (keys[event.key] === "first") {
      next = 0;
    } else if (keys[event.key] === "last") {
      next = items.length - 1;
    } else {
      next = (current + keys[event.key] + items.length) % items.length;
    }

    items.forEach(function (el) { el.tabIndex = -1; });
    items[next].tabIndex = 0;
    items[next].focus();
  };

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.editor = {
    init: function () {
      var fields = doc.querySelectorAll("textarea[data-editor]");

      Array.prototype.forEach.call(fields, function (textarea) {
        var editor = new Editor(textarea);
        editor.build();
      });
    }
  };
})(window);
