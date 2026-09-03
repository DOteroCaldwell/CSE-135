/*
 * HW2 echo form — enhanced mode.
 *
 * Vanilla JS, no libraries. Everything here is additive: with this file absent
 * or blocked the form still submits natively over GET/POST via the
 * formaction/formmethod buttons in the markup.
 */
(function () {
  "use strict";

  var form = document.getElementById("echo-form");
  var langSelect = document.getElementById("lang");
  var methodSelect = document.getElementById("method");
  var encodingSelect = document.getElementById("encoding");
  var statusLine = document.getElementById("response-status");
  var bodyOut = document.getElementById("response-body");

  if (!form || !langSelect || !methodSelect || !encodingSelect) {
    return; // markup changed underneath us; leave the native form alone
  }

  /* The fields the user is actually sending, as [name, value] pairs. The
     control selects live inside the form too, so they are excluded by name. */
  var CONTROL_NAMES = ["lang", "method", "encoding"];

  function collectFields() {
    var out = [];
    var elements = form.elements;
    for (var i = 0; i < elements.length; i++) {
      var el = elements[i];
      if (!el.name || CONTROL_NAMES.indexOf(el.name) !== -1) {
        continue;
      }
      if (el.tagName === "BUTTON") {
        continue;
      }
      out.push([el.name, el.value]);
    }
    return out;
  }

  function toQueryString(fields) {
    return fields
      .map(function (pair) {
        return encodeURIComponent(pair[0]) + "=" + encodeURIComponent(pair[1]);
      })
      .join("&");
  }

  function toJSON(fields) {
    var obj = {};
    fields.forEach(function (pair) {
      obj[pair[0]] = pair[1];
    });
    return JSON.stringify(obj);
  }

  function setStatus(text, ok) {
    statusLine.textContent = text;
    statusLine.style.borderLeftColor = ok ? "" : "#c0392b";
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();

    var endpoint = langSelect.value;
    var method = methodSelect.value;
    var encoding = encodingSelect.value;
    var fields = collectFields();

    var url = endpoint;
    var init = {
      method: method,
      /* Ask for JSON so the reply can be rendered inline. The same endpoints
         return a full HTML page when a browser navigates to them directly. */
      headers: { Accept: "application/json" },
      /* The site is behind HTTP basic auth; without this the browser omits the
         credentials it already holds and every request comes back 401. */
      credentials: "same-origin"
    };

    if (method === "GET" || method === "DELETE") {
      /* No body: these carry their data in the query string. */
      var qs = toQueryString(fields);
      if (qs) {
        url += (url.indexOf("?") === -1 ? "?" : "&") + qs;
      }
    } else {
      init.headers["Content-Type"] = encoding;
      init.body =
        encoding === "application/json" ? toJSON(fields) : toQueryString(fields);
    }

    setStatus("Sending " + method + " to " + url + " …", true);
    bodyOut.textContent = "—";

    fetch(url, init)
      .then(function (response) {
        return response.text().then(function (text) {
          setStatus(
            method +
              " " +
              url +
              " → " +
              response.status +
              " " +
              response.statusText +
              " (" +
              (response.headers.get("Content-Type") || "no content-type") +
              ")",
            response.ok
          );
          /* Pretty-print when it parses as JSON, otherwise show it verbatim.
             textContent, never innerHTML — this is echoed user input. */
          try {
            bodyOut.textContent = JSON.stringify(JSON.parse(text), null, 2);
          } catch (e) {
            bodyOut.textContent = text;
          }
        });
      })
      .catch(function (error) {
        setStatus("Request failed: " + error.message, false);
        bodyOut.textContent = "—";
      });
  });
})();
