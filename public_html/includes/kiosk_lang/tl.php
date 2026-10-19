<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| KIOSK — TAGALOG (tl)
|--------------------------------------------------------------------------
|
| Must keep the exact same keys as en.php. Values that contain HTML are
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
    'welcome.tagline' => 'Tamang <span class="hl">Sukat</span>, Gabay sa wastong <span class="hl">Kalusugan</span>.',
    'welcome.start' => 'SIMULAN',
    'welcome.starting' => 'Sinisimulan...',
    'welcome.device_offline' => 'Offline ang device',
    'welcome.device_online' => 'Online ang device',

    // Language toggle
    'lang.switch_label' => 'Wika',
    'lang.tagalog' => 'Tagalog',
    'lang.english' => 'English',
    'lang.filipino' => 'Filipino',

    // ----------------------------------------------------------
    // COMMON
    // ----------------------------------------------------------
    'common.back' => 'Bumalik',
    'common.close' => 'Isara',
    'common.clear' => 'Linisin',
    'common.na' => 'N/A',
    'common.child' => 'Bata',
    'common.pending' => 'Nakabinbin',

    // ----------------------------------------------------------
    // PRIVACY NOTICE (short summary screen)
    // ----------------------------------------------------------
    'privacy.title' => 'Paunawa sa Privacy',
    'privacy.lead' => 'Paalala sa Data Privacy bago magsimula',
    'privacy.collect_title' => 'Pagkolekta ng Data',
    'privacy.collect_sub' => 'Impormasyong kokolektahin',
    'privacy.tag.name' => 'Pangalan',
    'privacy.tag.age' => 'Edad',
    'privacy.tag.sex' => 'Kasarian',
    'privacy.tag.weight' => 'Timbang',
    'privacy.tag.height' => 'Taas',
    'privacy.tag.barangay' => 'Barangay',
    'privacy.purpose_title' => 'Layunin',
    'privacy.purpose_sub' => 'Bakit kokolektahin',
    'privacy.purpose_body' => 'Pagsukat, nutritional assessment, monitoring, at research para sa SukatKalusugan study.',
    'privacy.protection_title' => 'Proteksyon',
    'privacy.protection_sub' => 'Paano poprotektahan',
    'privacy.protection_body' => 'Kumpidensyal. Maa-access lamang ng awtorisadong tao na kasangkot sa pag-aaral.',
    'privacy.read_full' => 'Basahin ang buong Privacy Notice',
    'privacy.info_sheet_title' => 'Information Sheet',
    'privacy.info_sheet_sub' => 'Impormasyon tungkol sa pag-aaral',
    'privacy.read_info_sheet' => 'Basahin ang Information Sheet',
    'privacy.check_notice' => 'Nabasa at sang-ayon ako sa Privacy Notice',
    'privacy.check_sheet' => 'Nabasa at naintindihan ko ang Information Sheet',
    'privacy.continue' => 'MAGPATULOY',

    // ----------------------------------------------------------
    // CHILD LOOKUP
    // ----------------------------------------------------------
    'lookup.unregistered' => 'Hindi rehistrado ang device — makipag-ugnayan sa admin para i-assign ang kiosk na ito.',
    'lookup.ready' => 'HANDA ANG KIOSK',
    'lookup.title' => 'Sino ang susukatin?',
    'lookup.subtitle' => 'I-type ang Child ID o pangalan ng bata para mahanap ang record niya.',
    'lookup.placeholder' => 'Ilagay ang Child ID o pangalan...',
    'lookup.search' => 'HANAPIN',
    'lookup.no_id' => 'Walang Child ID?',
    'lookup.tap_here' => 'Pindutin ito!',
    'lookup.searching' => 'Hahanapin ang record...',
    'lookup.searching_sub' => 'Sandali lamang.',
    'lookup.found' => 'NAHANAP ANG RECORD',
    'lookup.retry' => 'Hanapin Ulit',
    'lookup.confirm' => 'Simulan na',
    'lookup.not_due' => 'HINDI PA DUE',
    'lookup.recheck' => 'Sukatin Pa Rin (double-check)',
    'lookup.help_title' => 'Kailangan ng tulong?',
    'lookup.help_body' => 'Kung wala pang Child ID ang bata, mangyaring magpatulong sa health worker upang ma-register ang bata sa system.',
    'lookup.help_close' => 'ISARA',
    'lookup.not_found_title' => 'Hindi nahanap',
    'lookup.no_record' => 'Walang nahanap na record. Subukan ang ibang pangalan o Child ID.',
    'lookup.simple_no_record' => 'Walang record na may ganiyang Child ID. Subukan muli.',

    // ----------------------------------------------------------
    // MEASUREMENT
    // ----------------------------------------------------------
    'measure.title' => 'SUKAT NG TAAS AT TIMBANG',
    // HTML
    'measure.subtitle' => 'Tumayo nang tuwid sa platform.<br>Huwag muna gumalaw habang sinusukat.',
    'measure.height' => 'TAAS',
    'measure.weight' => 'TIMBANG',
    'measure.waiting' => 'Naghihintay...',
    'measure.reminder' => 'Hintaying maging stable ang dalawang sensor bago kunin ang sukat.',
    'measure.process' => 'Iproseso ang sukat',
    'measure.standing' => 'Tumayo sa platform...',
    'measure.stand_now' => 'Tumayo sa platform ngayon.',
    'measure.weight_stable' => 'Timbang stable',
    'measure.height_stable' => 'Taas stable',
    'measure.weight_reading' => 'Binabasa ang timbang...',
    'measure.height_reading' => 'Binabasa ang taas...',
    'measure.final_weight' => 'Huling timbang na stable',
    'measure.final_height' => 'Huling taas na stable',
    'measure.weight_captured' => 'Nakuha na ang timbang',
    'measure.height_captured' => 'Nakuha na ang taas',
    'measure.weight_restored' => 'Naibalik ang timbang',
    'measure.height_restored' => 'Naibalik ang taas',
    'measure.wait_weight' => 'Naghihintay ng timbang...',
    'measure.wait_height' => 'Naghihintay ng taas...',
    'measure.ready_click' => 'Handa na ang sukat. Pindutin ang "Iproseso ang sukat".',
    'measure.ready_feed_title' => 'Handa na ang sukat',
    'measure.ready_feed_detail' => 'Nakuha na ang timbang at taas. Hinihintay ang operator.',
    'measure.process_btn_busy' => 'Pinoproseso...',
    'measure.wait_height_btn' => 'Naghihintay ng Taas',
    'measure.wait_weight_btn' => 'Naghihintay ng Timbang',
    'measure.wait_stable_btn' => 'Naghihintay ng Stable na Reading',
    'measure.stand_still_progress' => 'Tumayo nang hindi gumagalaw habang kinukuha ng sensor ang sukat...',
    'measure.sensors_complete_title' => 'Kumpleto na ang sensors',
    'measure.sensors_complete_detail' => 'Nakuha na ang timbang at taas. Pindutin ang "Iproseso ang sukat".',
    'measure.start_blocked_title' => 'Hindi makapagsimula',
    'measure.start_blocked_active' => 'May aktibong pagsukat na.',
    'measure.start_blocked_processing' => 'Pinoproseso pa ang kasalukuyang pagsukat.',
    'measure.device_offline_feed' => 'Hindi makakonekta sa device. I-check ang ESP32 at subukan muli.',
    'measure.started_feed_title' => 'Nagsimula ang pagsukat',
    'measure.started_feed_detail' => '{code} · Session #{id} · hinihintay ang ESP32',
    'measure.start_failed_title' => 'Hindi nagsimula',
    'measure.start_failed_detail' => 'Hindi ma-contact ang server.',
    'measure.start_error_default' => 'Hindi nagsimula ang pagsukat. Subukan muli.',
    'measure.reset' => 'Okay, Bumalik',

    // ----------------------------------------------------------
    // PROCESSING
    // ----------------------------------------------------------
    'processing.error_title' => 'Nabigo ang pagsukat',
    'processing.stage' => 'Pinoproseso ang pagsukat...',
    'processing.note' => 'Maghintay lamang, ilang segundo lang ito.',
    'processing.error_default' => 'May naganap na error sa pagsukat.',
    'processing.failed_default' => 'Nabigo ang pagproseso ng sukat.',
    'processing.unknown_error' => 'Hindi kilalang error sa pagsukat.',
    'processing.not_confirmed' => 'Hindi kinumpirma ng server ang kumpletong sukat.',
    'processing.wait_send' => 'Hinihintay na ipadala ng device ang sukat...',
    'processing.gathering' => 'Kumukuha ng data para sa mga growth indicators...',
    'processing.finalizing' => 'Fina-finalize na yung sukat, wait lang...',
    'processing.waiting_sql' => 'Hinihintay kumpirmahin ng SQL ang sukat...',
    'processing.started_feed_title' => 'Nagsimula ang pagproseso',
    'processing.started_feed_detail' => '{code} · Session #{id} · hinihintay ang ESP32 na tapusin',
    'processing.failed_feed_title' => 'Nabigo ang pagproseso',
    'processing.blocked_title' => 'Hindi makapag-proseso',
    'processing.no_child' => 'Walang napiling bata.',
    'processing.not_ready' => 'Hindi pa handa ang timbang at taas.',
    'processing.no_session' => 'Walang aktibong sesyon ng pagsukat.',
    'processing.missing_session_id' => 'Kulang ang session ID ng pagsukat.',
    'processing.waiting_device_btn' => 'Hinihintay ang device...',
    'processing.device_not_ready' => 'Ang device ay hindi pa handa. Subukan ulit pagkatapos ng ilang segundo.',
    'processing.submitting_btn' => 'Isinusumite...',
    'processing.request_failed' => 'Hindi ma-request ang pagproseso mula sa device.',
    'processing.server_unreachable' => 'Hindi maabot ang server para i-request ang pagproseso.',
    'processing.complete_progress' => 'Kumpleto na ang pagsukat',

    // ----------------------------------------------------------
    // RESULTS
    // ----------------------------------------------------------
    'results.title' => 'RESULTA',
    'results.subtitle' => 'Narito ang resulta ng pagsukat.',
    'results.weight_label' => 'Timbang',
    'results.height_label' => 'Taas',
    'results.section' => 'STATUS NUTRISYONAL',
    'results.axis_wfa' => 'Weight-for-Age (WFA)',
    'results.axis_hfa' => 'Height-for-Age (HFA)',
    'results.axis_wflh' => 'Weight-for-Length/Height (WFL/H)',
    'results.done' => 'Tapos na',
    'results.ready_note' => 'Handa nang sumukat',
    'results.name_placeholder' => 'Pangalan',
    'results.age_placeholder' => '-- buwan',
    'results.source_chain' => 'ESP32 → Firebase → SQL → WHO',
    'results.source_chain_short' => 'ESP32 → Firebase → SQL',
    'results.firebase_unavailable' => 'Hindi available ang Firebase',
    'results.complete_progress' => 'Kumpleto na ang pagsukat',
    'results.complete_feed_title' => 'Kumpleto na ang pagsukat',
    'results.flag_default' => 'May mga reading na mukhang hindi karaniwan para sa batang ito. Paki-double-check ang taas at timbang.',
    'results.select_child' => 'Pumili ng bata',

    // ----------------------------------------------------------
    // THANK YOU
    // ----------------------------------------------------------
    'thankyou.title' => 'Matagumpay!',
    // HTML
    'thankyou.msg' => 'Nai-save na ang sukat<br>sa system.',
    'thankyou.home' => 'Balik sa Home',
    'thankyou.countdown' => 'Babalik sa Home sa {n}s — o pindutin ang Balik sa Home.',

    // ----------------------------------------------------------
    // VIEW RESULTS
    // ----------------------------------------------------------
    'view.title' => 'MGA RESULTA',
    'view.subtitle' => 'Piliin ang bata para makita ang mga naunang resulta.',
    'view.search' => 'Hanapin ang pangalan...',
    'view.empty' => 'Wala pang rehistradong bata sa kiosk na ito.',
    'view.no_match' => 'Walang nakitang resulta para sa "{q}"',

    // ----------------------------------------------------------
    // INFO SCREEN
    // ----------------------------------------------------------
    // HTML
    'info.title' => 'TUNGKOL SA<br>SUKAT KALUSUGAN',
    'info.about_title' => 'Ano ang Sukat Kalusugan?',
    'info.about_body' => 'Ang Sukat Kalusugan ay isang sistema para sa pagsubaybay sa nutrisyon at kalusugan ng mga bata. Sinusukat ang taas at timbang ng bata upang matukoy kung siya ay nabibilang sa wastong nutritional status ayon sa mga pamantayan ng WHO.',
    'info.how_title' => 'Paano Gumagana?',
    // HTML
    'info.how_body' => '1. Pindutin ang <strong>"Simulan"</strong><br>2. Ilagay ang pangalan, araw ng kapanganakan, kasarian, at barangay ng bata<br>3. Sundin ang mga tagubilin para sa pagsukat ng taas at timbang<br>4. Tingnan ang resulta pagkatapos ng pagsukat',
    'info.measure_title' => 'Mga Sukat',
    // HTML
    'info.measure_body' => '<strong>Taas (Height):</strong> Sinusukat ang taas ng bata nang nakatayo nang tuwid. Mahalaga para sa Height-for-Age (HFA).<br><br><strong>Timbang (Weight):</strong> Sinusukat ang timbang ng bata sa timbangan. Mahalaga para sa Weight-for-Age (WFA) at Weight-for-Length/Height (WFL/H).',
    'info.status_title' => 'Nutritional Status',
    // HTML
    'info.status_body' => 'Gumagamit ang system ng WHO z-scores para matukoy ang status:<br><br><span class="kiosk-info-badge is-normal">Normal</span> – Nasa wastong timbang at taas<br><span class="kiosk-info-badge is-underweight">Kulang sa Timbang</span> – Mababa ang timbang para sa edad<br><span class="kiosk-info-badge is-stunted">Bansot</span> – Mababa ang taas para sa edad<br><span class="kiosk-info-badge is-wasted">Payat</span> – Mababa ang timbang para sa taas<br><span class="kiosk-info-badge is-overweight">Sobra sa Timbang</span> – Mabigat para sa taas',
    'info.reminders_title' => 'Mga Paalala',
    // HTML
    'info.reminders_body' => '• Siguraduhing tama ang impormasyong inilagay<br>• Ang mga resulta ay para sa monitoring lamang at hindi kapalit ng konsultasyon sa doktor<br>• Kung may alinlangan, kumonsulta sa inyong nutritionist o doktor<br>• Para sa mga emergency, tumawag sa inyong local health center',

    // ----------------------------------------------------------
    // SIDE PANEL
    // ----------------------------------------------------------
    'feed.title' => 'Aktibidad',

    // ----------------------------------------------------------
    // DEVICE STATUS
    // ----------------------------------------------------------
    'device.online' => 'Online ang device',
    'device.offline' => 'Offline ang device',
    'device.hero_online' => 'Pumili ng bata, pagkatapos ay simulan ang pagsukat.',
    'device.hero_offline' => 'Offline ang kiosk. I-check ang power at Wi-Fi ng ESP32.',
    'device.stopped_feed_title' => 'Offline ang device',
    'device.stopped_feed_detail' => 'Hindi na tumutugon ang kiosk.',
    'device.online_feed_title' => 'Online ang device',
    'device.online_feed_detail' => 'Konektado at handa na ang kiosk.',
    'device.lost_contact' => 'Nawalan ng koneksyon sa kiosk.',
    'device.ws_connected_title' => 'Konektado ang WebSocket',
    'device.ws_connected_detail' => 'Live na sensor data sa local network.',

    // ----------------------------------------------------------
    // SENSOR CHIPS
    // ----------------------------------------------------------
    'chip.device_connected' => 'Device: Konektado',
    'chip.device_online' => 'Device: Online',
    'chip.device_offline' => 'Device: Offline',
    'chip.device_waiting' => 'Device: Naghihintay',
    'chip.device_firebase_off' => 'Device: Naka-off ang Firebase',
    'chip.device_firebase_ready' => 'Device: Handa ang Firebase',
    'chip.device_firebase_error' => 'Device: Error sa Firebase',
    'chip.lidar_ready' => 'LiDAR: Handa',
    'chip.lidar_waiting' => 'LiDAR: Naghihintay',
    'chip.lidar_live' => 'LiDAR: Live',
    'chip.lidar_offline' => 'LiDAR: Offline',
    'chip.scale_ready' => 'Timbangan: Handa',
    'chip.scale_waiting' => 'Timbangan: Naghihintay',
    'chip.scale_live' => 'Timbangan: Live',
    'chip.scale_offline' => 'Timbangan: Offline',

    // ----------------------------------------------------------
    // ACTIVITY FEED
    // ----------------------------------------------------------
    'feed.child_lookup_title' => 'Paghahanap ng bata',
    'feed.child_lookup_detail' => 'I-type ang Child ID para hanapin ang record.',
    'feed.selection_locked_title' => 'Naka-lock ang pagpili',
    'feed.selection_locked_active' => 'Hintayin matapos ang kasalukuyang pagsukat.',
    'feed.selection_locked_processing' => 'Pinoproseso ang kasalukuyang pagsukat.',
    'feed.child_selected_title' => 'Napiling bata',
    'feed.children_updated_title' => 'Nag-i-update ang listahan',
    'feed.children_updated_detail' => '{n} bagong bata ang naidagdag sa system.',
    'feed.session_restored_title' => 'Naibalik ang sesyon',
    'feed.session_restored_detail' => 'Naibalik ang aktibong sesyon ng pagsukat. Hinihintay ang mga sensor...',
    'feed.session_mismatch_detail' => 'Inaasahang SQL session #{expected}, ngunit nagbalik ang server ng #{received}.',
    'feed.reset_blocked_title' => 'Hindi ma-reset',
    'feed.reset_blocked_detail' => 'Hintayin matapos ang pagproseso ng pagsukat.',
    'feed.kiosk_reset_title' => 'Ni-reset ang kiosk',
    'feed.kiosk_reset_detail' => 'Handa na para sa susunod na bata.',
    'feed.back_to_lookup_title' => 'Bumalik sa paghahanap',
    'feed.session_cancelled_detail' => 'Kinansela ang sesyon. I-type ang Child ID para magpatuloy.',
    'feed.child_deselected_detail' => 'Hindi na pinili ang bata. I-type ang Child ID para magpatuloy.',
    'feed.back_blocked_title' => 'Hindi makabalik',
    'feed.back_blocked_detail' => 'Pinoproseso ang kasalukuyang pagsukat.',
    'feed.cancelling_title' => 'Kinakansela',
    'feed.cancelling_detail' => 'Tinatapos ang aktibong sesyon ng pagsukat...',
    'feed.no_child_title' => 'Walang napiling bata',
    'feed.no_child_double_check' => 'Maghanap muna ng bata bago mag-double-check.',
    'feed.no_child_restart' => 'Maghanap muna ng bata bago umulit ng sukat.',
    'feed.no_child_continue' => 'Maghanap muna ng bata bago magpatuloy.',
    'feed.double_check_title' => 'Double-check',
    'feed.double_check_detail' => 'Muling pagsukat para kay {name} — hindi magbabago ang iskedyul.',
    'feed.measure_again_title' => 'Sukatin ulit',
    'feed.measure_again_detail' => 'Double-check na pagsukat — hindi magbabago ang iskedyul.',
    'feed.restart_title' => 'Inulit ang sukat',
    'feed.restart_recheck' => 'Bagong double-check na sesyon — hindi magbabago ang iskedyul.',
    'feed.restart_normal' => 'Bagong pagsukat para sa parehong bata.',
    'feed.child_selected_confirm_title' => 'Bata napili',

    // ----------------------------------------------------------
    // NOT DUE
    // ----------------------------------------------------------
    'notdue.reason' => 'Hindi pa panahon ng pagsukat. Susunod na iskedyul: {date}{days}.',
    'notdue.aged_out' => 'Mahigit 59 buwan na ang batang ito at hindi ito saklaw ng kiosk.',
    'notdue.days' => ' (sa {n} araw)',
    'notdue.already_today' => 'Nasukat na ngayong araw. Puwede pa ring mag-double-check nang hindi nagbabago ang iskedyul.',

    // ----------------------------------------------------------
    // AGE / SEX
    // ----------------------------------------------------------
    'age.years_months' => '{y} taon, {m} buwan',
    'age.months' => '{m} buwan',
    'age.years_months_days' => '{y} taon, {m} buwan ({d} araw)',
    'age.months_days' => '{m} buwan ({d} araw)',
    'age.years_months_dot_days' => '{y} taon, {m} buwan · {d} araw',
    'age.months_dot_days' => '{m} buwan · {d} araw',
    'age.months_days_short' => '{m} buwan',
    'sex.male' => 'Lalaki',
    'sex.female' => 'Babae',

    // ----------------------------------------------------------
    // NUTRITIONAL STATUS LABELS
    // ----------------------------------------------------------
    'status.normal' => 'Normal',
    'status.tall' => 'Matangkad',
    'status.moderately_underweight' => 'Katamtamang Kulang sa Timbang',
    'status.severely_underweight' => 'Matinding Kulang sa Timbang',
    'status.overweight' => 'Sobra sa Timbang',
    'status.obese' => 'Napakataba',
    'status.moderately_stunted' => 'Katamtamang Bansot',
    'status.severely_stunted' => 'Matinding Bansot',
    'status.moderately_wasted' => 'Katamtamang Payat',
    'status.severely_wasted' => 'Matinding Payat',
    'status.pending' => 'Nakabinbin',

    // ----------------------------------------------------------
    // CLIENT-SIDE FALLBACKS / VALIDATION
    // ----------------------------------------------------------
    'errors.start_failed' => 'Hindi nasimulan ang pagsukat.',
    'errors.invalid_session_response' => 'Hindi nagbalik ng wastong session ID ng pagsukat ang server.',
    'errors.unable_contact_server' => 'Hindi ma-contact ang server.',
    'errors.confirm_cancel_session' => 'May aktibong pagsukat. Kapag bumalik, makakansela ang kasalukuyang sesyon. Magpatuloy?',

    // ----------------------------------------------------------
    // SERVER ERROR CODES (must match api/kiosk/*.php `code` values)
    // ----------------------------------------------------------
    'errors.invalid_json' => 'Hindi wastong kahilingan. Subukan muli.',
    'errors.invalid_device' => 'Hindi wastong device ID.',
    'errors.device_not_found' => 'Hindi mahanap ang device.',
    'errors.device_not_registered' => 'Hindi rehistrado ang device na ito. Makipag-ugnayan sa administrator.',
    'errors.child_required' => 'Kailangang pumili ng wastong bata.',
    'errors.child_not_found' => 'Hindi mahanap ang bata o hindi aktibo.',
    'errors.child_barangay' => 'Hindi awtorisadong sukatin ang batang ito sa kiosk na ito (ibang barangay).',
    'errors.session_id_required' => 'Kailangan ang wastong session ID.',
    'errors.session_not_found' => 'Hindi mahanap ang sesyon ng pagsukat para sa device na ito.',
    'errors.session_mismatch' => 'Hindi tugma ang sesyon ng pagsukat.',
    'errors.session_not_active' => 'Hindi aktibo ang pagsukat para sa sesyong ito.',
    'errors.session_moved_on' => 'Nausad na ang sesyon ng pagsukat. Magsimula muli.',
    'errors.session_already_active' => 'May aktibong pagsukat na para sa kiosk na ito.',
    'errors.session_load_failed' => 'Hindi ma-load ang sesyon ng pagsukat. Subukan muli.',
    'errors.unable_cancel' => 'Hindi makansela ang sesyon.',
    'errors.unable_process' => 'Hindi ma-request ang pagproseso.',
    'errors.unable_device_lookup' => 'Hindi maihanda ang device lookup.',
    'errors.server_error' => 'May naganap na error. Subukan muli.',
    'errors.unable_load_status' => 'Hindi ma-load ang status ng pagsukat.',

    // ----------------------------------------------------------
    // FULL PRIVACY NOTICE DOCUMENT
    // ----------------------------------------------------------
    'doc.privacy_title' => 'PAUNAWA SA PRIVACY',
    'doc.understand' => 'NAIINTINDIHAN KO',
    // HTML
    'doc.privacy_body' => <<<'KIOSK_HTML'
