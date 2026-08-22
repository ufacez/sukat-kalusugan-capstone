(function () {
  const form = document.getElementById("resetPasswordForm");
  const message = document.getElementById("formMessage");
  const submitButton = form ? form.querySelector(".auth-submit") : null;
  const passwordInput = document.getElementById("password");
  const confirmInput = document.getElementById("confirm_password");

  if (!form || !message || !submitButton) {
    return;
  }

  form.addEventListener("submit", async function (event) {
    event.preventDefault();

    if (passwordInput.value.length < 8) {
      message.textContent = "Password must be at least 8 characters long.";
      return;
    }

    if (passwordInput.value !== confirmInput.value) {
      message.textContent = "Passwords do not match.";
      return;
    }

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

      const raw = await response.text();
      let payload = null;

      try {
        payload = JSON.parse(raw);
      } catch (_) {
        throw new Error("The server returned an invalid response.");
      }

      if (!response.ok || !payload || !payload.success) {
        throw new Error((payload && payload.message) || "Unable to reset password.");
      }

      window.location.href = "login.php?notice=" + encodeURIComponent(payload.message || "Password reset. Please sign in.");
    } catch (error) {
      message.textContent = error.message || "Unable to reset password.";
      submitButton.disabled = false;
      submitButton.classList.remove("is-loading");
    }
  });

  document.querySelectorAll("[data-toggle-password]").forEach(function (toggle) {
    toggle.addEventListener("click", function () {
      const field = toggle.previousElementSibling;

      if (!field) {
        return;
      }

      const showing = field.type === "text";
      field.type = showing ? "password" : "text";
      toggle.textContent = showing ? "Show" : "Hide";
      toggle.setAttribute("aria-label", showing ? "Show password" : "Hide password");
    });
  });
})();
