# Sukat Kalusugan — Click-by-Click Test Script (Azure live site)

Private tab. Base = `https://sukatkalusugan.app/public_html` + path shown.
Result column: ✅ pass / ❌ fail + exact error text + timestamp. Any ❌ stops its section.
Keep Apache `error.log` tail open throughout; section F must end with zero new fatals.

Test accounts needed: 1 admin, 1 nutritionist (with barangay, full access),
1 readonly nutritionist (for 3.16), 1 parent (2 children: one measured, one never).

## 0. Pre-flight

| ID | Where to go → what to click/type | Must see | Result |
|----|------|----------|--------|
| 0.1 | VM: `git pull`, then `./tools/demo_preflight.sh` | All 5 sections green | |
| 0.2 | Login staff → Sign out → login parent → Sign out | Login + "Signed out successfully. See you soon!" toast both times | |

## 1. Auth

| ID | Where to go → what to click/type | Must see | Result |
|----|------|----------|--------|
| 1.1 | `/auth/login.php`: leave empty → **Sign in** | "Email/username and password are required." | |
| 1.2 | Wrong password → **Sign in** | "Invalid email/username or password." | |
| 1.3 | Admin login → lands `/admin/dashboard.php`. Nutritionist login → `/nutritionist/dashboard.php`. Parent login → `/parent/dashboard.php` | Role routing correct | |
| 1.4 | Logged out → open `/parent/dashboard.php` directly | Bounced to login: "Please sign in to continue." | |
| 1.5 | Staff login → open any `/parent/*` page | "You do not have permission to access this page." | |
| 1.6 | **Forgot password?** → unknown email → **Send reset link** | "If that email is linked to an account, we've sent…" (never reveals existence) | |
| 1.7 | Real email → open link (< 30 min) → mismatch passwords → fix → submit | "Passwords do not match." then "Your password has been reset…" → log in with it | |
| 1.8 | Admin: **Management → Invitations** → fill → **Generate Invitation** → copy 6-char code → `/auth/activate.php` → weak password (checklist errors) → strong match → **Activate Account** | "Account activated successfully!" → sign in works | |

## 2. Admin portal (as admin)

| ID | Where to go → what to click/type | Must see | Result |
|----|------|----------|--------|
| 2.1 | **Overview → Dashboard** | Cards, kiosk tiles paginate `‹ / ›`, map basemaps switch | |
| 2.2 | **Management → Users** → search filters table → **Invite staff** → **Add user** path covered in 1.8 | Navigation + filters work | |
| 2.3 | Users row pencil **Edit** → phone `123` → save | "Enter a valid 11-digit PH mobile number starting with 09." → fix to `09171234567` → "User updated successfully." | |
| 2.4 | Users row **Archive** → confirm → **Archived: View archived users** → **Restore** → back | "User archived successfully." then "User restored successfully." | |
| 2.5 | Archived user → **Delete permanently** → type `WRONG` (guard) → type `DELETE` → confirm | Guard blocks first; "User permanently deleted." second | |
| 2.6 | **Configuration → Roles & Permissions** → row **Change Access** → Standard → confirm | Pill changes after reload | |
| 2.7 | **Management → Barangays** → **Add barangay** → picker loads → create | "Barangay created successfully." | |
| 2.8 | Barangay row **Local Areas** → **Add Local Area** → create → **Edit** → rename → **Deactivate** → **Reactivate** | Each step updates list | |
| 2.9 | Barangay row **Delete** → confirm | "Barangay deleted. Linked records were unassigned, not removed." | |
| 2.10 | **Management → Parents** → **Add parent** → password/confirm mismatch → fix → save | Mismatch error, then "Parent added." Duplicate email → duplicate error | |
| 2.11 | **Management → Children** → barangay filter + search → **Add child** → empty surname → save | "First name, last name, birthdate, and parent are required." → complete → "Child added successfully." Birthdate 6y ago → 1825-days error | |
| 2.12 | **Monitoring → Sensors** → row **Edit** → change location → **Save sensor settings** | "Sensor settings updated successfully." | |
| 2.13 | **Monitoring → Auto-Archive** → note count → confirm dialog → run | Inline "Archive Complete" box | |
| 2.14 | **Monitoring → Audit Logs** → each filter option → paginate | Counts/rows change correctly | |
| 2.15 | Gear → **Settings** → bad first name → mismatch passwords → valid save | Each exact error, then "Account updated successfully." | |

## 3. Nutritionist portal