<p class="kiosk-modal-lead">Mahalaga sa amin ang inyong privacy.</p>
<p>Ang SukatKalusugan ay isang sistemang nakabatay sa pananaliksik na dinisenyo upang suportahan ang pagkolekta at pagtatasa ng mga antropometrikong sukat ng mga bata. Bago kayo sumali, nais naming maintindihan ninyo kung paano hahawakan ang impormasyong kokolektahin ng system.</p>

<h4>1. IMPORMASYONG KOKOLEKTAHIN</h4>
<p>Para sa layunin ng pag-aaral, maaaring kolektahin ng SukatKalusugan ang impormasyong kailangan para sa pagtatasa ng bata, kabilang ang:</p>
<ul>
    <li>Child ID o reference number</li>
    <li>Pangalan, kung kinakailangan</li>
    <li>Araw ng kapanganakan o edad</li>
    <li>Kasarian</li>
    <li>Barangay o iba pang impormasyong kailangan ng pag-aaral</li>
    <li>Timbang</li>
    <li>Taas o haba</li>
    <li>Petsa at oras ng pagsukat</li>
    <li>Resulta ng nutritional assessment</li>
    <li>WHO-based growth assessment at klasipikasyon</li>
</ul>
<p>Ang kokolektahin lamang ay ang impormasyong kailangan para sa mga layuning nabanggit.</p>

