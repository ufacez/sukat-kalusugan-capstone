/* Sukat Kalusugan — shared auth success handoff.
 *
 * Gives every auth form the same exit choreography: the submit button
 * morphs into a success state, the card fades out, then navigation
 * happens. Pure paint — never changes where the user goes or what was
 * submitted. Safe no-op fallback when anything is missing.
 *
 * Usage:
 *   SKAuthHandoff.exitTo({ button: submitButton, url: redirectUrl });
 *   SKAuthHandoff.exitTo({ button: submitButton, url: redirectUrl, welcome: true });
 *
 * `welcome: true` plants a one-shot sessionStorage flag consumed by
 * admin.js on the landing dashboard for a welcome emphasis.
 */
(function () {
  "use strict";

  function prefersReducedMotion() {
    return (
      typeof window.matchMedia === "function" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    );
  }

  function exitTo(options) {
    var opts = options || {};
    var url = opts.url;
    if (!url) return;

    var button = opts.button || null;
    var shell =
      document.querySelector(".auth-shell") ||
      (button && button.closest("main")) ||
      null;

    if (opts.welcome) {
      try {
        window.sessionStorage.setItem("sk-welcome", "1");
      } catch (error) {
        // Welcome emphasis is optional; navigation must still happen.
      }
    }

    if (!shell || prefersReducedMotion()) {
      window.location.href = url;
      return;
    }

    if (button) {
      button.classList.remove("is-loading");
      button.classList.add("is-success");
      var label = button.querySelector(".button-label");
      if (label) label.textContent = opts.successLabel || "Welcome back";
    }

    // Let the success state paint one frame before exiting.
    window.requestAnimationFrame(function () {
      shell.classList.add("is-exiting");
      window.setTimeout(function () {
        window.location.href = url;
      }, 260);
    });
  }

  window.SKAuthHandoff = { exitTo: exitTo };
})();
