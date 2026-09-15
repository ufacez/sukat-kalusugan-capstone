(function () {
  const form = document.getElementById("loginForm");
  const message = document.getElementById("formMessage");
  const submitButton = form ? form.querySelector(".auth-submit") : null;
  const loader = document.getElementById("authLoader");
  const loaderTitle = document.getElementById("authLoaderTitle");

  if (!form || !message || !submitButton) {
    return;
  }

  function showLoader(title) {
    if (loaderTitle && title) loaderTitle.textContent = title;
    if (loader) loader.hidden = false;
  }

  function hideLoader() {
    if (loader) loader.hidden = true;
  }

  form.addEventListener("submit", async function (event) {
    event.preventDefault();

    const formData = new FormData(form);
    message.textContent = "";
    submitButton.disabled = true;
    submitButton.classList.add("is-loading");
    showLoader("Signing you in…");

    try {
      const response = await fetch(form.action, {
        method: "POST",
        body: formData,
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        },
        credentials: "same-origin",
      });

      const raw = await response.text();
      let payload = null;

      try {
        payload = JSON.parse(raw);
      } catch (_) {
        throw new Error("Login service returned an invalid response.");
      }

      if (!response.ok || !payload || !payload.success) {
        throw new Error(payload.message || "Unable to sign in.");
      }

      const redirectUrl = payload.redirect_url || "../auth/login.php";
      showLoader("Welcome back…");
      if (window.SKAuthHandoff) {
        window.SKAuthHandoff.exitTo({ button: submitButton, url: redirectUrl, welcome: true });
      } else {
        window.location.href = redirectUrl;
      }
    } catch (error) {
      message.textContent = error.message || "Unable to sign in.";
      hideLoader();
      submitButton.disabled = false;
      submitButton.classList.remove("is-loading");
    }
  });

  const toggle = document.querySelector("[data-toggle-password]");
  const passwordInput = document.getElementById("password");

  if (toggle && passwordInput) {
    toggle.addEventListener("click", function () {
      const showing = passwordInput.type === "text";
      passwordInput.type = showing ? "password" : "text";
      toggle.classList.toggle("is-visible", !showing);
      toggle.setAttribute(
        "aria-label",
        showing ? "Show password" : "Hide password",
      );
    });
  }
})();