<h4>2. BAKIT KOKOLEKTAHIN ANG IMPORMASYONG ITO?</h4>
<p>Ang impormasyon ay kokolektahin para sa mga layunin ng pag-aaral na SukatKalusugan, kabilang ang:</p>
<ul>
    <li>pagsasagawa ng mga antropometrikong pagsukat;</li>
    <li>pagtatasa ng nutritional status ng mga bata;</li>
    <li>pagbuo ng mga resulta ng nutritional assessment;</li>
    <li>pagsuporta sa monitoring at pag-uulat kaugnay ng pag-aaral; at</li>
    <li>pagsuporta sa pananaliksik, ebalwasyon, at pagpapaunlad ng SukatKalusugan system.</li>
</ul>
<p>Ang impormasyon ay gagamitin lamang para sa mga tiyak na layuning saklaw ng aprubadong pag-aaral at data request.</p>

<h4>3. PAANO GAGAMITIN ANG IMPORMASYON?</h4>
<p>Ang nakolektang impormasyon ay maaaring iproseso upang makabuo ng mga sukat at nutritional indicator gaya ng:</p>
<ul>
    <li>Weight-for-Age (WFA)</li>
    <li>Height-for-Age (HFA)</li>
    <li>Weight-for-Height/Length (WFH/WFL)</li>
