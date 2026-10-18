/**
 * Shared live-validation + CSFP address picker for the admin and
 * nutritionist forms. Everything here is progressive enhancement: forms
 * still work (and are still validated) server-side if JS is unavailable.
 * The address picker is locked to the City of San Fernando, Pampanga.
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

  // ---- Touched-gated errors (no red while typing) ----
  // Red (is-invalid) only appears after blur or submit. While the user is
  // still typing in an untouched field, intermediate values ("J", "juan@",
  // "ab") stay neutral instead of flashing red/green on every keystroke.
  // Once touched, live feedback resumes so fixing an error clears it.
  const touchedInputs = new WeakSet();

  function markTouched(input) {
    if (input) touchedInputs.add(input);
  }

  function isTouched(input) {
    return touchedInputs.has(input);
  }

  function clearState(input) {
    const wrap = fieldWrapper(input);
    const msg = messageEl(input);
    if (wrap) wrap.classList.remove("is-valid", "is-invalid");
    if (msg) msg.textContent = "";
    return true;
  }

  function setStateLenient(input, ok, message) {
    if (ok) return setState(input, true, "");
    if (!isTouched(input)) return clearState(input);
    return setState(input, false, message);
  }

  function validateNamePart(input, label) {
    const value = input.value.trim();
    const required = input.required;

    if (value === "") {
      return setStateLenient(input, !required, label + " is required.");
    }

    return setStateLenient(input, NAME_RE.test(value), label + " may only contain letters, spaces, hyphens, and periods.");
  }

  function validateEmail(input) {
    const value = input.value.trim();

    if (value === "") {
      return setStateLenient(input, !input.required, "Email is required.");
    }

    return setStateLenient(input, EMAIL_RE.test(value), "Enter a valid email address (e.g. juan@example.com).");
  }

  function validateUsername(input) {
    const value = input.value.trim();

    if (value === "") {
      return setStateLenient(input, !input.required, "Username is required.");
    }

    return setStateLenient(
      input,
      USERNAME_RE.test(value),
      "3-30 characters: letters, numbers, dot, or underscore only."
    );
  }

  // Mirrors admin_normalize_ph_mobile() in includes/admin_helpers.php:
  // accepts 09XXXXXXXXX, +639XXXXXXXXX, and 639XXXXXXXXX (spaces and
  // dashes are ignored). Returns the canonical 09XXXXXXXXX form, or
  // null when the input is not a valid PH mobile number.
  function normalizePhMobile(raw) {
    const trimmed = String(raw || "").trim();
    if (trimmed === "") return null;
    const hasPlus = trimmed.charAt(0) === "+";
    const digitsOnly = trimmed.replace(/[^0-9]/g, "");

    if (hasPlus && digitsOnly.substring(0, 2) !== "63") {
      return null;
    }

    let canonical = digitsOnly;
    if (canonical.substring(0, 2) === "63") {
      canonical = "0" + canonical.substring(2);
    }

    return PH_MOBILE_RE.test(canonical) ? canonical : null;
  }

  // While typing, a partial number that could still grow into a valid
  // one ("+", "+63", "09…", with spaces/dashes) must stay neutral instead
  // of flashing red on every keystroke. Strict validity is enforced on
  // blur and on submit; this only decides whether the current keystrokes
  // are still a plausible prefix.
  function isPotentialPhMobile(raw) {
    const trimmed = String(raw || "").trim();
    if (trimmed === "" || trimmed === "+") return true;
    // Only digits, one leading "+", and spaces/dashes while typing.
    if (/[^0-9+\s-]/.test(trimmed)) return false;
    if (trimmed.slice(1).indexOf("+") !== -1) return false;

    const digitsOnly = trimmed.replace(/[^0-9]/g, "");
    if (digitsOnly === "") return true;

    if (trimmed.charAt(0) === "+") {
      // Must stay a prefix of "+63" + 10 more digits (12 digits total).
      if ("63".indexOf(digitsOnly) === 0) return true;
      if (digitsOnly.indexOf("63") !== 0) return false;
      return digitsOnly.length <= 12;
    }

    // Local form: a prefix of "09" + 9 digits (11 total)…
    if ("09".indexOf(digitsOnly) === 0 && digitsOnly.length <= 2) return true;
    if (digitsOnly.indexOf("09") === 0) return digitsOnly.length <= 11;
    // …or a prefix of "63" + 10 digits (12 total, no plus typed yet).
    if ("63".indexOf(digitsOnly) === 0 && digitsOnly.length <= 2) return true;
    if (digitsOnly.indexOf("63") === 0) return digitsOnly.length <= 12;
    return false;
  }

  function validatePhone(input, strict) {
    const raw = input.value.trim();

    if (raw === "") {
      return setStateLenient(input, !input.required, "Mobile number is required.");
    }

    if (!strict && normalizePhMobile(raw) === null && isPotentialPhMobile(raw)) {
      // Still typing a plausible number — clear any state, no error.
      return clearState(input);
    }

    return setStateLenient(
      input,
      normalizePhMobile(raw) !== null,
      "Enter a valid PH mobile number (e.g. 09171234567)."
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

      // Dynamic message: name only what's missing so the user knows
    // exactly what to fix (e.g. "Password must have a number").
    var missing = [];
    if (!rules.length) missing.push("8+ characters");
    if (!rules.lower) missing.push("one lowercase letter");
    if (!rules.upper) missing.push("one uppercase letter");
    if (!rules.number) missing.push("a number");
    if (!rules.special) missing.push("a special character");

    setStateLenient(
      input,
      optional || isStrongPassword(value),
      missing.length > 0 ? "Password must have " + missing.join(", ") : ""
    );

      // Re-check confirm password whenever the password changes, but keep
      // it neutral too when the confirm field itself is still untouched.
      const confirmInput = document.querySelector('[data-match="' + input.id + '"]');
      if (confirmInput && confirmInput.value !== "") {
        validateConfirmPassword(confirmInput);
      }
    }

    input.addEventListener("input", update);
    input.addEventListener("blur", () => {
      markTouched(input);
      update();
    });
    // Exposed for the submit handler so it can force strict validation.
    input._skValidateStrict = () => {
      markTouched(input);
      update();
    };
    update();
  }

  function validateConfirmPassword(input) {
    const targetId = input.getAttribute("data-match");
    const target = targetId ? document.getElementById(targetId) : null;
    const value = input.value;

    if (value === "") {
      return setStateLenient(input, !input.required, "Please re-enter the password.");
    }

    const matches = target ? value === target.value : true;

    return setStateLenient(input, matches, "Passwords do not match.");
  }

  // Strict runner for submit: marks touched first so lenient suppression
  // is lifted, then runs the canonical validator for the field kind.
  function validateInputStrict(input) {
    const kind = input.getAttribute("data-validate");
    markTouched(input);
    if (typeof input._skValidateStrict === "function") {
      input._skValidateStrict();
      return;
    }
    if (kind === "name") {
      validateNamePart(input, input.getAttribute("data-label") || "This field");
    } else if (kind === "email") {
      validateEmail(input);
    } else if (kind === "username") {
      validateUsername(input);
    } else if (kind === "phone-ph") {
      validatePhone(input, true);
    } else if (kind === "confirm-password") {
      validateConfirmPassword(input);
    } else if (kind === "password") {
      // Fallback if setupPasswordField did not wire this input (e.g.
      // dynamically added rows): enforce required/strength strictly.
      const val = input.value;
      const optional = !input.required && val === "";
      setState(input, optional || isStrongPassword(val), "Password must have 8+ characters, one lowercase letter, one uppercase letter, a number, a special character.");
    }
  }

  // ---- Wire up every field with a data-validate attribute ----

  function initValidation(scope) {
    scope.querySelectorAll('[data-validate="name"]').forEach((input) => {
      const label = input.getAttribute("data-label") || "This field";
      input.addEventListener("input", () => validateNamePart(input, label));
      input.addEventListener("blur", () => {
        markTouched(input);
        validateNamePart(input, label);
      });
    });

    scope.querySelectorAll('[data-validate="email"]').forEach((input) => {
      input.addEventListener("input", () => validateEmail(input));
      input.addEventListener("blur", () => {
        markTouched(input);
        validateEmail(input);
      });
    });

    scope.querySelectorAll('[data-validate="username"]').forEach((input) => {
      input.addEventListener("input", () => validateUsername(input));
      input.addEventListener("blur", () => {
        markTouched(input);
        validateUsername(input);
      });
    });

    scope.querySelectorAll('[data-validate="phone-ph"]').forEach((input) => {
      // 14 fits "+639123456789" (13) plus room for a space/dash while typing.
      input.setAttribute("maxlength", "14");
      input.setAttribute("inputmode", "tel");
      // Lenient while typing (partial "+63…" stays neutral), strict on blur.
      input.addEventListener("input", () => validatePhone(input, false));
      input.addEventListener("blur", () => {
        markTouched(input);
        validatePhone(input, true);
      });
    });

    scope.querySelectorAll('[data-validate="password"]').forEach((input) => {
      setupPasswordField(input);
    });

    scope.querySelectorAll('[data-validate="confirm-password"]').forEach((input) => {
      input.addEventListener("input", () => validateConfirmPassword(input));
      input.addEventListener("blur", () => {
        markTouched(input);
        validateConfirmPassword(input);
      });
    });

    // Block submission if any wired field is currently marked invalid.
    scope.querySelectorAll("form[data-validate-form]").forEach((form) => {
      form.addEventListener("submit", (event) => {
        // Strict-validate everything (including untouched fields) so no
        // required-but-untouched field slips through. Marking touched
        // first lifts the lenient "no red while typing" suppression.
        form.querySelectorAll("[data-validate]").forEach((input) => {
          validateInputStrict(input);
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

  // ---- CSFP address picker ----
  //
  // The system only serves the City of San Fernando, Pampanga. The address
  // picker used to cascade through the external PSGC API (province -> city
  // -> barangay). It is now locked to the local CSFP list so every personal
  // address resolves to CSFP: Province and City are fixed single options and
  // the Barangay list comes from the local /api/admin/csfp_barangays.php
  // endpoint. No network dependency on the public PSGC service.

  const CSFP_PROVINCE = "Pampanga";
  const CSFP_CITY = "City of San Fernando";

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

  function setFixedOption(select, value, label) {
    select.innerHTML = "";
    const option = document.createElement("option");
    option.value = value;
    option.textContent = label;
    option.dataset.name = label;
    option.selected = true;
    select.appendChild(option);
  }

  function initPsgcPicker(root) {
    const provinceSelect = root.querySelector('[data-psgc="province"]');
    const citySelect = root.querySelector('[data-psgc="city"]');
    const barangaySelect = root.querySelector('[data-psgc="barangay"]');
    // The street input, purok select and status message sit around the select
    // grid (the picker root is itself a CSS grid), so look in the surrounding
    // field wrapper instead of only inside root.
    const scope = root.closest(".admin-field-wide") || root.parentElement || root;
    const streetInput = scope.querySelector('[data-psgc="street"]');
    const areaSelect = scope.querySelector('[data-psgc="area"]');
    const targetId = root.getAttribute("data-psgc-address-target");
    const target = targetId ? document.getElementById(targetId) : null;
    const status = scope.querySelector("[data-psgc-status]");

    if (!provinceSelect || !citySelect || !barangaySelect) {
      return;
    }

    function setStatus(text, isError) {
      if (!status) return;
      status.textContent = text || "";
      status.classList.toggle("is-error", !!isError);
    }

    function selectedName(select) {
      return select ? select.selectedOptions[0]?.dataset.name || "" : "";
    }

    function rebuildAddress() {
      if (!target) return;

      const street = streetInput ? streetInput.value.trim() : "";
      const areaName = selectedName(areaSelect);
      const barangayName = selectedName(barangaySelect);
      const cityName = selectedName(citySelect);
      const provinceName = selectedName(provinceSelect);

      const parts = [
        street,
        areaName,
        barangayName ? "Brgy. " + barangayName : "",
        cityName,
        provinceName,
      ].filter((part) => part !== "");

      target.value = parts.join(", ");
    }

    // Load the purok / local area list for the currently selected barangay.
    // `preferredName` lets the hydrator re-select the area from a saved address.
    function loadAreas(preferredName) {
      if (!areaSelect) {
        rebuildAddress();
        return;
      }

      function fillAreaOptions(items, selected) {
        areaSelect.innerHTML = "";
        const placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = "-- Select purok / area (optional) --";
        areaSelect.appendChild(placeholder);

        items.forEach((item) => {
          const option = document.createElement("option");
          option.value = item.name;
          option.textContent = item.name;
          option.dataset.name = item.name;
          areaSelect.appendChild(option);
        });

        if (selected) {
          const match = Array.from(areaSelect.options).find(
            (option) => (option.dataset.name || "").toLowerCase() === selected.toLowerCase()
          );
          if (match) areaSelect.value = match.value;
        }

        areaSelect.disabled = items.length === 0;
      }

      const barangayName = selectedName(barangaySelect);

      if (barangayName === "") {
        fillAreaOptions([], "");
        rebuildAddress();
        return;
      }

      areaSelect.disabled = true;
      areaSelect.innerHTML = "";
      const loading = document.createElement("option");
      loading.value = "";
      loading.textContent = "Loading puroks…";
      areaSelect.appendChild(loading);

      fetch("../api/admin/barangay_local_areas.php?barangay_name=" + encodeURIComponent(barangayName))
        .then((response) => {
          if (!response.ok) throw new Error("barangay local areas request failed");
          return response.json();
        })
        .then((data) => {
          fillAreaOptions(Array.isArray(data.areas) ? data.areas : [], preferredName || "");
          rebuildAddress();
        })
        .catch(() => {
          fillAreaOptions([], "");
          rebuildAddress();
        });
    }

    // Re-hydrate the picker from a previously-saved combined address so the
    // house no./street, purok and barangay survive when editing a record.
    function hydrateFromStoredAddress() {
      if (!target || !target.value) return;

      const segments = target.value
        .split(",")
        .map((segment) => segment.trim())
        .filter((segment) => segment !== "");

      if (segments.length === 0) return;

      const isBarangay = (segment) => /^brgy\.?\s/i.test(segment);
      const isCity = (segment) => segment.toLowerCase() === CSFP_CITY.toLowerCase();
      const isProvince = (segment) => segment.toLowerCase() === CSFP_PROVINCE.toLowerCase();

      // Everything that is not the barangay / city / province, in order:
      // [house no./street, purok] for the canonical layout.
      const localParts = segments.filter(
        (segment) => !isBarangay(segment) && !isCity(segment) && !isProvince(segment)
      );

      if (streetInput && localParts[0]) {
        streetInput.value = localParts[0];
      }

      const areaName = localParts.length > 1 ? localParts[1] : "";

      // Match the "Brgy. X" segment to an option. If it cannot be matched, keep
      // the stored address as-is so legacy free-text rows are never rewritten.
      const brgySegment = segments.find(isBarangay);
      if (!brgySegment) return;

      const brgyName = brgySegment.replace(/^brgy\.?\s*/i, "").trim().toLowerCase();
      const option = Array.from(barangaySelect.options).find(
        (candidate) => (candidate.dataset.name || candidate.value).toLowerCase() === brgyName
      );

      if (!option) return;

      barangaySelect.value = option.value;
      loadAreas(areaName);
    }

    // CSFP is the only locale — lock province and city to fixed options.
    setFixedOption(provinceSelect, "pampanga", CSFP_PROVINCE);
    provinceSelect.disabled = true;
    setFixedOption(citySelect, "csfp", CSFP_CITY);
    citySelect.disabled = true;

    barangaySelect.addEventListener("change", () => loadAreas());

    if (streetInput) {
      streetInput.addEventListener("input", rebuildAddress);
    }

    if (areaSelect) {
      areaSelect.addEventListener("change", rebuildAddress);
    }

    setStatus("Loading barangays…");

    fetch("../api/admin/csfp_barangays.php")
      .then((response) => {
        if (!response.ok) throw new Error("CSFP barangay request failed");
        return response.json();
      })
      .then((data) => {
        const barangays = (data.barangays || []).map((name) => ({ code: name, name }));
        fillSelect(barangaySelect, barangays, "-- Select barangay --");
        barangaySelect.disabled = false;
        hydrateFromStoredAddress();
        setStatus("");
      })
      .catch(() => {
        setStatus("Couldn't load the CSFP barangay list. Please reload the page and try again.", true);
      });
  }

  // ---- Role-driven barangay scope ----
  //
  // "All barangays" (empty value) is an admin-only scope. Nutritionist
  // accounts must be tied to a single barangay, so the empty option is
  // hidden and the select is required whenever the role is not admin.
  function initRoleBarangayScope(form) {
    const roleSelect = form.querySelector('select[name="role"]');
    const barangaySelect = form.querySelector('select[name="barangay_id"]');

    if (!roleSelect || !barangaySelect) {
      return;
    }

    const allOption = barangaySelect.querySelector('option[value=""]');

    function sync() {
      const isAdmin = roleSelect.value === "admin";

      if (allOption) {
        allOption.hidden = !isAdmin;
        allOption.disabled = !isAdmin;
      }

      if (isAdmin) {
        barangaySelect.required = false;
        return;
      }

      barangaySelect.required = true;

      if (!barangaySelect.value) {
        const first = Array.from(barangaySelect.options).find((option) => option.value !== "");
        if (first) {
          barangaySelect.value = first.value;
        }
      }
    }

    roleSelect.addEventListener("change", sync);
    sync();
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
    // Add/Edit Barangay form: list official barangays NOT yet in the
    // directory (?mode=missing). Other pickers (child/parent forms) keep
    // using the default active-rows list via initCsfpChildForm below.
    fetch("../api/admin/csfp_barangays.php?mode=missing")
      .then((response) => {
        if (!response.ok) {
          throw new Error("CSFP barangay API request failed");
        }
        return response.json();
      })
      .then((data) => {
        const barangays = (data.barangays || []).map((name) => ({ code: name, name }));
        if (barangays.length === 0) {
          fillSelect(barangaySelect, [], "-- Select barangay --");
          barangaySelect.disabled = true;
          setStatus("All 35 official barangays are already in the directory.", false);
          return;
        }
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
    document.querySelectorAll("[data-role-barangay-form]").forEach((form) => initRoleBarangayScope(form));
    document.querySelectorAll("[data-csfp-barangay-picker]").forEach((root) => initCsfpBarangayForm(root));
    document.querySelectorAll("[data-csfp-child-picker]").forEach((root) => initCsfpChildForm(root));
  });
})();
