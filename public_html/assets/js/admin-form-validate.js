/**
 * Shared live-validation + PSGC (Philippine Standard Geographic Code)
 * address picker for the admin "Add/Edit User" and "Add/Edit Parent"
 * forms. Everything here is progressive enhancement: forms still work
 * (and are still validated) server-side if JS is unavailable.
 */
(function () {
  "use strict";

  const NAME_RE = /^[A-Za-zÀ-ÖØ-öø-ÿ.'\-\s]{2,60}$/u;
  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const USERNAME_RE = /^[A-Za-z0-9_.]{3,30}$/;
  const PH_MOBILE_RE = /^09\d{9}$/;

  function fieldWrapper(input) {
    return input.closest(".admin-field");
  }

  function messageEl(input) {
    const wrap = fieldWrapper(input);
    if (!wrap) return null;
    return wrap.querySelector(".admin-field-message");
  }

  function setState(input, ok, message) {
    const wrap = fieldWrapper(input);
    const msg = messageEl(input);

    if (!wrap) return ok;

    wrap.classList.remove("is-valid", "is-invalid");

    if (input.value.trim() === "" && !input.required) {
      if (msg) msg.textContent = "";
      return true;
    }

    wrap.classList.add(ok ? "is-valid" : "is-invalid");

    if (msg) {
      msg.textContent = ok ? "" : message;
    }

    return ok;
  }

  function validateNamePart(input, label) {
    const value = input.value.trim();
    const required = input.required;

    if (value === "") {
      return setState(input, !required, label + " is required.");
    }

    return setState(input, NAME_RE.test(value), label + " may only contain letters, spaces, hyphens, and periods.");
  }

  function validateEmail(input) {
    const value = input.value.trim();

    if (value === "") {
      return setState(input, !input.required, "Email is required.");
    }

    return setState(input, EMAIL_RE.test(value), "Enter a valid email address (e.g. juan@example.com).");
  }

  function validateUsername(input) {
    const value = input.value.trim();

    if (value === "") {
      return setState(input, !input.required, "Username is required.");
    }

    return setState(
      input,
      USERNAME_RE.test(value),
      "3-30 characters: letters, numbers, dot, or underscore only."
    );
  }

  function validatePhone(input) {
    const raw = input.value.trim();

    if (raw === "") {
      return setState(input, !input.required, "Mobile number is required.");
    }

    const digitsOnly = raw.replace(/[^0-9]/g, "");

    if (digitsOnly !== raw) {
      // normalize as the user types so 0917-910-393 style input still works
      input.value = digitsOnly;
    }

    return setState(
      input,
      PH_MOBILE_RE.test(digitsOnly),
      "Enter a valid 11-digit PH mobile number starting with 09 (e.g. 09171234567)."
    );
  }

  // ---- Password strength ----

  function passwordRules(value) {
    return {
      length: value.length >= 8,
      lower: /[a-z]/.test(value),
      upper: /[A-Z]/.test(value),
      number: /[0-9]/.test(value),
      special: /[^A-Za-z0-9]/.test(value),
    };
  }

  function isStrongPassword(value) {
    const rules = passwordRules(value);
    return rules.length && rules.lower && rules.upper && rules.number && rules.special;
  }

  function setupPasswordField(input) {
    const wrap = fieldWrapper(input);
    const checklist = wrap ? wrap.parentElement.querySelector('[data-pw-checklist-for="' + input.id + '"]') : null;
    const strengthFill = wrap ? wrap.parentElement.querySelector('[data-pw-strength-for="' + input.id + '"] .admin-pw-strength-fill') : null;
    const strengthLabel = wrap ? wrap.parentElement.querySelector('[data-pw-strength-for="' + input.id + '"] .admin-pw-strength-label') : null;

    function update() {
      const value = input.value;
      const rules = passwordRules(value);
      const optional = !input.required && value === "";

      if (checklist) {
        checklist.querySelectorAll("[data-pw-rule]").forEach((li) => {
          const rule = li.getAttribute("data-pw-rule");
          li.classList.toggle("is-met", !!rules[rule]);
        });
      }

      const metCount = Object.values(rules).filter(Boolean).length;

      if (strengthFill && strengthLabel) {
        const pct = (metCount / 5) * 100;
        strengthFill.style.width = pct + "%";

        let label = "Very weak";
        let color = "#c93b3b";

        if (value === "") {
          label = "";
        } else if (metCount <= 2) {
          label = "Weak";
          color = "#c93b3b";
        } else if (metCount <= 4) {
          label = "Fair";
          color = "#f2a93b";
        } else {
          label = "Strong";
          color = "#2f9e5c";
        }

        strengthFill.style.background = color;
        strengthLabel.textContent = label;
      }

      setState(input, optional || isStrongPassword(value), "Password doesn't meet all the requirements below.");

      // Re-check confirm password whenever the password changes
      const confirmInput = document.querySelector('[data-match="' + input.id + '"]');
      if (confirmInput && confirmInput.value !== "") {
        validateConfirmPassword(confirmInput);
      }
    }

    input.addEventListener("input", update);
    update();
  }

  function validateConfirmPassword(input) {
    const targetId = input.getAttribute("data-match");
    const target = targetId ? document.getElementById(targetId) : null;
    const value = input.value;

    if (value === "") {
      return setState(input, !input.required, "Please re-enter the password.");
    }

    const matches = target ? value === target.value : true;

    return setState(input, matches, "Passwords do not match.");
  }

  // ---- Wire up every field with a data-validate attribute ----

  function initValidation(scope) {
    scope.querySelectorAll('[data-validate="name"]').forEach((input) => {
      const label = input.getAttribute("data-label") || "This field";
      input.addEventListener("input", () => validateNamePart(input, label));
      input.addEventListener("blur", () => validateNamePart(input, label));
    });

    scope.querySelectorAll('[data-validate="email"]').forEach((input) => {
      input.addEventListener("input", () => validateEmail(input));
      input.addEventListener("blur", () => validateEmail(input));
    });

    scope.querySelectorAll('[data-validate="username"]').forEach((input) => {
      input.addEventListener("input", () => validateUsername(input));
      input.addEventListener("blur", () => validateUsername(input));
    });

    scope.querySelectorAll('[data-validate="phone-ph"]').forEach((input) => {
      input.setAttribute("maxlength", "11");
      input.setAttribute("inputmode", "numeric");
      input.addEventListener("input", () => validatePhone(input));
      input.addEventListener("blur", () => validatePhone(input));
    });

    scope.querySelectorAll('[data-validate="password"]').forEach((input) => {
      setupPasswordField(input);
    });

    scope.querySelectorAll('[data-validate="confirm-password"]').forEach((input) => {
      input.addEventListener("input", () => validateConfirmPassword(input));
      input.addEventListener("blur", () => validateConfirmPassword(input));
    });

    // Block submission if any wired field is currently marked invalid.
    scope.querySelectorAll("form[data-validate-form]").forEach((form) => {
      form.addEventListener("submit", (event) => {
        const invalid = form.querySelectorAll(".admin-field.is-invalid");

        // Trigger validation on any required-but-untouched fields too.
        form.querySelectorAll("[data-validate]").forEach((input) => {
          input.dispatchEvent(new Event("blur"));
        });

        const stillInvalid = form.querySelectorAll(".admin-field.is-invalid");

        if (stillInvalid.length > 0) {
          event.preventDefault();
          stillInvalid[0].querySelector("input, select")?.focus();
          const banner = form.querySelector("[data-validate-banner]");
          if (banner) {
            banner.textContent = "Please fix the highlighted fields before submitting.";
            banner.style.display = "block";
          }
        }
      });
    });
  }

  // ---- PSGC cascading address picker ----

  const PSGC_BASE = "https://psgc.gitlab.io/api";
  const psgcCache = {};

  async function psgcFetch(path) {
    if (psgcCache[path]) {
      return psgcCache[path];
    }

    const response = await fetch(PSGC_BASE + path);

    if (!response.ok) {
      throw new Error("PSGC request failed: " + path);
    }

    const data = await response.json();
    psgcCache[path] = data;

    return data;
  }

  function fillSelect(select, items, placeholder) {
    select.innerHTML = "";
    const opt = document.createElement("option");
    opt.value = "";
    opt.textContent = placeholder;
    select.appendChild(opt);

    items
      .slice()
      .sort((a, b) => a.name.localeCompare(b.name))
      .forEach((item) => {
        const option = document.createElement("option");
        option.value = item.code;
        option.textContent = item.name;
        option.dataset.name = item.name;
        select.appendChild(option);
      });
  }

  function initPsgcPicker(root) {
    const provinceSelect = root.querySelector('[data-psgc="province"]');
    const citySelect = root.querySelector('[data-psgc="city"]');
    const barangaySelect = root.querySelector('[data-psgc="barangay"]');
    const streetInput = root.querySelector('[data-psgc="street"]');
    const targetId = root.getAttribute("data-psgc-address-target");
    const target = targetId ? document.getElementById(targetId) : null;
    const status = root.querySelector("[data-psgc-status]");

    if (!provinceSelect || !citySelect || !barangaySelect) {
      return;
    }

    function setStatus(text, isError) {
      if (!status) return;
      status.textContent = text || "";
      status.classList.toggle("is-error", !!isError);
    }

    function rebuildAddress() {
      if (!target) return;

      const street = streetInput ? streetInput.value.trim() : "";
      const barangayName = barangaySelect.selectedOptions[0]?.dataset.name || "";
      const cityName = citySelect.selectedOptions[0]?.dataset.name || "";
      const provinceName = provinceSelect.selectedOptions[0]?.dataset.name || "";

      const parts = [street, barangayName ? "Brgy. " + barangayName : "", cityName, provinceName].filter(
        (part) => part !== ""
      );

      target.value = parts.join(", ");
    }

    provinceSelect.addEventListener("change", async () => {
      citySelect.innerHTML = "";
      barangaySelect.innerHTML = "";
      citySelect.disabled = true;
      barangaySelect.disabled = true;

      if (!provinceSelect.value) {
        fillSelect(citySelect, [], "-- Select province first --");
        fillSelect(barangaySelect, [], "-- Select city/municipality first --");
        rebuildAddress();
        return;
      }

      setStatus("Loading cities/municipalities…");

      try {
        const cities = await psgcFetch("/provinces/" + provinceSelect.value + "/cities-municipalities/");
        fillSelect(citySelect, cities, "-- Select city/municipality --");
        fillSelect(barangaySelect, [], "-- Select city/municipality first --");
        citySelect.disabled = false;
        setStatus("");
      } catch (err) {
        setStatus("Couldn't load cities right now. You can still type the address manually below.", true);
      }

      rebuildAddress();
    });

    citySelect.addEventListener("change", async () => {
      barangaySelect.innerHTML = "";
      barangaySelect.disabled = true;

      if (!citySelect.value) {
        fillSelect(barangaySelect, [], "-- Select city/municipality first --");
        rebuildAddress();
        return;
      }

      setStatus("Loading barangays…");

      try {
        const barangays = await psgcFetch("/cities-municipalities/" + citySelect.value + "/barangays/");
        fillSelect(barangaySelect, barangays, "-- Select barangay --");
        barangaySelect.disabled = false;
        setStatus("");
      } catch (err) {
        setStatus("Couldn't load barangays right now. You can still type the address manually below.", true);
      }

      rebuildAddress();
    });

    barangaySelect.addEventListener("change", rebuildAddress);

    if (streetInput) {
      streetInput.addEventListener("input", rebuildAddress);
    }

    setStatus("Loading provinces…");

    psgcFetch("/provinces/")
      .then((provinces) => {
        fillSelect(provinceSelect, provinces, "-- Select province --");
        setStatus("");
      })
      .catch(() => {
        setStatus("Couldn't reach the PH address service. You can still type the address manually below.", true);
      });
  }

  function initCsfpBarangayForm(root) {
    const barangaySelect = root.querySelector('[data-csfp="barangay"]');
    const form = root.closest("form");
    const nameInput = form ? form.querySelector('input[name="name"]') : null;
    const cityInput = form ? form.querySelector('input[name="city_municipality"]') : null;
    const status = root.parentElement.querySelector("[data-csfp-status]");

    if (!barangaySelect || !nameInput || !cityInput) {
      return;
    }

    function setStatus(text, isError) {
      if (!status) return;
      status.textContent = text || "";
      status.classList.toggle("is-error", !!isError);
    }

    barangaySelect.addEventListener("change", () => {
      nameInput.value = barangaySelect.selectedOptions[0]?.dataset.name || "";
    });

    setStatus("Loading CSFP barangays...");
    fetch("../api/admin/csfp_barangays.php")
      .then((response) => {
        if (!response.ok) {
          throw new Error("CSFP barangay API request failed");
        }
        return response.json();
      })
      .then((data) => {
        const barangays = (data.barangays || []).map((name) => ({ code: name, name }));
        fillSelect(barangaySelect, barangays, "-- Select barangay --");
        const savedName = nameInput.value.trim();
        if (savedName) {
          barangaySelect.value = savedName;
        }
        cityInput.value = data.city_municipality || "City of San Fernando, Pampanga";
        barangaySelect.disabled = false;
        setStatus("");
      })
      .catch(() => {
        setStatus("Couldn't load the local CSFP barangay list.", true);
      });
  }

  function initCsfpChildForm(root) {
    const barangaySelect = root.querySelector('[data-csfp="barangay"]');
    const addressInput = root.closest("form")?.querySelector("[data-csfp-address]");
    const status = root.parentElement.querySelector("[data-csfp-status]");
    const savedName = root.getAttribute("data-csfp-selected") || "";
    let barangayMap = {};

    try {
      barangayMap = JSON.parse(root.getAttribute("data-csfp-map") || "{}");
    } catch (err) {
      barangayMap = {};
    }

    if (!barangaySelect || !addressInput) {
      return;
    }

    function setStatus(text, isError) {
      if (!status) return;
      status.textContent = text || "";
      status.classList.toggle("is-error", !!isError);
    }

    setStatus("Loading CSFP barangays...");
    fetch("../api/admin/csfp_barangays.php")
      .then((response) => {
        if (!response.ok) throw new Error("CSFP barangay API request failed");
        return response.json();
      })
      .then((data) => {
        const barangays = (data.barangays || []).map((name) => ({ code: barangayMap[name] || name, name }));
        fillSelect(barangaySelect, barangays, "-- Select barangay --");
        if (savedName) {
          barangaySelect.value = String(barangayMap[savedName] || savedName);
        }
        barangaySelect.disabled = false;
        setStatus("");
      })
      .catch(() => {
        setStatus("Couldn't load the local CSFP barangay list.", true);
      });

    barangaySelect.addEventListener("change", () => {
      const barangayName = barangaySelect.selectedOptions[0]?.dataset.name || "";
      const street = addressInput.value.split(",")[0].trim();
      const parts = [street, barangayName ? "Brgy. " + barangayName : "", "City of San Fernando", "Pampanga"].filter(Boolean);
      addressInput.value = parts.join(", ");
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    initValidation(document);
    document.querySelectorAll("[data-psgc-picker]").forEach((root) => initPsgcPicker(root));
    document.querySelectorAll("[data-csfp-barangay-picker]").forEach((root) => initCsfpBarangayForm(root));
    document.querySelectorAll("[data-csfp-child-picker]").forEach((root) => initCsfpChildForm(root));
  });
})();