</ul>
<p>Maaaring gamitin ng system ang edad, kasarian, timbang, at taas ng bata upang mabuo ang kaukulang resulta ng pagtatasa batay sa naaangkop na WHO Child Growth Standards.</p>

<h4>4. SINO ANG MAAARING MAKAAKSES SA IMPORMASYON?</h4>
<p>Ang pag-access sa nakolektang impormasyon ay limitado lamang sa mga awtorisadong indibidwal na kasangkot sa pag-aaral at sa pagpapatakbo ng SukatKalusugan system, ayon sa kanilang itinalagang tungkulin.</p>
<p>Ang personal na impormasyon ng bata ay hindi ipapakalat sa publiko o ia-access ng mga hindi awtorisadong tao.</p>

<h4>5. PAANO POPROTEKTAHAN ANG IMPORMASYON?</h4>
<ul>
    <li>Naaangkop na teknikal, pisikal, at organisasyonal na hakbang ang ipapatupad upang maprotektahan ang impormasyon laban sa hindi awtorisadong pag-access, pagbubunyag, pagbabago, pagkawala, o maling paggamit.</li>
    <li>Ang mga awtorisadong user ng system ay bibigyan lamang ng access sa impormasyong kailangan para sa kanilang tungkulin.</li>
    <li>Lahat ng awtorisadong makakakuha ng impormasyon ay inaasahang pananatilihin itong kumpidensyal.</li>