| ID | Where to go → what to click/type | Must see | Result |
|----|------|----------|--------|
| 3.1 | **Overview → Dashboard** → tabs WFA/HAZ/WFH → calendar `‹ / ›` → click dotted day | Chart + counts switch; day detail panel opens; View-all links work | |
| 3.2 | **Clinical → Children** → search → area dropdown → Active/Graduated tabs → numbered pages | 10/page slices correct; row opens child card modal (Profile/Address/Parent/Household) | |
| 3.3 | **Add child** → fill names/birthdate → **-- Select Parent --** → type 2 letters → `‹ Prev / Next ›` (5/page, "Page X of Y (total)") → pick | Label fills, Barangay auto-loads → **Create child** → "Child added successfully." No-parent submit → toast + modal reopens | |
| 3.4 | **Measurements → New measurement** → search + pick child → type weight `14.500` + height `95.50` | Real WAZ/HAZ/WHZ + status pill appear live (never "—") → **Save** → toast "Measurement saved for {name} ({code})." | |
| 3.5 | Save with: no child / garbage values / future date / already-measured-today / not-due child | Each blocked with its own specific message | |
| 3.6 | Measurements list → row card (metric tabs switch chart) → **+ New measurement** | Prefilled via `?child=`; history table correct | |
| 3.7 | **WHO Analysis** → change filters → **Apply Filters** → **Reset** → prevalence dropdown all 7 options | URL params + cards update; reset clears | |
| 3.8 | **WHO Reference** → switch WFA/HFA/WFL/WFH, Boys/Girls, Months/Days → paginate → **Export** | Table + file download work | |
| 3.9 | **EOPT Reports** → Monthly/Quarterly switch → all 5 tabs → one Form PDF + one Excel download | Files download, totals sane | |
| 3.10 | Monitoring **List → View** → its PDF + Excel | Full table + files | |
| 3.11 | **Appointments** → confirm Pending → **✓ Done** → completed; cancel another | Toasts each step | |
| 3.12 | Follow-up group → **View follow-up** → set Special + interval → Save → **Record Measurement** link | Status saves; link works | |
| 3.13 | **Appointment form** → pick child (guardian auto-fills readonly) → schedule → save → edit status | "Appointment scheduled." then "Appointment updated." | |
| 3.14 | **Community → Parents** → search → card → **Edit** → archive flow | Modal, toasts correct | |
| 3.15 | **Parent form** → locked barangay pill → bad mobile → duplicate email | Exact errors; out-of-barangay blocked | |
| 3.16 | As readonly nutritionist: Add buttons hidden; direct URL to add-form | "You do not have permission…" | |
| 3.17 | **Risk Map** → filters + Clear → marker → side panel → **+ Add Parent/Child** (empty submit → "Please select at least one…") → unassign with confirm → **Add Spot** → save → **Import** bad CSV | Toasts everywhere, zero native `alert()` popups | |
| 3.18 | **Tools → Kali AI** → **Analyze child** → search → pick (header/detail fill) → ask "What does this mean?" → reply + nav-card link → **New** → **Recent sessions** reopen → **Clear conversations** + confirm | Full flow; NO floating bubble on this page | |
| 3.19 | Floating bubble (dashboard): open → chips send → type → reply → **Full chat →** → ×/Esc | Same behavior; lands on Kali AI | |
| 3.20 | **EOPT → DQC records** drill-down (unknown `?issue=` → "Unknown issue code.") | Records or friendly empty state | |

## 4. Parent portal

| ID | Where to go → what to click/type | Must see | Result |
|----|------|----------|--------|
| 4.1 | Dashboard greeting + **Tap to change child** → switch `?child_id=` → **View results →** + **View full history →** | Correct child + links land right | |
| 4.2 | **Children** → card → modal tabs Profile/Measurements/History → **View growth history** | Pills + last-5 rows correct | |
| 4.3 | `/parent/child_edit.php?id=` → clear first name → valid edit → `?id=0` → other's id | Exact errors; "Child profile updated."; "Invalid child id."; "Child not found." | |
| 4.4 | **Growth History** → Weight/Height tabs → **View all →** modal (×/overlay/Esc close) | Chart switches; modal table correct | |
| 4.5 | **Appointments → Request appointment** → pick child card (highlights) → nutritionist → datetime → notes → **Submit request** | "Appointment requested successfully." + Pending row | |
| 4.6 | Open new row → **Cancel Appointment** → confirm → Past tab | "Appointment cancelled." + Cancelled pill; mandatory follow-up shows no cancel button | |
| 4.7 | Gear → **Settings** → each validation error → valid save | Exact wordings; "Account updated successfully." | |
| 4.8 | **Kali AI page**: picker shows ONLY own children → ask → warm reply → sessions persist → **no bubble here** | Privacy holds | |
| 4.9 | Bubble (any other parent page): chips + typing + **Full chat →** | Works; lands on AI page | |
| 4.10 | 360px portrait: burger → drawer → sign-out taps THROUGH visible toast → login + toast; composer pinned; tables scroll internally; no sideways page scroll | All hold | |

## 5. Kiosk + ESP32 (hardware present, else N/A + video fallback)

| ID | Where to go → what to click/type | Must see | Result |
|----|------|----------|--------|
| 5.1 | Kiosk scan → queue → submit | Lands in measurements list | |
| 5.2 | Wrong/missing `X-Device-Key` | 401, nothing written | |
| 5.3 | Power off device → dashboards | Status flips offline, no crash | |

## 6. Finish

| ID | Where to go → what to click/type | Must see | Result |
|----|------|----------|--------|
| 6.1 | Toast types on any page (success/error/info) | Float top-right desktop / bottom ≤480px; auto-dismiss; ✕ works | |
| 6.2 | Dark toggle on 3 pages | Readable text + charts | |
| 6.3 | Sign-out everywhere → login + toast; Back button | Session not resurrected | |
| 6.4 | Apache `error.log` full-run review | Zero new fatals | |
