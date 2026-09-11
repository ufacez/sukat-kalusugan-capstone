# Sukat Kalusugan — Manual Test Plan (demo rehearsal)

Run top-to-bottom on Azure, in a private tab, as the listed role.
Legend for **Result**: ✅ pass / ❌ fail + exact error text. Any ❌ stops its section until fixed.

## 0. Pre-flight (do first)

| ID | Case | Expected | Result |
|----|------|----------|--------|
| 0.1 | Run `tools/demo_preflight.sh` | All 5 sections green | |
| 0.2 | Private-tab login (staff) → logout → login (parent) → logout | Both land on login with "Signed out" toast, zero fatals in Apache log | |

## 1. Auth (all roles)

| ID | Case | Expected | Result |
|----|------|----------|--------|
| 1.1 | Wrong password login | Friendly error, no 500, account NOT locked on first tries | |
| 1.2 | Forgot password → reset link → set new password → login | Each step toasts; new password works | |
| 1.3 | Invitation → activate account (6-char code) | Account activates, can sign in | |
| 1.4 | Expired/used reset link | Clean "link invalid/expired" message, no stack trace | |

## 2. Admin (staff/admin)

| ID | Case | Expected | Result |
|----|------|----------|--------|
| 2.1 | Dashboard loads (stats, charts) | Numbers render, no console errors | |
| 2.2 | Users: create → edit → archive → restore → hard delete | Toast each step; lists update | |
| 2.3 | Roles/permissions: change access level | Takes effect on next login of that user | |
| 2.4 | Barangays + Local areas: create/edit/deactivate | Scoped lists update | |
| 2.5 | Parents/Children CRUD + archive/restore | Same as 2.2 | |
| 2.6 | Invitations: create → cancel | Statuses update | |
| 2.7 | Sensors/devices: view status, calibrate (weight + height) | Validation toasts on bad input; values save | |
| 2.8 | Auto-archive dry run | Count shown, confirm modal, executes | |
| 2.9 | Audit logs page: filter by action, paginate | Correct rows, counts match | |
| 2.10 | Settings: update profile + password rules | Mismatched/weak passwords rejected with plain messages | |

## 3. Nutritionist

| ID | Case | Expected | Result |
|----|------|----------|--------|
| 3.1 | Dashboard: stats, charts, Recent Measurements (mobile: swipe-scrolls) | Renders; table scrolls inside card on 360px | |
| 3.2 | Children page: 10/page, tabs Active/Graduated, area filter, search | Correct slices, page buttons work | |
| 3.3 | **Add child** (parent picker modal: search, 5/page, select) | Barangay auto-matches parent; success toast | |
| 3.4 | **Record measurement**: pick child → type values → **live z-scores appear** → Save | Preview numbers == saved numbers; toast confirms | |
| 3.5 | Save validation: no child / bad values / not-due child / aged-out (60+ mo) | Each blocked with its specific friendly message | |
| 3.6 | Measurements list: filter, pagination, edit/override flow | Override requires reason; audit shows it | |
| 3.7 | WHO analysis + reference pages render | Charts/tables match DB values | |
| 3.8 | EOPT reports: list/rounds → Excel export + PDF generate | Files download, totals sane | |
| 3.9 | DQC records: flag review flow | Status updates | |
| 3.10 | Appointments: schedule → confirm → complete → cancel; mandatory re-measurement enforced | Each transition toasts; illegal jumps blocked | |
| 3.11 | Follow-up child page: recent grid scrolls on mobile; timeline renders | No crushed columns | |
| 3.12 | Risk map: assign/unassign parent/child, add/import spots | Toasts (no native alerts); map updates | |
| 3.13 | **Kali AI page**: new chat, pick child, send, sessions, archive | Replies arrive; history persists; no floating bubble on this page | |
| 3.14 | Floating Kali bubble (any other page): open, chip, send, Full-chat link | Same behavior as page; link lands on 3.13 | |
| 3.15 | Parents management: add/edit (barangay guard enforced) | Out-of-barangay blocked with message | |

## 4. Parent portal

| ID | Case | Expected | Result |
|----|------|----------|--------|
| 4.1 | Dashboard: children cards, upcoming, recent list (mobile 2-line layout) | Renders | |
| 4.2 | Children: view list, edit child profile | Validation toasts; saves | |
| 4.3 | Growth history: chart + recent list + All-measurements modal (mobile scrolls) | Modal table scrolls inside on 360px | |
| 4.4 | Appointments: request → cancel own; mandatory follow-ups un-cancellable | Block message for mandatory ones | |
| 4.5 | Settings: update profile/password | Same rules as 2.10 | |
| 4.6 | **Kali AI page**: only own children listed; forged child never visible; send → reply; sessions persist | Privacy holds; replies warm/plain | |
| 4.7 | Floating Kali bubble (all parent pages, NOT on AI page): send + chips | Works; "Full chat →" opens 4.6 | |
| 4.8 | Sidebar drawer (portrait phone): burger 44px, sign-out tappable with toast visible | Sign-out reaches login + toast | |
| 4.9 | Composer pinned at bottom, no whitespace; thread scrolls | 360px portrait check | |

## 5. Kiosk + ESP32 (if demoed live — otherwise fallback video)

| ID | Case | Expected | Result |
|----|------|----------|--------|
| 5.1 | Kiosk scan → measurement lands in queue → submits | Appears in measurements list | |
| 5.2 | Wrong/missing `X-Device-Key` | 401, nothing written | |
| 5.3 | Offline device shows offline in dashboards | Status flips, no crash | |

## 6. Cross-cutting (every portal)

| ID | Case | Expected | Result |
|----|------|----------|--------|
| 6.1 | All toast types (success/error/info) | Float top-right desktop / bottom sheet ≤480px, auto-dismiss, ✕ works | |
| 6.2 | Dark mode toggle on 3 sample pages | No unreadable text, charts legible | |
| 6.3 | 360px pass: drawer, tables, modals, forms | No horizontal page scroll; scroll-wrappers scroll internally | |
| 6.4 | Sign-out everywhere → login + toast; back button doesn't resurrect session | Cache headers hold | |
| 6.5 | Apache `error.log` after full run | Zero new fatals | |