</ul>

<h4>6. KUMPIDENSYALIDAD</h4>
<p>Ang impormasyong makokolekta sa pag-aaral ay ituturing na kumpidensyal.</p>
<p>Ang impormasyong makukuha sa pamamagitan ng aprubadong data request ay gagamitin lamang para sa mga tiyak na layuning nakasaad sa pag-aaral at kaugnay na dokumentasyon.</p>
<p>Hindi pinapayagan ang hindi awtorisadong pag-access, pagproseso, paggamit, o pagbubunyag ng impormasyon.</p>

<h4>7. PAGBABAHAGI AT PAGBUBUNYAG NG DATA</h4>
<p>Ang personal na impormasyon ay hindi ibubunyag o ibabahagi sa mga hindi awtorisadong indibidwal o organisasyon.</p>
<p>Anumang awtorisadong paggamit, pagbubunyag, o pagproseso ng impormasyon ay dapat naaayon sa mga layunin ng pag-aaral at sa mga naaangkop na kinakailangan sa data privacy.</p>

<h4>8. PAGTATAGO NG DATA</h4>
<p>Ang impormasyon ng pag-aaral ay itatago lamang sa panahong kailangan upang matupad ang aprubadong layunin ng pag-aaral at ang mga naaangkop na kinakailangan.</p>
<p>Kapag hindi na kailangan ang impormasyon, ito ay ligtas na itatapon, ide-delete, o ia-anonymize kapag naaangkop at alinsunod sa mga naaangkop na patakaran ng pag-aaral.</p>

