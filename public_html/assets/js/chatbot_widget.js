(function () {
  const config = window.CHATBOT_CONFIG || {};
  const apiBase = config.apiBase || "";
  const role = config.role || "parent"; // 'parent' | 'staff'

  if (!apiBase) {
    return;
  }

  const state = {
    open: false,
    children: [],
    selectedChildId: null,
    history: [], // [{role: 'user'|'assistant', content: string}]
    sending: false,
  };

  // ---- Build DOM -----------------------------------------------------

  const launcher = document.createElement("button");
  launcher.type = "button";
  launcher.className = "chatbot-launcher";
  launcher.innerHTML =
    '<span class="chatbot-launcher-icon">💬</span><span>Ask about results</span>';

  const panel = document.createElement("div");
  panel.className = "chatbot-panel";
  panel.innerHTML = `
    <div class="chatbot-panel-header">
      <div>
        <h2>Growth Result Assistant</h2>
        <p>Explains your child's measurement results</p>
      </div>
      <button type="button" class="chatbot-close" aria-label="Close">&times;</button>
    </div>
    <div class="chatbot-child-row">
      ${
        role === "staff"
          ? '<input type="text" data-chatbot-search placeholder="Search child by name or code…" autocomplete="off">'
          : ""
      }
      <select data-chatbot-select style="margin-top:${role === "staff" ? "8px" : "0"};">
        <option value="">Select a child…</option>
      </select>
    </div>
    <div class="chatbot-messages" data-chatbot-messages></div>
    <div class="chatbot-input-row">
      <textarea rows="1" data-chatbot-input placeholder="Ask what this result means…"></textarea>
      <button type="button" class="chatbot-send" data-chatbot-send>Send</button>
    </div>
    <div class="chatbot-disclaimer">Not a medical diagnosis — for medical decisions, please consult your nutritionist or doctor.</div>
  `;

  document.body.appendChild(panel);
  document.body.appendChild(launcher);

  const messagesEl = panel.querySelector("[data-chatbot-messages]");
  const selectEl = panel.querySelector("[data-chatbot-select]");
  const searchEl = panel.querySelector("[data-chatbot-search]");
  const inputEl = panel.querySelector("[data-chatbot-input]");
  const sendBtn = panel.querySelector("[data-chatbot-send]");
  const closeBtn = panel.querySelector(".chatbot-close");

  // ---- Helpers ---------------------------------------------------------

  function addBubble(text, kind) {
    const bubble = document.createElement("div");
    bubble.className = "chatbot-bubble is-" + kind;
    bubble.textContent = text;
    messagesEl.appendChild(bubble);
    messagesEl.scrollTop = messagesEl.scrollHeight;
    return bubble;
  }

  function setChildOptions(children) {
    state.children = children;
    selectEl.innerHTML = '<option value="">Select a child…</option>';
    children.forEach((child) => {
      const option = document.createElement("option");
      option.value = String(child.id);
      option.textContent =
        child.name + (child.child_code ? " (" + child.child_code + ")" : "");
      selectEl.appendChild(option);
    });
  }

  async function loadChildren(query) {
    try {
      const url =
        apiBase +
        "/children.php" +
        (query ? "?q=" + encodeURIComponent(query) : "");
      const response = await fetch(url, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });

      let data = null;
      try {
        data = await response.json();
      } catch (parseError) {
        addBubble(
          "Could not load children — the server response wasn't valid JSON (HTTP " +
            response.status +
            "). Check your browser's Network tab for the raw response from children.php, or your PHP error log for a fatal error.",
          "error"
        );
        return;
      }

      if (data && data.success) {
        setChildOptions(data.data.children || []);

        if ((data.data.children || []).length === 0) {
          addBubble(
            "No children were found for your account yet.",
            "system"
          );
        }
      } else {
        addBubble(
          (data && data.message) || "Could not load children (HTTP " + response.status + ").",
          "error"
        );
      }
    } catch (error) {
      addBubble(
        "Could not reach the server to load children: " + error.message,
        "error"
      );
    }
  }

  function resetConversation() {
    state.history = [];
    messagesEl.innerHTML = "";
    addBubble(
      "Hi! Pick a child above, then ask me what their latest growth result means.",
      "system"
    );
  }

  async function sendMessage() {
    const message = inputEl.value.trim();

    if (!message || state.sending) {
      return;
    }

    if (!state.selectedChildId) {
      addBubble("Please select a child first.", "error");
      return;
    }

    inputEl.value = "";
    addBubble(message, "user");
    state.history.push({ role: "user", content: message });
    state.sending = true;
    sendBtn.disabled = true;
    const thinkingBubble = addBubble("Thinking…", "system");

    try {
      const response = await fetch(apiBase + "/interpret.php", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({
          child_id: state.selectedChildId,
          message: message,
          history: state.history.slice(0, -1),
        }),
      });

      const data = await response.json();
      thinkingBubble.remove();

      if (data && data.success) {
        const reply = data.data.reply;
        addBubble(reply, "assistant");
        state.history.push({ role: "assistant", content: reply });
      } else {
        addBubble(
          (data && data.message) || "Something went wrong. Please try again.",
          "error"
        );
      }
    } catch (error) {
      thinkingBubble.remove();
      addBubble("Could not reach the assistant. Please try again.", "error");
    } finally {
      state.sending = false;
      sendBtn.disabled = false;
    }
  }

  // ---- Events ------------------------------------------------------

  launcher.addEventListener("click", () => {
    state.open = !state.open;
    panel.classList.toggle("is-open", state.open);

    if (state.open && state.children.length === 0) {
      loadChildren("");
    }
  });

  closeBtn.addEventListener("click", () => {
    state.open = false;
    panel.classList.remove("is-open");
  });

  selectEl.addEventListener("change", () => {
    state.selectedChildId = selectEl.value ? parseInt(selectEl.value, 10) : null;
    resetConversation();
  });

  if (searchEl) {
    let debounceTimer = null;
    searchEl.addEventListener("input", () => {
      window.clearTimeout(debounceTimer);
      debounceTimer = window.setTimeout(() => {
        loadChildren(searchEl.value.trim());
      }, 300);
    });
  }

  sendBtn.addEventListener("click", sendMessage);

  inputEl.addEventListener("keydown", (event) => {
    if (event.key === "Enter" && !event.shiftKey) {
      event.preventDefault();
      sendMessage();
    }
  });

  resetConversation();
})();