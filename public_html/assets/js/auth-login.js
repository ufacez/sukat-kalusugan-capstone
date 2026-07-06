(function () {
  const form = document.getElementById("loginForm");
  const message = document.getElementById("formMessage");
  const submitButton = form ? form.querySelector(".auth-submit") : null;
  const passwordInput = document.getElementById("password");

  if (!form || !message || !submitButton) {
    return;
  }

  form.addEventListener("submit", async function (event) {
    event.preventDefault();

    const formData = new FormData(form);
    message.textContent = "";
    submitButton.disabled = true;
    submitButton.classList.add("is-loading");

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

      const payload = await response.json();

      if (!response.ok || !payload.success) {
        throw new Error(payload.message || "Unable to sign in.");
      }

      window.location.href = payload.redirect_url || "../auth/login.php";
    } catch (error) {
      message.textContent = error.message || "Unable to sign in.";
      submitButton.disabled = false;
      submitButton.classList.remove("is-loading");
    }
  });

  const toggle = document.querySelector("[data-toggle-password]");

  if (toggle && passwordInput) {
    toggle.addEventListener("click", function () {
      const showing = passwordInput.type === "text";
      passwordInput.type = showing ? "password" : "text";
      toggle.textContent = showing ? "Show" : "Hide";
      toggle.setAttribute(
        "aria-label",
        showing ? "Show password" : "Hide password",
      );
    });
  }
})();