<h4>9. INYONG MGA KARAPATAN SA PRIVACY</h4>
<p>Sa ilalim ng Data Privacy Act of 2012 (Republic Act No. 10173), ang data subjects ay may mga karapatan kaugnay ng kanilang personal na impormasyon, alinsunod sa mga naaangkop na batas at kinakailangan.</p>
<p>Kabilang dito ang karapatang:</p>
<ul>
    <li>mabigyan ng impormasyon tungkol sa pagproseso ng personal na impormasyon;</li>
    <li>ma-access ang personal na impormasyon;</li>
    <li>hilingin ang pagwawasto ng hindi tumpak na impormasyon;</li>
    <li>tutulan ang ilang pagproseso kapag naaangkop; at</li>
    <li>hilingin ang pag-delete o pag-block kapag naaangkop.</li>
</ul>
<p>Para sa impormasyong may kinalaman sa bata, ang mga karapatang ito ay maaaring gamitin ng magulang o legal na tagapag-alaga ng bata, kapag naaangkop.</p>

<h4>10. PANGANGAKO SA DATA PRIVACY</h4>
<p>Kinikilala ng SukatKalusugan ang kahalagahan ng pagprotekta sa personal na impormasyon ng mga bata.</p>
<p>Ang pagkolekta at pagproseso ng impormasyon para sa pag-aaral na ito ay isasagawa nang naaayon sa Republic Act No. 10173, o ang Data Privacy Act of 2012, at sa mga naaangkop na patakaran sa data privacy at seguridad na namamahala sa aprubadong pag-aaral at data request.</p>

