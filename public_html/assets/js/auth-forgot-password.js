(function () {
  const form = document.getElementById("forgotPasswordForm");
  const message = document.getElementById("formMessage");
  const submitButton = form ? form.querySelector(".auth-submit") : null;

  if (!form || !message || !submitButton) {
    return;
  }

  form.addEventListener("submit", async function (event) {
    event.preventDefault();

    const formData = new FormData(form);
    message.textContent = "";
    message.classList.remove("is-success");
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

      if (!payload) {
        throw new Error("Unable to send reset link.");
      }

      if (!response.ok || !payload.success) {
        throw new Error(payload.message || "Unable to send reset link.");
      }

      message.textContent =
        payload.message || "If that email is linked to an account, we've sent a reset link.";
      message.classList.add("is-success");
      form.reset();
    } catch (error) {
      message.textContent = error.message || "Unable to send reset link.";
    } finally {
      submitButton.disabled = false;
      submitButton.classList.remove("is-loading");
    }
  });
})();
