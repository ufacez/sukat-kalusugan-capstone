<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| KIOSK — ENGLISH (en)
|--------------------------------------------------------------------------
|
| Must keep the exact same keys as tl.php. Values that contain HTML are
| echoed raw by kiosk_index.php (marked "HTML" below) — never user input.
|
*/

return [

    // ----------------------------------------------------------
    // BRAND / WELCOME
    // ----------------------------------------------------------
    'brand.title' => 'Sukat Kalusugan | Kiosk',
    'brand.alt_icon' => 'Sukat Kalusugan Icon',
    'brand.alt_logo' => 'Sukat Kalusugan',
    // HTML
    'welcome.tagline' => 'Right <span class="hl">Measurement</span>, Guide to proper <span class="hl">Health</span>.',
    'welcome.start' => 'START',
    'welcome.starting' => 'Starting...',
    'welcome.device_offline' => 'Device offline',
    'welcome.device_online' => 'Device online',

    // Language toggle
    'lang.switch_label' => 'Language',
    'lang.tagalog' => 'Tagalog',
    'lang.english' => 'English',
    'lang.filipino' => 'Filipino',

    // ----------------------------------------------------------
    // COMMON
    // ----------------------------------------------------------
    'common.back' => 'Back',
    'common.close' => 'Close',
    'common.clear' => 'Clear',
    'common.na' => 'N/A',
    'common.child' => 'Child',
    'common.pending' => 'Pending',

    // ----------------------------------------------------------
    // PRIVACY NOTICE (short summary screen)
    // ----------------------------------------------------------
    'privacy.title' => 'Privacy Notice',
    'privacy.lead' => 'Data privacy reminder before we begin',
    'privacy.collect_title' => 'Data Collection',
    'privacy.collect_sub' => 'Information to be collected',
    'privacy.tag.name' => 'Name',
    'privacy.tag.age' => 'Age',
    'privacy.tag.sex' => 'Sex',
    'privacy.tag.weight' => 'Weight',
    'privacy.tag.height' => 'Height',
    'privacy.tag.barangay' => 'Barangay',
    'privacy.purpose_title' => 'Purpose',
    'privacy.purpose_sub' => 'Why we collect it',
    'privacy.purpose_body' => 'Measurement, nutritional assessment, monitoring, and research for the SukatKalusugan study.',
    'privacy.protection_title' => 'Protection',
    'privacy.protection_sub' => 'How it is protected',
    'privacy.protection_body' => 'Confidential. Accessible only to authorized persons involved in the study.',
    'privacy.read_full' => 'Read the full Privacy Notice',
    'privacy.info_sheet_title' => 'Information Sheet',
    'privacy.info_sheet_sub' => 'Information about the study',
    'privacy.read_info_sheet' => 'Read the Information Sheet',
    'privacy.check_notice' => 'I have read and agree to the Privacy Notice',
    'privacy.check_sheet' => 'I have read and understood the Information Sheet',
    'privacy.continue' => 'CONTINUE',

    // ----------------------------------------------------------
    // CHILD LOOKUP
    // ----------------------------------------------------------
    'lookup.unregistered' => 'Device not registered — contact admin to assign this kiosk.',
    'lookup.ready' => 'KIOSK READY',
    'lookup.title' => 'Who will be measured?',
    'lookup.subtitle' => "Type the Child ID or the child's name to find their record.",
    'lookup.placeholder' => 'Enter Child ID or name...',
    'lookup.search' => 'SEARCH',
    'lookup.no_id' => 'No Child ID?',
    'lookup.tap_here' => 'Tap here!',
    'lookup.searching' => 'Searching for the record...',
    'lookup.searching_sub' => 'Just a moment.',
    'lookup.found' => 'RECORD FOUND',
    'lookup.retry' => 'Search Again',
    'lookup.confirm' => 'Start',
    'lookup.not_due' => 'NOT DUE YET',
    'lookup.recheck' => 'Measure Anyway (double-check)',
    'lookup.help_title' => 'Need help?',
    'lookup.help_body' => 'If the child does not have a Child ID yet, please ask a health worker for help to register the child in the system.',
    'lookup.help_close' => 'CLOSE',
    'lookup.not_found_title' => 'Not found',
    'lookup.no_record' => 'No matching record found. Try another name or Child ID.',
    'lookup.simple_no_record' => 'No record found with that Child ID. Try again.',

    // ----------------------------------------------------------
    // MEASUREMENT
    // ----------------------------------------------------------
    'measure.title' => 'HEIGHT AND WEIGHT MEASUREMENT',
    // HTML
    'measure.subtitle' => 'Stand straight on the platform.<br>Do not move while the measurement is being taken.',
    'measure.height' => 'HEIGHT',
    'measure.weight' => 'WEIGHT',
    'measure.waiting' => 'Waiting...',
    'measure.reminder' => 'Wait for both sensors to become stable before taking the measurement.',
    'measure.process' => 'Process measurement',
    'measure.standing' => 'Stand on the platform...',
    'measure.stand_now' => 'Stand on the platform now.',
    'measure.weight_stable' => 'Weight stable',
    'measure.height_stable' => 'Height stable',
    'measure.weight_reading' => 'Reading weight...',
    'measure.height_reading' => 'Reading height...',
    'measure.final_weight' => 'Final weight stable',
    'measure.final_height' => 'Final height stable',
    'measure.weight_captured' => 'Weight captured',
    'measure.height_captured' => 'Height captured',
    'measure.weight_restored' => 'Weight restored',
    'measure.height_restored' => 'Height restored',
    'measure.wait_weight' => 'Waiting for weight...',
    'measure.wait_height' => 'Waiting for height...',
    'measure.ready_click' => 'Measurement ready. Click Process Measurement.',
    'measure.ready_feed_title' => 'Measurement ready',
    'measure.ready_feed_detail' => 'Weight and height captured. Waiting for operator.',
    'measure.process_btn_busy' => 'Processing...',
    'measure.wait_height_btn' => 'Waiting for Height',
    'measure.wait_weight_btn' => 'Waiting for Weight',
    'measure.wait_stable_btn' => 'Waiting for Stable Reading',
    'measure.stand_still_progress' => 'Stand still while the sensors capture your measurement...',
    'measure.sensors_complete_title' => 'Sensors complete',
    'measure.sensors_complete_detail' => 'Weight and height captured. Click Process Measurement.',
    'measure.start_blocked_title' => 'Start blocked',
    'measure.start_blocked_active' => 'A measurement is already active.',
    'measure.start_blocked_processing' => 'The current measurement is still being processed.',
    'measure.device_offline_feed' => 'Cannot connect to the device. Check the ESP32 and try again.',
    'measure.started_feed_title' => 'Measurement started',
    'measure.started_feed_detail' => '{code} · Session #{id} · waiting for ESP32',
    'measure.start_failed_title' => 'Start failed',
    'measure.start_failed_detail' => 'Unable to contact server.',
    'measure.start_error_default' => 'The measurement did not start. Please try again.',
    'measure.reset' => 'Okay, Go Back',

    // ----------------------------------------------------------
    // PROCESSING
    // ----------------------------------------------------------
    'processing.error_title' => 'Measurement failed',
    'processing.stage' => 'Processing the measurement...',
    'processing.note' => 'Please wait, this will only take a few seconds.',
    'processing.error_default' => 'Something went wrong with the measurement.',
    'processing.failed_default' => 'Measurement processing failed.',
    'processing.unknown_error' => 'Unknown measurement error.',
    'processing.not_confirmed' => 'The server did not confirm the completed measurement.',
    'processing.wait_send' => 'Waiting for the device to send the measurement...',
    'processing.gathering' => 'Gathering data for the growth indicators...',
    'processing.finalizing' => 'Finalizing the measurement, please wait...',
    'processing.waiting_sql' => 'Waiting for SQL to confirm the measurement...',
    'processing.started_feed_title' => 'Processing started',
    'processing.started_feed_detail' => '{code} · Session #{id} · waiting for ESP32 to finalize',
    'processing.failed_feed_title' => 'Processing failed',
    'processing.blocked_title' => 'Processing blocked',
    'processing.no_child' => 'No child selected.',
    'processing.not_ready' => 'Weight and height are not ready.',
    'processing.no_session' => 'No active measurement session.',
    'processing.missing_session_id' => 'The measurement session ID is missing.',
    'processing.waiting_device_btn' => 'Waiting for the device...',
    'processing.device_not_ready' => 'The device is not ready yet. Please try again in a few seconds.',
    'processing.submitting_btn' => 'Submitting...',
    'processing.request_failed' => 'Could not request processing from the device.',
    'processing.server_unreachable' => 'Could not reach the server to request processing.',
    'processing.complete_progress' => 'Measurement complete',

    // ----------------------------------------------------------
    // RESULTS
    // ----------------------------------------------------------
    'results.title' => 'RESULT',
    'results.subtitle' => 'Here is the measurement result.',
    'results.weight_label' => 'Weight',
    'results.height_label' => 'Height',
    'results.section' => 'NUTRITIONAL STATUS',
    'results.axis_wfa' => 'Weight-for-Age (WFA)',
    'results.axis_hfa' => 'Height-for-Age (HFA)',
    'results.axis_wflh' => 'Weight-for-Length/Height (WFL/H)',
    'results.done' => 'Done',
    'results.ready_note' => 'Ready to measure',
    'results.name_placeholder' => 'Name',
    'results.age_placeholder' => '-- months old',
    'results.source_chain' => 'ESP32 → Firebase → SQL → WHO',
    'results.source_chain_short' => 'ESP32 → Firebase → SQL',
    'results.firebase_unavailable' => 'Firebase unavailable',
    'results.complete_progress' => 'Measurement complete',
    'results.complete_feed_title' => 'Measurement complete',
    'results.flag_default' => 'One or more readings look unusual for this child. Please double-check height and weight.',
    'results.select_child' => 'Choose a child',

    // ----------------------------------------------------------
    // THANK YOU
    // ----------------------------------------------------------
    'thankyou.title' => 'Success!',
    // HTML
    'thankyou.msg' => 'The measurement has been saved<br>to the system.',
    'thankyou.home' => 'Back to Home',
    'thankyou.countdown' => 'Returning home in {n}s — or tap Back to Home.',

    // ----------------------------------------------------------
    // VIEW RESULTS
    // ----------------------------------------------------------
    'view.title' => 'RESULTS',
    'view.subtitle' => 'Select a child to view previous results.',
    'view.search' => 'Search for a name...',
    'view.empty' => 'No registered children on this kiosk yet.',
    'view.no_match' => 'No results found for "{q}"',

    // ----------------------------------------------------------
    // INFO SCREEN
    // ----------------------------------------------------------
    // HTML
    'info.title' => 'ABOUT<br>SUKAT KALUSUGAN',
    'info.about_title' => 'What is Sukat Kalusugan?',
    'info.about_body' => 'Sukat Kalusugan is a system for monitoring the nutrition and health of children. It measures the height and weight of a child to determine whether they fall within the correct nutritional status according to WHO standards.',
    'info.how_title' => 'How It Works?',
    // HTML
    'info.how_body' => '1. Tap <strong>"Start"</strong><br>2. Enter the child\'s name, date of birth, sex, and barangay<br>3. Follow the instructions for measuring height and weight<br>4. View the result after the measurement',
    'info.measure_title' => 'Measurements',
    // HTML
    'info.measure_body' => '<strong>Height:</strong> The child\'s height is measured while standing straight. This is important for Height-for-Age (HFA).<br><br><strong>Weight:</strong> The child\'s weight is measured on the scale. This is important for Weight-for-Age (WFA) and Weight-for-Length/Height (WFL/H).',
    'info.status_title' => 'Nutritional Status',
    // HTML
    'info.status_body' => 'The system uses WHO z-scores to determine the status:<br><br><span class="kiosk-info-badge is-normal">Normal</span> – Within the correct weight and height<br><span class="kiosk-info-badge is-underweight">Underweight</span> – Low weight for age<br><span class="kiosk-info-badge is-stunted">Stunted</span> – Low height for age<br><span class="kiosk-info-badge is-wasted">Wasted</span> – Low weight for height<br><span class="kiosk-info-badge is-overweight">Overweight</span> – Heavy for height',
    'info.reminders_title' => 'Reminders',
    // HTML
    'info.reminders_body' => '• Make sure the information entered is correct<br>• The results are for monitoring only and are not a substitute for a doctor\'s consultation<br>• If in doubt, consult your nutritionist or doctor<br>• For emergencies, call your local health center',

    // ----------------------------------------------------------
    // SIDE PANEL
    // ----------------------------------------------------------
    'feed.title' => 'Activity',

    // ----------------------------------------------------------
    // DEVICE STATUS
    // ----------------------------------------------------------
    'device.online' => 'Device online',
    'device.offline' => 'Device offline',
    'device.hero_online' => 'Select a child, then start the measurement.',
    'device.hero_offline' => 'Kiosk device is offline. Check the ESP32 power and Wi-Fi connection.',
    'device.stopped_feed_title' => 'Device offline',
    'device.stopped_feed_detail' => 'The kiosk device stopped responding.',
    'device.online_feed_title' => 'Device online',
    'device.online_feed_detail' => 'The kiosk device is connected and ready.',
    'device.lost_contact' => 'Lost contact with the kiosk device.',
    'device.ws_connected_title' => 'WebSocket connected',
    'device.ws_connected_detail' => 'Live sensor data via local network.',

    // ----------------------------------------------------------
    // SENSOR CHIPS
    // ----------------------------------------------------------
    'chip.device_connected' => 'Device: Connected',
    'chip.device_online' => 'Device: Online',
    'chip.device_offline' => 'Device: Offline',
    'chip.device_waiting' => 'Device: Waiting',
    'chip.device_firebase_off' => 'Device: Firebase Off',
    'chip.device_firebase_ready' => 'Device: Firebase Ready',
    'chip.device_firebase_error' => 'Device: Firebase Error',
    'chip.lidar_ready' => 'LiDAR: Ready',
    'chip.lidar_waiting' => 'LiDAR: Waiting',
    'chip.lidar_live' => 'LiDAR: Live',
    'chip.lidar_offline' => 'LiDAR: Offline',
    'chip.scale_ready' => 'Scale: Ready',
    'chip.scale_waiting' => 'Scale: Waiting',
    'chip.scale_live' => 'Scale: Live',
    'chip.scale_offline' => 'Scale: Offline',

    // ----------------------------------------------------------
    // ACTIVITY FEED
    // ----------------------------------------------------------
    'feed.child_lookup_title' => 'Child lookup',
    'feed.child_lookup_detail' => 'Enter Child ID to find the record.',
    'feed.selection_locked_title' => 'Selection locked',
    'feed.selection_locked_active' => 'Wait for the current measurement to finish.',
    'feed.selection_locked_processing' => 'The current measurement is being processed.',
    'feed.child_selected_title' => 'Child selected',
    'feed.children_updated_title' => 'List updated',
    'feed.children_updated_detail' => '{n} new children added to the system.',
    'feed.session_restored_title' => 'Session restored',
    'feed.session_restored_detail' => 'Restored active measurement session. Waiting for sensors...',
    'feed.session_mismatch_detail' => 'Expected SQL session #{expected}, but the server returned #{received}.',
    'feed.reset_blocked_title' => 'Reset blocked',
    'feed.reset_blocked_detail' => 'Wait for measurement processing to finish.',
    'feed.kiosk_reset_title' => 'Kiosk reset',
    'feed.kiosk_reset_detail' => 'Ready for the next child.',
    'feed.back_to_lookup_title' => 'Back to lookup',
    'feed.session_cancelled_detail' => 'Session cancelled. Enter Child ID to continue.',
    'feed.child_deselected_detail' => 'Child deselected. Enter Child ID to continue.',
    'feed.back_blocked_title' => 'Back blocked',
    'feed.back_blocked_detail' => 'The current measurement is being processed.',
    'feed.cancelling_title' => 'Cancelling',
    'feed.cancelling_detail' => 'Ending active measurement session...',
    'feed.no_child_title' => 'No child selected',
    'feed.no_child_double_check' => 'Find a child first before double-checking.',
    'feed.no_child_restart' => 'Find a child first before repeating the measurement.',
    'feed.no_child_continue' => 'Find a child first before continuing.',
    'feed.double_check_title' => 'Double-check',
    'feed.double_check_detail' => 'Recheck for {name} — the schedule will not change.',
    'feed.measure_again_title' => 'Measure again',
    'feed.measure_again_detail' => 'Double-check measurement — the schedule will not change.',
    'feed.restart_title' => 'Measurement restarted',
    'feed.restart_recheck' => 'New double-check session — the schedule will not change.',
    'feed.restart_normal' => 'New measurement for the same child.',
    'feed.child_selected_confirm_title' => 'Child selected',

    // ----------------------------------------------------------
    // NOT DUE
    // ----------------------------------------------------------
    'notdue.reason' => 'Not due yet. Next scheduled: {date}{days}.',
    'notdue.aged_out' => 'This child is older than 59 months and is not covered by this kiosk.',
    'notdue.days' => ' (in {n} days)',
    'notdue.already_today' => 'Already measured today. You can still double-check without changing the schedule.',

    // ----------------------------------------------------------
    // AGE / SEX
    // ----------------------------------------------------------
    'age.years_months' => '{y} years, {m} months',
    'age.months' => '{m} months',
    'age.years_months_days' => '{y} years, {m} months ({d} days)',
    'age.months_days' => '{m} months ({d} days)',
    'age.years_months_dot_days' => '{y} years, {m} months · {d} days',
    'age.months_dot_days' => '{m} months · {d} days',
    'age.months_days_short' => '{m} months',
    'sex.male' => 'Male',
    'sex.female' => 'Female',

    // ----------------------------------------------------------
    // NUTRITIONAL STATUS LABELS
    // ----------------------------------------------------------
    'status.normal' => 'Normal',
    'status.tall' => 'Tall',
    'status.moderately_underweight' => 'Moderately Underweight',
    'status.severely_underweight' => 'Severely Underweight',
    'status.overweight' => 'Overweight',
    'status.obese' => 'Obese',
    'status.moderately_stunted' => 'Moderately Stunted',
    'status.severely_stunted' => 'Severely Stunted',
    'status.moderately_wasted' => 'Moderately Wasted',
    'status.severely_wasted' => 'Severely Wasted',
    'status.pending' => 'Pending',

    // ----------------------------------------------------------
    // CLIENT-SIDE FALLBACKS / VALIDATION
    // ----------------------------------------------------------
    'errors.start_failed' => 'Could not start measurement.',
    'errors.invalid_session_response' => 'Server did not return a valid measurement session ID.',
    'errors.unable_contact_server' => 'Unable to contact server.',
    'errors.confirm_cancel_session' => 'A measurement is active. Going back will cancel the current session. Continue?',

    // ----------------------------------------------------------
    // SERVER ERROR CODES (must match api/kiosk/*.php `code` values)
    // ----------------------------------------------------------
    'errors.invalid_json' => 'Invalid request. Please try again.',
    'errors.invalid_device' => 'Invalid device ID.',
    'errors.device_not_found' => 'Device not found.',
    'errors.device_not_registered' => 'This device is not registered. Please contact the administrator.',
    'errors.child_required' => 'A valid child must be selected.',
    'errors.child_not_found' => 'Child not found or inactive.',
    'errors.child_barangay' => 'This child is not authorized for measurement at this kiosk (different barangay).',
    'errors.session_id_required' => 'A valid session ID is required.',
    'errors.session_not_found' => 'Measurement session not found for this device.',
    'errors.session_mismatch' => 'Measurement session mismatch.',
    'errors.session_not_active' => 'Measurement is not currently active for this session.',
    'errors.session_moved_on' => 'Measurement session already moved on. Please start again.',
    'errors.session_already_active' => 'A measurement is already active for this kiosk.',
    'errors.session_load_failed' => 'The measurement session could not be loaded. Please try again.',
    'errors.unable_cancel' => 'Unable to cancel the session.',
    'errors.unable_process' => 'Unable to request processing.',
    'errors.unable_device_lookup' => 'Unable to prepare the device lookup.',
    'errors.server_error' => 'Something went wrong. Please try again.',
    'errors.unable_load_status' => 'Unable to load measurement status.',

    // ----------------------------------------------------------
    // FULL PRIVACY NOTICE DOCUMENT
    // ----------------------------------------------------------
    'doc.privacy_title' => 'PRIVACY NOTICE',
    'doc.understand' => 'I UNDERSTAND',
    // HTML
    'doc.privacy_body' => <<<'KIOSK_HTML'
<p class="kiosk-modal-lead">Your privacy matters to us.</p>
<p>SukatKalusugan is a study-based system designed to support the collection and assessment of children's anthropometric measurements. Before participating, we want you to understand how information collected through the system will be handled.</p>

<h4>1. INFORMATION WE COLLECT</h4>
<p>For the purposes of the study, SukatKalusugan may collect information necessary for the assessment of the child, including:</p>
<ul>
    <li>Child ID or reference number</li>
    <li>Name, when required</li>
    <li>Date of birth or age</li>
    <li>Sex</li>
    <li>Barangay or other information required by the study</li>
    <li>Weight</li>
    <li>Height or length</li>
    <li>Date and time of measurement</li>
    <li>Nutritional assessment results</li>
    <li>WHO-based growth assessment and classification</li>
</ul>
<p>Only information necessary for the stated study purposes will be collected.</p>

<h4>2. WHY DO WE COLLECT THIS INFORMATION?</h4>
<p>The information is collected for the purposes of the SukatKalusugan study, including:</p>
<ul>
    <li>conducting anthropometric measurements;</li>
    <li>assessing the nutritional status of children;</li>
    <li>generating nutritional assessment results;</li>
    <li>supporting monitoring and reporting related to the study; and</li>
    <li>supporting the research, evaluation, and development of the SukatKalusugan system.</li>
</ul>
<p>The information will only be used for the specific purposes covered by the approved study and data request.</p>

<h4>3. HOW WILL THE INFORMATION BE USED?</h4>
<p>The collected information may be processed to generate measurements and nutritional indicators such as:</p>
<ul>
    <li>Weight-for-Age (WFA)</li>
    <li>Height-for-Age (HFA)</li>
    <li>Weight-for-Height/Length (WFH/WFL)</li>
</ul>
<p>The system may use the child's age, sex, weight, and height/length to generate the corresponding assessment results based on the applicable WHO Child Growth Standards.</p>

<h4>4. WHO MAY ACCESS THE INFORMATION?</h4>
<p>Access to the collected information will be limited to authorized individuals involved in the study and the operation of the SukatKalusugan system, according to their assigned responsibilities.</p>
<p>Personal information of the child will not be made publicly available or accessed by unauthorized persons.</p>

<h4>5. HOW WILL THE INFORMATION BE PROTECTED?</h4>
<ul>
    <li>Reasonable technical, physical, and organizational safeguards will be applied to protect the information against unauthorized access, disclosure, alteration, loss, or misuse.</li>
    <li>Authorized system users will only be given access to information necessary for their assigned responsibilities.</li>
    <li>All persons who are authorized to access the information are expected to maintain its confidentiality.</li>
</ul>

<h4>6. CONFIDENTIALITY</h4>
<p>Information collected through the study will be treated as confidential.</p>
<p>The information obtained through the approved data request will be used only for the specific purposes stated in the study and related documentation.</p>
<p>Unauthorized access, processing, use, or disclosure of the information is not permitted.</p>

<h4>7. DATA SHARING AND DISCLOSURE</h4>
<p>Personal information will not be disclosed or shared with unauthorized individuals or organizations.</p>
<p>Any authorized use, disclosure, or processing of the information must be consistent with the purposes of the study and applicable data privacy requirements.</p>

<h4>8. DATA RETENTION</h4>
<p>Study information will be retained only for the period necessary to fulfill the approved purposes of the study and applicable requirements.</p>
<p>When the information is no longer necessary, it will be securely disposed of, deleted, or anonymized when appropriate and in accordance with the applicable study policies.</p>

<h4>9. YOUR PRIVACY RIGHTS</h4>
<p>Under the Data Privacy Act of 2012 (Republic Act No. 10173), data subjects have rights concerning their personal information, subject to applicable laws and requirements.</p>
<p>These may include the right to:</p>
<ul>
    <li>be informed about the processing of personal information;</li>
    <li>access personal information;</li>
    <li>request correction of inaccurate information;</li>
    <li>object to certain processing when applicable; and</li>
    <li>request deletion or blocking when applicable.</li>
</ul>
<p>For information concerning a child, these rights may be exercised by the child's parent or legal guardian, when appropriate.</p>

<h4>10. DATA PRIVACY COMMITMENT</h4>
<p>SukatKalusugan recognizes the importance of protecting children's personal information.</p>
<p>The collection and processing of information for this study will be conducted in accordance with the Republic Act No. 10173, or the Data Privacy Act of 2012, and the applicable data privacy policies and security procedures governing the approved study and data request.</p>

<h4>ACKNOWLEDGMENT</h4>
<p>Before proceeding with the measurement, please make sure that you have read and understood this Privacy Notice.</p>
<p><em>I have read and understood this Privacy Notice and understand how my child's information may be collected and used for the purposes of the SukatKalusugan study.</em></p>
KIOSK_HTML,

    // ----------------------------------------------------------
    // FULL INFORMATION SHEET DOCUMENT
    // ----------------------------------------------------------
    'doc.info_title' => 'INFORMATION SHEET',
    // HTML
    'doc.info_body' => <<<'KIOSK_HTML'
<h4>Introduction</h4>
<p>We are students from the College of Computer Studies at Our Lady of Fatima University conducting research for our capstone project, "SukatKalusugan: A Smart Kiosk-Based Anthropometric Monitoring System with a Web Application for the City Health Office in the City of San Fernando, Pampanga." This study is being carried out at the City Health Office of the City of San Fernando, Pampanga, with the aim of improving the speed and accuracy of data collection through the use of innovative technology.</p>

<h4>Purpose of the Research</h4>
<p>The purpose of this study is to design and develop a smart kiosk-based system for monitoring anthropometric measures that integrates IoT sensors, AI analysis for decision supporting, and a web application for the collection, monitoring, and management of anthropometric data. The system aims to provide accurate real-time measurements and identify trends and patterns in anthropometric classifications among children aged 24-59 months in accordance to WHO standards, supporting health monitoring and promotes awareness of their nutritional and physical growth status.</p>

<h4>Type of Research Intervention</h4>
<p>Participation will involve parents, nutritionists, and IT experts testing the smart kiosk-based anthropometric monitoring system with the help of City Nutrition Program Coordinators from City Health Office. Participants will use the kiosk and web application to collect and view anthropometric data, while the system tracks measurements over time to identify trends and patterns that may support follow-up monitoring. Participants will also evaluate the system through an ISO 25010-based survey and interview.</p>

<h4>Participant Selection</h4>
<p>You are invited to participate because your experience as a parent/guardian, nutritionist, or IT expert is valuable in helping us understand child nutrition monitoring and in improving the system.</p>

<h4>Voluntary Participation</h4>
<p>Your participation in this study is entirely voluntary. You may choose not to participate or to withdraw from the study at any time without providing a reason. If you decide to withdraw, you will no longer be considered a participant and will no longer be involved in any part of the study or its activities. Choosing not to participate or withdrawing from the study will not result in any penalty, disadvantage, or loss of any health services or benefits that you are entitled to receive from the City Health Office. Your decision will not affect your relationship with the researchers, the City Health Office, Our Lady of Fatima University, or any other institution involved in the study.</p>

<h4>Procedures</h4>
<p>The researchers invite you to help them understand the child nutrition monitoring methods at the City Health Office of the City of San Fernando, Pampanga and how the SukatKalusugan system can improve the process. If you agree to participate, you will be asked to sign consent forms for documentation.</p>
<p>You will be asked to:</p>
<ul>
    <li>Interact with the SukatKalusugan system (kiosk and web application) to collect and view children's anthropometric measurements, which will take approximately 1 hour.</li>
    <li>Complete a survey based on ISO 25010 standards to evaluate the system's functionality, reliability, usability, and other quality attributes, which will take approximately 15-20 minutes.</li>
    <li>Participate in a brief interview (10-15 minutes) to provide feedback on your experience.</li>
</ul>
<p>If you prefer not to answer any question in the survey or in the interview, you may skip them and proceed to the next activity. All information collected will be kept confidential. Your name will not appear on the survey or interview records; instead, a unique number will identify your responses. Only the researchers, the capstone adviser, and panelists will have access to the data.</p>

<h4>Duration</h4>
<p>The activities will take approximately 1 hour in total to complete. The research will be conducted from June 2026 to December 2026. During this period, we may contact you occasionally through email or phone for follow-up or additional input.</p>

<h4>Risks</h4>
<p>There are no anticipated physical risks associated with this study. The anthropometric measurements collected by the system are non-invasive and will not cause physical harm to participants. However, there may be minor discomfort or inconvenience while using the kiosk or providing information required for the study. Participants may choose not to answer any question or may withdraw from the study at any time without penalty. To protect privacy and confidentiality, appropriate security measures will be implemented to safeguard the data collected through the kiosk and web application.</p>

<h4>Benefits</h4>
<p>As a parent/guardian, nutritionist, or IT expert, your participation will help improve the development of a smart kiosk-based anthropometric monitoring system for children aged 24–59 months. Your involvement will contribute to evaluating the system's ability to collect anthropometric measurements, monitor records, and identify trends and patterns over time. You will gain experience with an innovative IoT and your feedback will help improve the usability, functionality, and reliability of the kiosk and web application.</p>

<h4>Reimbursements</h4>
<p>There will be no financial or material incentives provided for participating in this research.</p>

<h4>Confidentiality</h4>
<p>All information collected during this study will be kept strictly confidential and will only be accessible to the research team, the capstone adviser, and panel members for academic evaluation purposes. Personal information and anthropometric records will be stored securely, and participant identities will be protected through the use of unique identifiers or codes instead of names whenever possible. The collected data will be used solely for research purposes and will be handled in accordance with the Data Privacy Act of 2012 (Republic Act No. 10173) of the Philippines. Upon completion of the study, all records will be securely retained or disposed of following institutional and data privacy guidelines.</p>

<h4>Sharing the Results</h4>
<p>Any information you provide during this study will remain confidential and will not be shared outside the research team. No data will be linked to your name. The insights gained from this research will be shared with you and the City Health Office of the City of San Fernando Pampanga before any public dissemination. The researchers plan to publish the results so others may benefit from the study as well.</p>

<h4>Right to Refuse or Withdraw</h4>
<p>Participation in this research is entirely voluntary. Choosing not to participate will not affect your employment, duties, responsibilities, or relationship with the City Health Office in any way. You may also withdraw from the study at any time without any penalty or negative consequences. At the end of the data collection process, you will have the opportunity to review your responses and may request modifications or the removal of any information that you are not comfortable sharing.</p>
KIOSK_HTML,

];