<h4>PAGKILALA</h4>
<p>Bago magpatuloy sa pagsukat, siguraduhing nabasa at naintindihan ninyo ang Paunawa sa Privacy na ito.</p>
<p><em>Nabasa at naintindihan ko ang Paunawa sa Privacy na ito at nauunawaan ko kung paano maaaring kolektahin at gamitin ang impormasyon ng aking anak para sa mga layunin ng pag-aaral na SukatKalusugan.</em></p>
KIOSK_HTML,

    // ----------------------------------------------------------
    // FULL INFORMATION SHEET DOCUMENT
    // ----------------------------------------------------------
    'doc.info_title' => 'SHEET NG IMPORMASYON',
    // HTML
    'doc.info_body' => <<<'KIOSK_HTML'
<h4>Panimula</h4>
<p>Kami ay mga estudyante mula sa College of Computer Studies ng Our Lady of Fatima University na nagsasagawa ng pananaliksik para sa aming capstone project, "SukatKalusugan: A Smart Kiosk-Based Anthropometric Monitoring System with a Web Application for the City Health Office in the City of San Fernando, Pampanga." Ang pag-aaral na ito ay isinasagawa sa City Health Office ng Lungsod ng San Fernando, Pampanga, na may layuning mapabuti ang bilis at katumpakan ng pagkolekta ng data gamit ang makabagong teknolohiya.</p>

<h4>Layunin ng Pananaliksik</h4>
<p>Layunin ng pag-aaral na ito na magdisenyo at bumuo ng smart kiosk-based system para sa pagsubaybay ng mga antropometrikong sukat na pinagsasama ang mga IoT sensor, AI analysis para sa decision support, at web application para sa pagkolekta, pagsubaybay, at pamamahala ng antropometrikong data. Nilalayon ng system na magbigay ng tumpak na real-time na mga sukat at matukoy ang mga trend at pattern sa mga antropometrikong klasipikasyon ng mga batang edad 24-59 buwan ayon sa pamantayan ng WHO, na sumusuporta sa health monitoring at nagtataguyod ng kamalayan sa kanilang nutritional at pisikal na paglaki.</p>

<h4>Uri ng Research Intervention</h4>
<p>Kasama sa pakikilahok ang mga magulang, nutritionist, at IT expert na sumusubok sa smart kiosk-based anthropometric monitoring system sa tulong ng City Nutrition Program Coordinators mula sa City Health Office. Gagamitin ng mga kalahok ang kiosk at web application upang kolektahin at tingnan ang antropometrikong data, habang sinusubaybayan ng system ang mga sukat sa paglipas ng panahon upang matukoy ang mga trend at pattern na maaaring makatulong sa follow-up monitoring. Susuriin din ng mga kalahok ang system sa pamamagitan ng ISO 25010-based na survey at panayam.</p>

<h4>Pagpili ng Kalahok</h4>
<p>Inaanyayahan ka naming makilahok dahil ang iyong karanasan bilang magulang/tagapag-alaga, nutritionist, o IT expert ay mahalaga upang matulungan kaming maunawaan ang child nutrition monitoring at mapabuti ang system.</p>

<h4>Kusang-loob na Pakikilahok</h4>
<p>Ang iyong pakikilahok sa pag-aaral na ito ay lubos na kusang-loob. Maaari kang pumili na hindi makilahok o mag-withdraw sa pag-aaral anumang oras nang hindi nagbibigay ng dahilan. Kapag nagpasya kang mag-withdraw, hindi ka na ituturing na kalahok at hindi na kasama sa anumang bahagi ng pag-aaral o mga gawain nito. Ang pagpili na hindi makilahok o pag-withdraw sa pag-aaral ay hindi magreresulta sa anumang parusa, kawalan, o pagkawala ng anumang serbisyo o benepisyong pangkalusugan na karapatan mong matanggap mula sa City Health Office. Hindi maaapektuhan ng iyong desisyon ang ugnayan mo sa mga mananaliksik, City Health Office, Our Lady of Fatima University, o anumang institusyong kasangkot sa pag-aaral.</p>

<h4>Mga Pamamaraan</h4>
<p>Inaanyayahan ka ng mga mananaliksik na tulungan silang maunawaan ang mga paraan ng child nutrition monitoring sa City Health Office ng Lungsod ng San Fernando, Pampanga at kung paano mapapabuti ng SukatKalusugan system ang proseso. Kung sang-ayon kang makilahok, hihilingin sa iyong pumirma ng consent forms para sa dokumentasyon.</p>
<p>Hihilingin sa iyo na:</p>
<ul>
    <li>Makipag-ugnayan sa SukatKalusugan system (kiosk at web application) upang kolektahin at tingnan ang mga antropometrikong sukat ng mga bata, na tatagal ng humigit-kumulang 1 oras.</li>
    <li>Sagutan ang survey batay sa ISO 25010 standards upang suriin ang functionality, reliability, usability, at iba pang quality attributes ng system, na tatagal ng humigit-kumulang 15-20 minuto.</li>
    <li>Makilahok sa maikling panayam (10-15 minuto) upang magbigay ng feedback sa iyong karanasan.</li>
</ul>
<p>Kung ayaw mong sagutin ang anumang tanong sa survey o panayam, maaari mong laktawan ang mga ito at magpatuloy sa susunod na gawain. Ang lahat ng makokolektang impormasyon ay mananatiling kumpidensyal. Hindi lalabas ang iyong pangalan sa mga rekord ng survey o panayam; sa halip, isang natatanging numero ang kikilala sa iyong mga sagot. Tanging ang mga mananaliksik, capstone adviser, at panelist lamang ang may access sa data.</p>

<h4>Tagal</h4>
<p>Ang mga gawain ay tatagal ng humigit-kumulang 1 oras sa kabuuan. Isasagawa ang pananaliksik mula Hunyo 2026 hanggang Disyembre 2026. Sa panahong ito, maaari ka naming kontakin paminsan-minsan sa email o telepono para sa follow-up o karagdagang input.</p>

<h4>Mga Panganib</h4>
<p>Walang inaasahang pisikal na panganib na kaugnay ng pag-aaral na ito. Ang mga antropometrikong sukat na kokolektahin ng system ay non-invasive at hindi magdudulot ng pisikal na pinsala sa mga kalahok. Gayunpaman, maaaring may bahagyang abala o kakulangan sa kaginhawahan habang ginagamit ang kiosk o nagbibigay ng impormasyong kailangan ng pag-aaral. Maaaring piliin ng mga kalahok na hindi sagutin ang anumang tanong o mag-withdraw sa pag-aaral anumang oras nang walang parusa. Upang maprotektahan ang privacy at kumpidensyalidad, ipapatupad ang naaangkop na hakbang sa seguridad upang pangalagaan ang data na kokolektahin sa pamamagitan ng kiosk at web application.</p>

<h4>Mga Benepisyo</h4>
<p>Bilang magulang/tagapag-alaga, nutritionist, o IT expert, ang iyong pakikilahok ay makakatulong mapabuti ang pagbuo ng smart kiosk-based anthropometric monitoring system para sa mga batang edad 24–59 buwan. Ang iyong pakikilahok ay mag-aambag sa ebalwasyon ng kakayahan ng system na kolektahin ang mga antropometrikong sukat, subaybayan ang mga rekord, at tukuyin ang mga trend at pattern sa paglipas ng panahon. Magkakaroon ka ng karanasan sa makabagong IoT at ang iyong feedback ay makakatulong mapabuti ang usability, functionality, at reliability ng kiosk at web application.</p>

<h4>Mga Reimbursement</h4>
<p>Walang anumang pinansyal o materyal na insentibo na ibibigay para sa pakikilahok sa pananaliksik na ito.</p>

<h4>Kumpidensyalidad</h4>
<p>Ang lahat ng impormasyong makokolekta sa pag-aaral na ito ay mananatiling mahigpit na kumpidensyal at maa-access lamang ng research team, capstone adviser, at panel members para sa layunin ng akademikong ebalwasyon. Ang personal na impormasyon at antropometrikong rekord ay itatago nang ligtas, at ang pagkakakilanlan ng mga kalahok ay poprotektahan sa pamamagitan ng paggamit ng natatanging identifier o code sa halip na pangalan hangga't maaari. Ang nakolektang data ay gagamitin lamang para sa layunin ng pananaliksik at hahawakan alinsunod sa Data Privacy Act of 2012 (Republic Act No. 10173) ng Pilipinas. Sa pagtatapos ng pag-aaral, ang lahat ng rekord ay ligtas na itatago o itatapon alinsunod sa institutional at data privacy guidelines.</p>

<h4>Pagbabahagi ng Resulta</h4>
<p>Anumang impormasyong ibibigay mo sa pag-aaral na ito ay mananatiling kumpidensyal at hindi ibabahagi sa labas ng research team. Walang data na maiuugnay sa iyong pangalan. Ang mga natuklasan mula sa pananaliksik na ito ay ibabahagi sa iyo at sa City Health Office ng Lungsod ng San Fernando Pampanga bago ang anumang pampublikong pagpapalabas. Balak ng mga mananaliksik na i-publish ang mga resulta upang makinabang din ang iba sa pag-aaral.</p>

<h4>Karapatang Tumanggi o Mag-withdraw</h4>
<p>Ang pakikilahok sa pananaliksik na ito ay lubos na kusang-loob. Ang pagpili na hindi makilahok ay hindi maaapektuhan ang iyong trabaho, tungkulin, responsibilidad, o ugnayan sa City Health Office sa anumang paraan. Maaari ka ring mag-withdraw sa pag-aaral anumang oras nang walang anumang parusa o negatibong kahihinatnan. Sa pagtatapos ng proseso ng pagkolekta ng data, magkakaroon ka ng pagkakataong suriin ang iyong mga sagot at maaaring humiling ng pagbabago o pag-alis ng anumang impormasyong hindi mo komportableng ibahagi.</p>
KIOSK_HTML,

];
